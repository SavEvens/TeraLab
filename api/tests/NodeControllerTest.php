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
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;

final class NodeControllerTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;

    /** @var list<array{method: string, path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-nodes-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testStartRequiresCsrf(): void
    {
        [$app, $cookie] = $this->appWithSession();

        $response = $this->request($app, 'POST', '/api/v2/nodes/start', [
            'id' => 'node1',
            'name' => 'R1',
        ], [], $cookie);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testStartForwardsSpecAndReturnsAgentState(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();

        $response = $this->request($app, 'POST', '/api/v2/nodes/start', [
            'id' => 'node1',
            'lab_id' => 'lab1',
            'name' => 'R1',
            'image' => '/var/lib/teralab/images/router.qcow2',
            'cpu' => 2,
            'ram' => 1024,
            'ethernet' => 2,
            'networks' => ['tlab0', 'tlab1'],
            'console' => 'telnet',
        ], ['X-CSRF-Token' => $csrf], $cookie);

        self::assertSame(202, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('running', $body['data']['state']);
        self::assertSame('node1', $body['data']['id']);

        self::assertCount(1, $this->calls);
        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('/v1/node.start', $call['path']);
        self::assertSame('node1', $call['payload']['id']);
        self::assertSame('lab1', $call['payload']['lab_id']);
        self::assertSame(2, $call['payload']['cpu']);
        self::assertSame(1024, $call['payload']['ram']);
        self::assertSame(['tlab0', 'tlab1'], $call['payload']['networks']);
    }

    public function testStartRejectsInvalidFields(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();

        foreach ([
            ['name' => 'R1'],
            ['id' => 'node1'],
            ['id' => 'node1', 'name' => 'R1', 'cpu' => 1000],
            ['id' => 'node1', 'name' => 'R1', 'ethernet' => 'two'],
            ['id' => 'node1', 'name' => 'R1', 'networks' => [1]],
        ] as $payload) {
            $response = $this->request(
                $app,
                'POST',
                '/api/v2/nodes/start',
                $payload,
                ['X-CSRF-Token' => $csrf],
                $cookie,
            );
            self::assertSame(422, $response->getStatusCode(), json_encode($payload));
        }
        self::assertSame([], $this->calls);
    }

    public function testDockerStartForwardsTheImageAndTheDockerBlock(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();

        $response = $this->request($app, 'POST', '/api/v2/nodes/start', [
            'id' => 'node1',
            'lab_id' => 'lab1',
            'name' => 'R1',
            'engine' => 'docker',
            'image' => 'frrouting/frr:10.0.1',
            'cpu' => 1,
            'ram' => 256,
            'ethernet' => 1,
            'networks' => ['tlab0'],
            'console' => 'telnet',
            'docker' => [
                'console_command' => '/usr/bin/vtysh',
                'cap_add' => ['NET_ADMIN'],
                'pull' => false,
            ],
        ], ['X-CSRF-Token' => $csrf], $cookie);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(1, $this->calls);
        $payload = $this->calls[0]['payload'];
        self::assertSame('docker', $payload['engine']);
        self::assertSame('frrouting/frr:10.0.1', $payload['image']);
        self::assertSame([
            'console_command' => '/usr/bin/vtysh',
            'cap_add' => ['NET_ADMIN'],
            'pull' => false,
        ], $payload['docker']);
    }

    public function testDockerStartRejectsAnUnsafeBlockOrMissingImage(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();

        foreach ([
            ['engine' => 'docker', 'docker' => ['cap_add' => ['SYS_MODULE']]],
            ['engine' => 'docker', 'docker' => ['cap_add' => ['SYS_PTRACE']]],
            ['engine' => 'docker', 'docker' => ['image' => 'x', 'pull' => 'yes']],
            ['engine' => 'docker'],
        ] as $payload) {
            $response = $this->request(
                $app,
                'POST',
                '/api/v2/nodes/start',
                ['id' => 'node1', 'name' => 'R1'] + $payload,
                ['X-CSRF-Token' => $csrf],
                $cookie,
            );
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        }
        self::assertSame([], $this->calls);
    }

    public function testStopWipeStatusAndConsole(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();

        $stop = $this->request($app, 'POST', '/api/v2/nodes/stop', ['id' => 'node1'], ['X-CSRF-Token' => $csrf], $cookie);
        self::assertSame(200, $stop->getStatusCode());

        $wipe = $this->request($app, 'POST', '/api/v2/nodes/wipe', ['id' => 'node1'], ['X-CSRF-Token' => $csrf], $cookie);
        self::assertSame(200, $wipe->getStatusCode());

        $status = $this->request($app, 'GET', '/api/v2/nodes/status?id=node1', [], [], $cookie);
        self::assertSame(200, $status->getStatusCode());
        self::assertSame('node1', $this->json($status)['data']['id']);

        $console = $this->request($app, 'POST', '/api/v2/nodes/console', ['node_id' => 'node1'], ['X-CSRF-Token' => $csrf], $cookie);
        self::assertSame(200, $console->getStatusCode());
        $consoleBody = $this->json($console)['data'];
        self::assertSame('telnet', $consoleBody['kind']);
        self::assertSame('token1', $consoleBody['token']);
        self::assertStringStartsWith('/ws/console?token=', $consoleBody['ws_path']);

        self::assertSame(
            ['/v1/node.stop', '/v1/node.wipe', '/v1/node.status?id=node1', '/v1/console.open'],
            array_column($this->calls, 'path'),
        );
        self::assertSame(['node_id' => 'node1'], $this->calls[3]['payload']);
    }

    public function testVncConsoleUsesProxyPathAndPassword(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession(
            static fn (): string => '{"ok":true,"result":{"kind":"vnc","token":"token1","expires_in":300,"port":5901,"password":"vncpass"}}'
        );

        $response = $this->request($app, 'POST', '/api/v2/nodes/console', ['node_id' => 'node1'], ['X-CSRF-Token' => $csrf], $cookie);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('vnc', $body['kind']);
        self::assertSame('/ws/vnc?token=token1', $body['ws_path']);
        self::assertSame('vncpass', $body['password']);
        self::assertSame('token1', $body['token']);
    }

    public function testTcpConsoleUsesTcpProxyPath(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession(
            static fn (): string => '{"ok":true,"result":{"kind":"telnet","token":"token1","expires_in":300,"port":39083}}'
        );

        $response = $this->request($app, 'POST', '/api/v2/nodes/console', ['node_id' => 'node1'], ['X-CSRF-Token' => $csrf], $cookie);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('/ws/tcp?token=token1', $body['ws_path']);
        self::assertSame(39083, $body['port']);
    }

    public function testAgentErrorIsPassedThrough(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();

        $response = $this->request($app, 'POST', '/api/v2/nodes/stop', ['id' => 'missing'], ['X-CSRF-Token' => $csrf], $cookie);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('node not found', $this->json($response)['message']);
    }

    public function testAgentClientSpeaksTheUnixSocketProtocol(): void
    {
        $secret = str_repeat('k', 32);
        file_put_contents($this->root . '/data/agent.secret', $secret . "\n");
        $socket = $this->root . '/data/agent.sock';
        $capture = $this->root . '/data/request.txt';

        $script = <<<'PHP'
[$script, $socket, $capture] = $argv;
@unlink($socket);
$server = stream_socket_server('unix://' . $socket, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr . "\n");
    exit(1);
}
$connection = stream_socket_accept($server, 10);
if ($connection === false) {
    exit(2);
}
$raw = '';
while (!str_contains($raw, "\r\n\r\n")) {
    $chunk = fread($connection, 1024);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $raw .= $chunk;
}
$length = 0;
if (preg_match('/Content-Length: ([0-9]+)/i', $raw, $matches) === 1) {
    $length = (int) $matches[1];
}
while (strlen(substr($raw, strpos($raw, "\r\n\r\n") + 4)) < $length) {
    $chunk = fread($connection, 1024);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $raw .= $chunk;
}
file_put_contents($capture, $raw);
$body = json_encode(['ok' => true, 'result' => ['status' => 'ok', 'version' => '0.1.0']]);
fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
fclose($connection);
fclose($server);
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, $socket, $capture],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            $deadline = microtime(true) + 5;
            while (!file_exists($socket) && microtime(true) < $deadline) {
                usleep(20000);
            }
            self::assertFileExists($socket, 'test agent did not start');

            $config = Config::forTests($this->root, $this->root . '/data');
            $client = new AgentClient($config);
            $result = $client->health();

            self::assertSame('ok', $result['status']);
            self::assertSame('0.1.0', $result['version']);

            $request = (string) file_get_contents($capture);
            self::assertStringStartsWith('GET /v1/health HTTP/1.1', $request);
            self::assertStringContainsString('Authorization: Bearer ' . $secret, $request);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }

    /**
     * @return array{SlimApp<\DI\Container>, string, string}
     */
    private function appWithSession(?\Closure $consoleResponse = null): array
    {
        $config = Config::forTests($this->root, $this->root . '/data');
        $client = new AgentClient($config, function (string $method, string $path, array $payload) use ($consoleResponse): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

            if (($payload['id'] ?? '') === 'missing') {
                return [404, '{"ok":false,"error":{"code":"not_found","message":"node not found"}}'];
            }
            if ($path === '/v1/node.start') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'id' => $payload['id'] ?? '',
                        'name' => $payload['name'] ?? '',
                        'state' => 'running',
                        'pid' => 4321,
                        'run_dir' => '/var/lib/teralab/run/node',
                        'console' => 'telnet',
                    ],
                ], JSON_THROW_ON_ERROR)];
            }
            if (str_starts_with($path, '/v1/node.status')) {
                return [200, '{"ok":true,"result":{"id":"node1","state":"running"}}'];
            }

            return match ($path) {
                '/v1/node.stop' => [200, '{"ok":true,"result":{"state":"stopped"}}'],
                '/v1/node.wipe' => [200, '{"ok":true,"result":{"state":"wiped"}}'],
                '/v1/console.open' => [200, $consoleResponse !== null
                    ? $consoleResponse()
                    : '{"ok":true,"result":{"kind":"telnet","token":"token1","expires_in":300}}'],
                default => [200, '{"ok":true,"result":{}}'],
            };
        });

        $app = (new App($config, $client))->create();
        $setup = $this->request($app, 'POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        $body = $this->json($setup);

        return [$app, $this->cookie($setup), (string) $body['csrf_token']];
    }

    /**
     * @param SlimApp<\DI\Container> $app
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(
        SlimApp $app,
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $cookie = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookie !== null && $cookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $cookie]);
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $app->handle($request);
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
