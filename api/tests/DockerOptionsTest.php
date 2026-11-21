<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Templates\DockerOptions;
use TeraLab\Api\Templates\TemplateRepository;

final class DockerOptionsTest extends TestCase
{
    private string $root;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-dockeropts-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/templates', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testFrrTemplateShapeIsAccepted(): void
    {
        $docker = [
            'image' => 'frrouting/frr:10.0.1',
            'console_command' => '/usr/bin/vtysh',
            'cap_add' => ['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'],
            'pull' => true,
        ];

        $template = $this->repository()->saveCustom('frr', $this->templateYaml('frr', $docker));

        self::assertSame($docker, $template['options']['docker']);
        self::assertSame('frrouting/frr:10.0.1', DockerOptions::image($template['options']));
        self::assertSame([
            'console_command' => '/usr/bin/vtysh',
            'cap_add' => ['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'],
            'pull' => true,
        ], DockerOptions::spec($template['options']));
    }

    public function testDockerBlockIsOptionalForOtherKinds(): void
    {
        $template = $this->repository()->saveCustom(
            'qemu-plain',
            $this->templateYaml('qemu-plain', null, 'qemu'),
        );

        self::assertArrayNotHasKey('docker', $template['options']);
        self::assertNull(DockerOptions::image($template['options']));
        self::assertSame(['pull' => true], DockerOptions::spec($template['options']));
    }

    public function testMissingImageIsRejectedForDockerKind(): void
    {
        foreach ([
            ['pull' => true],
            ['console_command' => '/usr/bin/vtysh'],
        ] as $docker) {
            try {
                $this->repository()->saveCustom('no-image', $this->templateYaml('no-image', $docker));
                self::fail('a docker template without an image must be rejected');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
                self::assertStringContainsString('options.docker.image', $exception->getMessage());
            }
        }

        // No docker block at all is the same failure.
        try {
            $this->repository()->saveCustom('no-block', "schema: 1\nid: no-block\nname: No block\nkind: docker\n");
            self::fail('a docker template without options.docker must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('options.docker.image', $exception->getMessage());
        }
    }

    public function testUnknownDockerKeyIsRejected(): void
    {
        $exception = $this->saveFailure([
            'image' => 'frrouting/frr:10.0.1',
            'privileged' => true,
        ]);
        self::assertStringContainsString('unsupported key', $exception->getMessage());
    }

    public function testDockerBlockMustBeAMapping(): void
    {
        $this->saveFailure('frrouting/frr:10.0.1');
    }

    /**
     * @param array<string, mixed> $docker
     */
    #[DataProvider('invalidDockerBlocks')]
    public function testInvalidDockerBlockIsRejected(array $docker): void
    {
        $exception = $this->saveFailure($docker);
        self::assertSame(422, $exception->status);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidDockerBlocks(): iterable
    {
        yield 'bad capability' => [['image' => 'frrouting/frr:10.0.1', 'cap_add' => ['SYS_MODULE']]];
        yield 'mixed capabilities' => [['image' => 'frrouting/frr:10.0.1', 'cap_add' => ['NET_ADMIN', 'SYS_PTRACE']]];
        yield 'capabilities not a list' => [['image' => 'frrouting/frr:10.0.1', 'cap_add' => ['NET_ADMIN' => true]]];
        yield 'non-string capability' => [['image' => 'frrouting/frr:10.0.1', 'cap_add' => [7]]];
        yield 'too many capabilities' => [[
            'image' => 'frrouting/frr:10.0.1',
            'cap_add' => array_fill(0, 9, 'NET_ADMIN'),
        ]];
        yield 'pull is a string' => [['image' => 'frrouting/frr:10.0.1', 'pull' => 'yes']];
        yield 'pull is an int' => [['image' => 'frrouting/frr:10.0.1', 'pull' => 1]];
        yield 'relative console command' => [['image' => 'frrouting/frr:10.0.1', 'console_command' => 'vtysh']];
        yield 'console command with a space' => [['image' => 'frrouting/frr:10.0.1', 'console_command' => '/bin/sh -i']];
        yield 'console command with a newline' => [['image' => 'frrouting/frr:10.0.1', 'console_command' => "/bin/sh\n"]];
        yield 'console command not a string' => [['image' => 'frrouting/frr:10.0.1', 'console_command' => 42]];
        yield 'image not a string' => [['image' => ['frrouting/frr:10.0.1']]];
        yield 'uppercase repository' => [['image' => 'FRR:10.0.1']];
        yield 'uppercase path segment' => [['image' => 'frrouting/FRR:10.0.1']];
        yield 'space in image' => [['image' => 'frrouting /frr:10.0.1']];
        yield 'parent traversal' => [['image' => '../frr:10.0.1']];
        yield 'traversal inside the path' => [['image' => 'frrouting/../frr:10.0.1']];
        yield 'empty tag' => [['image' => 'frrouting/frr:']];
        yield 'leading slash' => [['image' => '/frrouting/frr:10.0.1']];
        yield 'control character' => [['image' => "frrouting/frr:\x0010"]];
        yield 'over-long reference' => [['image' => 'frrouting/' . str_repeat('a', 250) . ':1']];
    }

    /**
     * @param array<string, mixed> $docker
     */
    #[DataProvider('validImageReferences')]
    public function testValidImageReferencesAreAccepted(array $docker): void
    {
        $template = $this->repository()->saveCustom('ref', $this->templateYaml('ref', $docker));
        self::assertSame($docker['image'], DockerOptions::image($template['options']));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function validImageReferences(): iterable
    {
        yield 'bare name' => [['image' => 'alpine']];
        yield 'tag' => [['image' => 'frrouting/frr:10.0.1']];
        yield 'registry host' => [['image' => 'ghcr.io/teralab/frr:10.0.1']];
        yield 'registry port' => [['image' => 'localhost:5000/team/frr:10.0.1']];
        yield 'digest' => [['image' => 'frrouting/frr@sha256:' . str_repeat('a', 64)]];
        yield 'tag and digest' => [['image' => 'frrouting/frr:10.0.1@sha256:' . str_repeat('0', 64)]];
        yield 'separators' => [['image' => 'my-org/my_repo/sub.name:edge_1']];
    }

    public function testImagePatternIsReusable(): void
    {
        self::assertSame(
            1,
            preg_match(DockerOptions::IMAGE_PATTERN, 'frrouting/frr:10.0.1'),
        );
        self::assertSame(
            0,
            preg_match(DockerOptions::IMAGE_PATTERN, 'frrouting/FRR:10.0.1'),
        );
    }

    public function testSysAdminIsAcceptedWhileOtherPrivilegedCapabilitiesAreNot(): void
    {
        $spec = DockerOptions::spec([
            'docker' => ['image' => 'frrouting/frr:10.0.1', 'cap_add' => ['SYS_ADMIN']],
        ]);
        self::assertSame(['SYS_ADMIN'], $spec['cap_add']);

        foreach (['SYS_MODULE', 'SYS_PTRACE', 'DAC_READ_SEARCH'] as $capability) {
            try {
                DockerOptions::spec([
                    'docker' => ['image' => 'frrouting/frr:10.0.1', 'cap_add' => [$capability]],
                ]);
                self::fail($capability . ' must stay rejected');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status, $capability);
            }
        }
    }

    public function testCapAddIsDedupedAndPullDefaultsToTrue(): void
    {
        $spec = DockerOptions::spec([
            'docker' => [
                'image' => 'frrouting/frr:10.0.1',
                'cap_add' => ['NET_ADMIN', 'NET_ADMIN', 'NET_RAW', 'SYS_ADMIN', 'SYS_ADMIN'],
            ],
        ]);

        self::assertSame(['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'], $spec['cap_add']);
        self::assertTrue($spec['pull']);
    }

    public function testEmptyConsoleCommandIsOmitted(): void
    {
        $spec = DockerOptions::spec([
            'docker' => [
                'image' => 'frrouting/frr:10.0.1',
                'console_command' => '',
                'pull' => false,
            ],
        ]);

        self::assertSame(['pull' => false], $spec);
    }

    public function testConsoleCommandUsesAnAbsolutePath(): void
    {
        foreach (['/usr/bin/vtysh', '/bin/sh', '/opt/app-1.2/bin/console'] as $command) {
            self::assertSame(
                $command,
                DockerOptions::spec(['docker' => ['image' => 'alpine:3.20', 'console_command' => $command]])['console_command'],
            );
        }
    }

    /**
     * @param mixed $docker
     */
    private function saveFailure(mixed $docker): HttpException
    {
        try {
            $this->repository()->saveCustom('bad-docker', $this->templateYaml('bad-docker', $docker));
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);

            return $exception;
        }

        self::fail('expected a 422 for the docker block');
    }

    /**
     * @param mixed $docker
     */
    private function templateYaml(string $id, mixed $docker, string $kind = 'docker'): string
    {
        $document = [
            'schema' => 1,
            'id' => $id,
            'name' => 'Docker test',
            'kind' => $kind,
            'options' => [],
        ];
        if ($docker !== null) {
            $document['options']['docker'] = $docker;
        }

        return Yaml::dump($document, 8, 2);
    }

    private function repository(): TemplateRepository
    {
        return new TemplateRepository($this->config);
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
