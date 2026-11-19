<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;
use TeraLab\Api\System\RestoreManager;

final class RestoreManagerTest extends TestCase
{
    private string $root;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-restore-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/restore', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/data/{,restore/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->root . '/data/restore');
        @rmdir($this->root . '/data');
        @rmdir($this->root);
    }

    public function testAppliesStagedDatabaseAndKeepsABackup(): void
    {
        file_put_contents($this->config->dbPath, 'old');
        file_put_contents($this->root . '/data/restore/database.sdb', 'new');
        file_put_contents($this->root . '/data/restore/pending', '1');

        RestoreManager::applyPending($this->config);

        self::assertSame('new', file_get_contents($this->config->dbPath));
        self::assertFileDoesNotExist($this->root . '/data/restore/database.sdb');
        self::assertFileDoesNotExist($this->root . '/data/restore/pending');
        self::assertNotSame([], glob($this->config->dbPath . '.pre-restore-*') ?: []);
    }

    public function testDoesNothingWithoutAMarker(): void
    {
        file_put_contents($this->config->dbPath, 'old');
        file_put_contents($this->root . '/data/restore/database.sdb', 'new');

        RestoreManager::applyPending($this->config);

        self::assertSame('old', file_get_contents($this->config->dbPath));
        self::assertFileExists($this->root . '/data/restore/database.sdb');
    }
}
