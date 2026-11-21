<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TeraLab\Api\Config;
use TeraLab\Api\Database\BusyRetry;
use TeraLab\Api\Database\Database;

final class BusyRetryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-busy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data', 0770, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDatabaseConfiguresASufficientBusyTimeout(): void
    {
        $config = Config::forTests($this->root, $this->root . '/data');
        $pdo = (new Database($config))->pdo();

        self::assertSame(15000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testRetriesBusyErrorsUntilTheCallableSucceeds(): void
    {
        $calls = 0;
        $result = BusyRetry::retry(function () use (&$calls): string {
            $calls++;
            if ($calls < 3) {
                throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            }

            return 'ok';
        }, 3, 1);

        self::assertSame('ok', $result);
        self::assertSame(3, $calls);
    }

    public function testGivesUpAfterTheAttemptCount(): void
    {
        $calls = 0;
        try {
            BusyRetry::retry(function () use (&$calls): void {
                $calls++;
                throw new PDOException('database is locked');
            }, 2, 1);
        } catch (PDOException $exception) {
            self::assertStringContainsString('database is locked', $exception->getMessage());
        }
        self::assertSame(2, $calls);
    }

    public function testNonBusyErrorsAreNotRetried(): void
    {
        $calls = 0;
        try {
            BusyRetry::retry(function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('inventory is unreachable');
            }, 3, 1);
        } catch (RuntimeException $exception) {
            self::assertSame('inventory is unreachable', $exception->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testNonBusySqliteErrorsAreNotRetried(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $calls = 0;
        try {
            BusyRetry::retry(function () use ($pdo, &$calls): void {
                $calls++;
                $pdo->exec('INSERT INTO missing_table VALUES (1)');
            }, 3, 1);
            self::fail('a missing table must not be retried');
        } catch (PDOException $exception) {
            self::assertStringContainsString('no such table', $exception->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testBusyClassification(): void
    {
        self::assertTrue(BusyRetry::isBusy(new PDOException('database is locked')));
        self::assertTrue(BusyRetry::isBusy(new PDOException('SQLITE_BUSY: database is locked')));
        self::assertFalse(BusyRetry::isBusy(new PDOException('no such table: events')));
        self::assertFalse(BusyRetry::isBusy(new RuntimeException('database is corrupt')));
    }

    /**
     * A second connection holds the write lock; the helper must wait for it and
     * still commit the write instead of surfacing SQLITE_BUSY.
     */
    public function testRetryWaitsForAnotherWriter(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required to hold a second SQLite writer');
        }
        $database = $this->root . '/data/busy.sdb';
        $ready = $this->root . '/ready';
        $writer = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $writer->exec('CREATE TABLE events (name TEXT NOT NULL)');

        $code = <<<'CHILD'
$db = getenv('TERALAB_BUSY_DB');
$ready = getenv('TERALAB_BUSY_READY');
$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('BEGIN IMMEDIATE');
file_put_contents($ready, 'ready');
usleep(900000);
$pdo->exec('COMMIT');
CHILD;

        $env = getenv();
        $env['TERALAB_BUSY_DB'] = $database;
        $env['TERALAB_BUSY_READY'] = $ready;
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process);

        try {
            for ($attempt = 0; $attempt < 300 && !is_file($ready); $attempt++) {
                usleep(10000);
            }
            self::assertFileExists($ready, 'the second writer did not acquire the lock');

            $writer->exec('PRAGMA busy_timeout = 0');
            $result = BusyRetry::retry(
                fn (): int|false => $writer->exec("INSERT INTO events (name) VALUES ('retried')"),
                12,
                100,
            );

            self::assertNotFalse($result);
            self::assertSame(1, (int) $writer->query('SELECT COUNT(*) FROM events')->fetchColumn());
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_close($process);
        }

        self::assertSame(0, $status, 'the second writer must commit cleanly');
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
