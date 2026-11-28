<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** Bound hashing work for a client-controlled password field. */
    public const MAX_LENGTH = 256;

    /** @var list<string> */
    private const COMMON_PASSWORDS = [
        'password',
        'password123',
        'password1234',
        'admin',
        'admin123',
        'unetlab',
        'teralab',
        'letmein',
        'qwerty123456',
        'welcome12345',
    ];

    /**
     * @return list<string>
     */
    public function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > self::MAX_LENGTH) {
            $errors[] = 'Password must be at most ' . self::MAX_LENGTH . ' characters.';
        }

        $classes = 0;
        foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^A-Za-z0-9]/'] as $pattern) {
            if (preg_match($pattern, $password) === 1) {
                $classes++;
            }
        }
        if ($classes < 3) {
            $errors[] = 'Use at least three of lowercase, uppercase, digits, and symbols.';
        }

        if (in_array(strtolower($password), self::COMMON_PASSWORDS, true)) {
            $errors[] = 'Password is too common.';
        }

        if (preg_match('/(.)\1{3,}/', $password) === 1) {
            $errors[] = 'Password repeats a character too many times.';
        }

        return $errors;
    }
}
