<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Auth\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    public function testRejectsShortAndCommonPasswords(): void
    {
        $policy = new PasswordPolicy();

        self::assertNotSame([], $policy->validate('short'));
        self::assertNotSame([], $policy->validate('password123456'));
        self::assertNotSame([], $policy->validate('aaaaaaaaaaaa'));
        self::assertNotSame([], $policy->validate('lowercaseonly1'));
    }

    public function testAcceptsStrongPassword(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame([], $policy->validate('Teralab-Admin-2026!'));
    }
}
