<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Database;

use TeraLab\Api\Config;

/**
 * Cheap request-time bootstrap check.
 *
 * Migrations are only needed when the applied schema or the code version
 * changes. A small marker in the data directory records the version and the
 * exact migration file list that was applied, so a normal request reads one
 * file instead of opening a transaction and scanning the migration directory.
 *
 * The marker is written after a successful migration. When it is missing,
 * unreadable, or stale, the migrator runs and the marker is replaced
 * atomically.
 */
final class Bootstrap
{
    public const MARKER_FILE = '.bootstrap.json';

    public function __construct(
        private readonly Config $config,
        private readonly Migrator $migrator,
    ) {
    }

    /**
     * Run migrations only when the marker does not match the current code.
     *
     * @return bool true when migrations ran, false when the marker was current
     */
    public function ensureMigrated(): bool
    {
        $fingerprint = $this->fingerprint();
        if ($this->markerValue() === $fingerprint) {
            return false;
        }

        $this->migrator->migrate();
        $this->writeMarker($fingerprint);

        return true;
    }

    public function fingerprint(): string
    {
        $versions = [];
        foreach (['*.sql', '*.php'] as $pattern) {
            foreach (glob($this->config->migrationsDir() . '/' . $pattern) ?: [] as $file) {
                $versions[] = basename($file);
            }
        }
        sort($versions);

        return $this->config->version() . ':' . hash('sha256', implode("\n", $versions));
    }

    public function markerPath(): string
    {
        return $this->config->dataDir . '/' . self::MARKER_FILE;
    }

    private function markerValue(): ?string
    {
        $path = $this->markerPath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $value = $decoded['fingerprint'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function writeMarker(string $fingerprint): void
    {
        $directory = $this->config->dataDir;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }
        $records = [
            'fingerprint' => $fingerprint,
            'version' => $this->config->version(),
            'migrated_at' => time(),
        ];
        $payload = json_encode($records, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temporary = $directory . '/' . self::MARKER_FILE . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $payload) === false) {
            return;
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->markerPath())) {
            @unlink($temporary);
        }
    }
}
