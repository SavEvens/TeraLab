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
use TeraLab\Api\Templates\QemuDisks;
use TeraLab\Api\Templates\TemplateRepository;

final class QemuDisksTest extends TestCase
{
    private string $root;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-qemudisks-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/templates', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testPlanShapeIsAccepted(): void
    {
        $block = [
            ['slot' => 'virtiob', 'image' => 'catmanager-20.16.1-virtiob.qcow2'],
            ['slot' => 'virtioc', 'size_gb' => 40],
        ];

        QemuDisks::validate(['qemu_disks' => $block]);

        self::assertSame($block, QemuDisks::disks(['qemu_disks' => $block]));
    }

    public function testImageOnlyAndGeneratedOnlyBlocksAreAccepted(): void
    {
        $imageOnly = [['slot' => 'virtiob', 'image' => 'virtiob.qcow2']];
        $generatedOnly = [['slot' => 'virtiod', 'size_gb' => 1]];

        self::assertSame($imageOnly, QemuDisks::disks(['qemu_disks' => $imageOnly]));
        self::assertSame($generatedOnly, QemuDisks::disks(['qemu_disks' => $generatedOnly]));
    }

    public function testAbsentBlockYieldsNoDisks(): void
    {
        QemuDisks::validate([]);

        self::assertSame([], QemuDisks::disks([]));
        self::assertSame([], QemuDisks::disks(['qemu_nic' => 'virtio']));
    }

    public function testEmptyListIsAccepted(): void
    {
        QemuDisks::validate(['qemu_disks' => []]);

        self::assertSame([], QemuDisks::disks(['qemu_disks' => []]));
    }

    public function testAllThreeSlotsAreAcceptedInTemplateOrder(): void
    {
        $block = [
            ['slot' => 'virtiod', 'size_gb' => 100],
            ['slot' => 'virtiob', 'image' => 'a.qcow2'],
            ['slot' => 'virtioc', 'size_gb' => 2],
        ];

        self::assertSame($block, QemuDisks::disks(['qemu_disks' => $block]));
    }

    /**
     * @param mixed $block
     */
    #[DataProvider('invalidBlocks')]
    public function testMalformedBlocksAreRejected(mixed $block): void
    {
        try {
            QemuDisks::validate(['qemu_disks' => $block]);
            self::fail('expected a 422 for the qemu_disks block');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('qemu_disks', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidBlocks(): iterable
    {
        yield 'not a list' => ['virtiob'];
        yield 'mapping instead of list' => [['slot' => 'virtiob', 'size_gb' => 1]];
        yield 'null block' => [null];
        yield 'too many entries' => [[
            ['slot' => 'virtiob', 'size_gb' => 1],
            ['slot' => 'virtioc', 'size_gb' => 1],
            ['slot' => 'virtiod', 'size_gb' => 1],
            ['slot' => 'virtiob', 'image' => 'a.qcow2'],
        ]];
        yield 'entry is a scalar' => [['virtiob']];
        yield 'entry is a list' => [[['virtiob', 'a.qcow2']]];
        yield 'missing slot' => [[['image' => 'a.qcow2']]];
        yield 'primary slot' => [[['slot' => 'virtioa', 'image' => 'a.qcow2']]];
        yield 'slot out of range' => [[['slot' => 'virtioe', 'image' => 'a.qcow2']]];
        yield 'uppercase slot' => [[['slot' => 'VirtioB', 'image' => 'a.qcow2']]];
        yield 'non-string slot' => [[['slot' => 1, 'image' => 'a.qcow2']]];
        yield 'duplicate slot' => [[
            ['slot' => 'virtiob', 'image' => 'a.qcow2'],
            ['slot' => 'virtiob', 'size_gb' => 1],
        ]];
        yield 'both sources' => [[['slot' => 'virtiob', 'image' => 'a.qcow2', 'size_gb' => 10]]];
        yield 'neither source' => [[['slot' => 'virtiob']]];
        yield 'size zero' => [[['slot' => 'virtiob', 'size_gb' => 0]]];
        yield 'size over the maximum' => [[['slot' => 'virtiob', 'size_gb' => 101]]];
        yield 'size is a float' => [[['slot' => 'virtiob', 'size_gb' => 1.5]]];
        yield 'size is a numeric string' => [[['slot' => 'virtiob', 'size_gb' => '40']]];
        yield 'size is a bool' => [[['slot' => 'virtiob', 'size_gb' => true]]];
        yield 'size is null' => [[['slot' => 'virtiob', 'size_gb' => null]]];
        yield 'unsupported key' => [[['slot' => 'virtiob', 'size_gb' => 1, 'path' => '/tmp/x']]];
        yield 'misspelled key' => [[['slot' => 'virtiob', 'size' => 1]]];
        yield 'empty image' => [[['slot' => 'virtiob', 'image' => '']]];
        yield 'image not a string' => [[['slot' => 'virtiob', 'image' => 42]]];
        yield 'parent traversal' => [[['slot' => 'virtiob', 'image' => '../a.qcow2']]];
        yield 'traversal inside the name' => [[['slot' => 'virtiob', 'image' => 'a/../../b.qcow2']]];
        yield 'path separator' => [[['slot' => 'virtiob', 'image' => 'qemu/a.qcow2']]];
        yield 'windows separator' => [[['slot' => 'virtiob', 'image' => 'qemu\\a.qcow2']]];
        yield 'absolute path' => [[['slot' => 'virtiob', 'image' => '/var/lib/teralab/images/a.qcow2']]];
        yield 'surrounding space' => [[['slot' => 'virtiob', 'image' => ' a.qcow2']]];
        yield 'control character' => [[['slot' => 'virtiob', 'image' => "a.qcow2\n"]]];
        yield 'invalid utf-8 surrogate' => [[['slot' => 'virtiob', 'image' => "\xED\xA0\x80.qcow2"]]];
        yield 'over-long reference' => [[['slot' => 'virtiob', 'image' => str_repeat('a', 129)]]];
    }

    public function testCustomTemplateSaveAndLoadRoundTripsTheBlock(): void
    {
        $block = [
            ['slot' => 'virtiob', 'image' => 'catmanager-20.16.1-virtiob.qcow2'],
            ['slot' => 'virtioc', 'size_gb' => 40],
        ];
        $yaml = $this->templateYaml('catmanager-custom', $block);

        $saved = $this->repository()->saveCustom('catmanager-custom', $yaml);
        self::assertSame($block, $saved['options']['qemu_disks']);

        $loaded = $this->repository()->find('catmanager-custom');
        self::assertIsArray($loaded);
        self::assertSame($block, $loaded['options']['qemu_disks']);
        self::assertSame($block, QemuDisks::disks($loaded['options']));
    }

    public function testBuiltinTemplateLoadRoundTripsTheBlock(): void
    {
        $block = [['slot' => 'virtiod', 'size_gb' => 5]];
        $directory = $this->config->root . '/api/templates/builtin';
        mkdir($directory, 0770, true);
        file_put_contents($directory . '/disked.yaml', $this->templateYaml('disked', $block));

        $loaded = $this->repository()->find('disked');
        self::assertIsArray($loaded);
        self::assertSame('builtin', $loaded['source']);
        self::assertSame($block, $loaded['options']['qemu_disks']);
        self::assertSame($block, QemuDisks::disks($loaded['options']));
    }

    public function testImportRejectsAMalformedBlock(): void
    {
        try {
            $this->repository()->import($this->templateYaml('bad-import', [
                ['slot' => 'virtiob', 'size_gb' => 101],
            ]));
            self::fail('a malformed qemu_disks block must be rejected on import');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('size_gb', $exception->getMessage());
        }
    }

    public function testImportRoundTripsTheBlock(): void
    {
        $block = [
            ['slot' => 'virtiob', 'image' => 'rendered.qcow2'],
            ['slot' => 'virtioc', 'size_gb' => 8],
        ];
        $imported = $this->repository()->import($this->templateYaml('imported-disks', $block));

        self::assertSame($block, $imported['options']['qemu_disks']);
        self::assertSame($block, $this->repository()->find('imported-disks')['options']['qemu_disks']);
    }

    public function testMalformedStoredTemplateDoesNotLoad(): void
    {
        $directory = $this->config->root . '/api/templates/builtin';
        mkdir($directory, 0770, true);
        file_put_contents($directory . '/broken.yaml', $this->templateYaml('broken', [
            ['slot' => 'virtioa', 'image' => 'a.qcow2'],
        ]));

        try {
            $this->repository()->find('broken');
            self::fail('a malformed stored template must not load');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }

        $ids = array_column($this->repository()->all(), 'id');
        self::assertNotContains('broken', $ids);
    }

    /**
     * @param list<array<string, mixed>> $disks
     */
    private function templateYaml(string $id, array $disks): string
    {
        return Yaml::dump([
            'schema' => 1,
            'id' => $id,
            'name' => 'QEMU disks',
            'kind' => 'qemu',
            'options' => ['qemu_disks' => $disks],
        ], 8, 2);
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
