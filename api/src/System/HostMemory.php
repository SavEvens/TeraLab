<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

/**
 * Host memory reader for the demo resource floor.
 *
 * The API and the agent share one host, so `/proc/meminfo` is authoritative
 * and needs no extra plumbing. Every failure is reported as `null`: callers
 * fail open (no floor rejection, no reaping) rather than guess.
 */
final class HostMemory
{
    private const PATH = '/proc/meminfo';

    public function __construct(private readonly string $meminfoPath = self::PATH)
    {
    }

    /**
     * Estimated memory available for new work, in MiB.
     *
     * Returns null when the file or the `MemAvailable` key is missing or
     * malformed.
     */
    public function availableMb(): ?int
    {
        if (!is_file($this->meminfoPath) || !is_readable($this->meminfoPath)) {
            return null;
        }
        $lines = file($this->meminfoPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            if (preg_match('/^MemAvailable:\s+(\d+)\s*kB\s*$/', $line, $matches) !== 1) {
                continue;
            }

            return intdiv((int) $matches[1], 1024);
        }

        return null;
    }
}
