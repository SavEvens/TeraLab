<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Labs\UnlWriter;
use TeraLab\Api\Snapshots\SnapshotRepository;
use TeraLab\Api\System\MaintenanceService;
use TeraLab\Api\System\SettingsService;

final class MaintenanceServiceTest extends TestCase
{
    private string $root;
    private Config $config;
    private PDO $pdo;
    private SettingsService $settings;
    private MaintenanceService $maintenance;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-maintenance-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
        $this->settings = new SettingsService($this->pdo);
        $this->maintenance = $this->maintenanceWith($this->labService());
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testRetentionDeletesOldRowsAndKeepsCurrentOnes(): void
    {
        $now = time();
        $this->insertUser('maintenance-user');
        $this->insertSession('expired', 'maintenance-user', $now - 10, $now - 10);
        $this->insertSession('idle', 'maintenance-user', $now - 7200, $now + 3600);
        $this->insertSession('current', 'maintenance-user', $now, $now + 3600);

        $this->insertAttempt('old', $now - 31 * 86400);
        $this->insertAttempt('current', $now - 60);
        $this->insertAudit('old', $now - 91 * 86400);
        $this->insertAudit('current', $now - 60);

        $this->insertJob('old-terminal', 'succeeded', $now - 31 * 86400);
        $this->insertJob('running', 'running', $now - 31 * 86400);
        $this->insertJob('current-terminal', 'succeeded', $now - 60);
        for ($index = 0; $index < 105; $index++) {
            $this->insertJob('bulk-' . $index, 'failed', $now - 100 - $index);
        }

        for ($index = 0; $index < 5; $index++) {
            $this->insertSnapshot('lab-retained', 'snap-' . $index, $now - $index);
        }
        $this->settings->update(['demo_max_snapshots_per_lab' => 3]);
        $this->settings->update(['retention_jobs_max_rows' => 100]);

        $summary = $this->maintenance->runNow();

        self::assertSame(2, $summary['sessions_deleted']);
        self::assertSame(1, $summary['login_attempts_deleted']);
        self::assertSame(1, $summary['audit_log_deleted']);
        self::assertGreaterThanOrEqual(1, $summary['jobs_deleted']);
        self::assertSame(2, $summary['snapshots_deleted']);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        self::assertSame(100, (int) $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE state != 'running'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE id = 'running'")->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM lab_snapshots WHERE lab_id = 'lab-retained'"
        )->fetchColumn());
        self::assertNotNull($this->maintenance->lastRun());
    }

    public function testRunIfDueHonoursTheConfiguredInterval(): void
    {
        $this->settings->update(['maintenance_interval_seconds' => 3600]);
        $first = $this->maintenance->runIfDue();
        self::assertIsArray($first);
        self::assertNull($this->maintenance->runIfDue(), 'a second run inside the interval must be skipped');

        $this->setLastRun(time() - 3700);
        self::assertIsArray($this->maintenance->runIfDue());
    }

    public function testExpiredTrashIsRemovedButCurrentTrashIsKept(): void
    {
        $labs = $this->labService();
        $old = $labs->create(['name' => 'Old Trash', 'nodes' => []]);
        $new = $labs->create(['name' => 'New Trash', 'nodes' => []]);
        $labs->delete((string) $old['id'], 'maintenance-user');
        $labs->delete((string) $new['id'], 'maintenance-user');
        $this->backdateTrash((string) $old['id'], time() - 31 * 86400);

        $summary = $this->maintenance->runNow();

        self::assertSame(1, $summary['trash_deleted']);
        self::assertCount(1, $labs->trash());
        self::assertSame((string) $new['id'], (string) $labs->trash()[0]['id']);
    }

    public function testMaintenanceKeysAreNotSettingsDefaults(): void
    {
        self::assertArrayNotHasKey(MaintenanceService::LAST_RUN_KEY, SettingsService::DEFAULTS);
    }

    private function maintenanceWith(?LabService $labs): MaintenanceService
    {
        return new MaintenanceService($this->pdo, $this->settings, $this->config, $labs);
    }

    private function labService(): LabService
    {
        $agent = new AgentClient(
            $this->config,
            static fn (string $method, string $path, array $payload): array => [200, '{"ok":true,"result":{}}'],
        );

        return new LabService(
            $this->config,
            new UnlParser(),
            new UnlWriter(),
            $agent,
            new LabLocks($this->pdo),
            new ImageReferences($this->pdo, $this->config),
            new SnapshotRepository($this->pdo),
        );
    }

    private function insertUser(string $id): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :hash, :role, -1, 0, :created, :created)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $id,
            ':hash' => 'unused',
            ':role' => 'admin',
            ':created' => time(),
        ]);
    }

    private function insertSession(string $id, string $userId, int $lastSeen, int $expires): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:id_hash, :user_id, :csrf, :ip, :agent, :created, :last_seen, :expires)'
        );
        $statement->execute([
            ':id_hash' => $id,
            ':user_id' => $userId,
            ':csrf' => 'csrf',
            ':ip' => '127.0.0.1',
            ':agent' => 'phpunit',
            ':created' => time() - 7200,
            ':last_seen' => $lastSeen,
            ':expires' => $expires,
        ]);
    }

    private function insertAttempt(string $name, int $at): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip, attempted_at, success) VALUES (:name, :ip, :at, 0)'
        );
        $statement->execute([':name' => $name, ':ip' => '127.0.0.1', ':at' => $at]);
    }

    private function insertAudit(string $id, int $at): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (id, ts, user_id, ip, action, target, result, details_json) '
            . 'VALUES (:id, :ts, NULL, :ip, :action, NULL, :result, :details)'
        );
        $statement->execute([
            ':id' => $id,
            ':ts' => $at,
            ':ip' => '127.0.0.1',
            ':action' => 'test',
            ':result' => 'success',
            ':details' => '{}',
        ]);
    }

    private function insertJob(string $id, string $state, int $createdAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (id, type, state, progress, created_at) VALUES (:id, :type, :state, 0, :created)'
        );
        $statement->execute([
            ':id' => $id,
            ':type' => 'lab.start',
            ':state' => $state,
            ':created' => $createdAt,
        ]);
    }

    private function insertSnapshot(string $labID, string $id, int $createdAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO lab_snapshots (id, lab_id, name, revision, xml, created_by, created_at) '
            . 'VALUES (:id, :lab_id, :name, 1, :xml, NULL, :created)'
        );
        $statement->execute([
            ':id' => $id,
            ':lab_id' => $labID,
            ':name' => $id,
            ':xml' => '<lab/>',
            ':created' => $createdAt,
        ]);
    }

    private function backdateTrash(string $labID, int $deletedAt): void
    {
        $directory = $this->config->labsDir . '/.trash';
        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $metadata = json_decode((string) file_get_contents($file), true);
            if (!is_array($metadata) || (string) ($metadata['id'] ?? '') !== $labID) {
                continue;
            }
            $metadata['deleted_at'] = $deletedAt;
            file_put_contents($file, json_encode($metadata, JSON_THROW_ON_ERROR));
        }
    }

    private function setLastRun(int $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([
            ':key' => MaintenanceService::LAST_RUN_KEY,
            ':value' => (string) $value,
            ':updated_at' => $value,
        ]);
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
