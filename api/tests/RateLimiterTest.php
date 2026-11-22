<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Http\HttpException;

final class RateLimiterTest extends TestCase
{
    private PDO $pdo;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            'CREATE TABLE login_attempts ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'username TEXT NOT NULL, '
            . 'ip TEXT NOT NULL, '
            . 'attempted_at INTEGER NOT NULL, '
            . 'success INTEGER NOT NULL DEFAULT 0)'
        );
        $this->limiter = new RateLimiter($this->pdo);
    }

    public function testCountIncludesSuccessfulAndFailedAttemptsWithinTheWindow(): void
    {
        $this->limiter->record('budget:key', '192.0.2.1', false);
        $this->limiter->consume('budget:key', '192.0.2.1');
        $this->limiter->consume('budget:key', '192.0.2.1');

        self::assertSame(3, $this->limiter->count('budget:key', '192.0.2.1', 900));
        self::assertSame(0, $this->limiter->count('budget:key', '192.0.2.2', 900));
        self::assertSame(0, $this->limiter->count('other:key', '192.0.2.1', 900));

        $old = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip, attempted_at, success) '
            . 'VALUES (:username, :ip, :attempted_at, 1)'
        );
        $old->execute([
            ':username' => 'budget:key',
            ':ip' => '192.0.2.1',
            ':attempted_at' => time() - 901,
        ]);

        self::assertSame(3, $this->limiter->count('budget:key', '192.0.2.1', 900));
        self::assertSame(4, $this->limiter->count('budget:key', '192.0.2.1', 3600));
    }

    public function testAssertCountBelowThrowsAtTheLimit(): void
    {
        $this->limiter->consume('budget:key', '192.0.2.1');
        $this->limiter->consume('budget:key', '192.0.2.1');

        // Below the limit is accepted.
        $this->limiter->assertCountBelow('budget:key', '192.0.2.1', 3, 900, 'limited');

        $this->limiter->consume('budget:key', '192.0.2.1');
        try {
            $this->limiter->assertCountBelow('budget:key', '192.0.2.1', 3, 900, 'limited');
            self::fail('The count-based budget did not reject the limit.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('limited', $exception->getMessage());
        }
    }

    public function testSuccessfulBudgetRowsDoNotCountAsLoginFailures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->limiter->consume('guest:192.0.2.1', '192.0.2.1');
        }

        // Five successful budget rows must not trip the credential limiter.
        $this->limiter->assertAllowed('guest:192.0.2.1', '192.0.2.1');
        self::assertSame(0, $this->countFailures('guest:192.0.2.1', '192.0.2.1'));

        // Real failures still do.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->limiter->record('guest:192.0.2.1', '192.0.2.1', false);
        }
        self::assertSame(5, $this->countFailures('guest:192.0.2.1', '192.0.2.1'));

        try {
            $this->limiter->assertAllowed('guest:192.0.2.1', '192.0.2.1');
            self::fail('Failed attempts did not trip the credential limiter.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->status);
        }
    }

    public function testConsumeStoresASuccessfulAttempt(): void
    {
        $this->limiter->consume('captcha:192.0.2.1', '192.0.2.1');

        $statement = $this->pdo->prepare(
            'SELECT username, ip, success FROM login_attempts WHERE username = :username'
        );
        $statement->execute([':username' => 'captcha:192.0.2.1']);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame('captcha:192.0.2.1', (string) $row['username']);
        self::assertSame('192.0.2.1', (string) $row['ip']);
        self::assertSame(1, (int) $row['success']);
    }

    private function countFailures(string $key, string $ip): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE username = :username AND ip = :ip AND success = 0'
        );
        $statement->execute([':username' => $key, ':ip' => $ip]);

        return (int) $statement->fetchColumn();
    }
}
