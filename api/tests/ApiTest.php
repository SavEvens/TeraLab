<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use DI\Container;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\App;
use TeraLab\Api\Config;

final class ApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<Container> */
    private SlimApp $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        copy(__DIR__ . '/fixtures/sample.unl', $this->root . '/labs/sample.unl');

        $config = Config::forTests($this->root, $this->root . '/data');
        $this->app = (new App($config))->create();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testHealthAndSecurityHeaders(): void
    {
        $response = $this->request('GET', '/api/v2/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('ok', $this->json($response)['status']);
    }

    public function testSetupCreatesAdminOnce(): void
    {
        $first = $this->createAdmin();
        self::assertSame(201, $first->getStatusCode());
        self::assertNotSame('', $this->cookie($first));
        $setCookie = $first->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Lax', $setCookie);
        self::assertStringContainsString('Path=/', $setCookie);

        $second = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin2',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(409, $second->getStatusCode());
    }

    public function testLoginMeAndLogoutWithCsrf(): void
    {
        $setup = $this->createAdmin();
        $cookie = $this->cookie($setup);
        $csrf = (string) $this->json($setup)['csrf_token'];

        $wrong = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'admin',
            'password' => 'Wrong-Password-2026!',
        ]);
        self::assertSame(401, $wrong->getStatusCode());

        $login = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $login->getStatusCode());
        $cookie = $this->cookie($login);
        $csrf = (string) $this->json($login)['csrf_token'];

        $me = $this->request('GET', '/api/v2/auth/me', [], [], $cookie);
        self::assertSame(200, $me->getStatusCode());
        self::assertSame('admin', $this->json($me)['user']['username']);
        $csrf = (string) $this->json($me)['csrf_token'];

        $noCsrf = $this->request('POST', '/api/v2/auth/logout', [], [], $cookie);
        self::assertSame(403, $noCsrf->getStatusCode());

        $logout = $this->request('POST', '/api/v2/auth/logout', [], ['X-CSRF-Token' => $csrf], $cookie);
        self::assertSame(200, $logout->getStatusCode());

        $after = $this->request('GET', '/api/v2/auth/me', [], [], $cookie);
        self::assertSame(401, $after->getStatusCode());
    }

    public function testLoginRateLimit(): void
    {
        $this->createAdmin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->request('POST', '/api/v2/auth/login', [
                'username' => 'admin',
                'password' => 'Wrong-Password-2026!',
            ]);
            self::assertSame(401, $response->getStatusCode());
        }

        $blocked = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'admin',
            'password' => 'Wrong-Password-2026!',
        ]);
        self::assertSame(429, $blocked->getStatusCode());
    }

    public function testLabsRequireAuthAndReturnFixtures(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v2/labs')->getStatusCode());

        $setup = $this->createAdmin();
        $cookie = $this->cookie($setup);

        $labs = $this->request('GET', '/api/v2/labs', [], [], $cookie);
        self::assertSame(200, $labs->getStatusCode());
        $payload = $this->json($labs);
        self::assertCount(1, $payload['data']);
        self::assertSame('sample-lab', $payload['data'][0]['id']);

        $one = $this->request('GET', '/api/v2/labs/sample-lab', [], [], $cookie);
        self::assertSame(200, $one->getStatusCode());

        $missing = $this->request('GET', '/api/v2/labs/missing', [], [], $cookie);
        self::assertSame(404, $missing->getStatusCode());
    }

    public function testSystemStatusRequiresAuth(): void
    {
        self::assertSame(401, $this->request('GET', '/api/v2/system/status')->getStatusCode());

        $setup = $this->createAdmin();
        $status = $this->request('GET', '/api/v2/system/status', [], [], $this->cookie($setup));
        self::assertSame(200, $status->getStatusCode());
        self::assertSame('ok', $this->json($status)['status']);
    }

    private function createAdmin(): ResponseInterface
    {
        return $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
            'email' => 'admin@example.test',
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
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR)
            );
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream);
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
        $header = $response->getHeaderLine('Set-Cookie');
        if (preg_match('/' . preg_quote(Config::COOKIE_NAME, '/') . '=([^;]+)/', $header, $matches) !== 1) {
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
