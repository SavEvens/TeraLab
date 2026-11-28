<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Tests\Support\SetupToken;

final class DockerImagesApiTest extends TestCase
{
    use SetupToken;

    private const PASSWORD = 'Teralab-Admin-2026!';
    private const VIEWER_PASSWORD = 'Teralab-Viewer-2026!';

    private string $root;

    /** @var list<array{method: string, path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-docker-images-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->installSetupToken(Config::forTests($this->root, $this->root . '/data'));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDockerImagesRequiresAuthentication(): void
    {
        ['app' => $app] = $this->appWithAgent($this->recordingTransport('{"ok":true,"result":{"images":[]}}'));

        $response = $this->request($app, 'GET', '/api/v2/docker/images');

        self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->calls, 'an unauthenticated request must not reach the agent');
    }

    public function testDockerImagesReturnsReferencesOnly(): void
    {
        $images = [
            [
                'reference' => 'frrouting/frr:v8.4.1',
                'id' => 'sha256:9f2d0c1a',
                'size' => '158MB',
                'created' => '2026-01-02T03:04:05Z',
                'path' => '/var/lib/docker/overlay2/secret',
            ],
            [
                'reference' => 'alpine:3.20',
                'id' => 'sha256:0a1b2c3d',
                'size' => '8MB',
                'created' => '2026-01-03T00:00:00Z',
            ],
        ];
        $body = json_encode(['ok' => true, 'result' => ['images' => $images]], JSON_THROW_ON_ERROR);
        ['app' => $app, 'cookie' => $cookie] = $this->appWithAgent($this->recordingTransport($body));

        $response = $this->request($app, 'GET', '/api/v2/docker/images', $cookie);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([
            ['reference' => 'frrouting/frr:v8.4.1'],
            ['reference' => 'alpine:3.20'],
        ], $this->json($response)['data']);
        self::assertStringNotContainsString('/var/lib/docker', (string) $response->getBody());
        self::assertSame(
            [['method' => 'GET', 'path' => '/v1/docker/images', 'payload' => []]],
            $this->calls,
        );
    }

    public function testDockerImagesTreatsAMissingImagesKeyAsEmpty(): void
    {
        ['app' => $app, 'cookie' => $cookie] = $this->appWithAgent(
            $this->recordingTransport('{"ok":true,"result":{}}'),
        );

        $response = $this->request($app, 'GET', '/api/v2/docker/images', $cookie);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->json($response)['data']);
    }

    public function testMalformedImageEntryMapsToBadGateway(): void
    {
        $body = '{"ok":true,"result":{"images":[{"id":"sha256:9f2d0c1a","size":"158MB","created":"2026-01-02T03:04:05Z"}]}}';
        ['app' => $app, 'cookie' => $cookie] = $this->appWithAgent($this->recordingTransport($body));

        $response = $this->request($app, 'GET', '/api/v2/docker/images', $cookie);

        self::assertSame(502, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'The Docker image list is unavailable: Docker is not available on the agent host.',
            $this->json($response)['message'],
        );
    }

    public function testUnavailableDockerEngineMapsToBadGateway(): void
    {
        ['app' => $app, 'cookie' => $cookie] = $this->appWithAgent(
            $this->recordingTransport(
                '{"ok":false,"error":{"code":"unavailable","message":"the Docker engine is not available"}}',
                503,
            ),
        );

        $response = $this->request($app, 'GET', '/api/v2/docker/images', $cookie);

        self::assertSame(502, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'The Docker image list is unavailable: Docker is not available on the agent host.',
            $this->json($response)['message'],
        );
    }

    public function testAgentTransportFailureMapsToBadGateway(): void
    {
        ['app' => $app, 'cookie' => $cookie] = $this->appWithAgent(
            function (string $method, string $path, array $payload): array {
                $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

                throw new HttpException(503, 'The TeraLab agent is not running.');
            },
        );

        $response = $this->request($app, 'GET', '/api/v2/docker/images', $cookie);

        self::assertSame(502, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'The Docker image list is unavailable: Docker is not available on the agent host.',
            $this->json($response)['message'],
        );
    }

    public function testNonAdminCannotReadDockerImages(): void
    {
        $body = json_encode([
            'ok' => true,
            'result' => [
                'images' => [[
                    'reference' => 'frrouting/frr:v8.4.1',
                    'id' => 'sha256:9f2d0c1a',
                    'size' => '158MB',
                    'created' => '2026-01-02T03:04:05Z',
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        ['app' => $app, 'pdo' => $pdo] = $this->appWithAgent($this->recordingTransport($body));
        $this->createUser($pdo, 'viewer', self::VIEWER_PASSWORD, 'user');

        $login = $this->request($app, 'POST', '/api/v2/auth/login', null, [
            'username' => 'viewer',
            'password' => self::VIEWER_PASSWORD,
        ]);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $this->calls = [];

        $response = $this->request($app, 'GET', '/api/v2/docker/images', $this->cookie($login));

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->calls);
    }

    public function testAgentClientReadsTheImagesListAndToleratesAMissingOne(): void
    {
        $config = Config::forTests($this->root, $this->root . '/data');
        $images = [[
            'reference' => 'frrouting/frr:v8.4.1',
            'id' => 'sha256:9f2d0c1a',
            'size' => '158MB',
            'created' => '2026-01-02T03:04:05Z',
        ]];
        $calls = [];
        $client = new AgentClient($config, function (string $method, string $path, array $payload) use (&$calls, $images): array {
            $calls[] = ['method' => $method, 'path' => $path];

            return [200, json_encode(['ok' => true, 'result' => ['images' => $images]], JSON_THROW_ON_ERROR)];
        });

        self::assertSame($images, $client->dockerImages());
        self::assertSame([['method' => 'GET', 'path' => '/v1/docker/images']], $calls);

        $empty = new AgentClient(
            $config,
            static function (string $method, string $path, array $payload): array {
                return [200, '{"ok":true,"result":{}}'];
            },
        );

        self::assertSame([], $empty->dockerImages());
    }

    public function testAgentClientRejectsImagesWithoutAReference(): void
    {
        $config = Config::forTests($this->root, $this->root . '/data');

        foreach ([
            [['id' => 'sha256:9f2d0c1a', 'size' => '158MB', 'created' => '2026-01-02T03:04:05Z']],
            [['reference' => '', 'id' => 'sha256:9f2d0c1a', 'size' => '158MB', 'created' => '2026-01-02T03:04:05Z']],
            [['reference' => '   ']],
            [['reference' => 42]],
        ] as $images) {
            $body = json_encode(['ok' => true, 'result' => ['images' => $images]], JSON_THROW_ON_ERROR);
            $client = new AgentClient(
                $config,
                static function (string $method, string $path, array $payload) use ($body): array {
                    return [200, $body];
                },
            );

            try {
                $client->dockerImages();
                self::fail('expected a Docker image entry without a reference to be rejected');
            } catch (HttpException $exception) {
                self::assertSame(502, $exception->status);
                self::assertSame(
                    'The agent returned a Docker image entry without a reference.',
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * @return Closure(string, string, array<string, mixed>): array{int, string}
     */
    private function recordingTransport(string $body, int $status = 200): Closure
    {
        return function (string $method, string $path, array $payload) use ($body, $status): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

            return [$status, $body];
        };
    }

    /**
     * @param Closure(string, string, array<string, mixed>): array{int, string} $transport
     * @return array{app: SlimApp<\DI\Container>, cookie: string, pdo: PDO}
     */
    private function appWithAgent(Closure $transport): array
    {
        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, $transport);
        $app = (new App($config, $agent))->create();
        $setup = $this->request($app, 'POST', '/api/v2/setup', null, [
            'username' => 'admin',
            'password' => self::PASSWORD,
            'setup_token' => $this->setupToken(),
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());

        return [
            'app' => $app,
            'cookie' => $this->cookie($setup),
            'pdo' => (new Database($config))->pdo(),
        ];
    }

    private function createUser(PDO $pdo, string $username, string $password, string $role): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':username' => $username,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => $role,
            ':now' => time(),
        ]);
    }

    /**
     * @param SlimApp<\DI\Container> $app
     * @param array<string, mixed> $body
     */
    private function request(
        SlimApp $app,
        string $method,
        string $path,
        ?string $cookie = null,
        array $body = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
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
