<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Image;

/**
 * Portable image names used inside lab files.
 *
 * Lab files never contain absolute paths: a node stores a single path segment
 * (an EVE-NG-style image name) that resolves to a row in the image store.
 */
final class ImageReference
{
    public const MAX_LENGTH = 128;

    /**
     * Turn an arbitrary value (path, filename, display name) into a portable
     * single-segment reference.
     */
    public static function portable(string $value): string
    {
        $base = basename(str_replace('\\', '/', trim($value)));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        $base = ltrim($base, '.');
        if ($base === '') {
            $base = 'image';
        }

        return substr($base, 0, self::MAX_LENGTH);
    }

    /**
     * Append -2, -3, … until the candidate is free. The extension, when
     * present, is kept at the end (base-2.qcow2).
     *
     * @param callable(string): bool $exists
     */
    public static function unique(string $candidate, callable $exists): string
    {
        $candidate = self::portable($candidate);
        if (!$exists($candidate)) {
            return $candidate;
        }

        $dot = strrpos($candidate, '.');
        $stem = $dot === false || $dot === 0 ? $candidate : substr($candidate, 0, $dot);
        $extension = $dot === false || $dot === 0 ? '' : substr($candidate, $dot);

        for ($index = 2; $index <= 999; $index++) {
            $suffix = '-' . $index;
            $trimmed = substr($stem, 0, max(1, self::MAX_LENGTH - strlen($suffix) - strlen($extension)));
            $next = $trimmed . $suffix . $extension;
            if (!$exists($next)) {
                return $next;
            }
        }

        return substr($stem, 0, self::MAX_LENGTH - 13) . '-' . bin2hex(random_bytes(4)) . $extension;
    }

    /**
     * Values that are already a single, safe path segment.
     */
    public static function isPortable(string $value): bool
    {
        return $value !== ''
            && $value === trim($value)
            && !str_contains($value, '/')
            && !str_contains($value, '\\')
            && !str_contains($value, '..')
            && strlen($value) <= self::MAX_LENGTH;
    }
}
