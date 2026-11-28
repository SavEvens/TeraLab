<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;

final class DatabasePermissionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-db-perms-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDatabaseWalAndShmAreOwnerOnly(): void
    {
        $config = Config::forTests($this->root, $this->root . '/data');

        $pdo = (new Database($config))->pdo();
        $pdo->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->prepare('INSERT INTO probe (value) VALUES (:value)')->execute([':value' => 'x']);
        clearstatcache();

        self::assertFileExists($config->dbPath);
        self::assertSame(0600, fileperms($config->dbPath) & 0777);

        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $config->dbPath . $suffix;
            if (!file_exists($sidecar)) {
                // SQLite versions without WAL support never create the file.
                continue;
            }
            self::assertSame(0600, fileperms($sidecar) & 0777, $suffix . ' must be owner-only');
        }

        // The database directory is created by the API when absent and must
        // not inherit a permissive umask.
        self::assertSame(0750, fileperms(dirname($config->dbPath)) & 0777);
    }

    public function testExistingWorldReadableDatabaseIsRestrictedOnConnect(): void
    {
        $directory = $this->root . '/data';
        mkdir($directory, 0750, true);
        $config = Config::forTests($this->root, $directory);

        $raw = new PDO('sqlite:' . $config->dbPath);
        $raw->exec('CREATE TABLE probe (id INTEGER)');
        $raw = null;
        chmod($config->dbPath, 0644);

        (new Database($config))->pdo();
        clearstatcache();

        self::assertSame(0600, fileperms($config->dbPath) & 0777);
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
