<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use PDO;
use TeraLab\Api\Config;
use TeraLab\Api\Database\BusyRetry;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\System\SettingsService;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogger $audit,
        private readonly Config $config,
        private readonly ?SettingsService $settings = null,
    ) {
    }

    public function hasUsers(): bool
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM users');
        if ($statement === false) {
            return false;
        }

        return (int) $statement->fetchColumn() > 0;
    }

    public function setup(string $username, string $password, ?string $email, string $ip): void
    {
        if ($this->hasUsers()) {
            throw new HttpException(409, 'Setup has already been completed.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,31}$/', $username) !== 1) {
            throw new HttpException(422, 'Invalid username.');
        }

        $errors = $this->passwordPolicy->validate($password);
        if ($errors !== []) {
            throw new HttpException(422, 'Password does not meet the policy.', ['password' => $errors]);
        }

        $now = time();
        $userId = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare(
            'INSERT INTO users '
            . '(id, username, email, name, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :email, :name, :password_hash, :role, -1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $userId,
            ':username' => $username,
            ':email' => $email,
            ':name' => null,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 3,
                'threads' => 2,
            ]),
            ':role' => 'admin',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $this->audit->log($userId, $ip, 'auth.setup', null, 'success', ['username' => $username]);
    }

    /**
     * @return array{user: array<string, mixed>, session_id: string, csrf_token: string, expires_in: int}
     */
    public function login(string $username, string $password, string $ip, string $userAgent): array
    {
        $this->rateLimiter->assertAllowed($username, $ip);

        $user = $this->findUserByUsername($username);
        $verified = $user !== null && password_verify($password, (string) $user['password_hash']);
        $legacy = $user['legacy_password_hash'] ?? null;
        if (!$verified && $user !== null && is_string($legacy) && $legacy !== ''
            && hash_equals($legacy, hash('sha256', $password))) {
            $verified = true;
            $rehash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 3,
                'threads' => 2,
            ]);
            $statement = $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash, legacy_password_hash = NULL, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute([
                ':password_hash' => $rehash,
                ':updated_at' => time(),
                ':id' => (string) $user['id'],
            ]);
            $user['password_hash'] = $rehash;
            $user['legacy_password_hash'] = null;
        }
        if ($user === null || !$verified) {
            $this->rateLimiter->record($username, $ip, false);
            $this->audit->log(null, $ip, 'auth.login', null, 'failure', ['username' => $username]);
            throw new HttpException(401, 'Invalid username or password.');
        }

        if ((int) $user['expiration'] >= 0 && (int) $user['expiration'] < time()) {
            $this->audit->log((string) $user['id'], $ip, 'auth.login', null, 'failure', ['reason' => 'expired']);
            throw new HttpException(403, 'This account has expired.');
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 3,
            'threads' => 2,
        ])) {
            $statement = $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute([
                ':password_hash' => password_hash($password, PASSWORD_ARGON2ID, [
                    'memory_cost' => 65536,
                    'time_cost' => 3,
                    'threads' => 2,
                ]),
                ':updated_at' => time(),
                ':id' => (string) $user['id'],
            ]);
        }

        $this->rateLimiter->clear($username, $ip);
        $session = $this->createSession((string) $user['id'], $ip, $userAgent);
        $this->audit->log((string) $user['id'], $ip, 'auth.login', null, 'success');

        return [
            'user' => $this->publicUser($user),
            'session_id' => $session['session_id'],
            'csrf_token' => $session['csrf_token'],
            'expires_in' => $session['expires_in'],
        ];
    }

    /**
     * Number of distinct guests holding an unexpired session. This is the
     * concurrency measure for the demo cap: one guest can open several tabs
     * without consuming more than one slot, while a visitor who lets a guest
     * expire frees the slot when the session goes away.
     */
    public function activeGuestCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT s.user_id) FROM sessions s '
            . 'JOIN users u ON u.id = s.user_id '
            . 'WHERE u.guest = 1 AND s.expires_at > :now'
        );
        $statement->execute([':now' => time()]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Mint an ephemeral demo guest and open its session.
     *
     * The account has no personal data and its password hash is random and
     * never disclosed, so the only way in is the session returned here.
     *
     * @return array{user: array<string, mixed>, session_id: string, csrf_token: string, expires_in: int}
     */
    public function createGuest(string $ip, string $agent): array
    {
        $now = time();
        $ttlHours = $this->settings?->int('demo_guest_ttl_hours', 48) ?? 48;
        $expiration = $now + max(1, $ttlHours) * 3600;
        $userId = bin2hex(random_bytes(16));
        $username = 'g-' . bin2hex(random_bytes(8));

        $statement = $this->pdo->prepare(
            'INSERT INTO users '
            . '(id, username, email, name, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, NULL, NULL, :password_hash, :role, :expiration, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $userId,
            ':username' => $username,
            ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 3,
                'threads' => 2,
            ]),
            ':role' => 'editor',
            ':expiration' => $expiration,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        // The demo account expires before a default session would; clamp the
        // session so it can never outlive the account that owns it.
        $session = $this->createSession($userId, $ip, $agent, $expiration);
        $this->audit->log($userId, $ip, 'demo.guest', null, 'success');

        return [
            'user' => $this->publicUser([
                'id' => $userId,
                'username' => $username,
                'email' => null,
                'name' => null,
                'role' => 'editor',
                'expiration' => $expiration,
                'guest' => 1,
            ]),
            'session_id' => $session['session_id'],
            'csrf_token' => $session['csrf_token'],
            'expires_in' => $session['expires_in'],
        ];
    }

    /**
     * @return array{user: array<string, mixed>, session: array<string, mixed>}
     */
    public function authenticate(string $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id_hash, s.csrf_hash, s.expires_at, s.last_seen_at, '
            . 'u.id, u.username, u.email, u.name, u.role, u.expiration, u.guest '
            . 'FROM sessions s JOIN users u ON u.id = s.user_id '
            . 'WHERE s.id_hash = :id_hash'
        );
        $statement->execute([':id_hash' => hash('sha256', $sessionId)]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new HttpException(401, 'Authentication required.');
        }

        if ((int) $row['expires_at'] < time()) {
            $delete = $this->pdo->prepare('DELETE FROM sessions WHERE id_hash = :id_hash');
            $delete->execute([':id_hash' => (string) $row['id_hash']]);
            throw new HttpException(401, 'Session expired.');
        }
        if ((int) $row['last_seen_at'] + $this->config->idleTtl < time()) {
            $delete = $this->pdo->prepare('DELETE FROM sessions WHERE id_hash = :id_hash');
            $delete->execute([':id_hash' => (string) $row['id_hash']]);
            throw new HttpException(401, 'Session expired after inactivity.');
        }

        if ((int) $row['expiration'] >= 0 && (int) $row['expiration'] < time()) {
            throw new HttpException(403, 'This account has expired.');
        }

        // Last-seen bookkeeping is idempotent and must never fail a request.
        BusyRetry::retry(function () use ($row): void {
            $update = $this->pdo->prepare('UPDATE sessions SET last_seen_at = :now WHERE id_hash = :id_hash');
            $update->execute([':now' => time(), ':id_hash' => (string) $row['id_hash']]);
        });

        return [
            'user' => $this->publicUser($row),
            'session' => [
                'id_hash' => (string) $row['id_hash'],
                'csrf_hash' => (string) $row['csrf_hash'],
                'expires_at' => (int) $row['expires_at'],
            ],
        ];
    }

    public function changePassword(string $userId, string $sessionId, string $current, string $new, string $ip): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user) || !password_verify($current, (string) $user['password_hash'])) {
            $this->audit->log($userId, $ip, 'auth.password', null, 'failure', ['reason' => 'current_password']);
            throw new HttpException(403, 'The current password is incorrect.');
        }
        $errors = $this->passwordPolicy->validate($new);
        if ($errors !== []) {
            throw new HttpException(422, 'Password does not meet the policy.', ['password' => $errors]);
        }

        $rehash = password_hash($new, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 3,
            'threads' => 2,
        ]);
        $update = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, legacy_password_hash = NULL, updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([':password_hash' => $rehash, ':updated_at' => time(), ':id' => $userId]);

        $revoke = $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id AND id_hash != :current');
        $revoke->execute([':user_id' => $userId, ':current' => hash('sha256', $sessionId)]);
        $this->audit->log($userId, $ip, 'auth.password', null, 'success');
    }

    public function revokeOtherSessions(string $userId, string $sessionId, string $ip): int
    {
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id AND id_hash != :current');
        $statement->execute([':user_id' => $userId, ':current' => hash('sha256', $sessionId)]);
        $count = $statement->rowCount();
        $this->audit->log($userId, $ip, 'auth.sessions.revoke', null, 'success', ['revoked' => $count]);

        return $count;
    }

    public function logout(string $sessionId, ?string $userId, string $ip): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE id_hash = :id_hash');
        $statement->execute([':id_hash' => hash('sha256', $sessionId)]);
        $this->audit->log($userId, $ip, 'auth.logout', null, 'success');
    }

    public function rotateCsrf(string $sessionId): string
    {
        $token = bin2hex(random_bytes(32));
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET csrf_hash = :csrf_hash WHERE id_hash = :id_hash'
        );
        $statement->execute([
            ':csrf_hash' => hash('sha256', $token),
            ':id_hash' => hash('sha256', $sessionId),
        ]);

        return $token;
    }

    private function sessionTtl(): int
    {
        return $this->settings?->int('session_ttl', $this->config->sessionTtl) ?? $this->config->sessionTtl;
    }

    /**
     * @param int|null $expiresAt Absolute cap, used to keep guest sessions
     *        from outliving the account that owns them.
     * @return array{session_id: string, csrf_token: string, expires_in: int}
     */
    private function createSession(
        string $userId,
        string $ip,
        string $userAgent,
        ?int $expiresAt = null,
    ): array {
        $sessionId = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $now = time();
        $ttl = $this->sessionTtl();
        if ($expiresAt !== null) {
            $ttl = min($ttl, $expiresAt - $now);
        }
        $ttl = max(0, $ttl);
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions '
            . '(id_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:id_hash, :user_id, :csrf_hash, :ip, :user_agent, :created_at, :last_seen_at, :expires_at)'
        );
        $statement->execute([
            ':id_hash' => hash('sha256', $sessionId),
            ':user_id' => $userId,
            ':csrf_hash' => hash('sha256', $csrfToken),
            ':ip' => $ip,
            ':user_agent' => $userAgent,
            ':created_at' => $now,
            ':last_seen_at' => $now,
            ':expires_at' => $now + $ttl,
        ]);

        return [
            'session_id' => $sessionId,
            'csrf_token' => $csrfToken,
            'expires_in' => $ttl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
        $statement->execute([':username' => $username]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publicUser(array $user): array
    {
        $email = $user['email'] ?? null;
        $name = $user['name'] ?? null;

        return [
            'id' => (string) $user['id'],
            'username' => (string) $user['username'],
            'email' => is_string($email) ? $email : null,
            'name' => is_string($name) ? $name : null,
            'role' => (string) $user['role'],
            'expiration' => (int) ($user['expiration'] ?? -1),
            'guest' => DemoPolicy::isGuest($user),
        ];
    }
}
