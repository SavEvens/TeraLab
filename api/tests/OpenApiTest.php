<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OpenApiTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/docs/openapi.yaml';
        self::assertFileExists($path);
        $parsed = Yaml::parseFile($path);
        self::assertIsArray($parsed);
        /** @var array<string, mixed> $parsed */
        $this->spec = $parsed;
    }

    public function testDocumentShapeAndSecurity(): void
    {
        self::assertSame('3.1.0', $this->spec['openapi']);
        self::assertSame('TeraLab API', $this->spec['info']['title']);
        self::assertArrayHasKey('cookieAuth', $this->spec['components']['securitySchemes']);
        self::assertSame('teralab_session', $this->spec['components']['securitySchemes']['cookieAuth']['name']);
        self::assertArrayHasKey('external', $this->spec['components']['schemas']['Image']['properties']);
    }

    public function testCorePathsAreDocumented(): void
    {
        $paths = $this->spec['paths'];
        self::assertIsArray($paths);
        foreach ([
            '/api/v2/health',
            '/api/v2/version',
            '/api/v2/auth/login',
            '/api/v2/labs',
            '/api/v2/labs/{id}',
            '/api/v2/labs/{id}/start',
            '/api/v2/labs/{id}/stop',
            '/api/v2/labs/{id}/wipe',
            '/api/v2/labs/{id}/export',
            '/api/v2/labs/import',
            '/api/v2/labs/{id}/nodes/{node}/console',
            '/api/v2/labs/{id}/nodes/{node}/status',
            '/api/v2/templates',
            '/api/v2/images',
            '/api/v2/images/import',
            '/api/v2/jobs',
            '/api/v2/system/host',
            // Four path templates cover the six user routes (GET/POST on the
            // collection, PATCH/DELETE on the item, plus password and revoke).
            '/api/v2/users',
            '/api/v2/users/{id}',
            '/api/v2/users/{id}/password',
            '/api/v2/users/{id}/sessions/revoke',
        ] as $path) {
            self::assertArrayHasKey($path, $paths, 'missing OpenAPI path ' . $path);
        }
    }

    public function testRawAgentNodePathsAreRemoved(): void
    {
        foreach ([
            '/api/v2/nodes/start',
            '/api/v2/nodes/stop',
            '/api/v2/nodes/wipe',
            '/api/v2/nodes/status',
            '/api/v2/nodes/console',
        ] as $path) {
            self::assertArrayNotHasKey($path, $this->spec['paths'], 'raw node path must be removed: ' . $path);
        }
    }

    public function testLabScopedNodeRoutesDocumentParametersAndSecurity(): void
    {
        $console = $this->spec['paths']['/api/v2/labs/{id}/nodes/{node}/console'] ?? null;
        self::assertIsArray($console);
        self::assertArrayHasKey('post', $console);
        $post = $console['post'];
        self::assertIsArray($post);
        self::assertSame('nodes', $post['tags'][0]);
        self::assertStringContainsString('CSRF', (string) ($post['description'] ?? ''));
        self::assertSame(
            [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'node', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            $post['parameters'],
        );
        self::assertSame(
            '#/components/schemas/ConsoleSession',
            $post['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'],
        );

        $status = $this->spec['paths']['/api/v2/labs/{id}/nodes/{node}/status'] ?? null;
        self::assertIsArray($status);
        self::assertArrayHasKey('get', $status);
        $get = $status['get'];
        self::assertIsArray($get);
        self::assertSame('nodes', $get['tags'][0]);
        self::assertStringContainsString('read', (string) ($get['description'] ?? ''));
        self::assertSame(
            [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'node', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            $get['parameters'],
        );
        self::assertSame(
            '#/components/schemas/NodeStatus',
            $get['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'],
        );
    }

    public function testEveryOperationHasSummaryAndResponses(): void
    {
        $methods = ['get', 'post', 'put', 'patch', 'delete'];
        foreach ($this->spec['paths'] as $path => $operations) {
            self::assertIsArray($operations);
            foreach ($methods as $method) {
                if (!isset($operations[$method])) {
                    continue;
                }
                $operation = $operations[$method];
                self::assertArrayHasKey('summary', $operation, $method . ' ' . $path . ' needs a summary');
                self::assertArrayHasKey('responses', $operation, $method . ' ' . $path . ' needs responses');
                self::assertNotEmpty($operation['responses']);
            }
        }
    }
}
