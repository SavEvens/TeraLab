<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Templates;

use TeraLab\Api\Http\HttpException;

/**
 * Structured QEMU parameters stored in a template's `options` mapping.
 *
 * Templates are editable by the `editor` role, so every value that can reach
 * the QEMU command line is validated here. The agent validates again before it
 * starts a node (defence in depth); this class is the API-side authority.
 *
 * The raw `qemu_options` string is kept for legacy templates. It is forwarded
 * to the engine as `extra_options`, parsed there (the engine maps known flags
 * into structured fields, drops the ones it renders itself, and keeps the
 * allow-listed extras). The API runs the same tokenizer and classification on
 * save so a dangerous value fails with a 422 instead of at start.
 */
final class QemuOptions
{
    /** Template option name => engine spec field. */
    public const MAPPING = [
        'qemu_nic' => 'nic',
        'qemu_machine' => 'machine',
        'qemu_arch' => 'arch',
        'qemu_cpu' => 'cpu_model',
        'qemu_accel' => 'accel',
        'qemu_disk_interface' => 'disk_interface',
    ];

    /**
     * Top-level node fields the API persists as node attributes, so a per-node
     * override survives the lab file without new managed `.unl` attributes.
     */
    public const NODE_FIELDS = [
        'arch' => 'qemu_arch',
        'cpu_model' => 'qemu_cpu',
        'accel' => 'qemu_accel',
        'disk_interface' => 'qemu_disk_interface',
    ];

    /** Legacy raw QEMU command-line string. */
    public const RAW_KEY = 'qemu_options';

    /** Conservative shape shared with the agent: no spaces, no shell characters. */
    private const PATTERN = '/^[A-Za-z0-9_.+-]{1,64}$/';

    /** Same machine grammar as the agent (pc/q35 aliases and versioned names). */
    private const MACHINE_PATTERN = '~^((pc|q35)(-[0-9]+(\.[0-9]+)*)?|(pc-i440fx|pc-q35)-[0-9]+(\.[0-9]+)*)$~';

    private const BOOT_PATTERN = '~^order=[a-z]{1,4}$~';

    private const ICOUNT_PATTERN = '~^[0-9]{1,6}$~';

    /** Per-field overrides: a CPU model may carry a comma-separated feature list. */
    private const PATTERNS = [
        'cpu_model' => '/^[A-Za-z0-9_.+,-]{1,64}$/',
        'machine' => self::MACHINE_PATTERN,
    ];

    /** Allowed values per spec field, when the field has an enumerable set. */
    private const ALLOWED = [
        'nic' => ['virtio', 'vmxnet3', 'e1000', 'rtl8139'],
        // The engine is built for x86_64 hosts only; aarch64 would fail at
        // start, so it is rejected when the template is saved.
        'arch' => ['x86_64'],
        'accel' => ['kvm', 'tcg', 'kvm:tcg'],
        'disk_interface' => ['virtio', 'scsi', 'ide', 'sata'],
    ];

    /**
     * Values shipped by older TeraLab/UNetLab templates: still readable, but
     * normalised to the structured value the engine expects.
     */
    private const LEGACY = [
        'nic' => ['virtio-net-pci' => 'virtio', 'i82559er' => 'e1000'],
        'arch' => ['i386' => 'x86_64'],
    ];

    private const RAW_MAX_LENGTH = 512;

    private const MAX_EXTRA_TOKENS = 16;

    private const MAX_EXTRA_VALUE_LENGTH = 256;

    /** Values that could reach host files, sockets or devices (lowercase). */
    private const BLOCKED_VALUE_PARTS = ['file=', 'path=', 'host=', 'unix:', 'socket', 'tap,'];

    /** Engine-owned flags rendered unconditionally; a template value is ignored. */
    private const DROPPED_FLAGS = [
        '-nodefaults', '-no-user-config', '-nodefconfig', '-nographic', '-no-reboot',
    ];

    /** Engine-owned flags that take one value; the value is dropped too. */
    private const DROPPED_VALUED_FLAGS = [
        '-smp', '-m', '-monitor', '-serial', '-display', '-rtc', '-name', '-uuid',
        '-kernel', '-initrd', '-append', '-cdrom', '-vga',
    ];

    /** Flags that must never appear in a template or node override. */
    private const REJECTED_FLAGS = [
        '-device', '-fsdev', '-virtfs', '-chardev', '-vnc', '-pidfile', '-runas',
        '-sandbox', '-daemonize', '-S', '-readconfig', '-writeconfig',
    ];

    /** Allow-listed extras that take no value. */
    private const EXTRA_FLAGS = ['-no-acpi', '-no-hpet', '-no-shutdown'];

    /** Allow-listed extras that take exactly one value. */
    private const EXTRA_VALUED_FLAGS = ['-smbios', '-global', '-icount', '-realtime', '-usbdevice'];

    /**
     * Validate a template `options` mapping.
     *
     * @param array<string, mixed> $options
     * @throws HttpException 422 when a structured value is not allowed
     */
    public static function validate(array $options): void
    {
        foreach (self::MAPPING as $option => $field) {
            if (!array_key_exists($option, $options)) {
                continue;
            }
            $value = $options[$option];
            if (!is_string($value)) {
                throw new HttpException(422, 'Template option ' . $option . ' must be a string.');
            }
            if (trim($value) === '') {
                continue;
            }
            self::normalise($field, $value, $option);
        }

        if (array_key_exists(self::RAW_KEY, $options)) {
            $raw = $options[self::RAW_KEY];
            if (!is_string($raw)) {
                throw new HttpException(422, 'Template option ' . self::RAW_KEY . ' must be a string.');
            }
            self::assertRaw($raw);
        }
    }

    /**
     * Map a validated template `options` mapping to engine spec fields.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     * @throws HttpException 422 when a value is not allowed
     */
    public static function spec(array $options): array
    {
        $spec = [];
        foreach (self::MAPPING as $option => $field) {
            if (!array_key_exists($option, $options)) {
                continue;
            }
            $value = $options[$option];
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $spec[$field] = self::normalise($field, $value, $option);
        }
        $raw = $options[self::RAW_KEY] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            self::assertRaw($raw);
            $spec['extra_options'] = $raw;
        }

        return $spec;
    }

    /**
     * Validate and normalise one structured template option, for per-node
     * overrides stored as node attributes or top-level node fields.
     *
     * @throws HttpException 422 when the value is not allowed
     */
    public static function value(string $option, string $value): string
    {
        $field = self::MAPPING[$option] ?? null;
        if ($field === null) {
            throw new HttpException(422, 'Unsupported QEMU option: ' . $option);
        }

        return self::normalise($field, $value, $option, 'Node attribute ');
    }

    /**
     * Validate a raw `qemu_options` string used as a node override.
     *
     * @throws HttpException 422 when the value is unsafe
     */
    public static function rawValue(string $value): string
    {
        self::assertRaw($value, 'Node attribute ');

        return $value;
    }

    /**
     * @throws HttpException 422 when the value does not match the field rules
     */
    private static function normalise(
        string $field,
        string $value,
        string $option,
        string $prefix = 'Template option ',
    ): string {
        $pattern = self::PATTERNS[$field] ?? self::PATTERN;
        if (preg_match($pattern, $value) !== 1) {
            throw new HttpException(422, $prefix . $option . ' has an invalid value.');
        }
        $allowed = self::ALLOWED[$field] ?? [];
        $legacy = self::LEGACY[$field] ?? [];
        if ($allowed !== [] && !in_array($value, $allowed, true)) {
            $replacement = $legacy[$value] ?? null;
            if ($replacement === null) {
                if ($field === 'arch') {
                    throw new HttpException(
                        422,
                        $prefix . $option . ' only supports x86_64; this build runs x86_64 guests only.',
                    );
                }
                throw new HttpException(422, $prefix . $option . ' must be one of: ' . implode(', ', $allowed) . '.');
            }

            return $replacement;
        }

        return $value;
    }

    /**
     * Length/control-character bounds plus the agent's token classification.
     *
     * @throws HttpException 422 when the string is too long, unsafe or contains
     *                       a token outside the engine-owned/allow-listed sets
     */
    private static function assertRaw(string $raw, string $prefix = 'Template option '): void
    {
        if (strlen($raw) > self::RAW_MAX_LENGTH) {
            throw new HttpException(
                422,
                $prefix . self::RAW_KEY . ' must be at most ' . self::RAW_MAX_LENGTH . ' characters.',
            );
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $raw) === 1) {
            throw new HttpException(422, $prefix . self::RAW_KEY . ' must not contain control characters.');
        }
        self::classify(self::split($raw, $prefix), $prefix);
    }

    /**
     * Shell-like split with single/double quotes and backslash escapes but no
     * expansion, mirroring the agent's SplitQEMUOptions.
     *
     * @return list<string>
     * @throws HttpException 422 on an unterminated quote, empty argument or a
     *                       trailing backslash
     */
    private static function split(string $raw, string $prefix): array
    {
        // Legacy UNetLab templates escaped their quotes for PHP (\"), and YAML
        // single-quoted scalars keep the backslash. Remove that artefact only.
        $raw = str_replace('\\"', '"', $raw);

        $tokens = [];
        $token = '';
        $started = false;
        $quote = '';
        $length = strlen($raw);
        for ($index = 0; $index < $length; $index++) {
            $char = $raw[$index];
            if ($quote === "'") {
                if ($char === "'") {
                    $quote = '';
                    continue;
                }
                $token .= $char;
                continue;
            }
            if ($quote === '"') {
                if ($char === '"') {
                    $quote = '';
                } elseif ($char === '\\') {
                    if ($index + 1 < $length && ($raw[$index + 1] === '"' || $raw[$index + 1] === '\\')) {
                        $index++;
                        $token .= $raw[$index];
                    } else {
                        $token .= $char;
                    }
                } else {
                    $token .= $char;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $started = true;
                continue;
            }
            if ($char === ' ') {
                if ($started) {
                    if ($token === '') {
                        throw new HttpException(422, $prefix . self::RAW_KEY . ' contain an empty argument.');
                    }
                    $tokens[] = $token;
                    $token = '';
                    $started = false;
                }
                continue;
            }
            if ($char === '\\') {
                if ($index + 1 >= $length) {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' end with a backslash.');
                }
                $index++;
                $token .= $raw[$index];
                $started = true;
                continue;
            }
            $token .= $char;
            $started = true;
        }
        if ($quote !== '') {
            throw new HttpException(422, $prefix . self::RAW_KEY . ' contain an unterminated quote.');
        }
        if ($started) {
            if ($token === '') {
                throw new HttpException(422, $prefix . self::RAW_KEY . ' contain an empty argument.');
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Apply the agent's MergeQEMUOptions classification: engine-owned tokens
     * are accepted (the engine drops or maps them), allow-listed extras are
     * counted, and everything else is a 422 naming the token.
     *
     * @param list<string> $tokens
     * @throws HttpException 422
     */
    private static function classify(array $tokens, string $prefix): void
    {
        $extras = 0;
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $flag = $tokens[$index];
            if (!str_starts_with($flag, '-')) {
                throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unexpected argument: ' . $flag);
            }
            if (in_array($flag, self::DROPPED_FLAGS, true)) {
                continue;
            }
            if (in_array($flag, self::DROPPED_VALUED_FLAGS, true)) {
                $index = self::valueIndex($tokens, $index, $prefix);
                continue;
            }
            if (in_array($flag, self::REJECTED_FLAGS, true)) {
                throw new HttpException(422, $prefix . self::RAW_KEY . ' rejects the option: ' . $flag);
            }
            if (in_array($flag, self::EXTRA_FLAGS, true)) {
                self::assertExtraValue($flag, '', $prefix);
                $extras++;
                continue;
            }
            if (in_array($flag, self::EXTRA_VALUED_FLAGS, true)) {
                $index = self::valueIndex($tokens, $index, $prefix);
                self::assertExtraValue($flag, $tokens[$index], $prefix);
                $extras += 2;
                continue;
            }
            switch ($flag) {
                case '-machine':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    self::assertMachine($tokens[$index], $prefix);
                    break;
                case '-cpu':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    if (preg_match(self::PATTERNS['cpu_model'], $tokens[$index]) !== 1) {
                        throw new HttpException(422, $prefix . self::RAW_KEY . ' has an invalid cpu model: ' . $tokens[$index]);
                    }
                    break;
                case '-accel':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    if (!in_array($tokens[$index], ['kvm', 'tcg'], true)) {
                        throw new HttpException(422, $prefix . self::RAW_KEY . ' has an invalid accelerator: ' . $tokens[$index]);
                    }
                    break;
                case '-nic':
                case '-net':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    self::assertNicOption($tokens[$index], $prefix);
                    break;
                case '-drive':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    self::assertDrive($tokens[$index], $prefix);
                    break;
                case '-netdev':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    if (!str_starts_with($tokens[$index], 'tap,')) {
                        throw new HttpException(
                            422,
                            $prefix . self::RAW_KEY . ' only allows the engine-owned tap netdev: ' . $tokens[$index],
                        );
                    }
                    break;
                case '-boot':
                    $index = self::valueIndex($tokens, $index, $prefix);
                    if (preg_match(self::BOOT_PATTERN, $tokens[$index]) !== 1) {
                        throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported -boot value: ' . $tokens[$index]);
                    }
                    // Counted as an extra: the engine keeps it when it does
                    // not choose the boot order itself.
                    $extras += 2;
                    break;
                default:
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' rejects the option: ' . $flag);
            }
        }
        if ($extras > self::MAX_EXTRA_TOKENS) {
            throw new HttpException(
                422,
                $prefix . self::RAW_KEY . ' allows at most ' . self::MAX_EXTRA_TOKENS . ' extra options.',
            );
        }
    }

    /**
     * Read the value of the flag at $index and advance the index past it.
     *
     * @param list<string> $tokens
     * @throws HttpException 422 when the value is missing or looks like a flag
     */
    private static function valueIndex(array $tokens, int $index, string $prefix): int
    {
        $value = $tokens[$index + 1] ?? null;
        if ($value === null || str_starts_with($value, '-')) {
            throw new HttpException(
                422,
                $prefix . self::RAW_KEY . ' option ' . $tokens[$index] . ' requires a value.',
            );
        }

        return $index + 1;
    }

    /**
     * @throws HttpException 422
     */
    private static function assertMachine(string $value, string $prefix): void
    {
        $machine = '';
        $accel = '';
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $separator = strpos($part, '=');
            if ($separator === false) {
                if ($machine !== '') {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' has an invalid -machine value: ' . $value);
                }
                $machine = $part;
                continue;
            }
            $key = substr($part, 0, $separator);
            $val = substr($part, $separator + 1);
            if ($key === 'type') {
                $machine = $val;
            } elseif ($key === 'accel') {
                $accel = $val;
            }
        }
        if ($machine === '' || preg_match(self::MACHINE_PATTERN, $machine) !== 1) {
            throw new HttpException(422, $prefix . self::RAW_KEY . ' has an invalid machine type: ' . $value);
        }
        if ($accel !== '' && $accel !== 'kvm' && $accel !== 'tcg') {
            throw new HttpException(422, $prefix . self::RAW_KEY . ' has an invalid machine accelerator: ' . $value);
        }
    }

    /**
     * Mirrors the agent's parseNICOption: model= or a bare model, MAC/id ignored.
     *
     * @throws HttpException 422
     */
    private static function assertNicOption(string $value, string $prefix): void
    {
        $parts = explode(',', $value);
        $model = '';
        foreach ($parts as $part) {
            if ($part === 'nic') {
                continue;
            }
            if (str_starts_with($part, 'model=')) {
                $model = substr($part, 6);
                continue;
            }
            if (str_starts_with($part, 'mac=') || str_starts_with($part, 'id=')) {
                continue;
            }
            if (count($parts) === 1) {
                $model = $part;
                continue;
            }
            throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported nic option: ' . $value);
        }
        $canonical = self::LEGACY['nic'][$model] ?? $model;
        if (!in_array($canonical, self::ALLOWED['nic'], true)) {
            throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported nic model: ' . $value);
        }
    }

    /**
     * Mirrors the agent's parseDriveInterface: the overlay path is engine-owned,
     * so a drive with a file or media reference is never allowed.
     *
     * @throws HttpException 422
     */
    private static function assertDrive(string $value, string $prefix): void
    {
        if (str_contains($value, 'file=') || str_contains($value, 'media=')) {
            throw new HttpException(
                422,
                $prefix . self::RAW_KEY . ' rejects -drive options with a file or media reference.',
            );
        }
        foreach (explode(',', $value) as $part) {
            if (!str_starts_with($part, 'if=')) {
                continue;
            }
            $interface = substr($part, 3);
            if (!in_array($interface, self::ALLOWED['disk_interface'], true)) {
                throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported disk interface: ' . $interface);
            }
        }
    }

    /**
     * @throws HttpException 422
     */
    private static function assertExtraValue(string $flag, string $value, string $prefix): void
    {
        if ($value !== '') {
            if (strlen($value) > self::MAX_EXTRA_VALUE_LENGTH) {
                throw new HttpException(422, $prefix . self::RAW_KEY . ' option ' . $flag . ' has an over-long value.');
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || str_starts_with($value, '-')) {
                throw new HttpException(422, $prefix . self::RAW_KEY . ' option ' . $flag . ' has an invalid value.');
            }
            $lower = strtolower($value);
            foreach (self::BLOCKED_VALUE_PARTS as $blocked) {
                if (str_contains($lower, $blocked)) {
                    throw new HttpException(
                        422,
                        $prefix . self::RAW_KEY . ' option ' . $flag . ' must not reference files, hosts or sockets.',
                    );
                }
            }
        }

        switch ($flag) {
            case '-no-acpi':
            case '-no-hpet':
            case '-no-shutdown':
                if ($value !== '') {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' option ' . $flag . ' does not take a value.');
                }

                return;
            case '-smbios':
                if (!str_contains($value, 'type=')) {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported -smbios value: ' . $value);
                }

                return;
            case '-global':
                if (!str_contains($value, '.') || !str_contains($value, '=')) {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported -global value: ' . $value);
                }

                return;
            case '-icount':
                if (preg_match(self::ICOUNT_PATTERN, $value) !== 1) {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported -icount value: ' . $value);
                }

                return;
            case '-realtime':
                if ($value !== 'mlock=off') {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' has an unsupported -realtime value: ' . $value);
                }

                return;
            case '-usbdevice':
                if ($value !== 'tablet') {
                    throw new HttpException(422, $prefix . self::RAW_KEY . ' only allows -usbdevice tablet.');
                }

                return;
        }

        throw new HttpException(422, $prefix . self::RAW_KEY . ' rejects the option: ' . $flag);
    }
}
