<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TeraLab\Api\Labs\UnlParser;

final class UnlParserTest extends TestCase
{
    public function testParsesLabMetadata(): void
    {
        $lab = (new UnlParser())->parse(__DIR__ . '/fixtures/sample.unl');

        self::assertSame('sample-lab', $lab['id']);
        self::assertSame('Sample Lab', $lab['name']);
        self::assertSame('TeraLab', $lab['author']);
        self::assertSame(1, $lab['nodes']);
        self::assertSame(1, $lab['networks']);
    }

    public function testRejectsUnexpectedRoot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'teralab-lab-');
        self::assertIsString($path);
        file_put_contents($path, '<?xml version="1.0"?><notlab/>');

        try {
            $this->expectException(RuntimeException::class);
            (new UnlParser())->parse($path);
        } finally {
            unlink($path);
        }
    }
}
