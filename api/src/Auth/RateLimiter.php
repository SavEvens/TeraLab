<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use PDO;
use TeraLab\Api\Http\HttpException;

final class RateLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_FAILURES = 5;

    /** Captcha verification budget: every attempt is charged before egress. */
    public const CAPTCHA_WINDOW_SECONDS = 900;

    public const CAPTCHA_MAX_ATTEMPTS = 20;

    public const CAPTCHA_LIMIT_MESSAGE = 'Too many captcha attempts. Try again later.';

    /** Guest-entry budget: one minted guest consumes one slot. */
    public const GUEST_WINDOW_SECONDS = 3600;

    public const GUEST_MAX_ENTRIES = 20;

    public const GUEST_LIMIT_MESSAGE = 'Too many demo entries from this network. Try again later.';

    /**
     * First-run setup budget. Setup is a one-time operator action, so both an
     * address budget and a global budget are small; every attempt is charged
     * before the token is compared.
     */
    public const SETUP_WINDOW_SECONDS = 900;

    public const SETUP_MAX_PER_IP = 10;

    public const SETUP_MAX_GLOBAL = 50;

    public const SETUP_GLOBAL_KEY = 'setup:global';

    public const SETUP_GLOBAL_IP = '*';

    public const SETUP_LIMIT_MESSAGE = 'Too many setup attempts. Try again later.';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(string $username, string $ip): void
    {
        if ($this->countAttempts($username, $ip, self::WINDOW_SECONDS, true) >= self::MAX_FAILURES) {
            throw new HttpException(429, 'Too many login attempts. Try again later.');
        }
    }

    /**
     * Count every attempt for a budget key inside the window, regardless of
     * the stored success flag.
     */
    public function count(string $key, string $ip, int $windowSeconds): int
    {
        return $this->countAttempts($key, $ip, $windowSeconds, false);
    }

    /**
     * Enforce a count-based budget. Unlike assertAllowed(), this counts both
     * successful and failed rows: it bounds total attempts, not credentials.
     */
    public function assertCountBelow(
        string $key,
        string $ip,
        int $max,
        int $windowSeconds,
        string $message,
    ): void {
        if ($this->count($key, $ip, $windowSeconds) >= $max) {
            throw new HttpException(429, $message);
        }
    }

    /**
     * Consume one slot in a count-based budget.
     *
     * Budget rows are stored as successful attempts so they never appear as
     * login failures in anomaly reports; assertCountBelow() counts them
     * regardless of that flag.
     */
    public function consume(string $key, string $ip): void
    {
        $this->record($key, $ip, true);
    }

    public function record(string $username, string $ip, bool $success): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip, attempted_at, success) '
            . 'VALUES (:username, :ip, :attempted_at, :success)'
        );
        $statement->execute([
            ':username' => $username,
            ':ip' => $ip,
            ':attempted_at' => time(),
            ':success' => $success ? 1 : 0,
        ]);
    }

    public function clear(string $username, string $ip): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE username = :username AND ip = :ip'
        );
        $statement->execute([':username' => $username, ':ip' => $ip]);
    }

    private function countAttempts(
        string $key,
        string $ip,
        int $windowSeconds,
        bool $failuresOnly,
    ): int {
        $sql = 'SELECT COUNT(*) FROM login_attempts '
            . 'WHERE username = :username AND ip = :ip AND attempted_at >= :since';
        if ($failuresOnly) {
            $sql .= ' AND success = 0';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':username' => $key,
            ':ip' => $ip,
            ':since' => time() - $windowSeconds,
        ]);

        return (int) $statement->fetchColumn();
    }
}
