<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Jobs\JobService;

final class BootstrapTest extends TestCase
{
    private string $root;
    private Config $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-bootstrap-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAppCreateMigratesOnceAndSkipsWhenTheMarkerIsCurrent(): void
    {
        (new App($this->config))->create();
        $marker = $this->config->dataDir . '/.bootstrap.json';
        self::assertFileExists($marker);
        $pdo = (new Database($this->config))->pdo();
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version = \'001_initial.sql\'')->fetchColumn()
        );

        // A stale marker (code/schema change) runs the migrator again.
        file_put_contents($marker, json_encode(['fingerprint' => 'stale'], JSON_THROW_ON_ERROR));
        (new App($this->config))->create();
        $restored = json_decode((string) file_get_contents($marker), true);
        self::assertIsArray($restored);
        self::assertNotSame('stale', $restored['fingerprint'] ?? null);

        // Removing the bookkeeping table proves the next create does not run
        // migrations: a migrated schema would recreate it.
        $pdo->exec('DROP TABLE schema_migrations');
        (new App($this->config))->create();
        self::assertSame(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'"
            )->fetchColumn(),
            'App::create() must not migrate while the bootstrap marker is current'
        );
    }

    public function testJobReconcileIsIntervalBoundedAcrossAppCreates(): void
    {
        (new App($this->config))->create();
        $pdo = (new Database($this->config))->pdo();
        $jobs = new JobService($pdo);
        self::assertNotNull($jobs->lastReconcileAt());

        $pdo->exec(
            "INSERT INTO jobs (id, type, state, progress, created_at) "
            . "VALUES ('interrupted', 'lab.start', 'running', 0, " . (time() - 7200) . ')'
        );

        // The marker and the reconcile timestamp are both current: no write.
        (new App($this->config))->create();
        self::assertSame(
            'running',
            (string) $pdo->query("SELECT state FROM jobs WHERE id = 'interrupted'")->fetchColumn()
        );

        // Age the timestamp past the interval and the next create reconciles.
        $statement = $pdo->prepare('UPDATE settings SET value = :value WHERE key = :key');
        $statement->execute([
            ':value' => (string) (time() - JobService::RECONCILE_INTERVAL_SECONDS - 5),
            ':key' => JobService::LAST_RECONCILE_KEY,
        ]);
        (new App($this->config))->create();
        self::assertSame(
            'failed',
            (string) $pdo->query("SELECT state FROM jobs WHERE id = 'interrupted'")->fetchColumn()
        );
    }

    public function testBootstrapMarkerIsNotASettingsDefault(): void
    {
        self::assertFileDoesNotExist($this->config->dataDir . '/.bootstrap.json');
        (new App($this->config))->create();
        self::assertFileExists($this->config->dataDir . '/.bootstrap.json');
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
