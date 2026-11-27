<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class LabRepository
{
    public function __construct(
        private readonly string $labsDir,
        private readonly UnlParser $parser,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $root = realpath($this->labsDir);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $labs = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'unl') {
                continue;
            }

            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR . '.trash' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            try {
                $lab = $this->parser->parse($path);
            } catch (Throwable) {
                continue;
            }

            $lab['path'] = substr($path, strlen($root) + 1);
            $lab['modified'] = $file->getMTime();
            $labs[] = $lab;
        }

        usort(
            $labs,
            static fn (array $left, array $right): int => ((int) $right['modified']) <=> ((int) $left['modified'])
        );

        return $labs;
    }

    /**
     * Legacy/display lookup by canonical id or basename.
     *
     * Never use this for authorization: two labs in different folders may share
     * a basename. Authenticated operations use findById().
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $lab) {
            $basename = basename((string) $lab['path'], '.unl');
            if ($lab['id'] === $id || $basename === $id) {
                return $lab;
            }
        }

        return null;
    }

    /**
     * Resolve exactly one lab by its canonical id.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $matches = [];
        foreach ($this->all() as $lab) {
            if ((string) ($lab['id'] ?? '') === $id) {
                $matches[] = $lab;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path): ?array
    {
        $normalized = '/' . ltrim($path, '/');
        foreach ($this->all() as $lab) {
            if ('/' . (string) $lab['path'] === $normalized) {
                return $lab;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->all());
    }
}
