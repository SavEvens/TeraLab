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

final class ImageReferencesTest extends TestCase
{
    private string $root;
    private Config $config;
    private PDO $pdo;
    private ImageReferences $references;
    private string $imagePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-refs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
        $this->references = new ImageReferences($this->pdo, $this->config);

        $directory = $this->config->imagesDir . '/qemu/img-1';
        mkdir($directory, 0755, true);
        $this->imagePath = $directory . '/base.qcow2';
        file_put_contents($this->imagePath, "QFI\xfb" . str_repeat("\0", 32));
        $statement = $this->pdo->prepare(
            'INSERT INTO images (id, kind, name, reference, path, sha256, size, virtual_size, format, created_at) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, :created_at)'
        );
        $statement->execute([
            ':id' => 'img-1',
            ':kind' => 'qemu',
            ':name' => 'Base',
            ':reference' => 'base.qcow2',
            ':path' => $this->imagePath,
            ':sha256' => hash('sha256', 'base'),
            ':size' => 36,
            ':virtual_size' => 36,
            ':format' => 'qcow2',
            ':created_at' => time(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testResolvesReferenceAndLegacyPaths(): void
    {
        self::assertSame($this->imagePath, $this->references->resolve('base.qcow2'));
        self::assertSame(
            (string) realpath($this->imagePath),
            $this->references->resolve($this->imagePath)
        );
        self::assertSame(
            (string) realpath($this->imagePath),
            $this->references->resolve('qemu/img-1/base.qcow2')
        );
        self::assertNull($this->references->resolve('missing.qcow2'));
        self::assertNull($this->references->resolve('/etc/passwd'));
        self::assertNull($this->references->resolve('../../etc/passwd'));
    }

    public function testReferenceForPathAndPortableValue(): void
    {
        self::assertSame('base.qcow2', $this->references->referenceForPath($this->imagePath));
        self::assertNull($this->references->referenceForPath('/tmp/other.qcow2'));

        self::assertSame('base.qcow2', $this->references->portableValue($this->imagePath));
        self::assertSame('base.qcow2', $this->references->portableValue('base.qcow2'));
        self::assertSame('other.qcow2', $this->references->portableValue('/tmp/elsewhere/other.qcow2'));
        self::assertSame('other.qcow2', $this->references->portableValue('sub/dir/other.qcow2'));
    }

    public function testUniqueReferences(): void
    {
        self::assertSame('base-2.qcow2', $this->references->unique('base.qcow2'));
        self::assertSame('veos', $this->references->unique('veos'));
        self::assertTrue($this->references->exists('base.qcow2'));
        self::assertFalse($this->references->exists('veos'));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
