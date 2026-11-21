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
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the database directory.');
        }

        $pdo = new PDO('sqlite:' . $this->config->dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 15000');

        $this->pdo = $pdo;

        return $pdo;
    }
}
