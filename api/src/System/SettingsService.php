<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use PDO;
use TeraLab\Api\Http\HttpException;

final class SettingsService
{
    /** @var array<string, string|int> */
    public const DEFAULTS = [
        'timezone' => 'UTC',
        'session_ttl' => 3600,
        'max_upload_bytes' => 33554432,
        'console_default' => 'telnet',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string|int>
     */
    public function all(): array
    {
        $values = self::DEFAULTS;
        $statement = $this->pdo->query('SELECT key, value FROM settings');
        if ($statement !== false) {
            foreach ($statement->fetchAll() as $row) {
                if (!is_array($row) || !isset($row['key']) || !is_string($row['key'])) {
                    continue;
                }
                if (!array_key_exists($row['key'], $values)) {
                    continue;
                }
                $values[$row['key']] = $this->cast($row['key'], (string) $row['value']);
            }
        }

        return $values;
    }

    public function int(string $key, int $fallback): int
    {
        $value = $this->all()[$key] ?? $fallback;

        return is_int($value) ? $value : (int) $value;
    }

    public function string(string $key, string $fallback): string
    {
        $value = $this->all()[$key] ?? $fallback;

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string|int>
     */
    public function update(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $clean[$key] = $this->validate($key, $value);
        }
        if ($clean === []) {
            throw new HttpException(422, 'No supported settings were provided.');
        }

        $now = time();
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        foreach ($clean as $key => $value) {
            $statement->execute([':key' => $key, ':value' => (string) $value, ':updated_at' => $now]);
        }

        return $this->all();
    }

    private function cast(string $key, string $value): string|int
    {
        return in_array($key, ['session_ttl', 'max_upload_bytes'], true) ? (int) $value : $value;
    }

    private function validate(string $key, mixed $value): string|int
    {
        if ($key === 'timezone') {
            if (!is_string($value) || !in_array($value, timezone_identifiers_list(), true)) {
                throw new HttpException(422, 'Invalid timezone.');
            }

            return $value;
        }
        if ($key === 'console_default') {
            if (!is_string($value) || !in_array($value, ['telnet', 'vnc'], true)) {
                throw new HttpException(422, 'console_default must be telnet or vnc.');
            }

            return $value;
        }
        $int = is_int($value) ? $value : (int) $value;
        if ($key === 'session_ttl' && ($int < 300 || $int > 86400)) {
            throw new HttpException(422, 'session_ttl must be between 300 and 86400 seconds.');
        }
        if ($key === 'max_upload_bytes' && ($int < 1048576 || $int > 10737418240)) {
            throw new HttpException(422, 'max_upload_bytes must be between 1 MiB and 10 GiB.');
        }

        return $int;
    }
}
