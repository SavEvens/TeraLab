<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

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

final class LabApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private string $cookie = '';
    private string $csrf = '';
    private \PDO $pdo;

    /** @var list<array{path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $runningNodes = [];

    /** @var array<string, bool> Agent node id => running. */
    private array $running = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-labapi-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, function (string $method, string $path, array $payload): array {
            $this->calls[] = ['path' => $path, 'payload' => $payload];
            if ($path === '/v1/lab.ensure') {
                return [200, '{"ok":true,"result":{"bridges":{"0":"tl-test-bridge-0"}}}'];
            }
            if ($path === '/v1/node.start') {
                $id = (string) ($payload['id'] ?? '');
                $this->running[$id] = true;

                return [200, json_encode([
                    'ok' => true,
                    'result' => ['id' => $id, 'state' => 'running', 'ready' => true],
                ], JSON_THROW_ON_ERROR)];
            }
            if (str_starts_with($path, '/v1/node.status')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $id = (string) ($query['id'] ?? '');
                if (!isset($this->running[$id])) {
                    return [404, '{"ok":false,"error":{"code":"not_found","message":"node not found"}}'];
                }

                return [200, '{"ok":true,"result":{"id":"node","state":"running","ready":true}}'];
            }
            if ($path === '/v1/node.stop' || $path === '/v1/node.wipe') {
                unset($this->running[(string) ($payload['id'] ?? '')]);

                return [200, '{"ok":true,"result":{"state":"ok"}}'];
            }
            if ($path === '/v1/system/status') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => ['nodes' => $this->runningNodes],
                ], JSON_THROW_ON_ERROR)];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->pdo = (new Database($config))->pdo();
        $this->app = (new App($config, $agent))->create();
        $this->registerImage('base.qcow2');
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        $this->cookie = $this->cookie($setup);
        $this->csrf = (string) $this->json($setup)['csrf_token'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testCreateStartExportImportAndV1NodeLifecycle(): void
    {
        $create = $this->request('POST', '/api/v2/labs', [
            'name' => 'API Lab',
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [[
                'id' => '1',
                'name' => 'R1',
                'type' => 'qemu',
                'image' => 'base.qcow2',
                'cpu' => 1,
                'ram' => 512,
                'interfaces' => [['id' => 0, 'network_id' => '0']],
            ]],
        ]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $lab = $this->json($create)['data'];
        self::assertSame(1, $lab['revision']);

        $start = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/start');
        self::assertSame(202, $start->getStatusCode(), (string) $start->getBody());
        self::assertCount(1, $this->json($start)['data']['nodes']);
        self::assertNotSame([], $this->calls);

        $nodes = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/nodes');
        self::assertSame(200, $nodes->getStatusCode());
        self::assertSame('running', $this->json($nodes)['data']['nodes'][0]['state']);
        self::assertTrue($this->json($nodes)['data']['nodes'][0]['ready']);

        $export = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/export');
        self::assertSame(200, $export->getStatusCode());
        self::assertStringContainsString('<node id="1"', (string) $export->getBody());
        self::assertStringContainsString('network_id="0"', (string) $export->getBody());

        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload((string) $export->getBody(), 'import.unl'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        self::assertNotSame($lab['id'], $this->json($import)['data']['id']);

        // Legacy v1 core routes: login, auth, labs, node start/stop/wipe.
        $login = $this->request('POST', '/api/auth/login', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $login->getStatusCode());
        $v1Cookie = $this->cookie($login);

        $auth = $this->request('GET', '/api/auth', [], [], $v1Cookie);
        self::assertSame(200, $auth->getStatusCode());
        self::assertSame('admin', $this->json($auth)['data']['username']);

        $labs = $this->request('GET', '/api/labs', [], [], $v1Cookie);
        self::assertSame(200, $labs->getStatusCode());
        self::assertNotEmpty($this->json($labs)['data']);

        $path = (string) $lab['path'];
        $v1Start = $this->request('GET', '/api/labs/' . $path . '/nodes/1/start', [], [], $v1Cookie);
        self::assertSame(200, $v1Start->getStatusCode(), (string) $v1Start->getBody());
        self::assertSame('success', $this->json($v1Start)['status']);

        $v1Stop = $this->request('GET', '/api/labs/' . $path . '/nodes/1/stop', [], [], $v1Cookie);
        self::assertSame(200, $v1Stop->getStatusCode());

        $v1Wipe = $this->request('GET', '/api/labs/' . $path . '/nodes/1/wipe', [], [], $v1Cookie);
        self::assertSame(200, $v1Wipe->getStatusCode());
    }

    public function testSaveIncrementsRevisionAndRejectsStaleWrites(): void
    {
        $create = $this->request('POST', '/api/v2/labs', ['name' => 'Revision Lab']);
        $lab = $this->json($create)['data'];

        $save = $this->request('PUT', '/api/v2/labs/' . $lab['id'], [
            'name' => 'Revision Lab 2',
            'revision' => 1,
        ]);
        self::assertSame(200, $save->getStatusCode());
        self::assertSame(2, $this->json($save)['data']['revision']);

        $stale = $this->request('PUT', '/api/v2/labs/' . $lab['id'], [
            'name' => 'Stale',
            'revision' => 1,
        ]);
        self::assertSame(409, $stale->getStatusCode());
    }

    public function testNodesResponseIncludesReadyFlag(): void
    {
        $create = $this->request('POST', '/api/v2/labs', [
            'name' => 'Ready Lab',
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'cpu' => 1,
                'ram' => 64,
                'interfaces' => [],
            ]],
        ]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $lab = $this->json($create)['data'];

        $nodes = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/nodes');
        self::assertSame(200, $nodes->getStatusCode());
        $node = $this->json($nodes)['data']['nodes'][0];
        self::assertArrayHasKey('ready', $node);
        self::assertFalse($node['ready']);
        self::assertSame('stopped', $node['state']);
    }

    public function testLabListReportsRunningState(): void
    {
        $create = $this->request('POST', '/api/v2/labs', ['name' => 'State Lab']);
        $lab = $this->json($create)['data'];

        $list = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        $row = $this->labRow($list, (string) $lab['id']);
        self::assertFalse($row['running']);
        self::assertSame(0, $row['running_nodes']);

        $this->runningNodes = [
            ['id' => 'a-1', 'lab_id' => $lab['id'], 'state' => 'running'],
            ['id' => 'a-2', 'lab_id' => $lab['id'], 'state' => 'stopped'],
            ['id' => 'b-1', 'lab_id' => 'another-lab', 'state' => 'running'],
        ];

        $list = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        $row = $this->labRow($list, (string) $lab['id']);
        self::assertTrue($row['running']);
        self::assertSame(1, $row['running_nodes']);
    }

    public function testCrossUserLabIsolation(): void
    {
        $lab = $this->createLab('Private Lab');
        $labID = (string) $lab['id'];
        $this->createUser('user-b', 'Teralab-User-B-2026!', 'editor');
        [$bCookie, $bCsrf] = $this->login('user-b', 'Teralab-User-B-2026!');

        // B is an editor: role gates pass, so every 403 below comes from the
        // per-lab ownership check rather than the editor middleware.
        $requests = [
            ['GET', '/api/v2/labs/' . $labID, []],
            ['PUT', '/api/v2/labs/' . $labID, ['revision' => 1]],
            ['GET', '/api/v2/labs/' . $labID . '/export', []],
            ['GET', '/api/v2/labs/' . $labID . '/nodes', []],
            ['POST', '/api/v2/labs/' . $labID . '/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/wipe', []],
            ['POST', '/api/v2/labs/' . $labID . '/move', ['folder' => 'elsewhere']],
            ['POST', '/api/v2/labs/' . $labID . '/duplicate', []],
            ['DELETE', '/api/v2/labs/' . $labID, []],
            ['GET', '/api/v2/labs/' . $labID . '/snapshots', []],
            ['POST', '/api/v2/labs/' . $labID . '/snapshots', ['name' => 'intruder']],
            ['POST', '/api/v2/labs/' . $labID . '/snapshots/whatever/restore', []],
            ['DELETE', '/api/v2/labs/' . $labID . '/snapshots/whatever', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/wipe', []],
        ];
        foreach ($requests as [$method, $path, $body]) {
            $response = $this->request($method, $path, $body, [], $bCookie, null, $bCsrf);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }

        $bIndex = $this->json($this->request('GET', '/api/v2/labs', [], [], $bCookie))['data'];
        self::assertNotContains($labID, array_column($bIndex, 'id'));

        $adminIndex = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        self::assertContains($labID, array_column($adminIndex, 'id'));
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID)->getStatusCode());
    }

    /**
     * @param list<array<string, mixed>> $labs
     * @return array<string, mixed>
     */
    private function labRow(array $labs, string $id): array
    {
        foreach ($labs as $lab) {
            if ((string) $lab['id'] === $id) {
                return $lab;
            }
        }
        self::fail('lab not found in listing: ' . $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function createLab(string $name): array
    {
        $response = $this->request('POST', '/api/v2/labs', [
            'name' => $name,
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'interfaces' => [['id' => 0, 'network_id' => '0']],
            ]],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function createUser(string $username, string $password, string $role): string
    {
        $id = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $username,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => $role,
            ':now' => time(),
        ]);

        return $id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function login(string $username, string $password): array
    {
        $response = $this->request('POST', '/api/v2/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return [$this->cookie($response), (string) $this->json($response)['csrf_token']];
    }

    private function registerImage(string $reference): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO images (id, kind, name, reference, path, sha256, size, virtual_size, format, created_at) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, :created_at)'
        );
        $statement->execute([
            ':id' => 'img-' . substr(hash('sha256', $reference), 0, 16),
            ':kind' => 'qemu',
            ':name' => $reference,
            ':reference' => $reference,
            ':path' => $this->root . '/data/images/' . $reference,
            ':sha256' => hash('sha256', $reference),
            ':size' => 1,
            ':virtual_size' => 1,
            ':format' => 'qcow2',
            ':created_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $cookie = null,
        ?UploadedFile $file = null,
        ?string $csrf = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->cookie;
        $effectiveCsrf = $csrf ?? $this->csrf;
        if ($effectiveCookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $effectiveCookie]);
        }
        if ($effectiveCsrf !== '') {
            $request = $request->withHeader('X-CSRF-Token', $effectiveCsrf);
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
                ->withUploadedFiles(['file' => $file]);
        }

        return $this->app->handle($request);
    }

    private function upload(string $content, string $name): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            'application/xml',
            strlen($content),
            UPLOAD_ERR_OK,
        );
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
