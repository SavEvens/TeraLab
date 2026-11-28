<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;

final class MigrationTest extends TestCase
{
    private string $root;
    private Config $config;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-migration-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testFreshInstallInsertsUsersWithTheUserRoleByDefault(): void
    {
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();

        $this->pdo->exec(
            "INSERT INTO users (id, username, password_hash, created_at, updated_at) "
            . "VALUES ('fresh-user', 'fresh-user', 'unused', 1, 1)"
        );
        $role = $this->pdo->query("SELECT role FROM users WHERE id = 'fresh-user'")->fetchColumn();

        self::assertSame('user', $role);

        $default = $this->columnDefault('role');
        self::assertSame("'user'", $default);
    }

    public function testRoleDefaultMigrationRebuildsUsersAndPreservesRowsAndChildren(): void
    {
        $partial = $this->root . '/migrations-partial';
        mkdir($partial, 0770, true);
        $this->buildOldMigrations($partial);
        (new Migrator($this->pdo, $partial))->migrate();

        self::assertSame("'admin'", $this->columnDefault('role'));

        $now = 1700000000;
        $this->pdo->exec(
            "INSERT INTO users (id, username, email, name, password_hash, role, expiration, created_at, updated_at) "
            . "VALUES ('admin-one', 'admin-one', 'admin@example.test', 'Admin', 'argon-hash', 'admin', -1, {$now}, {$now})"
        );
        $legacy = password_hash('random-imported-password', PASSWORD_ARGON2ID);
        $statement = $this->pdo->prepare(
            'INSERT INTO users '
            . '(id, username, password_hash, legacy_password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :legacy, :role, :expiration, :guest, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => 'legacy-user',
            ':username' => 'legacy-user',
            ':password_hash' => $legacy,
            ':legacy' => hash('sha256', 'Legacy-Pass-2026!'),
            ':role' => 'user',
            ':expiration' => $now + 3600,
            ':guest' => 1,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $this->pdo->exec(
            "INSERT INTO sessions (id_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen_at, expires_at) "
            . "VALUES ('session-1', 'admin-one', 'csrf', '127.0.0.1', 'phpunit', 1, 1, 9999999999)"
        );
        $this->pdo->exec(
            "INSERT INTO api_tokens (id, user_id, name, token_hash, scopes, created_at) "
            . "VALUES ('token-1', 'legacy-user', 'token', 'hash', 'read', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO permissions (lab_id, user_id, role, created_at) VALUES ('lab-1', 'admin-one', 'editor', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO lab_locks (lab_id, user_id, created_at, expires_at) VALUES ('lab-1', 'legacy-user', 1, 9999999999)"
        );

        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();

        // Existing rows keep every value, including the legacy hash and the
        // guest flag; only the table default changed.
        $rows = $this->pdo->query('SELECT * FROM users ORDER BY username')->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('admin', (string) $rows[0]['role']);
        self::assertSame('Admin', (string) $rows[0]['name']);
        self::assertSame('admin@example.test', (string) $rows[0]['email']);
        self::assertSame('user', (string) $rows[1]['role']);
        self::assertSame(hash('sha256', 'Legacy-Pass-2026!'), (string) $rows[1]['legacy_password_hash']);
        self::assertSame(1, (int) $rows[1]['guest']);
        self::assertSame($legacy, (string) $rows[1]['password_hash']);
        self::assertSame($now + 3600, (int) $rows[1]['expiration']);

        self::assertSame("'user'", $this->columnDefault('role'));
        $this->pdo->exec(
            "INSERT INTO users (id, username, password_hash, created_at, updated_at) "
            . "VALUES ('new-user', 'new-user', 'unused', 1, 1)"
        );
        self::assertSame(
            'user',
            $this->pdo->query("SELECT role FROM users WHERE id = 'new-user'")->fetchColumn()
        );

        // Child rows survive the rebuild and reference the renamed table.
        self::assertSame(1, $this->countWhere('sessions', 'user_id', 'admin-one'));
        self::assertSame(1, $this->countWhere('api_tokens', 'user_id', 'legacy-user'));
        self::assertSame(1, $this->countWhere('permissions', 'user_id', 'admin-one'));
        self::assertSame(1, $this->countWhere('lab_locks', 'user_id', 'legacy-user'));
        self::assertSame([], $this->pdo->query('PRAGMA foreign_key_check')->fetchAll());

        $indexes = array_column($this->pdo->query('PRAGMA index_list(users)')->fetchAll(), 'name');
        self::assertContains('users_guest', $indexes);

        $applied = $this->pdo->query(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = '010_user_role_default.php'"
        )->fetchColumn();
        self::assertSame(1, (int) $applied);

        // The rebuild must leave the connection's foreign-key enforcement on.
        $foreignKeys = $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();
        self::assertSame(1, (int) $foreignKeys);

        // A backup of the pre-rebuild database is written before the rebuild.
        $backups = glob($this->config->dataDir . '/backups/users-role-default-*.sdb') ?: [];
        self::assertCount(1, $backups);
        self::assertGreaterThan(0, filesize($backups[0]) ?: 0);
        self::assertSame(0600, fileperms($backups[0]) & 0777, 'the pre-rebuild backup must be owner-only');
        $backup = new PDO('sqlite:' . $backups[0]);
        $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::assertSame(2, (int) $backup->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $backupDefault = null;
        foreach ($backup->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (($column['name'] ?? '') === 'role') {
                $backupDefault = (string) $column['dflt_value'];
            }
        }
        self::assertSame("'admin'", $backupDefault);
        $backup = null;

        // Re-running the migrator is a no-op: no duplicate rows and no second
        // rebuild.
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
        self::assertSame(3, $this->countWhere('users'));
        self::assertCount(1, glob($this->config->dataDir . '/backups/users-role-default-*.sdb') ?: []);
    }

    private function buildOldMigrations(string $directory): void
    {
        $initial = (string) file_get_contents($this->config->migrationsDir() . '/001_initial.sql');
        $old = str_replace("DEFAULT 'user'", "DEFAULT 'admin'", $initial);
        file_put_contents($directory . '/001_initial.sql', $old);

        foreach (glob($this->config->migrationsDir() . '/*') ?: [] as $file) {
            $name = basename($file);
            $prefix = substr($name, 0, 3);
            if ($prefix !== '001' && $prefix !== '010') {
                copy($file, $directory . '/' . $name);
            }
        }
    }

    private function columnDefault(string $column): string
    {
        foreach ($this->pdo->query('PRAGMA table_info(users)')->fetchAll() as $row) {
            if (is_array($row) && ($row['name'] ?? '') === $column) {
                return (string) ($row['dflt_value'] ?? '');
            }
        }
        self::fail('missing users column ' . $column);
    }

    private function countWhere(string $table, ?string $column = null, ?string $value = null): int
    {
        if ($column === null) {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :value'
            );
            $statement->execute([':value' => $value]);
        }
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
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
