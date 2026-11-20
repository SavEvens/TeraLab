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
use TeraLab\Api\System\BackupService;
use TeraLab\Api\System\SettingsService;
use ZipArchive;

final class BackupServiceTest extends TestCase
{
    private string $root;
    private Config $config;
    private SettingsService $settings;
    private BackupService $backup;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-backup-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        mkdir($this->root . '/data/templates', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
        $this->settings = new SettingsService($this->pdo);
        $this->backup = new BackupService($this->config, $this->pdo, $this->settings);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testBackupAndRestoreLabsSettingsAndTemplates(): void
    {
        file_put_contents($this->root . '/labs/Test.unl', $this->labXml());
        file_put_contents($this->root . '/data/templates/custom.yaml', "schema: 1\nid: custom\nname: Custom\nkind: qemu\noptions: {}\n");
        $this->settings->update(['session_ttl' => 900]);

        $archive = $this->backup->create();
        self::assertFileExists($archive['path']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive['path']) === true);
        self::assertNotFalse($zip->locateName('labs/Test.unl'));
        self::assertNotFalse($zip->locateName('templates/custom.yaml'));
        self::assertNotFalse($zip->locateName('settings.json'));
        $zip->close();

        unlink($this->root . '/labs/Test.unl');
        $contents = file_get_contents($archive['path']);
        self::assertIsString($contents);
        $upload = new UploadedFile(
            (new StreamFactory())->createStream($contents),
            'backup.zip',
            'application/zip',
            strlen($contents),
            UPLOAD_ERR_OK,
        );

        $result = $this->backup->restore($upload);
        self::assertGreaterThanOrEqual(1, $result['labs']);
        self::assertGreaterThanOrEqual(1, $result['templates']);
        self::assertTrue($result['settings']);
        self::assertFileExists($this->root . '/labs/Test.unl');
        self::assertSame(900, $this->settings->int('session_ttl', 3600));
        @unlink($archive['path']);
    }

    public function testExternalImagesRoundTripAndDanglingTargetsAreSkipped(): void
    {
        $targetDirectory = $this->root . '/addons';
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0770, true);
        }
        $target = $targetDirectory . '/hda.qcow2';
        file_put_contents($target, "QFI\xfb" . str_repeat('a', 40));

        $validLink = $this->linkedPath('qemu-linked');
        symlink($target, $validLink);
        $this->insertExternalRow('qemu-linked', $validLink, str_repeat('a', 64));

        $danglingLink = $this->linkedPath('qemu-dangling');
        symlink($targetDirectory . '/missing.qcow2', $danglingLink);
        $this->insertExternalRow('qemu-dangling', $danglingLink, str_repeat('b', 64));

        $archive = $this->backup->create();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive['path']) === true);
        $images = json_decode((string) $zip->getFromName('images.json'), true);
        $zip->close();
        self::assertIsArray($images);
        self::assertCount(2, $images);
        self::assertSame(1, $images[0]['external']);

        $this->pdo->exec('DELETE FROM images');
        $contents = file_get_contents($archive['path']);
        self::assertIsString($contents);
        $upload = new UploadedFile(
            (new StreamFactory())->createStream($contents),
            'backup.zip',
            'application/zip',
            strlen($contents),
            UPLOAD_ERR_OK,
        );
        $result = $this->backup->restore($upload);

        self::assertSame(1, $result['images']);
        self::assertSame(1, $result['images_skipped']);
        $rows = $this->pdo->query('SELECT id, external FROM images')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('qemu-linked', $rows[0]['id']);
        self::assertSame(1, $rows[0]['external']);
        self::assertFileExists($target);
        @unlink($archive['path']);
    }

    private function linkedPath(string $id): string
    {
        $directory = $this->config->imagesDir . '/qemu/' . $id;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            self::fail('cannot create linked image directory');
        }

        return $directory . '/hda.qcow2';
    }

    private function insertExternalRow(string $id, string $path, string $sha256): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO images '
            . '(id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, created_at, external) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, 44, 44, :format, NULL, NULL, :created_at, 1)'
        );
        $statement->execute([
            ':id' => $id,
            ':kind' => 'qemu',
            ':name' => 'hda.qcow2',
            ':reference' => $id,
            ':path' => $path,
            ':sha256' => $sha256,
            ':format' => 'qcow2',
            ':created_at' => time(),
        ]);
    }

    private function labXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<lab name="Restore Lab" id="11111111-2222-4333-8444-555555555555" version="1">'
            . '<topology><nodes><node id="1" name="PC1" type="vpcs" console="telnet" cpu="1" ram="128" ethernet="0"/>'
            . '</nodes><networks/></topology></lab>';
    }
}
