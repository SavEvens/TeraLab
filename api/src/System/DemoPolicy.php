<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

/**
 * Conventions shared by the demo tier.
 *
 * A guest is an ordinary `users` row with `guest = 1`. Guest rows always carry
 * a non-negative `expiration`; `-1` means "no expiry" and is reserved for
 * non-guest accounts. Callers should not re-derive these rules themselves.
 */
final class DemoPolicy
{
    /**
     * @param array<string, mixed> $user
     */
    public static function isGuest(array $user): bool
    {
        $guest = $user['guest'] ?? false;
        if (is_bool($guest)) {
            return $guest;
        }
        if (is_int($guest)) {
            return $guest === 1;
        }
        if (is_string($guest)) {
            return in_array(strtolower(trim($guest)), ['1', 'true'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isExpired(array $user): bool
    {
        $expiration = $user['expiration'] ?? -1;
        if (is_string($expiration) && is_numeric($expiration)) {
            $expiration = (int) $expiration;
        }
        if (!is_int($expiration)) {
            return false;
        }

        return $expiration >= 0 && $expiration < time();
    }
}
