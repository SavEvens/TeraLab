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
use TeraLab\Api\Auth\TokenService;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\System\SettingsService;

final class WriteThrottleTest extends TestCase
{
    private string $root;
    private Config $config;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-throttle-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testSessionLastSeenIsWrittenAtMostOncePerWindow(): void
    {
        $now = time();
        $this->insertUser('throttle-user');
        $raw = bin2hex(random_bytes(16));
        $this->insertSession('throttle-user', hash('sha256', $raw), $now - 120);

        $service = $this->authService();
        $service->authenticate($raw);
        $first = $this->lastSeen(hash('sha256', $raw));
        self::assertGreaterThanOrEqual($now - 2, $first);

        // A request inside the 30 s window must not write again.
        $this->setLastSeen(hash('sha256', $raw), $now - 5);
        $service->authenticate($raw);
        self::assertSame($now - 5, $this->lastSeen(hash('sha256', $raw)));

        // Once the window has passed, activity is recorded again.
        $this->setLastSeen(hash('sha256', $raw), $now - AuthService::LAST_SEEN_THROTTLE_SECONDS);
        $service->authenticate($raw);
        self::assertGreaterThanOrEqual($now - 2, $this->lastSeen(hash('sha256', $raw)));
    }

    public function testTokenLastUsedIsWrittenAtMostOncePerWindow(): void
    {
        $now = time();
        $this->insertUser('token-throttle-user');
        $tokens = new TokenService($this->pdo);
        $created = $tokens->create('token-throttle-user', 'throttle', ['read'], null);
        $raw = (string) $created['token'];
        $id = (string) $created['id'];

        self::assertIsArray($tokens->authenticate($raw));
        $first = $this->lastUsed($id);
        self::assertNotNull($first);
        self::assertGreaterThanOrEqual($now - 2, $first);

        $this->setLastUsed($id, $now - 5);
        self::assertIsArray($tokens->authenticate($raw));
        self::assertSame($now - 5, $this->lastUsed($id));

        $this->setLastUsed($id, $now - TokenService::LAST_USED_THROTTLE_SECONDS);
        self::assertIsArray($tokens->authenticate($raw));
        self::assertGreaterThanOrEqual($now - 2, $this->lastUsed($id));
    }

    private function authService(): AuthService
    {
        return new AuthService(
            $this->pdo,
            new PasswordPolicy(),
            new RateLimiter($this->pdo),
            new AuditLogger($this->pdo),
            $this->config,
            new SettingsService($this->pdo),
        );
    }

    private function insertUser(string $id): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :hash, :role, -1, 0, :created, :created)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $id,
            ':hash' => password_hash('unused-password', PASSWORD_ARGON2ID),
            ':role' => 'admin',
            ':created' => time(),
        ]);
    }

    private function insertSession(string $userId, string $idHash, int $lastSeen): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:id_hash, :user_id, :csrf, :ip, :agent, :created, :last_seen, :expires)'
        );
        $statement->execute([
            ':id_hash' => $idHash,
            ':user_id' => $userId,
            ':csrf' => hash('sha256', 'csrf'),
            ':ip' => '127.0.0.1',
            ':agent' => 'phpunit',
            ':created' => time() - 600,
            ':last_seen' => $lastSeen,
            ':expires' => time() + 3600,
        ]);
    }

    private function lastSeen(string $idHash): int
    {
        $statement = $this->pdo->prepare('SELECT last_seen_at FROM sessions WHERE id_hash = :id_hash');
        $statement->execute([':id_hash' => $idHash]);

        return (int) $statement->fetchColumn();
    }

    private function setLastSeen(string $idHash, int $value): void
    {
        $statement = $this->pdo->prepare('UPDATE sessions SET last_seen_at = :value WHERE id_hash = :id_hash');
        $statement->execute([':value' => $value, ':id_hash' => $idHash]);
    }

    private function lastUsed(string $id): ?int
    {
        $statement = $this->pdo->prepare('SELECT last_used_at FROM api_tokens WHERE id = :id');
        $statement->execute([':id' => $id]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (int) $value;
    }

    private function setLastUsed(string $id, int $value): void
    {
        $statement = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = :value WHERE id = :id');
        $statement->execute([':value' => $value, ':id' => $id]);
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
