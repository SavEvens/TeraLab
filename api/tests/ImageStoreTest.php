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
        clearstatcache();

        self::assertSame('iol', $image['kind']);
        self::assertSame(0750, fileperms((string) $image['path']) & 0777);
        self::assertNotSame(0, fileperms((string) $image['path']) & 0111);
        self::assertSame(0, fileperms((string) $image['path']) & 0002);
        self::assertNotSame(0, fileperms(dirname((string) $image['path'])) & 0001);

        $gid = $this->imageGroupGid();
        if ($gid !== null) {
            self::assertSame($gid, filegroup((string) $image['path']));
        }
    }

    public function testStoredImageFilesAreNotGroupOrWorldWritable(): void
    {
        $image = $this->store->store(
            $this->upload("QFI\xfb" . str_repeat("\0", 60), 'disk.qcow2'),
            'qemu',
            null,
            null,
        );
        clearstatcache();

        $path = (string) $image['path'];
        self::assertSame(0640, fileperms($path) & 0777, 'image must be owner-write and group-read only');
        self::assertSame(0, fileperms($path) & 0022, 'image must not be group or world writable');
        $metadata = dirname($path) . '/meta.json';
        self::assertSame(0640, fileperms($metadata) & 0777);
        self::assertSame(0, fileperms($metadata) & 0002);

        $gid = $this->imageGroupGid();
        if ($gid !== null) {
            self::assertSame($gid, filegroup($path));
            self::assertSame($gid, filegroup($metadata));
        }

        $temporaryDirectory = $this->config->imagesDir . '/tmp';
        self::assertSame(0770, fileperms($temporaryDirectory) & 0777);
        self::assertSame(0, fileperms($temporaryDirectory) & 0002, 'upload directory must not be world writable');
        self::assertSame([], glob($temporaryDirectory . '/upload-*') ?: []);
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

    public function testQuotaExcludesLinkedImages(): void
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
            imageQuotaBytes: 100,
        );
        $this->insertImageRow('qemu-linked', $config->imagesDir . '/qemu/qemu-linked/hda.qcow2', 1000000, 1, str_repeat('a', 64), 'linked');
        $store = new ImageStore($config, $this->pdo, static fn (string $path): array => [
            'format' => 'qcow2',
            'virtual_size' => 1024,
            'backing_file' => '',
        ]);

        $image = $store->store($this->upload("QFI\xfb" . str_repeat('b', 64), 'small.qcow2'), 'qemu', null, null);

        self::assertSame(0, $image['external']);
        self::assertCount(2, $store->all());
    }

    public function testDeleteLinkedImageRemovesRowAndLinkButKeepsTarget(): void
    {
        $target = $this->root . '/addons/hda.qcow2';
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0770, true);
        }
        file_put_contents($target, 'legacy image');

        $id = 'qemu-linked';
        $directory = $this->config->imagesDir . '/qemu/' . $id;
        mkdir($directory, 0750, true);
        $link = $directory . '/hda.qcow2';
        symlink($target, $link);
        $this->insertImageRow($id, $link, 12, 1, str_repeat('c', 64), 'csr1000vng');

        $image = $this->store->find($id);
        self::assertIsArray($image);
        self::assertSame(1, $image['external']);

        $this->store->delete($id);

        self::assertNull($this->store->find($id));
        self::assertFalse(is_link($link));
        self::assertDirectoryDoesNotExist($directory);
        self::assertFileExists($target);
        self::assertSame('legacy image', file_get_contents($target));
    }

    public function testSetReferenceNormalisesAnExistingImageMode(): void
    {
        $image = $this->store->store(
            $this->upload("QFI\xfb" . str_repeat("r", 60), 'legacy.qcow2'),
            'qemu',
            null,
            null,
        );
        $path = (string) $image['path'];
        chmod($path, 0644);

        $this->store->setReference((string) $image['id'], 'normalised');
        clearstatcache();

        self::assertSame(0640, fileperms($path) & 0777);
        $gid = $this->imageGroupGid();
        if ($gid !== null) {
            self::assertSame($gid, filegroup($path));
        }
    }

    public function testReferenceSetDoesNotTouchAnAdoptedTarget(): void
    {
        $target = $this->root . '/addons/hda.qcow2';
        mkdir(dirname($target), 0770, true);
        file_put_contents($target, 'legacy image');
        chmod($target, 0644);

        $directory = $this->config->imagesDir . '/qemu/qemu-adopted';
        mkdir($directory, 0750, true);
        $link = $directory . '/hda.qcow2';
        symlink($target, $link);
        $this->insertImageRow('qemu-adopted', $link, 12, 1, str_repeat('d', 64), 'adopted');

        $this->store->setReference('qemu-adopted', 'adopted-ref');
        clearstatcache();

        self::assertSame(0644, fileperms($target) & 0777, 'an adopted legacy file is never modified');
        self::assertTrue(is_link($link));
    }

    public function testImageDirectoriesStayTraversableUnderTheServiceUmask(): void
    {
        $previous = umask(0077);
        try {
            $image = $this->store->store(
                $this->upload("QFI\xfb" . str_repeat("u", 60), 'umask.qcow2'),
                'qemu',
                null,
                null,
            );
            $binary = $this->store->store(
                $this->upload("\x7fELF" . str_repeat("\0", 60), 'tool.bin'),
                'iol',
                null,
                null,
            );
        } finally {
            umask($previous);
        }
        clearstatcache();

        $path = (string) $image['path'];
        self::assertSame(0755, fileperms(dirname($path)) & 0777, 'per-image directory must be traversable');
        self::assertSame(0755, fileperms(dirname($path, 2)) & 0777, 'kind directory must be traversable');
        self::assertSame(0640, fileperms($path) & 0777);

        $binaryPath = (string) $binary['path'];
        self::assertSame(0755, fileperms(dirname($binaryPath)) & 0777);
        self::assertSame(0755, fileperms(dirname($binaryPath, 2)) & 0777);
        self::assertSame(0750, fileperms($binaryPath) & 0777);

        self::assertSame(0755, fileperms($this->config->imagesDir) & 0777, 'image root must be traversable');

        $gid = $this->imageGroupGid();
        if ($gid !== null) {
            self::assertSame($gid, filegroup($path));
            self::assertSame($gid, filegroup($binaryPath));
        }
    }

    public function testMissingImageGroupIsLoggedOnceWithoutFailingUploads(): void
    {
        if ($this->imageGroupGid() !== null) {
            self::markTestSkipped('teralab-images exists on this host, so no warning is expected.');
        }

        $log = $this->root . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            $first = $this->store->store(
                $this->upload("QFI\xfb" . str_repeat("g", 60), 'one.qcow2'),
                'qemu',
                null,
                null,
            );
            $second = $this->store->store(
                $this->upload("QFI\xfb" . str_repeat("h", 60), 'two.qcow2'),
                'qemu',
                null,
                null,
            );
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
        }
        clearstatcache();

        self::assertNotSame($first['id'], $second['id']);
        $contents = is_file($log) ? (string) file_get_contents($log) : '';
        self::assertSame(1, substr_count($contents, 'TeraLab image store:'));
    }

    private function imageGroupGid(): ?int
    {
        if (!function_exists('posix_getgrnam')) {
            return null;
        }
        $group = @posix_getgrnam('teralab-images');

        return is_array($group) ? (int) $group['gid'] : null;
    }

    private function insertImageRow(
        string $id,
        string $path,
        int $size,
        int $external,
        string $sha256,
        string $reference,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO images '
            . '(id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, created_at, external) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, NULL, NULL, :created_at, :external)'
        );
        $statement->execute([
            ':id' => $id,
            ':kind' => 'qemu',
            ':name' => $reference,
            ':reference' => $reference,
            ':path' => $path,
            ':sha256' => $sha256,
            ':size' => $size,
            ':virtual_size' => $size,
            ':format' => 'qcow2',
            ':created_at' => time(),
            ':external' => $external,
        ]);
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
