<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use RuntimeException;
use TeraLab\Api\Config;

final class RestoreManager
{
    public static function applyPending(Config $config): void
    {
        $directory = $config->dataDir . '/restore';
        $staged = $directory . '/database.sdb';
        $marker = $directory . '/pending';
        if (!is_file($staged) || !is_file($marker)) {
            return;
        }

        // The staging area holds a full copy of the database: keep it and the
        // staged artifacts owner-only even if an older install left them loose.
        @chmod($directory, 0700);
        @chmod($staged, 0600);
        @chmod($marker, 0600);

        if (is_file($config->dbPath)) {
            $backup = $config->dbPath . '.pre-restore-' . gmdate('YmdHis');
            if (!@copy($config->dbPath, $backup)) {
                throw new RuntimeException('Cannot back up the current database before restore.');
            }
            @chmod($backup, 0600);
        }
        if (!@copy($staged, $config->dbPath)) {
            throw new RuntimeException('Cannot install the restored database.');
        }
        @chmod($config->dbPath, 0600);
        @unlink($config->dbPath . '-wal');
        @unlink($config->dbPath . '-shm');
        @unlink($staged);
        @unlink($marker);
    }
}
