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
use TeraLab\Api\App;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Tests\Support\SetupToken;

final class SetupTest extends TestCase
{
    use SetupToken;

    private const PASSWORD = 'Teralab-Admin-2026!';

    private const REMOTE_IP = '127.0.0.1';

    private string $root;
    private Config $config;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-setup-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->installSetupToken($this->config);
        $this->app = (new App($this->config))->create();
        $this->pdo = (new Database($this->config))->pdo();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testSetupIsUnavailableWithoutTheOperatorTokenFile(): void
    {
        unlink($this->setupTokenFile());

        $response = $this->postSetup(['username' => 'admin', 'password' => self::PASSWORD]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->userCount());
    }

    public function testSetupRequiresAMatchingOperatorToken(): void
    {
        $missing = $this->postSetup(['username' => 'admin', 'password' => self::PASSWORD], '');
        self::assertSame(401, $missing->getStatusCode(), (string) $missing->getBody());

        $wrong = $this->postSetup(
            ['username' => 'admin', 'password' => self::PASSWORD],
            'not-the-operator-token',
        );
        self::assertSame(403, $wrong->getStatusCode(), (string) $wrong->getBody());
        self::assertSame(0, $this->userCount());
        self::assertFileExists($this->setupTokenFile());

        $accepted = $this->postSetup(['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('admin', $this->json($accepted)['user']['role']);
        self::assertSame(1, $this->userCount());
    }

    public function testSetupAcceptsTheTokenInTheHeaderOnly(): void
    {
        $response = $this->postSetup(
            ['username' => 'admin', 'password' => self::PASSWORD],
            $this->setupToken(),
            true,
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->userCount());
    }

    public function testSetupUsesTheJsonTokenField(): void
    {
        $response = $this->postSetup([
            'username' => 'admin',
            'password' => self::PASSWORD,
            'setup_token' => $this->setupToken(),
        ], '');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->adminCount());
    }

    public function testSetupIsDisabledAfterTheFirstAdministrator(): void
    {
        $first = $this->postSetup(['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());

        // The route is disabled by the user count even when the one-time
        // token file is gone or the caller replays a stale token.
        self::assertFileDoesNotExist($this->setupTokenFile());
        $again = $this->postSetup(['username' => 'admin2', 'password' => self::PASSWORD], '');
        self::assertSame(409, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(1, $this->userCount());
        self::assertSame(1, $this->adminCount());
    }

    public function testSetupIsRateLimitedPerAddress(): void
    {
        for ($attempt = 1; $attempt <= RateLimiter::SETUP_MAX_PER_IP; $attempt++) {
            $response = $this->postSetup(
                ['username' => 'admin', 'password' => self::PASSWORD],
                'wrong-token-' . $attempt,
            );
            self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        }

        $blocked = $this->postSetup(['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(429, $blocked->getStatusCode(), (string) $blocked->getBody());
        self::assertSame(RateLimiter::SETUP_LIMIT_MESSAGE, $this->json($blocked)['message']);
        self::assertSame(0, $this->userCount());
    }

    public function testSetupIsRateLimitedGlobally(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip, attempted_at, success) '
            . 'VALUES (:username, :ip, :attempted_at, 1)'
        );
        for ($row = 0; $row < RateLimiter::SETUP_MAX_GLOBAL; $row++) {
            $statement->execute([
                ':username' => RateLimiter::SETUP_GLOBAL_KEY,
                ':ip' => RateLimiter::SETUP_GLOBAL_IP,
                ':attempted_at' => time(),
            ]);
        }

        $blocked = $this->postSetup([], $this->setupToken(), true, '198.51.100.9');

        self::assertSame(429, $blocked->getStatusCode(), (string) $blocked->getBody());
        self::assertSame(RateLimiter::SETUP_LIMIT_MESSAGE, $this->json($blocked)['message']);
        self::assertSame(0, $this->userCount());
    }

    public function testConcurrentSetupCreatesExactlyOneAdministrator(): void
    {
        $worker = $this->root . '/setup-worker.php';
        file_put_contents($worker, $this->workerScript());

        $processes = [];
        foreach (['admin-one', 'admin-two'] as $username) {
            $command = [
                PHP_BINARY,
                $worker,
                dirname(__DIR__) . '/vendor/autoload.php',
                $this->root,
                $this->setupToken(),
                $username,
            ];
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process, 'cannot start a setup worker');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        $statuses = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            self::assertSame(0, $exit, 'setup worker failed: ' . $stderr);
            self::assertMatchesRegularExpression('/^\d{3}$/', trim($stdout), $stdout . $stderr);
            $statuses[] = (int) trim($stdout);
        }
        sort($statuses);

        self::assertSame([201, 409], $statuses, 'concurrent setup must produce one winner');
        self::assertSame(1, $this->userCount());
        self::assertSame(1, $this->adminCount());

        $audit = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.setup'");
        self::assertNotFalse($audit);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testSetupValidatesTheEmailLikeUserCreation(): void
    {
        foreach (['not-an-email', "admin@example.test\nBcc: x@example.test", 'a@b'] as $email) {
            $response = $this->postSetup([
                'username' => 'admin',
                'password' => self::PASSWORD,
                'email' => $email,
            ]);
            self::assertSame(422, $response->getStatusCode(), $email . ': ' . (string) $response->getBody());
            self::assertArrayHasKey('email', $this->json($response)['details']);
            self::assertSame(0, $this->userCount());
        }

        $accepted = $this->postSetup([
            'username' => 'admin',
            'password' => self::PASSWORD,
            'email' => '  admin@example.test  ',
        ]);
        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('admin@example.test', $this->json($accepted)['user']['email']);
    }

    public function testSetupRejectsOversizedPasswordAndControlUsername(): void
    {
        $password = 'Aa1!' . str_repeat('x', 300);
        $response = $this->postSetup([
            'username' => 'admin',
            'password' => $password,
        ]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('password', $this->json($response)['details']);
        self::assertSame(0, $this->userCount());

        $username = $this->postSetup([
            'username' => "admin\n",
            'password' => self::PASSWORD,
        ]);
        self::assertSame(422, $username->getStatusCode(), (string) $username->getBody());
        self::assertSame(0, $this->userCount());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postSetup(
        array $body,
        ?string $token = null,
        bool $headerOnly = false,
        string $ip = self::REMOTE_IP,
    ): ResponseInterface {
        $token ??= $this->setupToken();
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/api/v2/setup',
            ['REMOTE_ADDR' => $ip],
        );
        if ($token !== '') {
            $request = $request->withHeader('X-TeraLab-Setup-Token', $token);
        }
        if ($headerOnly) {
            $body = array_intersect_key($body, ['username' => true, 'password' => true]);
        } elseif ($token !== '' && !array_key_exists('setup_token', $body)) {
            $body['setup_token'] = $token;
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(
                    json_encode($body, JSON_THROW_ON_ERROR)
                ));
        }

        return $this->app->handle($request);
    }

    private function setupTokenFile(): string
    {
        return $this->config->dataDir . '/setup.token';
    }

    private function userCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM users');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function adminCount(): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");

        return $statement === false ? -1 : (int) $statement->fetchColumn();
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

    private function workerScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

require $argv[1];

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\App;
use TeraLab\Api\Config;

try {
    $root = $argv[2];
    $token = $argv[3];
    $username = $argv[4];
    $config = Config::forTests($root, $root . '/data');
    $app = (new App($config))->create();
    $request = (new ServerRequestFactory())->createServerRequest(
        'POST',
        '/api/v2/setup',
        ['REMOTE_ADDR' => '127.0.0.1'],
    );
    $request = $request
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('X-TeraLab-Setup-Token', $token)
        ->withBody((new StreamFactory())->createStream(json_encode([
            'username' => $username,
            'password' => 'Teralab-Admin-2026!',
        ], JSON_THROW_ON_ERROR)));
    echo $app->handle($request)->getStatusCode();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
PHP;
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
