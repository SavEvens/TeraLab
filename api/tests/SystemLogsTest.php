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
use TeraLab\Api\Tests\Support\SetupToken;

final class SystemLogsTest extends TestCase
{
    use SetupToken;
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private string $adminCookie = '';
    private string $adminCsrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-system-logs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $config = Config::forTests($this->root, $this->root . '/data');
        $this->installSetupToken($config);
        $stub = static function (string $method, string $path, array $payload): array {
            return [200, '{"ok":true,"result":{}}'];
        };
        $this->app = (new App($config, new AgentClient($config, $stub)))->create();

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

    public function testEmptyApiLogReturnsEmptyLines(): void
    {
        $path = $this->root . '/data/logs/api.log';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, '');

        $response = $this->request('GET', '/api/v2/system/logs?source=api', [], [], $this->adminCookie);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertSame('api', $data['source']);
        self::assertSame([], $data['lines']);
        self::assertSame(0, $data['size']);
        self::assertTrue($data['modified'] === null || is_int($data['modified']));
    }

    public function testMissingApiLogReturnsEmptyLines(): void
    {
        if (!is_dir($this->root . '/data/logs')) {
            mkdir($this->root . '/data/logs', 0770, true);
        }

        $response = $this->request('GET', '/api/v2/system/logs?source=api', [], [], $this->adminCookie);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertSame('api', $data['source']);
        self::assertSame([], $data['lines']);
        self::assertSame(0, $data['size']);
    }

    public function testEmptyAgentLogReturnsEmptyLines(): void
    {
        $path = $this->root . '/data/logs/agent.log';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, '');

        $response = $this->request('GET', '/api/v2/system/logs?source=agent', [], [], $this->adminCookie);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertSame('agent', $data['source']);
        self::assertSame([], $data['lines']);
        self::assertSame(0, $data['size']);
        self::assertTrue($data['modified'] === null || is_int($data['modified']));
    }

    public function testNonEmptyApiLogReturnsLines(): void
    {
        $path = $this->root . '/data/logs/api.log';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        $contents = "line-one\nline-two\n";
        file_put_contents($path, $contents);

        $response = $this->request('GET', '/api/v2/system/logs?source=api', [], [], $this->adminCookie);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertSame('api', $data['source']);
        self::assertSame(['line-one', 'line-two'], $data['lines']);
        self::assertSame(strlen($contents), $data['size']);
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
