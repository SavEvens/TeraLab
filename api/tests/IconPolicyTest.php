<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Policy\IconPolicy;

final class IconPolicyTest extends TestCase
{
    private IconPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new IconPolicy(dirname(__DIR__, 2));
    }

    public function testEmptyAndExistingBasenamesAreAccepted(): void
    {
        self::assertSame('', $this->policy->validate(''));
        self::assertSame('Router.png', $this->policy->validate('Router.png'));
        self::assertSame('Cisco ACS.png', $this->policy->validate('Cisco ACS.png'));
        self::assertTrue($this->policy->isValid('Switch L3.png'));
    }

    public function testPathsUrlsAndUnknownNamesAreRejected(): void
    {
        foreach ([
            '../../about.php',
            '../../../api/v2/x',
            '//evil',
            'https://x/y',
            'file:///etc/passwd',
            'Router.png/..',
            '..',
            '.',
            'missing.png',
            "Router.png\n",
            "bad\x00name.png",
        ] as $value) {
            try {
                $this->policy->validate($value);
                self::fail('expected rejection: ' . var_export($value, true));
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status, var_export($value, true));
            }
        }
    }

    public function testNonStringValuesAreRejected(): void
    {
        foreach ([['Router.png'], 1, true] as $value) {
            try {
                $this->policy->validate($value);
                self::fail('expected rejection: ' . get_debug_type($value));
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }
}
