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

        if (is_file($config->dbPath)) {
            $backup = $config->dbPath . '.pre-restore-' . gmdate('YmdHis');
            if (!@copy($config->dbPath, $backup)) {
                throw new RuntimeException('Cannot back up the current database before restore.');
            }
        }
        if (!@copy($staged, $config->dbPath)) {
            throw new RuntimeException('Cannot install the restored database.');
        }
        @unlink($config->dbPath . '-wal');
        @unlink($config->dbPath . '-shm');
        @unlink($staged);
        @unlink($marker);
    }
}
