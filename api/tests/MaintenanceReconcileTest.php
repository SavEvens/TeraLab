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

final class MaintenanceReconcileTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private Config $config;
    private string $cookie = '';
    private string $csrf = '';

    /** @var list<array{method: string, path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-reconcile-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');

        $agent = new AgentClient($this->config, function (string $method, string $path, array $payload): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
            if ($path === '/v1/cleanup.stale') {
                return [200, '{"ok":true,"result":{"run_dirs_removed":1}}'];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->app = (new App($this->config, $agent))->create();
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

    public function testReconcileSendsTheLiveLabIdsAsTheKeepSet(): void
    {
        $this->lab($this->root . '/labs/live-one.unl', 'live-lab-1', 'Live One');
        mkdir($this->root . '/labs/nested', 0770, true);
        $this->lab($this->root . '/labs/nested/live-two.unl', 'live-lab-2', 'Live Two');
        // A trashed (soft-deleted) lab must never enter the keep-set.
        mkdir($this->root . '/labs/.trash', 0770, true);
        $this->lab($this->root . '/labs/.trash/abc.unl', 'trashed-lab-1', 'Trashed');

        $response = $this->request('POST', '/api/v2/maintenance/reconcile');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cleanup = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/cleanup.stale',
        ));
        self::assertCount(1, $cleanup);
        self::assertSame('POST', $cleanup[0]['method']);
        self::assertSame(['lab_ids'], array_keys($cleanup[0]['payload']));
        $ids = $cleanup[0]['payload']['lab_ids'];
        self::assertIsArray($ids);
        self::assertCount(2, $ids);
        self::assertEqualsCanonicalizing(['live-lab-1', 'live-lab-2'], $ids);
        self::assertNotContains('trashed-lab-1', $ids);
    }

    public function testReconcileRefusesAnUnreadableLabStore(): void
    {
        $this->lab($this->root . '/labs/live-one.unl', 'live-lab-1', 'Live One');
        rename($this->root . '/labs', $this->root . '/labs-gone');

        $response = $this->request('POST', '/api/v2/maintenance/reconcile');

        self::assertSame(500, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/cleanup.stale',
        )), 'an unreadable lab list must not become an empty keep-set');
    }

    public function testAgentClientReconcilePayloadSemantics(): void
    {
        $payloads = [];
        $client = new AgentClient(
            $this->config,
            function (string $method, string $path, array $payload) use (&$payloads): array {
                $payloads[] = ['path' => $path, 'payload' => $payload];

                return [200, '{"ok":true,"result":{}}'];
            },
        );

        $client->reconcile();
        $client->reconcile([]);
        $client->reconcile(['lab-a']);

        self::assertSame([
            ['path' => '/v1/cleanup.stale', 'payload' => []],
            ['path' => '/v1/cleanup.stale', 'payload' => ['lab_ids' => []]],
            ['path' => '/v1/cleanup.stale', 'payload' => ['lab_ids' => ['lab-a']]],
        ], $payloads);
    }

    private function lab(string $path, string $id, string $name): void
    {
        file_put_contents(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?><lab name="' . $name . '" id="' . $id . '" version="1">'
            . '<topology><nodes/><networks/></topology></lab>',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($this->cookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $this->cookie]);
        }
        if ($this->csrf !== '') {
            $request = $request->withHeader('X-CSRF-Token', $this->csrf);
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
