<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Image;

use PDO;
use TeraLab\Api\Config;

/**
 * Resolves portable image references (as stored in lab files) to absolute
 * paths, with backward compatibility for v1.4.x labs that embedded paths.
 */
final class ImageReferences
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /**
     * Resolve a lab value to an absolute image path.
     *
     * Accepts, in order: an exact reference, a legacy absolute path inside the
     * image store, and a legacy relative value below the image store.
     */
    public function resolve(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!str_contains($value, '/') && !str_contains($value, '\\')) {
            $statement = $this->pdo->prepare('SELECT path FROM images WHERE reference = :reference LIMIT 1');
            $statement->execute([':reference' => $value]);
            $path = $statement->fetchColumn();
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        $candidate = $value;
        if (!str_starts_with($candidate, '/')) {
            $candidate = rtrim($this->config->imagesDir, '/') . '/' . ltrim($candidate, '/');
        }
        $resolved = realpath($candidate);
        $root = realpath($this->config->imagesDir);
        if ($resolved === false || $root === false || !is_file($resolved)) {
            return null;
        }
        if ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $resolved;
    }

    /** The reference of the image stored at an absolute path, if any. */
    public function referenceForPath(string $path): ?string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            return null;
        }
        $statement = $this->pdo->query('SELECT reference, path FROM images');
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = realpath((string) ($row['path'] ?? ''));
            $reference = $row['reference'] ?? null;
            if ($candidate !== false && $candidate === $resolved && is_string($reference) && $reference !== '') {
                return $reference;
            }
        }

        return null;
    }

    public function exists(string $reference): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM images WHERE reference = :reference LIMIT 1');
        $statement->execute([':reference' => $reference]);

        return $statement->fetchColumn() !== false;
    }

    public function unique(string $candidate): string
    {
        return ImageReference::unique($candidate, fn (string $reference): bool => $this->exists($reference));
    }

    /** A portable replacement for any value, resolving known paths to rows. */
    public function portableValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (ImageReference::isPortable($value) && $this->exists($value)) {
            return $value;
        }
        $reference = $this->referenceForPath($value);
        if ($reference !== null) {
            return $reference;
        }

        return ImageReference::portable($value);
    }
}
