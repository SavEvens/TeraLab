<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Auth\PasswordPolicy;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;

final class LegacyAuthTest extends TestCase
{
    private string $root;
    private Config $config;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-legacy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
    }

    protected function tearDown(): void
    {
        $files = glob($this->root . '/data/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->root . '/data');
        @rmdir($this->root);
    }

    public function testLegacyHashIsAcceptedOnceAndRehashed(): void
    {
        $legacyPassword = 'Legacy-Pass-2026!';
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, legacy_password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :legacy, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':username' => 'legacyadmin',
            ':password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
            ':legacy' => hash('sha256', $legacyPassword),
            ':role' => 'admin',
            ':now' => time(),
        ]);

        $auth = new AuthService(
            $this->pdo,
            new PasswordPolicy(),
            new RateLimiter($this->pdo),
            new AuditLogger($this->pdo),
            $this->config,
        );

        $session = $auth->login('legacyadmin', $legacyPassword, '127.0.0.1', 'phpunit');
        self::assertSame('legacyadmin', $session['user']['username']);

        $row = $this->pdo->query("SELECT password_hash, legacy_password_hash FROM users WHERE username = 'legacyadmin'")->fetch();
        self::assertIsArray($row);
        self::assertNull($row['legacy_password_hash']);
        self::assertTrue(password_verify($legacyPassword, (string) $row['password_hash']));

        // The legacy hash is gone; a wrong password still fails.
        $this->expectException(\TeraLab\Api\Http\HttpException::class);
        $auth->login('legacyadmin', 'wrong-password', '127.0.0.1', 'phpunit');
    }

    public function testIdleSessionsAreRejected(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':username' => 'idleuser',
            ':password_hash' => password_hash('Idle-Pass-2026!', PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':now' => time(),
        ]);

        $auth = new AuthService(
            $this->pdo,
            new PasswordPolicy(),
            new RateLimiter($this->pdo),
            new AuditLogger($this->pdo),
            $this->config,
        );
        $session = $auth->login('idleuser', 'Idle-Pass-2026!', '127.0.0.1', 'phpunit');
        $this->pdo->exec('UPDATE sessions SET last_seen_at = ' . (time() - 4000));

        $this->expectException(\TeraLab\Api\Http\HttpException::class);
        $auth->authenticate($session['session_id']);
    }
}
