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

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(string $username, string $ip): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts '
            . 'WHERE username = :username AND ip = :ip AND success = 0 AND attempted_at >= :since'
        );
        $statement->execute([
            ':username' => $username,
            ':ip' => $ip,
            ':since' => time() - self::WINDOW_SECONDS,
        ]);

        if ((int) $statement->fetchColumn() >= self::MAX_FAILURES) {
            throw new HttpException(429, 'Too many login attempts. Try again later.');
        }
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
}
