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
        public readonly string $agentSocket = '/run/teralab/agent.sock',
        public readonly string $agentSecretFile = '/etc/teralab/agent.secret',
        public readonly string $consolePath = '/ws/console',
        public readonly string $consoleVncPath = '/ws/vnc',
        public readonly string $consoleTcpPath = '/ws/tcp',
        public readonly int $idleTtl = 3600,
        public readonly int $imageQuotaBytes = 0,
        public readonly string $templatesDir = '/etc/teralab/templates',
        public readonly string $imagesDir = '/var/lib/teralab/images',
        public readonly int $maxUploadBytes = 34359738368,
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
                self::boolValue($values, 'cookie_secure', self::requestIsHttps()),
            ),
            sessionTtl: self::intEnv(
                'TERALAB_SESSION_TTL',
                self::intValue($values, 'session_ttl', 3600),
            ),
            environment: self::env(
                'TERALAB_ENV',
                self::stringValue($values, 'environment', 'production'),
            ),
            agentSocket: self::env(
                'TERALAB_AGENT_SOCKET',
                self::stringValue($values, 'agent_socket', '/run/teralab/agent.sock'),
            ),
            agentSecretFile: self::env(
                'TERALAB_AGENT_SECRET_FILE',
                self::stringValue($values, 'agent_secret_file', '/etc/teralab/agent.secret'),
            ),
            consolePath: self::env(
                'TERALAB_CONSOLE_PATH',
                self::stringValue($values, 'console_path', '/ws/console'),
            ),
            consoleVncPath: self::env(
                'TERALAB_CONSOLE_VNC_PATH',
                self::stringValue($values, 'console_vnc_path', '/ws/vnc'),
            ),
            consoleTcpPath: self::env(
                'TERALAB_CONSOLE_TCP_PATH',
                self::stringValue($values, 'console_tcp_path', '/ws/tcp'),
            ),
            idleTtl: self::intEnv(
                'TERALAB_IDLE_TTL',
                self::intValue($values, 'idle_ttl', 3600),
            ),
            imageQuotaBytes: self::intEnv(
                'TERALAB_IMAGE_QUOTA_BYTES',
                self::intValue($values, 'image_quota_bytes', 0),
            ),
            templatesDir: self::env(
                'TERALAB_TEMPLATES_DIR',
                self::stringValue($values, 'templates_dir', '/etc/teralab/templates'),
            ),
            imagesDir: self::env(
                'TERALAB_IMAGES_DIR',
                self::stringValue($values, 'images_dir', $dataDir . '/images'),
            ),
            maxUploadBytes: self::intEnv(
                'TERALAB_MAX_UPLOAD_BYTES',
                self::intValue($values, 'max_upload_bytes', 34359738368),
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
            agentSocket: $dataDir . '/agent.sock',
            agentSecretFile: $dataDir . '/agent.secret',
            imagesDir: $dataDir . '/images',
            maxUploadBytes: 2147483648,
            templatesDir: $dataDir . '/templates',
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

        return '1.5.2';
    }

    /**
     * Detect HTTPS from the web SAPI so first-run HTTP installs can log in.
     *
     * @param array<string, mixed> $server
     */
    public static function requestIsHttps(array $server = []): bool
    {
        $server = $server !== [] ? $server : $_SERVER;
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($proto) && strtolower(trim(explode(',', $proto)[0])) === 'https') {
            return true;
        }

        return (string) ($server['SERVER_PORT'] ?? '') === '443';
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
