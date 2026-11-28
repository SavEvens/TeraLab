<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use TeraLab\Api\Http\HttpException;

/**
 * Email normalisation shared by user administration and first-run setup.
 *
 * Keeping one implementation means setup cannot accept an address that
 * {@see UserService} would reject (or vice versa).
 */
final class EmailValidator
{
    private const MAX_LENGTH = 254;

    /**
     * @throws HttpException 422 when the address is present but invalid
     */
    public static function validate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'email must be a string.', [
                'email' => ['Invalid email address.'],
            ]);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new HttpException(422, 'Invalid email address.', [
                'email' => ['Invalid email address.'],
            ]);
        }

        return $value;
    }
}
