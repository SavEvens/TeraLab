<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\System\HostMemory;

final class HostMemoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-host-memory-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
    }

    public function testParsesMemAvailableFromKilobytes(): void
    {
        $path = $this->fixture(
            "MemTotal:       16384000 kB\n"
            . "MemFree:         2048000 kB\n"
            . "MemAvailable:    1048576 kB\n"
            . "SwapTotal:             0 kB\n"
        );

        self::assertSame(1024, (new HostMemory($path))->availableMb());
    }

    public function testMissingKeyReturnsNull(): void
    {
        $path = $this->fixture("MemTotal: 16384000 kB\nMemFree: 2048000 kB\n");

        self::assertNull((new HostMemory($path))->availableMb());
    }

    public function testMalformedValueReturnsNull(): void
    {
        foreach (["MemAvailable: not-a-number kB\n", "MemAvailable: 1024 MB\n", "MemAvailable:\n"] as $content) {
            $path = $this->fixture($content);
            self::assertNull((new HostMemory($path))->availableMb());
        }
    }

    public function testMissingFileReturnsNull(): void
    {
        self::assertNull((new HostMemory($this->root . '/missing'))->availableMb());
    }

    private function fixture(string $contents): string
    {
        $path = $this->root . '/meminfo-' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        return $path;
    }
}
