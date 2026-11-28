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

    public function testConcurrentRequestBurstDoesNotSurfaceDatabaseLocked(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the burst writer test');
        }
        $config = Config::forTests($this->root, $this->root . '/data');
        $pdo = (new Database($config))->pdo();
        $pdo->exec('CREATE TABLE burst (id INTEGER PRIMARY KEY AUTOINCREMENT, worker INTEGER NOT NULL, sequence INTEGER NOT NULL)');

        $workers = 4;
        $perWorker = 20;
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = <<<'CHILD'
require getenv('TERALAB_BURST_AUTOLOAD');
$root = getenv('TERALAB_BURST_ROOT');
$worker = (int) getenv('TERALAB_BURST_WORKER');
$perWorker = (int) getenv('TERALAB_BURST_COUNT');
$config = TeraLab\Api\Config::forTests($root, $root . '/data');
$pdo = (new TeraLab\Api\Database\Database($config))->pdo();
$statement = $pdo->prepare('INSERT INTO burst (worker, sequence) VALUES (:worker, :sequence)');
for ($sequence = 0; $sequence < $perWorker; $sequence++) {
    TeraLab\Api\Database\BusyRetry::retry(
        static function () use ($statement, $worker, $sequence): void {
            $statement->execute([':worker' => $worker, ':sequence' => $sequence]);
        },
        20,
        50,
    );
}
CHILD;

        $processes = [];
        try {
            for ($worker = 0; $worker < $workers; $worker++) {
                $env = getenv();
                $env['TERALAB_BURST_AUTOLOAD'] = $autoload;
                $env['TERALAB_BURST_ROOT'] = $this->root;
                $env['TERALAB_BURST_WORKER'] = (string) $worker;
                $env['TERALAB_BURST_COUNT'] = (string) $perWorker;
                $process = proc_open(
                    [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    $env,
                );
                self::assertIsResource($process);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($processes as $entry) {
                $stdout = (string) stream_get_contents($entry['pipes'][1]);
                $stderr = (string) stream_get_contents($entry['pipes'][2]);
                foreach ([1, 2] as $index) {
                    if (is_resource($entry['pipes'][$index])) {
                        fclose($entry['pipes'][$index]);
                    }
                }
                $status = proc_close($entry['process']);
                self::assertSame(0, $status, 'burst worker failed: ' . $stdout . $stderr);
                self::assertStringNotContainsString('database is locked', $stderr);
                self::assertStringNotContainsString('database table is locked', $stderr);
            }
        } finally {
            foreach ($processes as $entry) {
                if (is_resource($entry['process'])) {
                    foreach ($entry['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_terminate($entry['process']);
                    proc_close($entry['process']);
                }
            }
        }

        self::assertSame(
            $workers * $perWorker,
            (int) $pdo->query('SELECT COUNT(*) FROM burst')->fetchColumn()
        );
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
