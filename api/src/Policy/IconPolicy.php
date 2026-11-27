<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Policy;

use TeraLab\Api\Http\HttpException;

/**
 * Template and lab icons are exactly one basename from the shipped static icon
 * set. The browser interpolates the value into `/images/icons/<icon>`, so a
 * path, URL, or traversal-shaped value must never reach storage.
 */
final class IconPolicy
{
    public const DIRECTORY = 'html/images/icons';
    public const MAX_LENGTH = 255;

    public function __construct(private readonly string $root)
    {
    }

    public function validate(mixed $value, string $context = 'icon'): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw new HttpException(422, $context . ' must be text.');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            throw new HttpException(422, $context . ' is too long.');
        }
        $problem = $this->problem($value);
        if ($problem !== null) {
            throw new HttpException(422, $context . ' ' . $problem);
        }

        return $value;
    }

    public function isValid(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && $this->problem($value) === null;
    }

    private function problem(string $value): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return 'must not contain control characters.';
        }
        if (str_contains($value, '/') || str_contains($value, '\\') || basename($value) !== $value) {
            return 'must be a single file name, not a path.';
        }
        if ($value === '.' || $value === '..' || str_contains($value, '..')) {
            return 'must not contain "..".';
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) === 1) {
            return 'must not be a URL.';
        }

        $directory = realpath($this->root . '/' . self::DIRECTORY);
        if ($directory === false || !is_dir($directory)) {
            return 'is not in the static icon set.';
        }
        $candidate = realpath($directory . '/' . $value);
        if ($candidate === false || dirname($candidate) !== $directory || !is_file($candidate)) {
            return 'is not in the static icon set.';
        }

        return null;
    }
}
