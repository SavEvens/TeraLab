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
use TeraLab\Api\Database\Database;
use TeraLab\Api\Tests\Support\SetupToken;

final class NodeControllerTest extends TestCase
{
    use SetupToken;

    private const PASSWORD = 'Teralab-Admin-2026!';

    private const USER_PASSWORD = 'Teralab-Viewer-2026!';

    private string $root;

    private string $adminId = '';

    /** @var list<array{method: string, path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-nodes-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->installSetupToken(Config::forTests($this->root, $this->root . '/data'));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testConsoleRequiresCsrf(): void
    {
        [$app, $cookie, , $lab] = $this->appWithSession();

        $response = $this->request($app, 'POST', '/api/v2/labs/' . $lab['id'] . '/nodes/1/console', [], [], $cookie);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testConsoleDerivesTheAgentNodeIdFromTheAuthorizedPair(): void
    {
        [$app, $cookie, $csrf, $lab] = $this->appWithSession();

        $response = $this->request(
            $app,
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/nodes/1/console',
            [],
            ['X-CSRF-Token' => $csrf],
            $cookie,
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response)['data'];
        self::assertSame('telnet', $body['kind']);
        self::assertSame('token1', $body['token']);
        self::assertSame('/ws/console', $body['ws_path']);
        self::assertSame('teralab.console.token1', $body['subprotocol']);
        self::assertStringNotContainsString('token', $body['ws_path']);
        self::assertStringNotContainsString('?', $body['ws_path']);

        self::assertCount(1, $this->calls);
        self::assertSame('/v1/console.open', $this->calls[0]['path']);
        self::assertSame(
            ['node_id' => $lab['id'] . '-1', 'user_id' => $this->adminId],
            $this->calls[0]['payload'],
        );
    }

    public function testConsoleDeniesAnotherUsersLabAndNeverCallsTheAgent(): void
    {
        [$app, , , $lab] = $this->appWithSession();
        $config = Config::forTests($this->root, $this->root . '/data');
        $pdo = (new Database($config))->pdo();
        $pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        )->execute([
            ':id' => 'other-user',
            ':username' => 'other-user',
            ':password_hash' => password_hash(self::USER_PASSWORD, PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':now' => time(),
        ]);

        $login = $this->request($app, 'POST', '/api/v2/auth/login', [
            'username' => 'other-user',
            'password' => self::USER_PASSWORD,
        ]);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $otherCookie = $this->cookie($login);
        $otherCsrf = (string) $this->json($login)['csrf_token'];

        $this->calls = [];
        $response = $this->request(
            $app,
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/nodes/1/console',
            [],
            ['X-CSRF-Token' => $otherCsrf],
            $otherCookie,
        );

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->calls);
    }

    public function testVncConsoleUsesProxyPathAndPassword(): void
    {
        [$app, $cookie, $csrf, $lab] = $this->appWithSession(
            static fn (): string => '{"ok":true,"result":{"kind":"vnc","token":"token1","expires_in":300,"port":5901,"password":"vncpass"}}'
        );

        $response = $this->request(
            $app,
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/nodes/1/console',
            [],
            ['X-CSRF-Token' => $csrf],
            $cookie,
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('vnc', $body['kind']);
        self::assertSame('/ws/vnc', $body['ws_path']);
        self::assertSame('teralab.console.token1', $body['subprotocol']);
        self::assertStringNotContainsString('token', $body['ws_path']);
        self::assertSame('vncpass', $body['password']);
        self::assertSame('token1', $body['token']);
    }

    public function testTcpConsoleUsesTcpProxyPath(): void
    {
        [$app, $cookie, $csrf, $lab] = $this->appWithSession(
            static fn (): string => '{"ok":true,"result":{"kind":"telnet","token":"token1","expires_in":300,"port":39083}}'
        );

        $response = $this->request(
            $app,
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/nodes/1/console',
            [],
            ['X-CSRF-Token' => $csrf],
            $cookie,
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('/ws/tcp', $body['ws_path']);
        self::assertSame('teralab.console.token1', $body['subprotocol']);
        self::assertStringNotContainsString('token', $body['ws_path']);
        self::assertSame(39083, $body['port']);
    }

    public function testUnknownLabNodeDoesNotCallTheAgent(): void
    {
        [$app, $cookie, $csrf, $lab] = $this->appWithSession();

        $response = $this->request(
            $app,
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/nodes/missing/console',
            [],
            ['X-CSRF-Token' => $csrf],
            $cookie,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testLabNodesReportStateAndReady(): void
    {
        [$app, $cookie, , $lab] = $this->appWithSession();

        $response = $this->request($app, 'GET', '/api/v2/labs/' . $lab['id'] . '/nodes', [], [], $cookie);

        self::assertSame(200, $response->getStatusCode());
        $node = $this->json($response)['data']['nodes'][0];
        self::assertSame('stopped', $node['state']);
        self::assertFalse($node['ready']);
        self::assertSame($lab['id'] . '-1', $node['agent_id']);
    }

    public function testNodeStatusIsLabScoped(): void
    {
        [$app, $cookie, , $lab] = $this->appWithSession();

        $response = $this->request($app, 'GET', '/api/v2/labs/' . $lab['id'] . '/nodes/1/status', [], [], $cookie);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('stopped', $this->json($response)['data']['state']);
        $calls = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => str_starts_with($call['path'], '/v1/node.status'),
        ));
        self::assertCount(1, $calls);
        self::assertStringContainsString('id=' . rawurlencode($lab['id'] . '-1'), $calls[0]['path']);
    }

    public function testRawNodeRoutesAreAbsent(): void
    {
        [$app, $cookie, $csrf] = $this->appWithSession();
        $this->calls = [];

        foreach ([
            ['POST', '/api/v2/nodes/start', ['id' => 'node1', 'name' => 'R1']],
            ['POST', '/api/v2/nodes/stop', ['id' => 'node1']],
            ['POST', '/api/v2/nodes/wipe', ['id' => 'node1']],
            ['GET', '/api/v2/nodes/status?id=node1', []],
            ['POST', '/api/v2/nodes/console', ['node_id' => 'node1']],
        ] as [$method, $path, $body]) {
            $response = $this->request($app, $method, $path, $body, ['X-CSRF-Token' => $csrf], $cookie);
            self::assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
        }
        self::assertSame([], $this->calls);
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
     * @return array{SlimApp<\DI\Container>, string, string, array<string, mixed>}
     */
    private function appWithSession(?\Closure $consoleResponse = null): array
    {
        $config = Config::forTests($this->root, $this->root . '/data');
        $client = new AgentClient($config, function (string $method, string $path, array $payload) use ($consoleResponse): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

            if (str_starts_with($path, '/v1/node.status')) {
                return [200, '{"ok":true,"result":{"id":"node","state":"stopped","ready":false}}'];
            }
            if ($path === '/v1/console.open') {
                return [200, $consoleResponse !== null
                    ? $consoleResponse()
                    : '{"ok":true,"result":{"kind":"telnet","token":"token1","expires_in":300}}'];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $app = (new App($config, $client))->create();
        $setup = $this->request($app, 'POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
            'setup_token' => $this->setupToken(),
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $setupBody = $this->json($setup);
        $this->adminId = (string) $setupBody['user']['id'];
        $cookie = $this->cookie($setup);
        $csrf = (string) $setupBody['csrf_token'];

        $created = $this->request($app, 'POST', '/api/v2/labs', [
            'name' => 'Console Lab',
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'interfaces' => [],
            ]],
        ], ['X-CSRF-Token' => $csrf], $cookie);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->calls = [];

        return [$app, $cookie, $csrf, $this->json($created)['data']];
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
