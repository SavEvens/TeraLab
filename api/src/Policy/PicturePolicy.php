<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Policy;

use TeraLab\Api\Http\HttpException;

/**
 * Picture annotations are either empty, a small base64 image data URI, or a
 * static same-origin path under `/images/`. External URLs, `javascript:` and
 * `file:` values, and same-origin API paths are rejected so a lab file cannot
 * turn into a stored same-origin request or a third-party tracking pixel.
 */
final class PicturePolicy
{
    /**
     * Cap for the base64-encoded image payload (the text after
     * `data:image/<type>;base64,`). 512 KiB keeps a picture annotation small
     * enough for the 10 MB lab-file limit while allowing roughly a 384 KiB
     * decoded raster image.
     */
    public const MAX_ENCODED_BYTES = 524288;

    private const DATA_URI = '~^data:image/(png|jpeg|gif|webp|svg\+xml);base64,([A-Za-z0-9+/]+={0,2})$~i';

    public function __construct(private readonly string $root)
    {
    }

    public function validate(mixed $value, string $context = 'image'): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw new HttpException(422, $context . ' must be text.');
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
        if (stripos($value, 'data:') === 0) {
            return $this->dataUriProblem($value);
        }
        if (!str_starts_with($value, '/images/')) {
            return 'must be empty, a /images/ static path, or an image data URI.';
        }

        return $this->staticPathProblem($value);
    }

    private function dataUriProblem(string $value): ?string
    {
        if (strlen($value) > self::MAX_ENCODED_BYTES + 64) {
            return 'exceeds the ' . self::MAX_ENCODED_BYTES . '-byte encoded limit.';
        }
        if (preg_match(self::DATA_URI, $value, $matches) !== 1) {
            return 'is not a supported base64 image data URI.';
        }
        $payload = $matches[2];
        if (strlen($payload) > self::MAX_ENCODED_BYTES) {
            return 'exceeds the ' . self::MAX_ENCODED_BYTES . '-byte encoded limit.';
        }
        if (base64_decode($payload, true) === false) {
            return 'has an invalid base64 payload.';
        }

        return null;
    }

    private function staticPathProblem(string $value): ?string
    {
        if (str_contains($value, '\\')) {
            return 'must not contain a backslash.';
        }
        if (str_contains($value, '//')) {
            return 'must not contain an empty path segment.';
        }
        if (str_contains($value, '..')) {
            return 'must be a normalised path.';
        }
        if (preg_match('/[?#]/', $value) === 1) {
            return 'must not contain a query or fragment.';
        }

        $root = realpath($this->root . '/html/images');
        $candidate = realpath($this->root . '/html' . $value);
        if ($root === false || $candidate === false) {
            return 'does not resolve to a static file.';
        }
        if (!str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            return 'resolves outside the static image directory.';
        }
        if (!is_file($candidate)) {
            return 'does not resolve to a regular file.';
        }
        if (preg_match('/\.(?:php\d*|phtml|phar|phps|pht)$/i', $candidate) === 1) {
            return 'must not reference an executable script.';
        }

        return null;
    }
}
