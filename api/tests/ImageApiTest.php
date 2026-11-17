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
use Slim\Psr7\UploadedFile;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;

final class ImageApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private string $cookie = '';
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-image-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);

        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, static function (string $method, string $path, array $payload): array {
            if ($path === '/v1/image.inspect') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'format' => 'qcow2',
                        'virtual_size' => 8388608,
                        'backing_file' => '',
                        'sha256' => hash_file('sha256', (string) ($payload['path'] ?? '')),
                    ],
                ], JSON_THROW_ON_ERROR)];
            }
            if ($path === '/v1/system/status') {
                return [200, '{"ok":true,"result":{"nodes":[]}}'];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->app = (new App($config, $agent))->create();
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode());
        $this->cookie = $this->cookie($setup);
        $this->csrf = (string) $this->json($setup)['csrf_token'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testUploadListAndDelete(): void
    {
        $content = "QFI\xfb" . str_repeat("\0", 128);
        $upload = $this->request('POST', '/api/v2/images', [], [], $this->uploaded($content, 'base.qcow2'), [
            'kind' => 'qemu',
            'name' => 'Base image',
        ]);
        self::assertSame(201, $upload->getStatusCode(), (string) $upload->getBody());
        $image = $this->json($upload)['data'];
        self::assertSame(hash('sha256', $content), $image['sha256']);
        self::assertSame('Base image', $image['name']);
        self::assertSame(8388608, $image['virtual_size']);

        $list = $this->request('GET', '/api/v2/images');
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $this->json($list)['data']);

        $delete = $this->request('DELETE', '/api/v2/images/' . $image['id']);
        self::assertSame(200, $delete->getStatusCode(), (string) $delete->getBody());

        $after = $this->request('GET', '/api/v2/images');
        self::assertCount(0, $this->json($after)['data']);
    }

    public function testInvalidUploadIsRejectedWithoutRow(): void
    {
        $upload = $this->request('POST', '/api/v2/images', [], [], $this->uploaded('not an image', 'bad.qcow2'), [
            'kind' => 'qemu',
        ]);
        self::assertSame(422, $upload->getStatusCode());

        $list = $this->request('GET', '/api/v2/images');
        self::assertCount(0, $this->json($list)['data']);
    }

    public function testUploadRequiresCsrf(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/images', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([Config::COOKIE_NAME => $this->cookie])
            ->withUploadedFiles(['file' => $this->uploaded("QFI\xfb" . str_repeat("\0", 16), 'base.qcow2')])
            ->withParsedBody(['kind' => 'qemu']);

        $response = $this->app->handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    private function uploaded(string $content, string $name): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            'application/octet-stream',
            strlen($content),
            UPLOAD_ERR_OK,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $fields
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?UploadedFile $file = null,
        array $fields = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($this->cookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $this->cookie]);
        }
        if ($this->csrf !== '') {
            $request = $request->withHeader('X-CSRF-Token', $this->csrf);
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
                ->withUploadedFiles(['file' => $file])
                ->withParsedBody($fields);
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
