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
            '/api/v2/nodes/console',
            '/api/v2/templates',
            '/api/v2/images',
            '/api/v2/images/import',
            '/api/v2/jobs',
            '/api/v2/system/host',
        ] as $path) {
            self::assertArrayHasKey($path, $paths, 'missing OpenAPI path ' . $path);
        }
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
