<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)'
        );

        $files = [];
        foreach (['*.sql', '*.php'] as $pattern) {
            foreach (glob($this->directory . '/' . $pattern) ?: [] as $file) {
                $files[] = $file;
            }
        }
        sort($files);

        $applied = $this->appliedVersions();
        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) {
                continue;
            }

            if (str_ends_with($version, '.php')) {
                // PHP migrations return either callable(PDO): void or
                // ['run' => callable(PDO): void, 'transactional' => false].
                // A non-transactional migration is required when SQLite needs
                // PRAGMA foreign_keys to be changed outside a transaction
                // (the users-table rebuild); it must manage its own
                // transaction and is followed by a foreign_key_check.
                $migration = require $file;
                $transactional = true;
                if (is_array($migration)) {
                    $transactional = ($migration['transactional'] ?? true) !== false;
                    $migration = $migration['run'] ?? null;
                }
                if (!is_callable($migration)) {
                    throw new RuntimeException('Migration ' . $version . ' did not return a callable.');
                }
                $operation = fn (): mixed => $migration($this->pdo);
                if ($transactional) {
                    $this->runInTransaction($version, $operation);
                } else {
                    $this->runOutsideTransaction($version, $operation);
                }
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Cannot read migration ' . $version . '.');
            }
            $this->runInTransaction($version, fn (): mixed => $this->pdo->exec($sql));
        }
    }

    private function runInTransaction(string $version, callable $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $operation();
            $this->record($version);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Run a PHP migration without the framework's wrapping transaction.
     *
     * Foreign-key enforcement is suspended for the duration: SQLite's
     * documented table-rebuild procedure requires `PRAGMA foreign_keys=OFF`
     * *outside* a transaction, otherwise a DROP TABLE on a parent cascades into
     * its children. The migration must be self-contained (its own transaction,
     * rollback, and backup) and the framework verifies referential integrity
     * before recording the version.
     */
    private function runOutsideTransaction(string $version, callable $operation): void
    {
        $foreignKeys = $this->pdo->query('PRAGMA foreign_keys');
        $enabled = $foreignKeys !== false && (int) $foreignKeys->fetchColumn() === 1;
        if ($foreignKeys instanceof \PDOStatement) {
            $foreignKeys->closeCursor();
        }
        if ($enabled) {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        }

        try {
            $operation();
            $violations = $this->pdo->query('PRAGMA foreign_key_check');
            $violation = $violations === false ? false : $violations->fetchColumn();
            if ($violations instanceof \PDOStatement) {
                $violations->closeCursor();
            }
            if ($violation !== false) {
                throw new RuntimeException(
                    'Migration ' . $version . ' left foreign key violations; the rebuild was not applied.'
                );
            }
            $this->record($version);
        } finally {
            if ($enabled) {
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function record(string $version): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
        );
        $statement->execute([':version' => $version, ':applied_at' => time()]);
    }

    /**
     * @return list<string>
     */
    private function appliedVersions(): array
    {
        $statement = $this->pdo->query('SELECT version FROM schema_migrations');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($statement instanceof \PDOStatement) {
            $statement->closeCursor();
        }

        $versions = [];
        foreach ($rows as $version) {
            if (is_string($version)) {
                $versions[] = $version;
            }
        }

        return $versions;
    }
}
