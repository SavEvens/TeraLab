<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use PDO;
use TeraLab\Api\Auth\TurnstileVerifier;
use TeraLab\Api\Http\HttpException;

final class SettingsService
{
    /** @var array<string, string|int> */
    public const DEFAULTS = [
        'timezone' => 'UTC',
        'session_ttl' => 3600,
        'max_upload_bytes' => 2147483648,
        'console_default' => 'telnet',
        'demo_enabled' => 0,
        'demo_landing' => 'login',
        'demo_max_guests' => 50,
        'demo_guest_ttl_hours' => 48,
        'demo_guest_idle_hours' => 6,
        'demo_max_nodes_per_lab' => 5,
        'demo_max_labs_per_guest' => 3,
        'demo_max_lab_bytes_per_guest' => 20971520,
        'demo_max_snapshots_per_lab' => 3,
        'demo_max_tokens_per_user' => 5,
        'demo_allowed_templates' => 'vpcs',
        'demo_max_ram_per_node' => 1024,
        'demo_memory_floor_mb' => 2048,
        'demo_idle_wipe_minutes' => 120,
        'demo_sweep_interval' => 300,
        'retention_login_attempts_days' => 30,
        'retention_audit_log_days' => 90,
        'retention_jobs_days' => 30,
        'retention_jobs_max_rows' => 1000,
        'maintenance_interval_seconds' => 3600,
        'backup_max_archive_bytes' => 2147483648,
        'backup_max_entries' => 10000,
        'backup_max_uncompressed_bytes' => 4294967296,
        'backup_max_database_bytes' => 1073741824,
        'turnstile_enabled' => 0,
        'turnstile_site_key' => '3x00000000000000000000FF',
        'turnstile_secret_key' => '1x0000000000000000000000000000000AA',
        'turnstile_hostname' => '',
        'legacy_v1_enabled' => 0,
    ];

    /** Keys stored as integers, regardless of their textual representation. */
    private const INT_KEYS = [
        'session_ttl',
        'max_upload_bytes',
        'demo_enabled',
        'demo_max_guests',
        'demo_guest_ttl_hours',
        'demo_guest_idle_hours',
        'demo_max_nodes_per_lab',
        'demo_max_labs_per_guest',
        'demo_max_lab_bytes_per_guest',
        'demo_max_snapshots_per_lab',
        'demo_max_tokens_per_user',
        'demo_max_ram_per_node',
        'demo_memory_floor_mb',
        'demo_idle_wipe_minutes',
        'demo_sweep_interval',
        'retention_login_attempts_days',
        'retention_audit_log_days',
        'retention_jobs_days',
        'retention_jobs_max_rows',
        'maintenance_interval_seconds',
        'backup_max_archive_bytes',
        'backup_max_entries',
        'backup_max_uncompressed_bytes',
        'backup_max_database_bytes',
        'turnstile_enabled',
        'legacy_v1_enabled',
    ];

    /** Integer settings that are on/off switches. */
    private const BOOL_KEYS = ['demo_enabled', 'turnstile_enabled', 'legacy_v1_enabled'];

    /**
     * Inclusive bounds for integer settings whose message can be generic.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const INT_BOUNDS = [
        'demo_max_guests' => [1, 1000],
        'demo_guest_ttl_hours' => [1, 720],
        'demo_guest_idle_hours' => [1, 72],
        'demo_max_nodes_per_lab' => [1, 16],
        'demo_max_labs_per_guest' => [1, 20],
        'demo_max_lab_bytes_per_guest' => [1048576, 268435456],
        'demo_max_snapshots_per_lab' => [1, 20],
        'demo_max_tokens_per_user' => [1, 50],
        'demo_max_ram_per_node' => [64, 4096],
        'demo_memory_floor_mb' => [256, 65536],
        'demo_idle_wipe_minutes' => [15, 1440],
        'demo_sweep_interval' => [60, 3600],
        'retention_login_attempts_days' => [1, 3650],
        'retention_audit_log_days' => [1, 3650],
        'retention_jobs_days' => [1, 3650],
        'retention_jobs_max_rows' => [100, 1000000],
        'maintenance_interval_seconds' => [60, 86400],
        'backup_max_archive_bytes' => [1048576, 10737418240],
        'backup_max_entries' => [1, 1000000],
        'backup_max_uncompressed_bytes' => [1048576, 42949672960],
        'backup_max_database_bytes' => [1048576, 10737418240],
    ];

    private const DEMO_LANDING = ['login', 'demo'];

    private const MAX_DEMO_TEMPLATES = 32;

    private const TEMPLATE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/';

    private const MAX_TURNSTILE_KEY_LENGTH = 128;

    private const MAX_HOSTNAME_LENGTH = 253;

    /** Settings whose change can make the Turnstile gate unsafe. */
    private const TURNSTILE_KEYS = [
        'turnstile_enabled',
        'turnstile_site_key',
        'turnstile_secret_key',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $environment = 'production',
    ) {
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
        if (array_intersect(array_keys($clean), self::TURNSTILE_KEYS) !== []) {
            $this->assertTurnstileIsUsable(array_merge($this->all(), $clean));
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
        return in_array($key, self::INT_KEYS, true) ? (int) $value : $value;
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
        if ($key === 'demo_landing') {
            if (!is_string($value) || !in_array($value, self::DEMO_LANDING, true)) {
                throw new HttpException(422, 'demo_landing must be login or demo.');
            }

            return $value;
        }
        if ($key === 'demo_allowed_templates') {
            return $this->csv($key, $value);
        }
        if ($key === 'turnstile_site_key' || $key === 'turnstile_secret_key') {
            return $this->turnstileKey($key, $value);
        }
        if ($key === 'turnstile_hostname') {
            return $this->hostname($value);
        }
        if (in_array($key, self::BOOL_KEYS, true)) {
            return $this->boolSetting($key, $value);
        }

        $int = is_int($value) ? $value : (int) $value;
        if ($key === 'session_ttl' && ($int < 300 || $int > 86400)) {
            throw new HttpException(422, 'session_ttl must be between 300 and 86400 seconds.');
        }
        if ($key === 'max_upload_bytes' && ($int < 1048576 || $int > 10737418240)) {
            throw new HttpException(422, 'max_upload_bytes must be between 1 MiB and 10 GiB.');
        }
        if (isset(self::INT_BOUNDS[$key])) {
            [$min, $max] = self::INT_BOUNDS[$key];
            if ($int < $min || $int > $max) {
                throw new HttpException(422, $key . ' must be between ' . $min . ' and ' . $max . '.');
            }
        }

        return $int;
    }

    /**
     * On/off switches accept JSON booleans, integers 0/1, and the strings
     * "0"/"1" so both the UI and API clients can round-trip them.
     */
    private function boolSetting(string $key, mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }
        if (is_string($value) && ($value === '0' || $value === '1')) {
            return (int) $value;
        }

        throw new HttpException(422, $key . ' must be 0 or 1.');
    }

    /**
     * Validate a comma-separated template allowlist, trimming, dropping empty
     * entries, and de-duplicating while preserving order. Template ids are
     * only shape-checked here; the demo guard resolves them against the real
     * template catalogue.
     */
    private function csv(string $key, mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, $key . ' must be a comma-separated string.');
        }

        $entries = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!in_array($entry, $entries, true)) {
                $entries[] = $entry;
            }
        }
        if (count($entries) > self::MAX_DEMO_TEMPLATES) {
            throw new HttpException(
                422,
                $key . ' accepts at most ' . self::MAX_DEMO_TEMPLATES . ' template ids.'
            );
        }
        foreach ($entries as $entry) {
            if (preg_match(self::TEMPLATE_ID_PATTERN, $entry) !== 1) {
                throw new HttpException(422, $key . ' contains an invalid template id.');
            }
        }

        return implode(',', $entries);
    }

    private function turnstileKey(string $key, mixed $value): string
    {
        // An empty value means "not configured" and is how the UI clears a
        // key; it overrides the shipped default until the key is set again.
        if (!is_string($value)
            || preg_match('/^[\x20-\x7E]{0,' . self::MAX_TURNSTILE_KEY_LENGTH . '}\z/', $value) !== 1
        ) {
            throw new HttpException(
                422,
                $key . ' must be 0-' . self::MAX_TURNSTILE_KEY_LENGTH . ' printable ASCII characters.'
            );
        }

        return $value;
    }

    /**
     * Refuse to enable the bot gate with Cloudflare's public test credentials
     * in production. Non-production environments and the explicit
     * TERALAB_ALLOW_TURNSTILE_TEST_KEYS=1 escape hatch keep the test flow
     * available without weakening a real deployment silently.
     *
     * @param array<string, string|int> $effective
     */
    private function assertTurnstileIsUsable(array $effective): void
    {
        if ((int) ($effective['turnstile_enabled'] ?? 0) !== 1) {
            return;
        }
        if ($this->environment !== 'production') {
            return;
        }
        if (getenv('TERALAB_ALLOW_TURNSTILE_TEST_KEYS') === '1') {
            return;
        }

        $secret = (string) ($effective['turnstile_secret_key'] ?? '');
        $siteKey = (string) ($effective['turnstile_site_key'] ?? '');
        if ($secret === '' || TurnstileVerifier::isTestSecretKey($secret)) {
            throw new HttpException(
                422,
                'turnstile_secret_key must be a real key in production; empty and Cloudflare test keys are refused.',
            );
        }
        if ($siteKey === '' || TurnstileVerifier::isTestSiteKey($siteKey)) {
            throw new HttpException(
                422,
                'turnstile_site_key must be a real key in production; empty and Cloudflare test keys are refused.',
            );
        }
    }

    private function hostname(mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'turnstile_hostname must be a hostname or empty.');
        }
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $pattern = '/^(?=.{1,' . self::MAX_HOSTNAME_LENGTH . '}$)'
            . '([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
            . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';
        if (preg_match($pattern, $value) !== 1) {
            throw new HttpException(
                422,
                'turnstile_hostname must be a hostname or empty.',
            );
        }

        return $value;
    }
}
