<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests\Support;

/**
 * Test helper that mirrors the shipped static image tree into a temporary
 * application root. The icon/picture policies resolve files against the
 * configured root, so isolated test roots need the same static assets the
 * product ships.
 */
trait StaticAssets
{
    private function installStaticAssets(string $root): void
    {
        $source = dirname(__DIR__, 3) . '/html/images';
        $target = $root . '/html/images';
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
            self::fail('Cannot create the temporary static image directory.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0770, true);
                }
                continue;
            }
            copy($item->getPathname(), $destination);
        }
    }
}
