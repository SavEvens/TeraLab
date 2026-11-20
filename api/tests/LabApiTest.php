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

final class LabApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private string $cookie = '';
    private string $csrf = '';

    /** @var list<array{path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $runningNodes = [];

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
                return [200, json_encode([
                    'ok' => true,
                    'result' => ['id' => $payload['id'] ?? '', 'state' => 'running'],
                ], JSON_THROW_ON_ERROR)];
            }
            if (str_starts_with($path, '/v1/node.status')) {
                return [200, '{"ok":true,"result":{"id":"node","state":"running"}}'];
            }
            if ($path === '/v1/node.stop' || $path === '/v1/node.wipe') {
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

        $this->app = (new App($config, $agent))->create();
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
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->cookie;
        if ($effectiveCookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $effectiveCookie]);
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
