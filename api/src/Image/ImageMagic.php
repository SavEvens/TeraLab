<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Image;

use RuntimeException;

/**
 * Magic-byte checks shared by uploads and legacy-addon adoption.
 *
 * Uploads fail closed with a 422 when a file does not match its extension;
 * the importer uses the same checks to classify an existing tree.
 */
final class ImageMagic
{
    /**
     * Whether the file matches the magic expected for its extension. Unknown
     * extensions are accepted (no check applies).
     *
     * @throws RuntimeException when the file cannot be read
     */
    public static function matches(string $path, string $extension): bool
    {
        return match (strtolower($extension)) {
            'qcow2' => self::isQcow2($path),
            'iso' => self::isIso9660($path),
            'bin' => self::isElf($path),
            default => true,
        };
    }

    /**
     * @throws RuntimeException when the file cannot be read
     */
    public static function isQcow2(string $path): bool
    {
        return self::bytes($path, 0, 4) === "QFI\xfb";
    }

    /**
     * ISO 9660 primary or supplementary volume descriptor.
     *
     * @throws RuntimeException when the file cannot be read
     */
    public static function isIso9660(string $path): bool
    {
        foreach ([0x8001, 0x8801] as $offset) {
            if (self::bytes($path, $offset, 5) === 'CD001') {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws RuntimeException when the file cannot be read
     */
    public static function isElf(string $path): bool
    {
        return self::bytes($path, 0, 4) === "\x7fELF";
    }

    /**
     * @throws RuntimeException when the file cannot be read
     */
    private static function bytes(string $path, int $offset, int $length): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the image file.');
        }
        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return '';
            }
            $data = fread($handle, $length);

            return is_string($data) ? $data : '';
        } finally {
            fclose($handle);
        }
    }
}
