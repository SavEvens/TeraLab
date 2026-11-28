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
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\System\DemoSweeper;
use TeraLab\Api\System\HostMemory;
use TeraLab\Api\Tests\Support\SetupToken;
use TeraLab\Api\Tests\Support\StaticAssets;

final class DemoAdminApiTest extends TestCase
{
    use SetupToken;
    use StaticAssets;

    private const PASSWORD = 'Teralab-Admin-2026!';

    private const GUEST_PASSWORD = 'Teralab-Guest-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private string $adminCookie = '';
    private string $adminCsrf = '';
    private string $guestCookie = '';
    private string $guestCsrf = '';

    /** @var list<array<string, mixed>> */
    private array $agentNodes = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-demo-admin-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->installStaticAssets($this->root);
        $config = $this->config();
        $this->installSetupToken($config);
        $this->writeTemplate($config);
        $this->pdo = (new Database($config))->pdo();
        $this->app = (new App($config, $this->agent($config), null, $this->memory(4096)))->create();

        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
            'setup_token' => $this->setupToken(),
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $this->adminCookie = $this->cookie($setup);
        $this->adminCsrf = (string) $this->json($setup)['csrf_token'];

        $this->insertGuest('guest-demo', self::GUEST_PASSWORD);
        [$this->guestCookie, $this->guestCsrf] = $this->login('guest-demo', self::GUEST_PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAdminReadsStatusAndRunsAManualSweep(): void
    {
        $lab = $this->createGuestLab();
        $this->agentNodes = [[
            'id' => (string) $lab['id'] . '-1',
            'lab_id' => (string) $lab['id'],
            'state' => 'running',
            'started' => gmdate('c'),
            'last_activity' => gmdate('c'),
        ]];

        $status = $this->request('GET', '/api/v2/system/demo/status');
        self::assertSame(200, $status->getStatusCode(), (string) $status->getBody());
        $statusData = $this->json($status)['data'];
        self::assertSame([
            'last_sweep_at',
            'mem_available_mb',
            'guests_active',
            'demo_nodes_running',
            'guest_labs',
            'guest_lab_bytes',
            'guest_snapshots',
        ], array_keys($statusData));
        self::assertNull($statusData['last_sweep_at']);
        self::assertSame(4096, $statusData['mem_available_mb']);
        self::assertSame(1, $statusData['guests_active']);
        self::assertSame(1, $statusData['demo_nodes_running']);
        self::assertSame(1, $statusData['guest_labs']);
        self::assertGreaterThan(0, $statusData['guest_lab_bytes']);
        self::assertSame(0, $statusData['guest_snapshots']);

        $sweep = $this->request('POST', '/api/v2/system/demo/sweep');
        self::assertSame(200, $sweep->getStatusCode(), (string) $sweep->getBody());
        $summary = $this->json($sweep)['data'];
        self::assertSame([
            'guests_purged',
            'labs_wiped',
            'labs_failed',
            'nodes_reaped',
            'guest_files_removed',
            'snapshots_removed',
            'mem_available_mb',
        ], array_keys($summary));
        self::assertSame(0, $summary['guests_purged']);
        self::assertSame(4096, $summary['mem_available_mb']);

        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute([':key' => DemoSweeper::LAST_SWEEP_KEY]);
        self::assertNotFalse($statement->fetchColumn());

        $manual = $this->pdo->query(
            "SELECT details_json FROM audit_log WHERE action = 'demo.sweep' ORDER BY ts DESC, id DESC LIMIT 1"
        )->fetchColumn();
        $details = json_decode((string) $manual, true);
        self::assertIsArray($details);
        self::assertTrue($details['manual'] ?? false);

        // The sweeper's bookkeeping row must never reach the settings API.
        $settings = $this->request('GET', '/api/v2/system/settings');
        self::assertSame(200, $settings->getStatusCode());
        self::assertArrayNotHasKey(DemoSweeper::LAST_SWEEP_KEY, $this->json($settings)['data']);
        self::assertStringNotContainsString(DemoSweeper::LAST_SWEEP_KEY, (string) $settings->getBody());
    }

    public function testManualSweepRequiresCsrf(): void
    {
        $response = $this->request('POST', '/api/v2/system/demo/sweep', [], [], $this->adminCookie, null, '');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Invalid CSRF token.', (string) $this->json($response)['message']);
    }

    public function testNonAdminCannotReadStatusOrSweep(): void
    {
        $status = $this->request('GET', '/api/v2/system/demo/status', [], [], $this->guestCookie, null, '');
        self::assertSame(403, $status->getStatusCode(), (string) $status->getBody());

        $sweep = $this->request('POST', '/api/v2/system/demo/sweep', [], [], $this->guestCookie, null, $this->guestCsrf);
        self::assertSame(403, $sweep->getStatusCode(), (string) $sweep->getBody());
    }

    public function testGuestStartIsRefusedAtTheMemoryFloorAndAdminsAreExempt(): void
    {
        $lab = $this->createGuestLab();
        $config = $this->config();
        $low = (new App($config, $this->agent($config), null, $this->memory(1024)))->create();

        $refused = $this->request(
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/start',
            [],
            [],
            $this->guestCookie,
            null,
            $this->guestCsrf,
            $low,
        );
        self::assertSame(409, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertSame(
            'The demo is at capacity right now; try again shortly.',
            (string) $this->json($refused)['message'],
        );

        // The floor is demo policy, not authorisation: admins still start.
        $accepted = $this->request(
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/start',
            [],
            [],
            $this->adminCookie,
            null,
            $this->adminCsrf,
            $low,
        );
        self::assertSame(202, $accepted->getStatusCode(), (string) $accepted->getBody());
    }

    public function testNodeStartRunsADueSweepFirst(): void
    {
        $lab = $this->createGuestLab();
        $this->setLastSweep(1);

        $before = time();
        $response = $this->request(
            'POST',
            '/api/v2/labs/' . $lab['id'] . '/nodes/1/start',
            [],
            [],
            $this->guestCookie,
            null,
            $this->guestCsrf,
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $lastSweep = $this->lastSweepValue();
        self::assertIsString($lastSweep);
        self::assertNotSame('1', $lastSweep);
        self::assertGreaterThanOrEqual($before, (int) $lastSweep);
    }

    /**
     * @return array<string, mixed>
     */
    private function createGuestLab(): array
    {
        $response = $this->request(
            'POST',
            '/api/v2/labs',
            [
                'name' => 'Guest Demo Lab',
                'nodes' => [[
                    'id' => '1',
                    'name' => 'PC1',
                    'type' => 'vpcs',
                    'template' => 'vpcs',
                    'ram' => 256,
                    'interfaces' => [],
                ]],
            ],
            [],
            $this->guestCookie,
            null,
            $this->guestCsrf,
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function setLastSweep(int $timestamp): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([
            ':key' => DemoSweeper::LAST_SWEEP_KEY,
            ':value' => (string) $timestamp,
            ':updated_at' => $timestamp,
        ]);
    }

    private function lastSweepValue(): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute([':key' => DemoSweeper::LAST_SWEEP_KEY]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function config(): Config
    {
        return Config::forTests($this->root, $this->root . '/data');
    }

    private function memory(int $availableMb): HostMemory
    {
        $path = $this->root . '/meminfo-' . $availableMb;
        file_put_contents($path, 'MemAvailable: ' . ($availableMb * 1024) . " kB\n");

        return new HostMemory($path);
    }

    private function agent(Config $config): AgentClient
    {
        return new AgentClient(
            $config,
            function (string $method, string $path, array $payload): array {
                $result = match ($path) {
                    '/v1/system/status' => ['nodes' => $this->agentNodes],
                    '/v1/node.start' => [
                        'id' => (string) ($payload['id'] ?? 'node'),
                        'state' => 'running',
                    ],
                    '/v1/lab.ensure' => ['bridges' => []],
                    default => [],
                };

                return [200, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)];
            },
        );
    }

    private function insertGuest(string $id, string $password): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :expiration, :guest, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $id,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':expiration' => time() + 3600,
            ':guest' => 1,
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function login(string $username, string $password): array
    {
        $response = $this->request('POST', '/api/v2/auth/login', [
            'username' => $username,
            'password' => $password,
        ], [], '');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return [$this->cookie($response), (string) $this->json($response)['csrf_token']];
    }

    private function writeTemplate(Config $config): void
    {
        $directory = $config->templatesDir;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $path = dirname(__DIR__) . '/templates/builtin/vpcs.yaml';
        self::assertFileExists($path);
        file_put_contents($directory . '/vpcs.yaml', (string) file_get_contents($path));
    }

    public function testBackupDownloadIsAdminOnlyAndNotCached(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('the zip extension is required for backups');
        }

        $guest = $this->request('GET', '/api/v2/system/backup', [], [], $this->guestCookie, null, '');
        self::assertSame(403, $guest->getStatusCode());

        $admin = $this->request('GET', '/api/v2/system/backup');
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getBody());
        self::assertSame('application/zip', $admin->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $admin->getHeaderLine('Cache-Control'));
    }

    public function testAdminMaintenanceRunsAndIsNotCached(): void
    {
        $guest = $this->request('POST', '/api/v2/system/maintenance', [], [], $this->guestCookie, null, $this->guestCsrf);
        self::assertSame(403, $guest->getStatusCode());

        $response = $this->request('POST', '/api/v2/system/maintenance');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = $this->json($response)['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('sessions_deleted', $data);
        self::assertArrayHasKey('trash_deleted', $data);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param SlimApp<\DI\Container>|null $app
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $cookie = null,
        ?\Slim\Psr7\UploadedFile $file = null,
        ?string $csrf = null,
        ?SlimApp $app = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->adminCookie;
        $effectiveCsrf = $csrf ?? $this->adminCsrf;
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

        return ($app ?? $this->app)->handle($request);
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
