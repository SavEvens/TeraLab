<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Templates;

use TeraLab\Api\Http\HttpException;

/**
 * Bounded `options.docker` block for container-backed templates.
 *
 * A docker node is a container, not a virtual machine: there is no image store
 * lookup, no iso/kernel/initrd and no QEMU parameters. The only values a
 * template can contribute are the container reference, the console command and
 * a small capability allowlist. The agent validates the same values again
 * (defence in depth); this class is the API-side authority.
 */
final class DockerOptions
{
    /** Template option name of the block. */
    public const OPTION = 'docker';

    /** Keys allowed inside `options.docker`. */
    public const KEYS = ['image', 'console_command', 'cap_add', 'pull'];

    /**
     * Docker reference grammar: optional registry host (with optional port),
     * lowercase repository path, optional tag, optional sha256 digest.
     *
     * Deliberately stricter than the distribution/reference parser: the
     * repository path is lower-case only and a digest must be a full sha256.
     */
    public const IMAGE_PATTERN = '~^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(?::[0-9]{1,5})?/)?[a-z0-9]+(?:(?:[._]|__|[-]+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|[-]+)[a-z0-9]+)*)*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[a-f0-9]{64})?\z~';

    public const IMAGE_MAX_LENGTH = 255;

    /** Console command: an absolute path, no shell metacharacters. */
    public const CONSOLE_COMMAND_PATTERN = '~^/[A-Za-z0-9_./+-]{1,255}\z~';

    /**
     * Capabilities a template may add. Everything else stays dropped.
     * `SYS_ADMIN` is required by some routing daemons (FRR zebra) and remains
     * opt-in per template; there is no blanket escalation.
     */
    public const CAP_ADD = ['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'];

    public const MAX_CAP_ADD = 8;

    /**
     * Validate `options.docker` when present.
     *
     * @param array<string, mixed> $options
     * @throws HttpException 422 when the block or one of its values is invalid
     */
    public static function validate(array $options, bool $required = false): void
    {
        if (!array_key_exists(self::OPTION, $options)) {
            if ($required) {
                throw new HttpException(422, 'A docker template requires options.docker.image.');
            }

            return;
        }

        $docker = $options[self::OPTION];
        if (!self::isMapping($docker)) {
            throw new HttpException(422, 'Template option docker must be a mapping.');
        }

        foreach (array_keys($docker) as $key) {
            if (!is_string($key) || !in_array($key, self::KEYS, true)) {
                throw new HttpException(
                    422,
                    'Template option docker has an unsupported key: ' . (string) $key . '.',
                );
            }
        }

        $image = $docker['image'] ?? null;
        if ($image === null || (is_string($image) && trim($image) === '')) {
            if ($required) {
                throw new HttpException(422, 'A docker template requires options.docker.image.');
            }
        } else {
            if (!is_string($image)) {
                throw new HttpException(422, 'Template option docker.image must be a string.');
            }
            self::assertImage($image);
        }

        if (array_key_exists('console_command', $docker)) {
            $command = $docker['console_command'];
            if (!is_string($command)) {
                throw new HttpException(422, 'Template option docker.console_command must be a string.');
            }
            if (trim($command) !== '') {
                self::assertConsoleCommand($command);
            }
        }

        if (array_key_exists('cap_add', $docker)) {
            $capabilities = $docker['cap_add'];
            if (!is_array($capabilities) || !array_is_list($capabilities)) {
                throw new HttpException(422, 'Template option docker.cap_add must be a list.');
            }
            if (count($capabilities) > self::MAX_CAP_ADD) {
                throw new HttpException(
                    422,
                    'Template option docker.cap_add allows at most ' . self::MAX_CAP_ADD . ' entries.',
                );
            }
            foreach ($capabilities as $capability) {
                if (!is_string($capability) || !in_array($capability, self::CAP_ADD, true)) {
                    throw new HttpException(
                        422,
                        'Template option docker.cap_add must be one of: ' . implode(', ', self::CAP_ADD) . '.',
                    );
                }
            }
        }

        if (array_key_exists('pull', $docker) && !is_bool($docker['pull'])) {
            throw new HttpException(422, 'Template option docker.pull must be a boolean.');
        }
    }

    /**
     * The container reference declared by the template, if any.
     *
     * @param array<string, mixed> $options
     * @return string|null
     * @throws HttpException 422 when the declaration is invalid
     */
    public static function image(array $options): ?string
    {
        $docker = $options[self::OPTION] ?? null;
        if (!is_array($docker)) {
            return null;
        }
        $image = $docker['image'] ?? null;
        if (!is_string($image) || trim($image) === '') {
            return null;
        }
        self::assertImage($image);

        return $image;
    }

    /**
     * Map a validated template `options` mapping to the agent's `docker`
     * block. `pull` is always explicit; empty optional fields are omitted.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws HttpException 422 when a value is invalid
     */
    public static function spec(array $options): array
    {
        $docker = $options[self::OPTION] ?? null;
        if (!is_array($docker)) {
            $docker = [];
        }

        $spec = [];
        $command = $docker['console_command'] ?? null;
        if (is_string($command) && trim($command) !== '') {
            self::assertConsoleCommand($command);
            $spec['console_command'] = $command;
        }

        $capabilities = $docker['cap_add'] ?? null;
        if (is_array($capabilities)) {
            $valid = [];
            foreach ($capabilities as $capability) {
                if (!is_string($capability) || !in_array($capability, self::CAP_ADD, true)) {
                    throw new HttpException(
                        422,
                        'Template option docker.cap_add must be one of: ' . implode(', ', self::CAP_ADD) . '.',
                    );
                }
                if (!in_array($capability, $valid, true)) {
                    $valid[] = $capability;
                }
            }
            if ($valid !== []) {
                $spec['cap_add'] = $valid;
            }
        }

        $pull = $docker['pull'] ?? true;
        if (!is_bool($pull)) {
            throw new HttpException(422, 'Template option docker.pull must be a boolean.');
        }
        $spec['pull'] = $pull;

        return $spec;
    }

    /**
     * @throws HttpException 422 when the reference is not acceptable
     */
    private static function assertImage(string $image): void
    {
        if (strlen($image) > self::IMAGE_MAX_LENGTH) {
            throw new HttpException(
                422,
                'Template option docker.image must be at most ' . self::IMAGE_MAX_LENGTH . ' characters.',
            );
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $image) === 1
            || str_contains($image, ' ')
            || str_contains($image, '..')
            || str_starts_with($image, '/')
        ) {
            throw new HttpException(422, 'Template option docker.image must be a valid container image reference.');
        }
        if (preg_match(self::IMAGE_PATTERN, $image) !== 1) {
            throw new HttpException(422, 'Template option docker.image must be a valid container image reference.');
        }
    }

    /**
     * @throws HttpException 422 when the command is not an absolute path
     */
    private static function assertConsoleCommand(string $command): void
    {
        if (preg_match(self::CONSOLE_COMMAND_PATTERN, $command) !== 1) {
            throw new HttpException(
                422,
                'Template option docker.console_command must be an absolute path without shell metacharacters.',
            );
        }
    }

    private static function isMapping(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
