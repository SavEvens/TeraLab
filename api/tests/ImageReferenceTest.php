<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Image\ImageReference;

final class ImageReferenceTest extends TestCase
{
    public function testPortableNames(): void
    {
        self::assertSame('base.qcow2', ImageReference::portable('/var/lib/teralab/images/qemu/base.qcow2'));
        self::assertSame('disk.qcow2', ImageReference::portable('dir\\disk.qcow2'));
        self::assertSame('IOL_L3.bin', ImageReference::portable('IOL L3.bin'));
        self::assertSame('image', ImageReference::portable('..'));
        self::assertSame('image', ImageReference::portable(''));
        self::assertSame('image', ImageReference::portable('/'));
    }

    public function testUniqueCandidates(): void
    {
        $taken = [];
        $exists = static function (string $value) use (&$taken): bool {
            return isset($taken[$value]);
        };

        self::assertSame('base.qcow2', ImageReference::unique('base.qcow2', $exists));
        $taken['base.qcow2'] = true;
        self::assertSame('base-2.qcow2', ImageReference::unique('base.qcow2', $exists));
        $taken['base-2.qcow2'] = true;
        self::assertSame('base-3.qcow2', ImageReference::unique('base.qcow2', $exists));

        self::assertSame('veos', ImageReference::unique('veos', $exists));
        $taken['veos'] = true;
        self::assertSame('veos-2', ImageReference::unique('veos', $exists));
    }

    public function testPortableSegments(): void
    {
        self::assertTrue(ImageReference::isPortable('base.qcow2'));
        self::assertTrue(ImageReference::isPortable('eve-image-1'));
        self::assertFalse(ImageReference::isPortable('/base.qcow2'));
        self::assertFalse(ImageReference::isPortable('a/b'));
        self::assertFalse(ImageReference::isPortable('..'));
        self::assertFalse(ImageReference::isPortable(' base.qcow2'));
        self::assertFalse(ImageReference::isPortable(''));
    }
}
