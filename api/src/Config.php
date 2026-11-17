<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api;

final class Config
{
    public const COOKIE_NAME = 'teralab_session';

    public function __construct(
        public readonly string $root,
        public readonly string $dataDir,
        public readonly string $dbPath,
        public readonly string $labsDir,
        public readonly bool $cookieSecure,
        public readonly int $sessionTtl,
        public readonly string $environment,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $values = self::loadConfigFile();
        $root = self::env(
            'TERALAB_ROOT',
            self::stringValue($values, 'root', self::defaultRoot()),
        );
        $dataDir = self::env(
            'TERALAB_DATA_DIR',
            self::stringValue($values, 'data_dir', '/var/lib/teralab'),
        );

        return new self(
            root: $root,
            dataDir: $dataDir,
            dbPath: self::env(
                'TERALAB_DB',
                self::stringValue($values, 'db_path', $dataDir . '/database.sdb'),
            ),
            labsDir: self::env(
                'TERALAB_LABS',
                self::stringValue($values, 'labs_dir', $dataDir . '/labs'),
            ),
            cookieSecure: self::boolEnv(
                'TERALAB_COOKIE_SECURE',
                self::boolValue($values, 'cookie_secure', true),
            ),
            sessionTtl: self::intEnv(
                'TERALAB_SESSION_TTL',
                self::intValue($values, 'session_ttl', 3600),
            ),
            environment: self::env(
                'TERALAB_ENV',
                self::stringValue($values, 'environment', 'production'),
            ),
        );
    }

    public static function forTests(string $root, string $dataDir): self
    {
        return new self(
            root: $root,
            dataDir: $dataDir,
            dbPath: $dataDir . '/database.sdb',
            labsDir: $root . '/labs',
            cookieSecure: false,
            sessionTtl: 3600,
            environment: 'test',
        );
    }

    public function version(): string
    {
        $file = $this->root . '/TERALAB_VERSION';
        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));
            if ($version !== '') {
                return $version;
            }
        }

        return '1.1.0';
    }

    public function migrationsDir(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    private static function defaultRoot(): string
    {
        return is_dir('/opt/teralab') ? '/opt/teralab' : dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfigFile(): array
    {
        $file = getenv('TERALAB_CONFIG');
        if ($file === false || $file === '') {
            $file = '/etc/teralab/config.php';
        }
        if (!is_file($file)) {
            return [];
        }

        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new \RuntimeException('TeraLab config must return an array.');
        }

        /** @var array<string, mixed> $loaded */
        return $loaded;
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function stringValue(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function boolValue(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function intValue(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? null;
        if (is_int($value)) {
            return max(1, $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return $default;
    }

    private static function boolEnv(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private static function intEnv(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return max(1, (int) $value);
    }
}
