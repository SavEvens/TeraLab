<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Auth\TokenService;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\System\SettingsService;
use TeraLab\Api\Tests\Support\SetupToken;

final class DemoSettingsTest extends TestCase
{
    use SetupToken;

    private const PASSWORD = 'Teralab-Admin-2026!';

    private const OTHER_PASSWORD = 'Teralab-Editor-2026!';

    private string $root;
    private Config $config;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private SettingsService $settings;
    private string $adminCookie = '';
    private string $adminCsrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-demo-settings-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->installSetupToken($this->config);
        $agent = new AgentClient(
            $this->config,
            static fn (string $method, string $path, array $payload): array => [200, '{"ok":true,"result":{}}']
        );
        $this->app = (new App($this->config, $agent))->create();
        $this->pdo = (new Database($this->config))->pdo();
        $this->settings = new SettingsService($this->pdo);

        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
            'setup_token' => $this->setupToken(),
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $this->adminCookie = $this->cookie($setup);
        $this->adminCsrf = (string) $this->json($setup)['csrf_token'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDemoAndTurnstileDefaults(): void
    {
        $all = $this->settings->all();

        self::assertSame(0, $all['demo_enabled']);
        self::assertSame('login', $all['demo_landing']);
        self::assertSame(50, $all['demo_max_guests']);
        self::assertSame(48, $all['demo_guest_ttl_hours']);
        self::assertSame(6, $all['demo_guest_idle_hours']);
        self::assertSame(5, $all['demo_max_nodes_per_lab']);
        self::assertSame(3, $all['demo_max_labs_per_guest']);
        self::assertSame(20971520, $all['demo_max_lab_bytes_per_guest']);
        self::assertSame(3, $all['demo_max_snapshots_per_lab']);
        self::assertSame(5, $all['demo_max_tokens_per_user']);
        self::assertSame('vpcs', $all['demo_allowed_templates']);
        self::assertSame(1024, $all['demo_max_ram_per_node']);
        self::assertSame(2048, $all['demo_memory_floor_mb']);
        self::assertSame(120, $all['demo_idle_wipe_minutes']);
        self::assertSame(300, $all['demo_sweep_interval']);
        self::assertSame(30, $all['retention_login_attempts_days']);
        self::assertSame(90, $all['retention_audit_log_days']);
        self::assertSame(30, $all['retention_jobs_days']);
        self::assertSame(1000, $all['retention_jobs_max_rows']);
        self::assertSame(3600, $all['maintenance_interval_seconds']);
        self::assertSame(2147483648, $all['backup_max_archive_bytes']);
        self::assertSame(10000, $all['backup_max_entries']);
        self::assertSame(4294967296, $all['backup_max_uncompressed_bytes']);
        self::assertSame(1073741824, $all['backup_max_database_bytes']);
        self::assertSame(0, $all['turnstile_enabled']);
        self::assertSame('3x00000000000000000000FF', $all['turnstile_site_key']);
        self::assertSame('1x0000000000000000000000000000000AA', $all['turnstile_secret_key']);
        self::assertSame(0, $all['legacy_v1_enabled']);

        // Existing keys keep their defaults, types, and helper behaviour.
        self::assertSame('UTC', $all['timezone']);
        self::assertSame(3600, $all['session_ttl']);
        self::assertSame(2147483648, $all['max_upload_bytes']);
        self::assertSame('telnet', $all['console_default']);
        self::assertSame(3600, $this->settings->int('session_ttl', 1));
        self::assertSame('UTC', $this->settings->string('timezone', 'Europe/Paris'));
    }

    #[DataProvider('integerBounds')]
    public function testIntegerSettingBounds(string $key, int $min, int $max): void
    {
        if ($key === 'turnstile_enabled') {
            $this->settings->update([
                'turnstile_site_key' => 'site-key-for-tests',
                'turnstile_secret_key' => 'secret-key-for-tests',
            ]);
        }
        $this->settings->update([$key => $min]);
        self::assertSame($min, $this->settings->int($key, -999));

        $this->settings->update([$key => $max]);
        self::assertSame($max, $this->settings->int($key, -999));

        $this->assertRejected($key, $min - 1, $key);
        $this->assertRejected($key, $max + 1, $key);

        // A rejected value must not overwrite the stored one.
        self::assertSame($max, $this->settings->int($key, -999));
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int}>
     */
    public static function integerBounds(): iterable
    {
        yield 'demo_enabled' => ['demo_enabled', 0, 1];
        yield 'demo_max_guests' => ['demo_max_guests', 1, 1000];
        yield 'demo_guest_ttl_hours' => ['demo_guest_ttl_hours', 1, 720];
        yield 'demo_guest_idle_hours' => ['demo_guest_idle_hours', 1, 72];
        yield 'demo_max_nodes_per_lab' => ['demo_max_nodes_per_lab', 1, 16];
        yield 'demo_max_labs_per_guest' => ['demo_max_labs_per_guest', 1, 20];
        yield 'demo_max_lab_bytes_per_guest' => ['demo_max_lab_bytes_per_guest', 1048576, 268435456];
        yield 'demo_max_snapshots_per_lab' => ['demo_max_snapshots_per_lab', 1, 20];
        yield 'demo_max_tokens_per_user' => ['demo_max_tokens_per_user', 1, 50];
        yield 'demo_max_ram_per_node' => ['demo_max_ram_per_node', 64, 4096];
        yield 'demo_memory_floor_mb' => ['demo_memory_floor_mb', 256, 65536];
        yield 'demo_idle_wipe_minutes' => ['demo_idle_wipe_minutes', 15, 1440];
        yield 'demo_sweep_interval' => ['demo_sweep_interval', 60, 3600];
        yield 'retention_login_attempts_days' => ['retention_login_attempts_days', 1, 3650];
        yield 'retention_audit_log_days' => ['retention_audit_log_days', 1, 3650];
        yield 'retention_jobs_days' => ['retention_jobs_days', 1, 3650];
        yield 'retention_jobs_max_rows' => ['retention_jobs_max_rows', 100, 1000000];
        yield 'maintenance_interval_seconds' => ['maintenance_interval_seconds', 60, 86400];
        yield 'backup_max_archive_bytes' => ['backup_max_archive_bytes', 1048576, 10737418240];
        yield 'backup_max_entries' => ['backup_max_entries', 1, 1000000];
        yield 'backup_max_uncompressed_bytes' => ['backup_max_uncompressed_bytes', 1048576, 42949672960];
        yield 'backup_max_database_bytes' => ['backup_max_database_bytes', 1048576, 10737418240];
        yield 'turnstile_enabled' => ['turnstile_enabled', 0, 1];
        yield 'legacy_v1_enabled' => ['legacy_v1_enabled', 0, 1];
        // Pre-existing keys must keep their bounds too.
        yield 'session_ttl' => ['session_ttl', 300, 86400];
        yield 'max_upload_bytes' => ['max_upload_bytes', 1048576, 10737418240];
    }

    public function testBooleanSettingsRejectOtherValues(): void
    {
        // The Turnstile switch cannot be enabled with Cloudflare's public test
        // keys in production; give it real-looking keys before toggling it.
        $this->settings->update([
            'turnstile_site_key' => 'site-key-for-tests',
            'turnstile_secret_key' => 'secret-key-for-tests',
        ]);
        foreach (['demo_enabled', 'turnstile_enabled', 'legacy_v1_enabled'] as $key) {
            $values = $this->settings->update([$key => 1]);
            self::assertSame(1, $values[$key]);
            $values = $this->settings->update([$key => true]);
            self::assertSame(1, $values[$key]);
            $values = $this->settings->update([$key => '0']);
            self::assertSame(0, $values[$key]);

            foreach ([2, -1, 'yes', 'true', null, 1.5] as $bad) {
                $this->assertRejected($key, $bad, $key . ' must be 0 or 1');
            }
        }
    }

    public function testLandingEnum(): void
    {
        $this->settings->update(['demo_landing' => 'demo']);
        self::assertSame('demo', $this->settings->string('demo_landing', 'login'));
        $this->settings->update(['demo_landing' => 'login']);
        self::assertSame('login', $this->settings->string('demo_landing', 'demo'));

        $this->assertRejected('demo_landing', 'splash', 'demo_landing must be login or demo');
        $this->assertRejected('demo_landing', '', 'demo_landing must be login or demo');
        $this->assertRejected('demo_landing', 1, 'demo_landing must be login or demo');
    }

    public function testCsvAllowlistNormalisesEntries(): void
    {
        $this->settings->update(['demo_allowed_templates' => ' vpcs , iosv , vpcs ']);
        self::assertSame('vpcs,iosv', $this->settings->string('demo_allowed_templates', ''));

        // Unknown but well-shaped ids are accepted; the guard resolves them.
        $this->settings->update(['demo_allowed_templates' => 'not-a-real-template_1.0']);
        self::assertSame(
            'not-a-real-template_1.0',
            $this->settings->string('demo_allowed_templates', '')
        );

        // Trailing separators and whitespace-only entries are dropped.
        $this->settings->update(['demo_allowed_templates' => ' , ']);
        self::assertSame('', $this->settings->string('demo_allowed_templates', 'vpcs'));
    }

    public function testCsvAllowlistLimits(): void
    {
        $accepted = [];
        for ($index = 0; $index < 32; $index++) {
            $accepted[] = 'tpl' . $index;
        }
        $this->settings->update(['demo_allowed_templates' => implode(',', $accepted)]);
        self::assertSame(implode(',', $accepted), $this->settings->string('demo_allowed_templates', ''));

        $rejected = $accepted;
        $rejected[] = 'tpl32';
        $this->assertRejected('demo_allowed_templates', implode(',', $rejected), 'at most 32');

        foreach (['vpcs;iosv', '-vpcs', 'has space', "\u{00fc}mlaut", str_repeat('a', 65)] as $bad) {
            $this->assertRejected('demo_allowed_templates', $bad, 'invalid template id');
        }
        $this->assertRejected('demo_allowed_templates', 42, 'comma-separated');
    }

    public function testTurnstileKeysAreValidated(): void
    {
        $longest = str_repeat('a', 128);
        $this->settings->update([
            'turnstile_site_key' => $longest,
            'turnstile_secret_key' => $longest,
        ]);
        self::assertSame(128, strlen($this->settings->string('turnstile_site_key', '')));
        self::assertSame(128, strlen($this->settings->string('turnstile_secret_key', '')));

        $this->assertRejected('turnstile_site_key', str_repeat('a', 129), '0-128 printable');
        $this->assertRejected('turnstile_secret_key', "bad\x00key", '0-128 printable');
        $this->assertRejected('turnstile_secret_key', 123, '0-128 printable');
    }

    public function testTurnstileKeysCanBeCleared(): void
    {
        $values = $this->settings->update([
            'turnstile_site_key' => '',
            'turnstile_secret_key' => '',
        ]);
        self::assertSame('', $values['turnstile_site_key']);
        self::assertSame('', $values['turnstile_secret_key']);

        $reloaded = new SettingsService($this->pdo);
        self::assertSame('', $reloaded->string('turnstile_site_key', 'fallback'));
        self::assertSame('', $reloaded->string('turnstile_secret_key', 'fallback'));

        // The shipped testing keys stay the defaults until they are edited.
        self::assertSame('3x00000000000000000000FF', SettingsService::DEFAULTS['turnstile_site_key']);
        self::assertSame(
            '1x0000000000000000000000000000000AA',
            SettingsService::DEFAULTS['turnstile_secret_key']
        );
    }

    public function testProductionRejectsShippedTurnstileTestKeys(): void
    {
        $production = new SettingsService($this->pdo);

        try {
            $production->update(['turnstile_enabled' => 1]);
            self::fail('Turnstile was enabled with the shipped test secret in production.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('turnstile_secret_key', $exception->getMessage());
        }
        self::assertSame(0, $production->int('turnstile_enabled', 0));

        // A real key pair may be enabled.
        $production->update([
            'turnstile_site_key' => 'real-site-key',
            'turnstile_secret_key' => 'real-secret-key',
        ]);
        self::assertSame(1, $production->update(['turnstile_enabled' => 1])['turnstile_enabled']);

        // Swapping back to a test key while enabled is refused.
        foreach ([
            'turnstile_secret_key' => '1x0000000000000000000000000000000AA',
            'turnstile_site_key' => '3x00000000000000000000FF',
        ] as $key => $value) {
            try {
                $production->update([$key => $value]);
                self::fail($key . ' accepted a Cloudflare test key in production.');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
        self::assertSame('real-secret-key', $production->string('turnstile_secret_key', ''));

        // The explicit test escape hatch is environment-gated, not implicit.
        putenv('TERALAB_ALLOW_TURNSTILE_TEST_KEYS=1');
        try {
            $production->update([
                'turnstile_secret_key' => '1x0000000000000000000000000000000AA',
            ]);
            self::assertSame(
                '1x0000000000000000000000000000000AA',
                $production->string('turnstile_secret_key', '')
            );
        } finally {
            putenv('TERALAB_ALLOW_TURNSTILE_TEST_KEYS');
        }
    }

    public function testNonProductionAllowsShippedTurnstileTestKeys(): void
    {
        $test = new SettingsService($this->pdo, 'test');
        $values = $test->update(['turnstile_enabled' => 1]);

        self::assertSame(1, $values['turnstile_enabled']);
    }

    public function testTurnstileHostnameIsValidated(): void
    {
        $values = $this->settings->update(['turnstile_hostname' => 'Demo.Example.Test']);
        self::assertSame('demo.example.test', $values['turnstile_hostname']);
        self::assertSame('', $this->settings->update(['turnstile_hostname' => ''])['turnstile_hostname']);

        foreach (['http://demo.example.test', 'demo.example.test/path', 'has space.test', str_repeat('a', 254)] as $bad) {
            try {
                $this->settings->update(['turnstile_hostname' => $bad]);
                self::fail('turnstile_hostname accepted ' . $bad);
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }

    public function testHealthFlagsShippedTurnstileTestKeysWithoutLeakingTheSecret(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([':key' => 'turnstile_enabled', ':value' => '1', ':updated_at' => time()]);

        $response = $this->request('GET', '/api/v2/health');
        self::assertSame(200, $response->getStatusCode());
        $turnstile = $this->json($response)['turnstile'];
        self::assertIsArray($turnstile);
        self::assertTrue($turnstile['enabled']);
        self::assertTrue($turnstile['test_keys']);
        self::assertArrayNotHasKey('secret_key', $turnstile);
        self::assertStringNotContainsString(
            '1x0000000000000000000000000000000AA',
            (string) $response->getBody()
        );
    }

    public function testSettingsPersistAndAreCasted(): void
    {
        $this->settings->update([
            'demo_enabled' => 1,
            'demo_landing' => 'demo',
            'demo_max_guests' => 77,
            'demo_allowed_templates' => 'vpcs, iosv',
        ]);

        $reloaded = new SettingsService($this->pdo);
        self::assertSame(1, $reloaded->int('demo_enabled', 0));
        self::assertSame('demo', $reloaded->string('demo_landing', 'login'));
        self::assertSame(77, $reloaded->int('demo_max_guests', 0));
        self::assertSame('vpcs,iosv', $reloaded->string('demo_allowed_templates', ''));
    }

    public function testAdminSeesEverySettingIncludingTheSecret(): void
    {
        $response = $this->request('GET', '/api/v2/system/settings');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        self::assertSame('1x0000000000000000000000000000000AA', $data['turnstile_secret_key']);
        self::assertSame('3x00000000000000000000FF', $data['turnstile_site_key']);
        self::assertSame(0, $data['demo_enabled']);
        self::assertSame(3600, $data['session_ttl']);
    }

    public function testNonAdminCannotSeeTheTurnstileSecret(): void
    {
        $this->createUser('editor-demo', 'editor');
        [$cookie, $csrf] = $this->login('editor-demo', self::OTHER_PASSWORD);

        $response = $this->request('GET', '/api/v2/system/settings', [], [], $cookie);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        self::assertArrayNotHasKey('turnstile_secret_key', $data);
        self::assertArrayHasKey('turnstile_site_key', $data);
        self::assertSame(3600, $data['session_ttl']);
        self::assertSame(0, $data['demo_enabled']);
        self::assertStringNotContainsString(
            '1x0000000000000000000000000000000AA',
            (string) $response->getBody()
        );

        // PUT stays admin-only, with or without a CSRF token.
        $byEditor = $this->request('PUT', '/api/v2/system/settings', ['demo_enabled' => 1], [], $cookie, $csrf);
        self::assertSame(403, $byEditor->getStatusCode());
        $noCsrf = $this->request('PUT', '/api/v2/system/settings', ['demo_enabled' => 1], [], $this->adminCookie, '');
        self::assertSame(403, $noCsrf->getStatusCode());
        $byAdmin = $this->request('PUT', '/api/v2/system/settings', ['demo_enabled' => 1]);
        self::assertSame(200, $byAdmin->getStatusCode(), (string) $byAdmin->getBody());
        self::assertSame(1, $this->settings->int('demo_enabled', 0));
    }

    public function testResponseRejectsUnknownSettingsAndEmptyUpdates(): void
    {
        $unknown = $this->request('PUT', '/api/v2/system/settings', ['demo_unknown' => 1]);
        self::assertSame(422, $unknown->getStatusCode());

        $this->assertRejected('demo_guest_ttl_hours', 0, 'demo_guest_ttl_hours');
        $http = $this->request('PUT', '/api/v2/system/settings', ['demo_guest_ttl_hours' => 0]);
        self::assertSame(422, $http->getStatusCode());
        self::assertStringContainsString('demo_guest_ttl_hours', (string) $http->getBody());
    }

    public function testPublicUserShapeForRegularAccounts(): void
    {
        $login = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ], [], '');
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $user = $this->json($login)['user'];
        self::assertIsArray($user);
        self::assertSame(-1, $user['expiration']);
        self::assertFalse($user['guest']);

        $me = $this->request('GET', '/api/v2/auth/me');
        self::assertSame(200, $me->getStatusCode(), (string) $me->getBody());
        $user = $this->json($me)['user'];
        self::assertIsArray($user);
        self::assertSame(-1, $user['expiration']);
        self::assertFalse($user['guest']);
    }

    public function testPublicUserShapeForGuests(): void
    {
        $expiration = time() + 3600;
        $this->insertUser('guest-one', 'guest-one', 1, $expiration);

        $login = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'guest-one',
            'password' => self::OTHER_PASSWORD,
        ], [], '');
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $user = $this->json($login)['user'];
        self::assertIsArray($user);
        self::assertTrue($user['guest']);
        self::assertSame($expiration, $user['expiration']);

        $cookie = $this->cookie($login);
        $me = $this->request('GET', '/api/v2/auth/me', [], [], $cookie);
        self::assertSame(200, $me->getStatusCode(), (string) $me->getBody());
        $user = $this->json($me)['user'];
        self::assertIsArray($user);
        self::assertTrue($user['guest']);
        self::assertSame($expiration, $user['expiration']);

        // Expired guests cannot log in at all.
        $this->pdo->prepare('UPDATE users SET expiration = :expiration WHERE id = :id')->execute([
            ':expiration' => time() - 10,
            ':id' => 'guest-one',
        ]);
        $expired = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'guest-one',
            'password' => self::OTHER_PASSWORD,
        ], [], '');
        self::assertSame(403, $expired->getStatusCode());
    }

    public function testGuestBearerTokenShapeAndAccountExpiry(): void
    {
        $expiration = time() + 3600;
        $this->insertUser('guest-token', 'guest-token', 1, $expiration);
        [$cookie, $csrf] = $this->login('guest-token', self::OTHER_PASSWORD);

        $created = $this->request('POST', '/api/v2/system/tokens', [
            'name' => 'guest-token',
            'scopes' => ['read'],
        ], [], $cookie, $csrf);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $token = (string) $this->json($created)['data']['token'];

        $context = (new TokenService($this->pdo))->authenticate($token);
        self::assertIsArray($context);
        self::assertTrue($context['user']['guest']);
        self::assertSame($expiration, $context['user']['expiration']);

        $before = $this->request('GET', '/api/v2/labs', [], ['Authorization' => 'Bearer ' . $token], '');
        self::assertSame(200, $before->getStatusCode(), (string) $before->getBody());

        // Expiring the account must also retire its bearer tokens.
        $this->pdo->prepare('UPDATE users SET expiration = :expiration WHERE id = :id')->execute([
            ':expiration' => time() - 10,
            ':id' => 'guest-token',
        ]);
        $expired = $this->request(
            'GET',
            '/api/v2/labs',
            [],
            ['Authorization' => 'Bearer ' . $token],
            ''
        );
        self::assertSame(403, $expired->getStatusCode(), (string) $expired->getBody());
        self::assertStringContainsString('This account has expired.', (string) $expired->getBody());

        // The token itself is still valid; the account is what rejected it.
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM api_tokens WHERE token_hash = :hash');
        $statement->execute([':hash' => hash('sha256', $token)]);
        $stored = (int) $statement->fetchColumn();
        $statement->closeCursor();
        self::assertSame(1, $stored);

        // Regular accounts keep the same shape with no guest flag or expiry.
        $adminToken = $this->request('POST', '/api/v2/system/tokens', [
            'name' => 'admin-token',
            'scopes' => ['read'],
        ]);
        self::assertSame(201, $adminToken->getStatusCode(), (string) $adminToken->getBody());
        $adminContext = (new TokenService($this->pdo))->authenticate(
            (string) $this->json($adminToken)['data']['token']
        );
        self::assertIsArray($adminContext);
        self::assertFalse($adminContext['user']['guest']);
        self::assertSame(-1, $adminContext['user']['expiration']);
    }

    public function testDemoPolicyGuestAndExpiryRules(): void
    {
        self::assertTrue(DemoPolicy::isGuest(['guest' => 1]));
        self::assertTrue(DemoPolicy::isGuest(['guest' => '1']));
        self::assertTrue(DemoPolicy::isGuest(['guest' => true]));
        self::assertTrue(DemoPolicy::isGuest(['guest' => ' true ']));
        self::assertFalse(DemoPolicy::isGuest(['guest' => 0]));
        self::assertFalse(DemoPolicy::isGuest(['guest' => '0']));
        self::assertFalse(DemoPolicy::isGuest(['guest' => 2]));
        self::assertFalse(DemoPolicy::isGuest(['guest' => null]));
        self::assertFalse(DemoPolicy::isGuest([]));

        $now = time();
        self::assertTrue(DemoPolicy::isExpired(['expiration' => $now - 1]));
        self::assertTrue(DemoPolicy::isExpired(['expiration' => 0]));
        self::assertTrue(DemoPolicy::isExpired(['expiration' => (string) ($now - 1)]));
        self::assertFalse(DemoPolicy::isExpired(['expiration' => $now + 60]));
        self::assertFalse(DemoPolicy::isExpired(['expiration' => -1]));
        self::assertFalse(DemoPolicy::isExpired(['expiration' => 'never']));
        self::assertFalse(DemoPolicy::isExpired([]));

        // A non-guest with a future expiry is neither a guest nor expired.
        $regular = ['guest' => 0, 'expiration' => $now + 60];
        self::assertFalse(DemoPolicy::isGuest($regular));
        self::assertFalse(DemoPolicy::isExpired($regular));
    }

    public function testMigrationAddsGuestColumnToFreshDatabases(): void
    {
        $guest = null;
        foreach ($this->pdo->query('PRAGMA table_info(users)')->fetchAll() as $column) {
            if (is_array($column) && ($column['name'] ?? '') === 'guest') {
                $guest = $column;
            }
        }
        self::assertIsArray($guest);
        self::assertSame('INTEGER', (string) $guest['type']);
        self::assertSame(1, (int) $guest['notnull']);
        self::assertSame('0', (string) $guest['dflt_value']);

        $this->insertUser('fresh-user', 'fresh-user', 0, -1);
        $statement = $this->pdo->prepare('SELECT guest FROM users WHERE id = :id');
        $statement->execute([':id' => 'fresh-user']);
        self::assertSame(0, (int) $statement->fetchColumn());

        $applied = $this->pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = '009_guest_users.sql'"
        )->fetchColumn();
        self::assertSame(1, (int) $applied);
    }

    public function testMigrationBackfillsGuestOnUpgradedDatabases(): void
    {
        $pdo = new PDO('sqlite:' . $this->root . '/upgrade.sdb', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Simulate a v1.5 database: apply 001-008 only, then add a user.
        $partial = $this->root . '/migrations-partial';
        mkdir($partial, 0770, true);
        foreach (glob($this->config->migrationsDir() . '/*') ?: [] as $file) {
            $name = basename($file);
            if ($name < '009' && !symlink($file, $partial . '/' . $name)) {
                self::fail('Cannot link migration ' . $name . '.');
            }
        }
        (new Migrator($pdo, $partial))->migrate();

        $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        self::assertNotContains('guest', $columns);

        $insert = $pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :created_at, :updated_at)'
        );
        $insert->execute([
            ':id' => 'legacy-user',
            ':username' => 'legacy-user',
            ':password_hash' => 'unused',
            ':role' => 'user',
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);

        // Upgrade, then re-run to prove the migrator is idempotent.
        (new Migrator($pdo, $this->config->migrationsDir()))->migrate();
        (new Migrator($pdo, $this->config->migrationsDir()))->migrate();

        $statement = $pdo->prepare('SELECT guest, expiration FROM users WHERE id = :id');
        $statement->execute([':id' => 'legacy-user']);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['guest']);
        self::assertSame(-1, (int) $row['expiration']);

        $indexes = array_column($pdo->query('PRAGMA index_list(users)')->fetchAll(), 'name');
        self::assertContains('users_guest', $indexes);
    }

    public function testTokenCountCapAndGuestLowestCap(): void
    {
        $this->settings->update(['demo_max_tokens_per_user' => 2]);
        self::assertSame(201, $this->request('POST', '/api/v2/system/tokens', ['name' => 'one'])->getStatusCode());
        self::assertSame(201, $this->request('POST', '/api/v2/system/tokens', ['name' => 'two'])->getStatusCode());
        $blocked = $this->request('POST', '/api/v2/system/tokens', ['name' => 'three']);
        self::assertSame(422, $blocked->getStatusCode());
        self::assertStringContainsString('At most 2 API tokens', (string) $blocked->getBody());

        $expiration = time() + 3600;
        $this->insertUser('guest-token-cap', 'guest-token-cap', 1, $expiration);
        [$cookie, $csrf] = $this->login('guest-token-cap', self::OTHER_PASSWORD);
        $first = $this->request('POST', '/api/v2/system/tokens', ['name' => 'guest-one'], [], $cookie, $csrf);
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $second = $this->request('POST', '/api/v2/system/tokens', ['name' => 'guest-two'], [], $cookie, $csrf);
        self::assertSame(422, $second->getStatusCode());
        self::assertSame(
            'The demo allows one API token per guest; revoke it before creating another.',
            $this->json($second)['message'],
        );
    }

    public function testTokenCreationIsRateLimitedPerUserAndAddress(): void
    {
        $this->settings->update(['demo_max_tokens_per_user' => 50]);
        for ($index = 1; $index <= RateLimiter::TOKEN_MAX_PER_WINDOW; $index++) {
            $response = $this->request('POST', '/api/v2/system/tokens', ['name' => 'token-' . $index]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        }
        $blocked = $this->request('POST', '/api/v2/system/tokens', ['name' => 'token-over']);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(RateLimiter::TOKEN_LIMIT_MESSAGE, $this->json($blocked)['message']);
    }

    /**
     * @param mixed $value
     */
    private function assertRejected(string $key, mixed $value, string $message): void
    {
        try {
            $this->settings->update([$key => $value]);
            self::fail($key . ' accepted an invalid value.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function createUser(string $username, string $role): void
    {
        $response = $this->request('POST', '/api/v2/users', [
            'username' => $username,
            'role' => $role,
            'password' => self::OTHER_PASSWORD,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function insertUser(string $id, string $username, int $guest, int $expiration): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :expiration, :guest, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $username,
            ':password_hash' => password_hash(self::OTHER_PASSWORD, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 3,
                'threads' => 2,
            ]),
            ':role' => 'user',
            ':expiration' => $expiration,
            ':guest' => $guest,
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
        ?string $csrf = null,
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
