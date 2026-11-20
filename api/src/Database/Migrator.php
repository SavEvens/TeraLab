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

            $this->pdo->beginTransaction();
            try {
                if (str_ends_with($version, '.php')) {
                    // PHP migrations return a callable(PDO): void so data
                    // backfills can use the application's helpers.
                    $migration = require $file;
                    if (!is_callable($migration)) {
                        throw new RuntimeException('Migration ' . $version . ' did not return a callable.');
                    }
                    $migration($this->pdo);
                } else {
                    $sql = file_get_contents($file);
                    if ($sql === false) {
                        throw new RuntimeException('Cannot read migration ' . $version . '.');
                    }
                    $this->pdo->exec($sql);
                }
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );
                $statement->execute([':version' => $version, ':applied_at' => time()]);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function appliedVersions(): array
    {
        $statement = $this->pdo->query('SELECT version FROM schema_migrations');
        if ($statement === false) {
            return [];
        }

        $versions = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $version) {
            if (is_string($version)) {
                $versions[] = $version;
            }
        }

        return $versions;
    }
}
