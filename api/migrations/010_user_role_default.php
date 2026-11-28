<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

use TeraLab\Api\Database\BusyRetry;

/**
 * Change `users.role` from the legacy `DEFAULT 'admin'` to `DEFAULT 'user'`.
 *
 * SQLite cannot alter a column default in place, so this uses the documented
 * table-rebuild procedure: back up the database, create the replacement,
 * copy every row, drop the old table and rename the new one. The migrator runs
 * this file outside its wrapping transaction because `PRAGMA foreign_keys=OFF`
 * is a no-op inside a transaction and a DROP TABLE on a parent would otherwise
 * cascade into sessions/api_tokens/permissions/lab_locks.
 *
 * The rebuild is verified with `PRAGMA foreign_key_check` before it commits;
 * the migrator repeats the check before recording the version. A backup is
 * written next to the database as
 * `<data_dir>/backups/users-role-default-<UTC>-<rand>.sdb`.
 */
return [
    'transactional' => false,
    'run' => static function (PDO $pdo): void {
        $columns = [];
        $statement = $pdo->query('PRAGMA table_info(users)');
        $info = $statement === false ? [] : $statement->fetchAll();
        if ($statement instanceof PDOStatement) {
            $statement->closeCursor();
        }
        foreach ($info as $column) {
            if (is_array($column) && is_string($column['name'] ?? null)) {
                $columns[$column['name']] = $column;
            }
        }
        if (!isset($columns['role'])) {
            throw new RuntimeException('Migration 010: users.role is missing.');
        }

        $expected = [
            'id',
            'username',
            'email',
            'name',
            'password_hash',
            'role',
            'expiration',
            'created_at',
            'updated_at',
            'legacy_password_hash',
            'guest',
        ];
        $unexpected = array_values(array_diff(array_keys($columns), $expected));
        if ($unexpected !== []) {
            throw new RuntimeException(
                'Migration 010: unexpected users columns, refusing to rebuild: ' . implode(', ', $unexpected)
            );
        }
        foreach (['legacy_password_hash', 'guest'] as $required) {
            if (!isset($columns[$required])) {
                throw new RuntimeException(
                    'Migration 010: users.' . $required . ' is missing; run the earlier migrations first.'
                );
            }
        }

        // The default is reported quoted by PRAGMA (for example `'user'`).
        if ((string) ($columns['role']['dflt_value'] ?? '') === "'user'") {
            // A previous run may have committed the rebuild and then failed to
            // record it; still refuse to hide referential damage.
            $violations = $pdo->query('PRAGMA foreign_key_check');
            $violation = $violations === false ? false : $violations->fetchColumn();
            if ($violations instanceof PDOStatement) {
                $violations->closeCursor();
            }
            if ($violation !== false) {
                throw new RuntimeException(
                    'Migration 010: users already defaults to user but the database has foreign key violations.'
                );
            }

            return;
        }

        $databaseList = $pdo->query('PRAGMA database_list');
        $databases = $databaseList === false ? [] : $databaseList->fetchAll();
        if ($databaseList instanceof PDOStatement) {
            $databaseList->closeCursor();
        }
        $databaseFile = '';
        foreach ($databases as $database) {
            if (is_array($database) && ($database['name'] ?? '') === 'main') {
                $databaseFile = (string) ($database['file'] ?? '');
            }
        }
        if ($databaseFile === '') {
            throw new RuntimeException(
                'Migration 010: cannot back up an in-memory database before rebuilding users.'
            );
        }

        $backupDirectory = dirname($databaseFile) . '/backups';
        if (!is_dir($backupDirectory)
            && !mkdir($backupDirectory, 0700, true)
            && !is_dir($backupDirectory)
        ) {
            throw new RuntimeException('Migration 010: cannot create the backup directory.');
        }
        $backup = $backupDirectory . '/users-role-default-'
            . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.sdb';
        $previousUmask = umask(0077);
        try {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($backup));
        } finally {
            umask($previousUmask);
        }
        clearstatcache(true, $backup);
        if (!is_file($backup) || (filesize($backup) ?: 0) === 0) {
            throw new RuntimeException('Migration 010: the pre-rebuild backup is missing or empty.');
        }
        @chmod($backup, 0600);

        BusyRetry::retry(static function () use ($pdo): void {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->exec(
                    'CREATE TABLE users_role_rebuild ('
                    . 'id TEXT PRIMARY KEY, '
                    . 'username TEXT NOT NULL UNIQUE, '
                    . 'email TEXT, '
                    . 'name TEXT, '
                    . 'password_hash TEXT NOT NULL, '
                    . "role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'editor', 'user')), "
                    . 'expiration INTEGER NOT NULL DEFAULT -1, '
                    . 'created_at INTEGER NOT NULL, '
                    . 'updated_at INTEGER NOT NULL, '
                    . 'legacy_password_hash TEXT, '
                    . 'guest INTEGER NOT NULL DEFAULT 0'
                    . ')'
                );
                $pdo->exec(
                    'INSERT INTO users_role_rebuild '
                    . '(id, username, email, name, password_hash, role, expiration, created_at, '
                    . 'updated_at, legacy_password_hash, guest) '
                    . 'SELECT id, username, email, name, password_hash, role, expiration, created_at, '
                    . 'updated_at, legacy_password_hash, guest FROM users'
                );
                $pdo->exec('DROP TABLE users');
                $pdo->exec('ALTER TABLE users_role_rebuild RENAME TO users');
                $pdo->exec('CREATE INDEX IF NOT EXISTS users_guest ON users (guest, expiration)');

                $violations = $pdo->query('PRAGMA foreign_key_check');
                $violation = $violations === false ? false : $violations->fetchColumn();
                if ($violations instanceof PDOStatement) {
                    $violations->closeCursor();
                }
                if ($violation !== false) {
                    throw new RuntimeException(
                        'Migration 010: the rebuilt users table would leave foreign key violations.'
                    );
                }

                $pdo->exec('COMMIT');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        });
    },
];
