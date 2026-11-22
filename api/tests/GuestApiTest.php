<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\App;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Auth\TurnstileVerifier;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\System\SettingsService;

final class GuestApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private const REMOTE_IP = '127.0.0.1';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private SettingsService $settings;
    /** @var list<array<string, string>> */
    private array $turnstileCalls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-guest-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $config = Config::forTests($this->root, $this->root . '/data');

        $transport = function (string $url, array $form): array {
            self::assertSame(TurnstileVerifier::ENDPOINT, $url);
            $this->turnstileCalls[] = $form;

            return match ($form['response']) {
                'valid-token' => ['success' => true],
                'unavailable-token' => throw new RuntimeException('siteverify timeout'),
                default => ['success' => false, 'error-codes' => ['invalid-input-response']],
            };
        };

        $this->app = (new App($config, null, new TurnstileVerifier($transport)))->create();
        $this->pdo = (new Database($config))->pdo();
        $this->settings = new SettingsService($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testGuestEntryIsHiddenWhenTheDemoIsDisabled(): void
    {
        $response = $this->request('POST', '/api/v2/auth/guest');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->guestCount());
        self::assertSame(0, $this->auditCount('demo.guest'));
    }

    public function testGuestEntryIsRefusedWhenTheDemoIsFull(): void
    {
        $this->settings->update(['demo_enabled' => 1, 'demo_max_guests' => 1]);

        $first = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());

        $second = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(403, $second->getStatusCode());
        self::assertSame('The demo is full.', $this->json($second)['message']);
        self::assertSame(1, $this->guestCount());
    }

    public function testGuestEntryProvisionsAnEphemeralEditorSession(): void
    {
        $this->settings->update(['demo_enabled' => 1, 'demo_guest_ttl_hours' => 2]);
        $before = time();

        $response = $this->request(
            'POST',
            '/api/v2/auth/guest',
            [],
            ['User-Agent' => 'GuestBrowser/1.0'],
        );
        $after = time();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = $this->json($response);
        $user = $payload['user'];
        self::assertIsArray($user);

        self::assertTrue($user['guest']);
        self::assertSame('editor', $user['role']);
        self::assertNull($user['email']);
        self::assertNull($user['name']);
        self::assertMatchesRegularExpression('/^g-[0-9a-f]{16}$/', (string) $user['username']);
        self::assertGreaterThanOrEqual($before + 7200, (int) $user['expiration']);
        self::assertLessThanOrEqual($after + 7200, (int) $user['expiration']);
        self::assertSame(3600, $payload['expires_in']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $payload['csrf_token']);

        $cookie = $this->cookie($response);
        self::assertNotSame('', $cookie);
        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Lax', $setCookie);
        self::assertStringContainsString('Path=/', $setCookie);

        $row = $this->guestRow((string) $user['id']);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['guest']);
        self::assertSame('editor', (string) $row['role']);
        self::assertNull($row['email']);
        self::assertNull($row['name']);
        self::assertSame((int) $user['expiration'], (int) $row['expiration']);
        self::assertStringStartsWith('$argon2id$', (string) $row['password_hash']);
        self::assertFalse(password_verify('anything', (string) $row['password_hash']));

        $session = $this->pdo->prepare(
            'SELECT user_id, ip, user_agent FROM sessions WHERE user_id = :user_id'
        );
        $session->execute([':user_id' => (string) $user['id']]);
        $sessionRow = $session->fetch();
        self::assertIsArray($sessionRow);
        self::assertSame(self::REMOTE_IP, (string) $sessionRow['ip']);
        self::assertSame('GuestBrowser/1.0', (string) $sessionRow['user_agent']);

        $audit = $this->pdo->prepare(
            'SELECT user_id, ip, details_json FROM audit_log WHERE action = :action'
        );
        $audit->execute([':action' => 'demo.guest']);
        $auditRow = $audit->fetch();
        self::assertIsArray($auditRow);
        self::assertSame((string) $user['id'], (string) $auditRow['user_id']);
        self::assertSame(self::REMOTE_IP, (string) $auditRow['ip']);
        self::assertSame([], json_decode((string) $auditRow['details_json'], true, 512, JSON_THROW_ON_ERROR));

        $me = $this->request('GET', '/api/v2/auth/me', [], [], $cookie);
        self::assertSame(200, $me->getStatusCode(), (string) $me->getBody());
        $meUser = $this->json($me)['user'];
        self::assertIsArray($meUser);
        self::assertTrue($meUser['guest']);
        self::assertSame($user['username'], $meUser['username']);

        // The guest session uses the same CSRF machinery as a credential
        // login, so it can sign out cleanly.
        $guestCsrf = (string) $this->json($me)['csrf_token'];
        $logout = $this->request(
            'POST',
            '/api/v2/auth/logout',
            [],
            ['X-CSRF-Token' => $guestCsrf],
            $cookie
        );
        self::assertSame(200, $logout->getStatusCode(), (string) $logout->getBody());
        self::assertSame(401, $this->request('GET', '/api/v2/auth/me', [], [], $cookie)->getStatusCode());
    }

    public function testGuestEntryRequiresCaptchaWhenTurnstileIsEnabled(): void
    {
        $this->settings->update([
            'demo_enabled' => 1,
            'turnstile_enabled' => 1,
            'turnstile_secret_key' => 'guest-test-secret',
        ]);

        $missing = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $this->json($missing)['message']);

        $invalid = $this->request('POST', '/api/v2/auth/guest', ['cf-turnstile-response' => 'wrong-token']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $this->json($invalid)['message']);

        $unavailable = $this->request(
            'POST',
            '/api/v2/auth/guest',
            ['cf-turnstile-response' => 'unavailable-token']
        );
        self::assertSame(422, $unavailable->getStatusCode());
        self::assertSame(TurnstileVerifier::UNAVAILABLE_MESSAGE, $this->json($unavailable)['message']);

        self::assertSame(0, $this->guestCount());
        self::assertCount(2, $this->turnstileCalls);
        self::assertSame('guest-test-secret', $this->turnstileCalls[0]['secret']);
        self::assertSame(self::REMOTE_IP, $this->turnstileCalls[0]['remoteip']);

        $accepted = $this->request(
            'POST',
            '/api/v2/auth/guest',
            ['cf-turnstile-response' => 'valid-token']
        );
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame(1, $this->guestCount());
    }

    public function testCaptchaTokenMayArriveInTheHeader(): void
    {
        $this->settings->update(['demo_enabled' => 1, 'turnstile_enabled' => 1]);

        $response = $this->request(
            'POST',
            '/api/v2/auth/guest',
            [],
            ['CF-Turnstile-Response' => 'valid-token']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->guestCount());
    }

    public function testGuestEntryBudgetIsTwentyPerHourAndRecordedAsSuccess(): void
    {
        $this->settings->update(['demo_enabled' => 1]);

        for ($attempt = 1; $attempt <= RateLimiter::GUEST_MAX_ENTRIES; $attempt++) {
            $response = $this->request('POST', '/api/v2/auth/guest');
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }

        $blocked = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(RateLimiter::GUEST_LIMIT_MESSAGE, $this->json($blocked)['message']);
        self::assertSame(RateLimiter::GUEST_MAX_ENTRIES, $this->guestCount());

        $rows = $this->attemptRows('guest:' . self::REMOTE_IP, self::REMOTE_IP);
        self::assertSame(RateLimiter::GUEST_MAX_ENTRIES, $rows['success']);
        self::assertSame(0, $rows['failure']);

        // Guest entries are successful budget rows, not login failures.
        self::assertSame(
            0,
            $this->countFailures('guest:' . self::REMOTE_IP, self::REMOTE_IP)
        );

        // Another address has its own window.
        $other = $this->request('POST', '/api/v2/auth/guest', [], [], null, '198.51.100.7');
        self::assertSame(200, $other->getStatusCode(), (string) $other->getBody());
    }

    public function testGuestBudgetIsConsumedWhenProvisioningFails(): void
    {
        $this->settings->update(['demo_enabled' => 1]);
        $this->pdo->exec(
            "CREATE TRIGGER block_guest_insert BEFORE INSERT ON users "
            . "WHEN NEW.guest = 1 BEGIN SELECT RAISE(ABORT, 'blocked'); END"
        );

        $failed = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(500, $failed->getStatusCode());
        self::assertSame(0, $this->guestCount());
        self::assertSame(
            1,
            $this->attemptRows('guest:' . self::REMOTE_IP, self::REMOTE_IP)['success']
        );

        $this->pdo->exec('DROP TRIGGER block_guest_insert');
        $retry = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(200, $retry->getStatusCode(), (string) $retry->getBody());
        self::assertSame(
            2,
            $this->attemptRows('guest:' . self::REMOTE_IP, self::REMOTE_IP)['success']
        );
    }

    public function testCaptchaBudgetIsSharedAcrossLoginAndLegacyLogin(): void
    {
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());

        $this->settings->update([
            'turnstile_enabled' => 1,
            'turnstile_secret_key' => 'budget-test-secret',
        ]);
        $credentials = ['username' => 'admin', 'password' => self::PASSWORD];

        for ($attempt = 1; $attempt <= RateLimiter::CAPTCHA_MAX_ATTEMPTS; $attempt++) {
            $response = $this->request('POST', '/api/v2/auth/login', $credentials + [
                'cf-turnstile-response' => 'wrong-token',
            ]);
            self::assertSame(422, $response->getStatusCode());
        }
        self::assertCount(RateLimiter::CAPTCHA_MAX_ATTEMPTS, $this->turnstileCalls);

        $blocked = $this->request('POST', '/api/v2/auth/login', $credentials + [
            'cf-turnstile-response' => 'valid-token',
        ]);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(RateLimiter::CAPTCHA_LIMIT_MESSAGE, $this->json($blocked)['message']);
        // The budget is checked before siteverify: no new outbound call.
        self::assertCount(RateLimiter::CAPTCHA_MAX_ATTEMPTS, $this->turnstileCalls);

        // The legacy login shares the same address bucket.
        $legacy = $this->request('POST', '/api/auth/login', $credentials + [
            'cf-turnstile-response' => 'valid-token',
        ]);
        self::assertSame(429, $legacy->getStatusCode());
        self::assertSame(RateLimiter::CAPTCHA_LIMIT_MESSAGE, $this->json($legacy)['message']);
        self::assertCount(RateLimiter::CAPTCHA_MAX_ATTEMPTS, $this->turnstileCalls);
    }

    public function testGuestCaptchaAttemptsUseTheSameAddressBudget(): void
    {
        $this->settings->update(['demo_enabled' => 1, 'turnstile_enabled' => 1]);

        for ($attempt = 1; $attempt <= RateLimiter::CAPTCHA_MAX_ATTEMPTS; $attempt++) {
            $response = $this->request('POST', '/api/v2/auth/guest', [
                'cf-turnstile-response' => 'wrong-token',
            ]);
            self::assertSame(422, $response->getStatusCode());
        }
        self::assertCount(RateLimiter::CAPTCHA_MAX_ATTEMPTS, $this->turnstileCalls);

        $blocked = $this->request('POST', '/api/v2/auth/guest', [
            'cf-turnstile-response' => 'valid-token',
        ]);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(RateLimiter::CAPTCHA_LIMIT_MESSAGE, $this->json($blocked)['message']);
        self::assertSame(0, $this->guestCount());
        self::assertCount(RateLimiter::CAPTCHA_MAX_ATTEMPTS, $this->turnstileCalls);
    }

    public function testGuestSessionCannotOutliveTheGuestAccount(): void
    {
        $this->settings->update([
            'demo_enabled' => 1,
            'demo_guest_ttl_hours' => 1,
            'session_ttl' => 86400,
        ]);

        $response = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = $this->json($response);
        $user = $payload['user'];
        self::assertIsArray($user);

        $expiration = (int) $user['expiration'];
        $expiresIn = (int) $payload['expires_in'];
        self::assertGreaterThanOrEqual(3595, $expiresIn);
        self::assertLessThanOrEqual(3600, $expiresIn);
        self::assertLessThan(86400, $expiresIn);

        $session = $this->pdo->prepare(
            'SELECT created_at, expires_at FROM sessions WHERE user_id = :user_id'
        );
        $session->execute([':user_id' => (string) $user['id']]);
        $sessionRow = $session->fetch();
        self::assertIsArray($sessionRow);
        self::assertSame($expiresIn, (int) $sessionRow['expires_at'] - (int) $sessionRow['created_at']);
        self::assertLessThanOrEqual($expiration, (int) $sessionRow['expires_at']);
        self::assertGreaterThanOrEqual($expiration - 2, (int) $sessionRow['expires_at']);

        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertSame(1, preg_match('/Max-Age=(\d+)/', $setCookie, $matches));
        self::assertSame($expiresIn, (int) $matches[1]);
    }

    public function testMappedIpv6PeerUsesTheCanonicalIpv4Key(): void
    {
        $this->settings->update(['demo_enabled' => 1]);

        $mapped = $this->request('POST', '/api/v2/auth/guest', [], [], null, '::ffff:127.0.0.1');
        self::assertSame(200, $mapped->getStatusCode(), (string) $mapped->getBody());
        $mappedUser = $this->json($mapped)['user'];
        self::assertIsArray($mappedUser);

        $session = $this->pdo->prepare('SELECT ip FROM sessions WHERE user_id = :user_id');
        $session->execute([':user_id' => (string) $mappedUser['id']]);
        self::assertSame(self::REMOTE_IP, (string) $session->fetchColumn());
        $session->closeCursor();

        $audit = $this->pdo->prepare('SELECT ip FROM audit_log WHERE user_id = :user_id');
        $audit->execute([':user_id' => (string) $mappedUser['id']]);
        self::assertSame(self::REMOTE_IP, (string) $audit->fetchColumn());
        $audit->closeCursor();

        // The plain IPv4 spelling shares the mapped form's budget key.
        $plain = $this->request('POST', '/api/v2/auth/guest');
        self::assertSame(200, $plain->getStatusCode(), (string) $plain->getBody());
        self::assertSame(
            2,
            $this->attemptRows('guest:' . self::REMOTE_IP, self::REMOTE_IP)['success']
        );
    }

    public function testHealthPublishesDemoAndTurnstileFlagsWithoutTheSecret(): void
    {
        $default = $this->json($this->request('GET', '/api/v2/health'));
        self::assertSame(['enabled' => false, 'landing' => 'login'], $default['demo']);
        self::assertFalse($default['turnstile']['enabled']);
        self::assertSame('3x00000000000000000000FF', $default['turnstile']['site_key']);

        $this->settings->update([
            'demo_enabled' => 1,
            'demo_landing' => 'demo',
            'turnstile_enabled' => 1,
            'turnstile_site_key' => 'site-key-for-tests',
            'turnstile_secret_key' => 'top-secret-value',
        ]);

        $response = $this->request('GET', '/api/v2/health');
        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertSame(['enabled' => true, 'landing' => 'demo'], $payload['demo']);
        self::assertSame(['enabled' => true, 'site_key' => 'site-key-for-tests'], $payload['turnstile']);
        self::assertArrayNotHasKey('turnstile_secret_key', $payload);
        self::assertStringNotContainsString('top-secret-value', (string) $response->getBody());
    }

    public function testLoginRequiresCaptchaWhenTurnstileIsEnabled(): void
    {
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());

        $this->settings->update([
            'turnstile_enabled' => 1,
            'turnstile_secret_key' => 'login-test-secret',
        ]);

        $credentials = ['username' => 'admin', 'password' => self::PASSWORD];

        $missing = $this->request('POST', '/api/v2/auth/login', $credentials);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $this->json($missing)['message']);

        $invalid = $this->request('POST', '/api/v2/auth/login', $credentials + [
            'cf-turnstile-response' => 'wrong-token',
        ]);
        self::assertSame(422, $invalid->getStatusCode());

        $unavailable = $this->request('POST', '/api/v2/auth/login', $credentials + [
            'cf-turnstile-response' => 'unavailable-token',
        ]);
        self::assertSame(422, $unavailable->getStatusCode());
        self::assertSame(TurnstileVerifier::UNAVAILABLE_MESSAGE, $this->json($unavailable)['message']);

        $accepted = $this->request('POST', '/api/v2/auth/login', $credentials + [
            'cf-turnstile-response' => 'valid-token',
        ]);
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('admin', $this->json($accepted)['user']['username']);
        self::assertSame('login-test-secret', $this->turnstileCalls[0]['secret']);

        // The legacy adapter shares the gate: it must not be a bypass.
        $legacyMissing = $this->request('POST', '/api/auth/login', $credentials);
        self::assertSame(422, $legacyMissing->getStatusCode());
        self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $this->json($legacyMissing)['message']);

        $legacyAccepted = $this->request('POST', '/api/auth/login', $credentials + [
            'cf-turnstile-response' => 'valid-token',
        ]);
        self::assertSame(200, $legacyAccepted->getStatusCode(), (string) $legacyAccepted->getBody());

        // Captcha failures never reached the credential check, so no login
        // failure rows were recorded.
        $attempts = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = 'admin'");
        $attempts->execute();
        self::assertSame(0, (int) $attempts->fetchColumn());
    }

    /**
     * @return array{success: int, failure: int}
     */
    private function attemptRows(string $key, string $ip): array
    {
        $statement = $this->pdo->prepare(
            'SELECT '
            . 'SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successes, '
            . 'SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures '
            . 'FROM login_attempts WHERE username = :username AND ip = :ip'
        );
        $statement->execute([':username' => $key, ':ip' => $ip]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return [
            'success' => (int) ($row['successes'] ?? 0),
            'failure' => (int) ($row['failures'] ?? 0),
        ];
    }

    private function countFailures(string $key, string $ip): int
    {
        return $this->attemptRows($key, $ip)['failure'];
    }

    private function guestCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM users WHERE guest = 1');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function guestRow(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id AND guest = 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function auditCount(string $action): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $statement->execute([':action' => $action]);

        return (int) $statement->fetchColumn();
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
        string $remoteAddr = self::REMOTE_IP,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => $remoteAddr]
        );
        if ($cookie !== null && $cookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $cookie]);
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
