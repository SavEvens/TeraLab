<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

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

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-backup-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        mkdir($this->root . '/data/templates', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $pdo = (new Database($this->config))->pdo();
        (new Migrator($pdo, $this->config->migrationsDir()))->migrate();
        $this->settings = new SettingsService($pdo);
        $this->backup = new BackupService($this->config, $pdo, $this->settings);
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

    private function labXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<lab name="Restore Lab" id="11111111-2222-4333-8444-555555555555" version="1">'
            . '<topology><nodes><node id="1" name="PC1" type="vpcs" console="telnet" cpu="1" ram="128" ethernet="0"/>'
            . '</nodes><networks/></topology></lab>';
    }
}
