<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;

final class ImageApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private string $cookie = '';
    private string $csrf = '';
    /** @var list<array<string, mixed>> */
    private array $agentNodes = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-image-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data/templates', 0770, true);
        mkdir($this->root . '/addons/qemu/csr1000vng', 0770, true);
        file_put_contents($this->root . '/addons/qemu/csr1000vng/hda.qcow2', "QFI\xfb" . str_repeat('a', 200));
        file_put_contents(
            $this->root . '/data/templates/csr1000v.yaml',
            "schema: 1\nid: csr1000v\nname: CSR1000v\nkind: qemu\n",
        );

        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, function (string $method, string $path, array $payload): array {
            if ($path === '/v1/image.inspect') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'format' => 'qcow2',
                        'virtual_size' => 8388608,
                        'backing_file' => '',
                        'sha256' => hash_file('sha256', (string) ($payload['path'] ?? '')),
                    ],
                ], JSON_THROW_ON_ERROR)];
            }
            if ($path === '/v1/system/status') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => ['nodes' => $this->agentNodes],
                ], JSON_THROW_ON_ERROR)];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->app = (new App($config, $agent))->create();
        $this->pdo = (new Database($config))->pdo();
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode());
        $this->cookie = $this->cookie($setup);
        $this->csrf = (string) $this->json($setup)['csrf_token'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testUploadListAndDelete(): void
    {
        $content = "QFI\xfb" . str_repeat("\0", 128);
        $upload = $this->request('POST', '/api/v2/images', [], [], $this->uploaded($content, 'base.qcow2'), [
            'kind' => 'qemu',
            'name' => 'Base image',
        ]);
        self::assertSame(201, $upload->getStatusCode(), (string) $upload->getBody());
        $image = $this->json($upload)['data'];
        self::assertSame(hash('sha256', $content), $image['sha256']);
        self::assertSame('Base image', $image['name']);
        self::assertSame(8388608, $image['virtual_size']);

        $list = $this->request('GET', '/api/v2/images');
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $this->json($list)['data']);

        $delete = $this->request('DELETE', '/api/v2/images/' . $image['id']);
        self::assertSame(200, $delete->getStatusCode(), (string) $delete->getBody());

        $after = $this->request('GET', '/api/v2/images');
        self::assertCount(0, $this->json($after)['data']);
    }

    public function testDeleteIgnoresStoppedNodesButBlocksRunningOnes(): void
    {
        $stopped = $this->uploadImage("QFI\xfb" . str_repeat('b', 256), 'stopped.qcow2');
        $this->agentNodes = [
            [
                'id' => 'node-stopped',
                'name' => 'stopped',
                'state' => 'stopped',
                'image' => $stopped['path'],
            ],
        ];
        $delete = $this->request('DELETE', '/api/v2/images/' . $stopped['id']);
        self::assertSame(200, $delete->getStatusCode(), (string) $delete->getBody());

        $running = $this->uploadImage("QFI\xfb" . str_repeat('c', 256), 'running.qcow2');
        $this->agentNodes = [
            [
                'id' => 'node-running',
                'name' => 'running',
                'state' => 'running',
                'image' => $running['path'],
            ],
        ];
        $blocked = $this->request('DELETE', '/api/v2/images/' . $running['id']);
        self::assertSame(409, $blocked->getStatusCode(), (string) $blocked->getBody());
        self::assertSame('The image is in use by a running node.', $this->json($blocked)['message']);

        $list = $this->request('GET', '/api/v2/images');
        self::assertCount(1, $this->json($list)['data']);
    }

    public function testImportRouteRequiresAdmin(): void
    {
        [$editorCookie, $editorCsrf] = $this->editorSession();

        $savedCookie = $this->cookie;
        $savedCsrf = $this->csrf;
        $this->cookie = '';
        $this->csrf = '';
        $anonymous = $this->request('POST', '/api/v2/images/import', ['dry_run' => true]);
        self::assertSame(401, $anonymous->getStatusCode());

        $this->cookie = $editorCookie;
        $this->csrf = $editorCsrf;
        $editor = $this->request('POST', '/api/v2/images/import', ['dry_run' => true]);
        self::assertSame(403, $editor->getStatusCode());

        $this->cookie = $savedCookie;
        $this->csrf = $savedCsrf;
        $admin = $this->request('POST', '/api/v2/images/import', [
            'root' => $this->addons(),
            'dry_run' => true,
        ]);
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getBody());
    }

    public function testImportDryRunShape(): void
    {
        $response = $this->request('POST', '/api/v2/images/import', [
            'root' => $this->addons(),
            'dry_run' => true,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('success', $body['status']);
        $data = $body['data'];
        self::assertSame(realpath($this->addons()), $data['root']);
        self::assertTrue($data['dry_run']);
        self::assertArrayHasKey('warnings', $data);
        self::assertSame(['new' => 1, 'existing' => 0, 'duplicate' => 0, 'unreadable' => 0, 'skipped' => 0], $data['summary']);
        self::assertCount(1, $data['entries']);
        $entry = $data['entries'][0];
        self::assertSame('qemu/csr1000vng/hda.qcow2', $entry['path']);
        self::assertSame('qemu', $entry['kind']);
        self::assertSame('new', $entry['status']);
        self::assertSame('csr1000vng', $entry['reference']);
        self::assertSame('csr1000v', $entry['template_suggestion']);
        self::assertSame('csr1000v', $entry['template']);
        self::assertNull($entry['error']);
        self::assertGreaterThan(0, $entry['size']);
    }

    public function testImportHappyPath(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $response = $this->request('POST', '/api/v2/images/import', [
            'root' => $this->addons(),
            'dry_run' => false,
            'files' => [$relative],
            'templates' => [$relative => 'csr1000v'],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertFalse($data['dry_run']);
        self::assertSame(['imported' => 1, 'duplicate' => 0, 'skipped' => 0, 'failed' => 0], $data['summary']);
        $result = $data['results'][0];
        self::assertSame($relative, $result['path']);
        self::assertSame('imported', $result['status']);
        self::assertNull($result['error']);
        self::assertSame(1, $result['image']['external']);
        self::assertSame('csr1000v', $result['image']['template']);
        self::assertTrue(is_link((string) $result['image']['path']));
        self::assertSame(
            realpath($this->addons() . '/' . $relative),
            readlink((string) $result['image']['path']),
        );

        $list = $this->request('GET', '/api/v2/images');
        $images = $this->json($list)['data'];
        self::assertCount(1, $images);
        self::assertSame(1, $images[0]['external']);

        $audit = $this->pdo->query("SELECT details_json FROM audit_log WHERE action = 'image.import'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $audit);
        $details = json_decode((string) $audit[0], true);
        self::assertIsArray($details);
        self::assertSame(realpath($this->addons()), $details['root']);
        self::assertSame(1, $details['summary']['imported']);
    }

    public function testImportRejectsAJsonObjectForFiles(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $response = $this->request('POST', '/api/v2/images/import', [
            'root' => $this->addons(),
            'dry_run' => false,
            'files' => [$relative => $relative],
        ]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('JSON list', (string) $this->json($response)['message']);
        self::assertCount(0, $this->json($this->request('GET', '/api/v2/images'))['data']);
    }

    public function testImportRejectsControlCharactersInPaths(): void
    {
        $response = $this->request('POST', '/api/v2/images/import', [
            'root' => $this->addons(),
            'dry_run' => false,
            'files' => ["qemu/csr1000vng/hda.qcow2\0"],
        ]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('control characters', (string) $this->json($response)['message']);
        self::assertCount(0, $this->json($this->request('GET', '/api/v2/images'))['data']);

        $root = $this->request('POST', '/api/v2/images/import', [
            'root' => $this->addons() . "\n",
            'dry_run' => true,
        ]);
        self::assertSame(422, $root->getStatusCode(), (string) $root->getBody());
        self::assertStringContainsString('control characters', (string) $this->json($root)['message']);
    }

    public function testImportRequiresCsrf(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/images/import', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([Config::COOKIE_NAME => $this->cookie])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode(['dry_run' => true], JSON_THROW_ON_ERROR)));

        $response = $this->app->handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvalidUploadIsRejectedWithoutRow(): void
    {
        $upload = $this->request('POST', '/api/v2/images', [], [], $this->uploaded('not an image', 'bad.qcow2'), [
            'kind' => 'qemu',
        ]);
        self::assertSame(422, $upload->getStatusCode());

        $list = $this->request('GET', '/api/v2/images');
        self::assertCount(0, $this->json($list)['data']);
    }

    public function testUploadRequiresCsrf(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/images', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([Config::COOKIE_NAME => $this->cookie])
            ->withUploadedFiles(['file' => $this->uploaded("QFI\xfb" . str_repeat("\0", 16), 'base.qcow2')])
            ->withParsedBody(['kind' => 'qemu']);

        $response = $this->app->handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    private function addons(): string
    {
        return $this->root . '/addons';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function editorSession(): array
    {
        $password = 'Editor-Pass-2026!';
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':username' => 'editor',
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':now' => time(),
        ]);
        $response = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'editor',
            'password' => $password,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return [$this->cookie($response), (string) $this->json($response)['csrf_token']];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadImage(string $content, string $name): array
    {
        $upload = $this->request('POST', '/api/v2/images', [], [], $this->uploaded($content, $name), [
            'kind' => 'qemu',
        ]);
        self::assertSame(201, $upload->getStatusCode(), (string) $upload->getBody());
        $data = $this->json($upload)['data'];
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function uploaded(string $content, string $name): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            'application/octet-stream',
            strlen($content),
            UPLOAD_ERR_OK,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $fields
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?UploadedFile $file = null,
        array $fields = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($this->cookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $this->cookie]);
        }
        if ($this->csrf !== '') {
            $request = $request->withHeader('X-CSRF-Token', $this->csrf);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        if ($file !== null) {
            $request = $request
                ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
                ->withUploadedFiles(['file' => $file])
                ->withParsedBody($fields);
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function cookie(ResponseInterface $response): string
    {
        if (preg_match('/' . preg_quote(Config::COOKIE_NAME, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
