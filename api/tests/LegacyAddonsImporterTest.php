<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Image\ImageStore;
use TeraLab\Api\Image\LegacyAddonsImporter;
use TeraLab\Api\Templates\TemplateRepository;

final class LegacyAddonsImporterTest extends TestCase
{
    private string $root;
    private string $addons;
    private Config $config;
    private PDO $pdo;
    private LegacyAddonsImporter $importer;
    private ImageStore $store;

    /** @var \Closure(string): array<string, mixed> */
    private \Closure $inspector;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-addons-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/templates', 0770, true);
        mkdir($this->root . '/labs', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();

        foreach (['csr1000v', 'vios', 'xrv9k', 'c7200'] as $id) {
            file_put_contents(
                $this->config->templatesDir . '/' . $id . '.yaml',
                "schema: 1\nid: " . $id . "\nname: " . strtoupper($id) . "\nkind: qemu\n",
            );
        }

        $this->addons = $this->root . '/addons';
        $this->writeAddon('qemu/csr1000vng/hda.qcow2', "QFI\xfb" . str_repeat('a', 120));
        $this->writeAddon('qemu/csr1000vng/licence.txt', 'not an image');
        $this->writeAddon('qemu/vios-adventerprisek9-m/hda.qcow2', "QFI\xfb" . str_repeat('b', 120));
        $this->writeAddon('qemu/xrv9k-6.x/hda.qcow2', "QFI\xfb" . str_repeat('c', 120));
        $this->writeAddon('iol/bin/x86_64.bin', "\x7fELF" . str_repeat('d', 120));
        $this->writeAddon('iol/bin/iourc', "licence data");
        $this->writeAddon('dynamips/c7200.image', 'IOS image');
        $this->writeAddon('docker/x', 'not supported');
        @symlink($this->root . '/missing-target', $this->addons . '/iol/bin/unreadable.bin');

        $this->inspector = static fn (string $path): array => [
            'format' => 'qcow2',
            'virtual_size' => 8388608,
            'backing_file' => '',
            'sha256' => hash_file('sha256', $path),
        ];
        $templates = new TemplateRepository($this->config);
        $this->importer = new LegacyAddonsImporter($this->config, $this->pdo, $this->inspector, $templates);
        $this->store = new ImageStore($this->config, $this->pdo, $this->inspector, $templates);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDryRunClassifiesFilesAndSuggestsTemplates(): void
    {
        $scan = $this->importer->scan($this->addons);
        self::assertTrue($scan['dry_run']);
        self::assertSame(realpath($this->addons), $scan['root']);
        $entries = $this->entries($scan);

        self::assertSame('qemu', $entries['qemu/csr1000vng/hda.qcow2']['kind']);
        self::assertSame('new', $entries['qemu/csr1000vng/hda.qcow2']['status']);
        self::assertSame('csr1000vng', $entries['qemu/csr1000vng/hda.qcow2']['reference']);
        self::assertSame('csr1000v', $entries['qemu/csr1000vng/hda.qcow2']['template_suggestion']);
        self::assertGreaterThan(0, $entries['qemu/csr1000vng/hda.qcow2']['size']);

        self::assertSame('vios', $entries['qemu/vios-adventerprisek9-m/hda.qcow2']['template_suggestion']);
        self::assertSame('xrv9k', $entries['qemu/xrv9k-6.x/hda.qcow2']['template_suggestion']);

        self::assertSame('iol', $entries['iol/bin/x86_64.bin']['kind']);
        self::assertSame('x86_64.bin', $entries['iol/bin/x86_64.bin']['reference']);
        self::assertSame('iol/bin/x86_64.bin', $entries['iol/bin/x86_64.bin']['path']);

        self::assertSame('dynamips', $entries['dynamips/c7200.image']['kind']);
        self::assertSame('c7200.image', $entries['dynamips/c7200.image']['reference']);
        self::assertSame('c7200', $entries['dynamips/c7200.image']['template_suggestion']);

        self::assertSame('skipped', $entries['iol/bin/iourc']['status']);
        self::assertSame('skipped', $entries['qemu/csr1000vng/licence.txt']['status']);
        self::assertNull($entries['iol/bin/iourc']['reference']);
        self::assertNotSame([], $entries['iol/bin/iourc']['warnings']);

        self::assertSame('unreadable', $entries['iol/bin/unreadable.bin']['status']);
        self::assertNotSame([], $entries['iol/bin/unreadable.bin']['warnings']);

        foreach ($entries as $path => $entry) {
            self::assertStringStartsNotWith('docker/', $path, 'docker addons must not be listed');
            self::assertNull($entry['error']);
        }

        self::assertSame(5, $scan['summary']['new']);
        self::assertSame(2, $scan['summary']['skipped']);
        self::assertSame(1, $scan['summary']['unreadable']);
        self::assertSame(0, $scan['summary']['existing']);
        self::assertSame(0, $scan['summary']['duplicate']);
    }

    public function testDryRunSurfacesReferenceCollisions(): void
    {
        $this->insertRow('qemu-taken', 'csr1000vng', str_repeat('e', 64));

        $entries = $this->entries($this->importer->scan($this->addons));
        self::assertSame('csr1000vng-2', $entries['qemu/csr1000vng/hda.qcow2']['reference']);
        self::assertNotSame([], $entries['qemu/csr1000vng/hda.qcow2']['warnings']);
    }

    public function testImportCreatesExternalSymlinkAndResolvesReference(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $result = $this->importer->import($this->addons, [$relative], [], null);

        self::assertSame(1, $result['summary']['imported']);
        self::assertSame('imported', $result['results'][0]['status']);
        $image = $result['results'][0]['image'];
        self::assertIsArray($image);
        self::assertSame(1, $image['external']);
        self::assertSame('csr1000vng', $image['reference']);
        self::assertSame('csr1000v', $image['template']);
        self::assertStringStartsWith($this->config->imagesDir . '/qemu/', (string) $image['path']);
        self::assertTrue(is_link((string) $image['path']));
        self::assertSame(
            realpath($this->addons . '/' . $relative),
            readlink((string) $image['path']),
        );
        self::assertFileExists($this->addons . '/' . $relative);

        self::assertSame(1, $this->store->find((string) $image['id'])['external']);
        self::assertSame(1, $this->store->all()[0]['external']);

        $references = new ImageReferences($this->pdo, $this->config);
        $resolved = $references->resolve('csr1000vng');
        self::assertSame((string) $image['path'], $resolved);
        self::assertSame(realpath($this->addons . '/' . $relative), realpath((string) $resolved));

        // A second dry run reports the adopted file as existing, not new.
        $entries = $this->entries($this->importer->scan($this->addons));
        self::assertSame('existing', $entries[$relative]['status']);
    }

    public function testImportCreatesTheStoreDirectoryWithTheUploadMode(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $previous = umask(0);
        try {
            $result = $this->importer->import($this->addons, [$relative], [], null);
        } finally {
            umask($previous);
        }

        self::assertSame('imported', $result['results'][0]['status']);
        $path = (string) $result['results'][0]['image']['path'];
        self::assertDirectoryExists(dirname($path));
        $mode = fileperms(dirname($path));
        self::assertIsInt($mode);
        // 0755 matches ImageStore::store(); the agent runs as another user
        // and must be able to traverse the store directory.
        self::assertSame(0755, $mode & 0777);
    }

    public function testImportKeepsStoreDirectoriesTraversableUnderTheServiceUmask(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $previous = umask(0077);
        try {
            $result = $this->importer->import($this->addons, [$relative], [], null);
        } finally {
            umask($previous);
        }
        clearstatcache();

        self::assertSame('imported', $result['results'][0]['status']);
        $path = (string) $result['results'][0]['image']['path'];
        self::assertTrue(is_link($path));
        $directory = dirname($path);
        self::assertSame(0755, fileperms($directory) & 0777, 'per-image directory must be traversable');
        self::assertSame(0755, fileperms(dirname($directory)) & 0777, 'kind directory must be traversable');
        self::assertSame(0755, fileperms($this->config->imagesDir) & 0777, 'image root must be traversable');
    }

    public function testDryRunDetectsDuplicateContentAndExistingAdoptions(): void
    {
        $this->importer->import($this->addons, ['qemu/csr1000vng/hda.qcow2'], [], null);
        $this->writeAddon('qemu/copy/hda.qcow2', "QFI\xfb" . str_repeat('a', 120));

        $scan = $this->importer->scan($this->addons);
        $entries = $this->entries($scan);
        self::assertSame('existing', $entries['qemu/csr1000vng/hda.qcow2']['status']);
        self::assertSame('duplicate', $entries['qemu/copy/hda.qcow2']['status']);
        self::assertSame('csr1000vng', $entries['qemu/csr1000vng/hda.qcow2']['reference']);
        self::assertSame(1, $scan['summary']['existing']);
        self::assertSame(1, $scan['summary']['duplicate']);
        self::assertNotSame([], $entries['qemu/copy/hda.qcow2']['warnings']);
    }

    public function testDuplicateChecksumIsSkipped(): void
    {
        $first = $this->importer->import($this->addons, ['qemu/csr1000vng/hda.qcow2'], [], null);
        self::assertSame('imported', $first['results'][0]['status']);

        $second = $this->importer->import($this->addons, ['qemu/csr1000vng/hda.qcow2'], [], null);
        self::assertSame('duplicate', $second['results'][0]['status']);
        self::assertSame($first['results'][0]['image']['id'], $second['results'][0]['image']['id']);
        self::assertSame(1, $second['summary']['duplicate']);
        self::assertCount(1, $this->store->all());

        // The duplicate path must not touch the link created by the first import.
        $path = (string) $first['results'][0]['image']['path'];
        self::assertTrue(is_link($path));
        self::assertSame(realpath($this->addons . '/qemu/csr1000vng/hda.qcow2'), realpath($path));
    }

    public function testInsertFailureKeepsALinkOwnedByAnotherImport(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $target = (string) realpath($this->addons . '/' . $relative);
        $sha256 = (string) hash_file('sha256', $target);
        $id = 'qemu-' . substr($sha256, 0, 16);

        // Simulate a previous or concurrent import that already linked the
        // file into the store and created the row's directory.
        $directory = $this->config->imagesDir . '/qemu/' . $id;
        $storePath = $directory . '/hda.qcow2';
        $this->makeDirectory($directory);
        self::assertTrue(symlink($target, $storePath));

        // A row with the derived primary key but different content forces the
        // INSERT to fail while the checksum lookup still reports no duplicate.
        $this->insertRow($id, 'pre-existing', str_repeat('9', 64));

        $result = $this->importer->import($this->addons, [$relative], [], null);

        self::assertSame('failed', $result['results'][0]['status']);
        self::assertStringContainsString('Cannot register the image', (string) $result['results'][0]['error']);
        self::assertTrue(is_link($storePath), 'a pre-existing store link must survive a failed insert');
        self::assertSame($target, realpath($storePath));
        self::assertDirectoryExists($directory, 'a pre-existing store directory must survive a failed insert');
    }

    public function testInsertFailureRemovesALinkItCreated(): void
    {
        $relative = 'qemu/csr1000vng/hda.qcow2';
        $target = (string) realpath($this->addons . '/' . $relative);
        $sha256 = (string) hash_file('sha256', $target);
        $id = 'qemu-' . substr($sha256, 0, 16);
        $directory = $this->config->imagesDir . '/qemu/' . $id;
        $storePath = $directory . '/hda.qcow2';
        self::assertFalse(is_dir($directory));
        $this->insertRow($id, 'pre-existing', str_repeat('9', 64));

        $result = $this->importer->import($this->addons, [$relative], [], null);

        self::assertSame('failed', $result['results'][0]['status']);
        self::assertFalse(is_link($storePath));
        self::assertDirectoryDoesNotExist($directory);
        self::assertFileExists($target);
    }

    public function testDeleteRemovesRowAndLinkButKeepsTarget(): void
    {
        $target = $this->addons . '/qemu/csr1000vng/hda.qcow2';
        $import = $this->importer->import($this->addons, ['qemu/csr1000vng/hda.qcow2'], [], null);
        $image = $import['results'][0]['image'];
        self::assertIsArray($image);
        $path = (string) $image['path'];

        $this->store->delete((string) $image['id']);

        self::assertNull($this->store->find((string) $image['id']));
        self::assertFalse(is_link($path));
        self::assertDirectoryDoesNotExist(dirname($path));
        self::assertFileExists($target);
    }

    public function testImportRejectsPathsOutsideTheRoot(): void
    {
        $outside = $this->root . '/outside.qcow2';
        file_put_contents($outside, "QFI\xfb" . str_repeat('f', 60));

        $result = $this->importer->import($this->addons, ['../outside.qcow2'], [], null);
        self::assertSame('failed', $result['results'][0]['status']);
        self::assertSame(1, $result['summary']['failed']);
        self::assertCount(0, $this->store->all());

        mkdir($this->addons . '/qemu/escape', 0770, true);
        symlink($outside, $this->addons . '/qemu/escape/hda.qcow2');
        $escaped = $this->importer->import($this->addons, ['qemu/escape/hda.qcow2'], [], null);
        self::assertSame('failed', $escaped['results'][0]['status']);
        self::assertStringContainsString('outside the addons root', (string) $escaped['results'][0]['error']);
        self::assertCount(0, $this->store->all());
    }

    public function testInspectFailuresAndBackingFilesAreWarnings(): void
    {
        $importer = new LegacyAddonsImporter(
            $this->config,
            $this->pdo,
            static fn (string $path): array => throw new \RuntimeException('agent unavailable'),
            new TemplateRepository($this->config),
        );
        $result = $importer->import($this->addons, ['qemu/csr1000vng/hda.qcow2'], [], null);
        self::assertSame('imported', $result['results'][0]['status']);
        self::assertSame(1, $result['results'][0]['image']['external'] ?? null);
        self::assertStringContainsString('could not inspect', implode(' ', $result['results'][0]['warnings']));

        $backed = new LegacyAddonsImporter(
            $this->config,
            $this->pdo,
            static fn (string $path): array => [
                'format' => 'qcow2',
                'virtual_size' => 8388608,
                'backing_file' => 'base.qcow2',
            ],
            new TemplateRepository($this->config),
        );
        $result = $backed->import($this->addons, ['qemu/vios-adventerprisek9-m/hda.qcow2'], [], null);
        self::assertSame('imported', $result['results'][0]['status']);
        self::assertStringContainsString('backing file', implode(' ', $result['results'][0]['warnings']));
        self::assertSame('base.qcow2', $result['results'][0]['image']['backing_file'] ?? null);
    }

    /**
     * @param array<string, mixed> $scan
     * @return array<string, array<string, mixed>>
     */
    private function entries(array $scan): array
    {
        $entries = [];
        foreach ((array) ($scan['entries'] ?? []) as $entry) {
            self::assertIsArray($entry);
            $entries[(string) $entry['path']] = $entry;
        }

        return $entries;
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            self::fail('cannot create fixture directory ' . $directory);
        }
    }

    private function writeAddon(string $relative, string $content): void
    {
        $path = $this->addons . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            self::fail('cannot create fixture directory ' . $directory);
        }
        file_put_contents($path, $content);
    }

    private function insertRow(string $id, string $reference, string $sha256): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO images '
            . '(id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, created_at, external) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, 1, 1, :format, NULL, NULL, :created_at, 0)'
        );
        $statement->execute([
            ':id' => $id,
            ':kind' => 'qemu',
            ':name' => 'taken',
            ':reference' => $reference,
            ':path' => $this->config->imagesDir . '/qemu/' . $id . '/disk.qcow2',
            ':sha256' => $sha256,
            ':format' => 'qcow2',
            ':created_at' => time(),
        ]);
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
