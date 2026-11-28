<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Database;

use PDO;
use RuntimeException;
use TeraLab\Api\Config;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $directory = dirname($this->config->dbPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create the database directory.');
            }
            // mkdir() is filtered by the process umask; pin the mode so a
            // permissive umask cannot leave the data directory writable.
            @chmod($directory, 0750);
        }

        // The database, its WAL and its shared-memory index must be readable
        // only by the API identity. Pin the umask while SQLite creates files
        // and then chmod explicitly so the guarantee does not depend on the
        // service umask.
        $previousUmask = umask(0077);
        try {
            $this->prepareSidecarFiles();
            $pdo = new PDO('sqlite:' . $this->config->dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 15000');
            $this->restrictDatabaseFiles();
        } finally {
            umask($previousUmask);
        }

        $this->pdo = $pdo;

        return $pdo;
    }

    /**
     * SQLite creates the -wal/-shm files on the first write transaction and
     * removes them when the last connection closes. Creating them ahead of
     * time with owner-only permissions stops SQLite from falling back to its
     * 0644 default when it recreates them later.
     */
    private function prepareSidecarFiles(): void
    {
        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $this->config->dbPath . $suffix;
            clearstatcache(true, $sidecar);
            if (!file_exists($sidecar) && @touch($sidecar)) {
                @chmod($sidecar, 0600);
            }
        }
    }

    private function restrictDatabaseFiles(): void
    {
        @chmod($this->config->dbPath, 0600);
        foreach (['-wal', '-shm'] as $suffix) {
            // chmod() on a missing sidecar is harmless; @ keeps a first
            // connection (or a read-only database) from raising a warning.
            @chmod($this->config->dbPath . $suffix, 0600);
        }
    }
}
