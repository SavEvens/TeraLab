<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Image\ImageStore;

final class ImageStoreTest extends TestCase
{
    private string $root;
    private Config $config;
    private PDO $pdo;
    private ImageStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-images-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();

        $this->store = new ImageStore(
            $this->config,
            $this->pdo,
            static fn (string $path): array => [
                'format' => 'qcow2',
                'virtual_size' => 8388608,
                'backing_file' => '',
                'sha256' => hash_file('sha256', $path),
            ],
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testStoresQCOW2WithChecksumAndMetadata(): void
    {
        $content = "QFI\xfb" . str_repeat("\0", 60);
        $image = $this->store->store($this->upload($content, 'disk.qcow2'), 'qemu', 'Base disk', null);

        self::assertSame('qemu-' . substr(hash('sha256', $content), 0, 16), $image['id']);
        self::assertSame(hash('sha256', $content), $image['sha256']);
        self::assertSame(strlen($content), $image['size']);
        self::assertSame(8388608, $image['virtual_size']);
        self::assertSame('qcow2', $image['format']);
        self::assertSame('disk.qcow2', $image['reference']);
        self::assertFileExists((string) $image['path']);
        self::assertFileExists(dirname((string) $image['path']) . '/meta.json');
        self::assertCount(1, $this->store->all());
    }

    public function testReferencesAreUniqueAndRenameable(): void
    {
        $first = $this->store->store($this->upload("QFI\xfb" . str_repeat("a", 60), 'disk.qcow2'), 'qemu', null, null);
        $second = $this->store->store($this->upload("QFI\xfb" . str_repeat("b", 60), 'disk.qcow2'), 'qemu', null, null);

        self::assertSame('disk.qcow2', $first['reference']);
        self::assertSame('disk-2.qcow2', $second['reference']);

        $renamed = $this->store->setReference((string) $second['id'], 'veos-4.22.0F');
        self::assertSame('veos-4.22.0F', $renamed['reference']);

        $this->expectException(HttpException::class);
        $this->store->setReference((string) $second['id'], 'disk.qcow2');
    }

    public function testRejectsUnsafeReference(): void
    {
        $image = $this->store->store($this->upload("QFI\xfb" . str_repeat("c", 60), 'safe.qcow2'), 'qemu', null, null);

        $this->expectException(HttpException::class);
        $this->store->setReference((string) $image['id'], '../escape.qcow2');
    }

    public function testDuplicateContentIsIdempotent(): void
    {
        $content = "QFI\xfb" . str_repeat("a", 60);
        $first = $this->store->store($this->upload($content, 'disk.qcow2'), 'qemu', null, null);
        $second = $this->store->store($this->upload($content, 'copy.qcow2'), 'qemu', null, null);

        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, $this->store->all());
        self::assertFileDoesNotExist($this->config->imagesDir . '/tmp/upload-missing');
    }

    public function testIOLImageIsStoredExecutable(): void
    {
        $content = "\x7fELF" . str_repeat("\0", 60);
        $image = $this->store->store($this->upload($content, 'iol.bin'), 'iol', 'IOL', null);
        self::assertSame('iol', $image['kind']);
        self::assertNotSame(0, fileperms((string) $image['path']) & 0111);
        self::assertNotSame(0, fileperms((string) $image['path']) & 0004);
        self::assertNotSame(0, fileperms(dirname((string) $image['path'])) & 0001);
    }

    public function testRejectsInvalidMagicWithoutRow(): void
    {
        try {
            $this->store->store($this->upload('not a qcow2 image', 'bad.qcow2'), 'qemu', null, null);
            self::fail('expected invalid magic to be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }

        self::assertCount(0, $this->store->all());
        self::assertSame([], glob($this->config->imagesDir . '/tmp/upload-*') ?: []);
    }

    public function testRejectsOversizedUpload(): void
    {
        $this->config = new Config(
            root: $this->root,
            dataDir: $this->root . '/data',
            dbPath: $this->root . '/data/database.sdb',
            labsDir: $this->root . '/labs',
            cookieSecure: false,
            sessionTtl: 3600,
            environment: 'test',
            agentSocket: $this->root . '/data/agent.sock',
            agentSecretFile: $this->root . '/data/agent.secret',
            imagesDir: $this->root . '/data/images',
            maxUploadBytes: 8,
        );
        $store = new ImageStore($this->config, $this->pdo);

        $this->expectException(HttpException::class);
        try {
            $store->store($this->upload("QFI\xfb" . str_repeat("\0", 64), 'big.qcow2'), 'qemu', null, null);
        } catch (HttpException $exception) {
            self::assertSame(413, $exception->status);
            throw $exception;
        }
    }

    public function testRejectsWhenStoreQuotaIsExhausted(): void
    {
        $config = new Config(
            root: $this->root,
            dataDir: $this->root . '/data',
            dbPath: $this->root . '/data/database.sdb',
            labsDir: $this->root . '/labs',
            cookieSecure: false,
            sessionTtl: 3600,
            environment: 'test',
            agentSocket: $this->root . '/data/agent.sock',
            agentSecretFile: $this->root . '/data/agent.secret',
            imagesDir: $this->root . '/data/images',
            maxUploadBytes: 1048576,
            imageQuotaBytes: 10,
        );
        $store = new ImageStore($config, $this->pdo, static fn (string $path): array => [
            'format' => 'qcow2',
            'virtual_size' => 1024,
            'backing_file' => '',
        ]);

        $this->expectException(HttpException::class);
        try {
            $store->store($this->upload("QFI\xfb" . str_repeat("q", 64), 'quota.qcow2'), 'qemu', null, null);
        } catch (HttpException $exception) {
            self::assertSame(507, $exception->status);
            throw $exception;
        }
    }

    public function testAcceptsISO9660(): void
    {
        $content = str_repeat("\0", 0x9000);
        $content = substr_replace($content, 'CD001', 0x8001, 5);
        $image = $this->store->store($this->upload($content, 'boot.iso'), 'iso', null, null);

        self::assertSame('iso', $image['format']);
        self::assertSame(hash('sha256', $content), $image['sha256']);
    }

    public function testRejectsWrongExtension(): void
    {
        $this->expectException(HttpException::class);
        $this->store->store($this->upload("QFI\xfb" . str_repeat("\0", 8), 'disk.iso'), 'qemu', null, null);
    }

    public function testDeleteRemovesStoreAndGuardsActiveImages(): void
    {
        $content = "QFI\xfb" . str_repeat("z", 64);
        $image = $this->store->store($this->upload($content, 'disk.qcow2'), 'qemu', null, null);

        try {
            $this->store->delete((string) $image['id'], [(string) $image['path']]);
            self::fail('expected an active image to be refused');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
        }

        $this->store->delete((string) $image['id']);
        self::assertNull($this->store->find((string) $image['id']));
        self::assertDirectoryDoesNotExist(dirname((string) $image['path']));
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $stream = (new StreamFactory())->createStream($content);

        return new UploadedFile($stream, $name, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);
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
