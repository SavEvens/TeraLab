<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use PDO;
use PDOException;
use Throwable;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;

/**
 * Admin-only user lifecycle: list, create, update, password reset, force
 * logout, and deletion. All state lives in the existing `users`, `sessions`,
 * `permissions`, and `lab_locks` tables; no schema change is required.
 */
final class UserService
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{2,31}$/';

    private const ROLES = ['admin', 'editor', 'user'];

    private const MAX_EMAIL_LENGTH = 254;

    private const MAX_NAME_LENGTH = 128;

    private const SELECT_USER = 'SELECT u.id, u.username, u.email, u.name, u.role, u.expiration, '
        . 'u.created_at, u.updated_at, '
        . '(SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > :now) AS session_count, '
        . '(SELECT MAX(s.last_seen_at) FROM sessions s WHERE s.user_id = u.id) AS last_seen_at '
        . 'FROM users u';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AuditLogger $audit,
        private readonly LabRepository $labs,
        private readonly LabService $labService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $statement = $this->pdo->prepare(self::SELECT_USER . ' ORDER BY u.username');
        $statement->execute([':now' => time()]);
        $rows = $statement->fetchAll();
        $counts = $this->labCounts();

        $users = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $users[] = $this->publicRow($row, $counts[(string) $row['id']] ?? 0);
        }

        return $users;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{user: array<string, mixed>, password: string|null}
     */
    public function create(array $input, string $actorId, string $ip): array
    {
        $username = $this->username($input['username'] ?? null);
        $role = $this->role($input['role'] ?? null);
        $email = $this->email($input['email'] ?? null);
        $name = $this->name($input['name'] ?? null);
        $expiration = $this->expiration($input['expiration'] ?? null);

        $typedPassword = $input['password'] ?? null;
        $generated = $typedPassword === null || $typedPassword === '';
        if ($generated) {
            $password = $this->generatePassword();
        } else {
            $password = $this->checkedPassword($typedPassword);
        }

        if ($this->usernameTaken($username)) {
            throw new HttpException(422, 'Username is already taken.', [
                'username' => ['Username is already taken.'],
            ]);
        }

        $now = time();
        $userId = bin2hex(random_bytes(16));
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users '
                . '(id, username, email, name, password_hash, role, expiration, created_at, updated_at) '
                . 'VALUES (:id, :username, :email, :name, :password_hash, :role, :expiration, :created_at, :updated_at)'
            );
            $statement->execute([
                ':id' => $userId,
                ':username' => $username,
                ':email' => $email,
                ':name' => $name,
                ':password_hash' => $this->hash($password),
                ':role' => $role,
                ':expiration' => $expiration,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new HttpException(422, 'Username is already taken.', [
                    'username' => ['Username is already taken.'],
                ]);
            }
            throw $exception;
        }

        $this->audit->log($actorId, $ip, 'user.create', $userId, 'success', [
            'username' => $username,
            'role' => $role,
            'generated_password' => $generated,
        ]);

        return [
            'user' => $this->publicRow($this->findRow($userId), $this->labCountFor($userId)),
            'password' => $generated ? $password : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $targetId, array $input, string $actorId, string $ip): array
    {
        $target = $this->findRow($targetId);

        /** @var array<string, string|int|null> $updates */
        $updates = [];
        /** @var list<string> $fields */
        $fields = [];

        if (array_key_exists('email', $input)) {
            $email = $this->email($input['email']);
            $current = is_string($target['email'] ?? null) ? (string) $target['email'] : null;
            if ($email !== $current) {
                $updates['email'] = $email;
                $fields[] = 'email';
            }
        }
        if (array_key_exists('name', $input)) {
            $name = $this->name($input['name']);
            $current = is_string($target['name'] ?? null) ? (string) $target['name'] : null;
            if ($name !== $current) {
                $updates['name'] = $name;
                $fields[] = 'name';
            }
        }
        if (array_key_exists('expiration', $input)) {
            $expiration = $this->expiration($input['expiration']);
            if ($expiration !== (int) $target['expiration']) {
                $updates['expiration'] = $expiration;
                $fields[] = 'expiration';
            }
        }

        if (array_key_exists('role', $input) && $targetId === $actorId) {
            // Even a request that repeats the current role is rejected so the
            // rule does not depend on whether the value happens to differ.
            throw new HttpException(409, 'You cannot change your own role.');
        }

        $roleChanged = false;
        if (array_key_exists('role', $input)) {
            $role = $this->role($input['role']);
            if ($role !== (string) $target['role']) {
                $updates['role'] = $role;
                $roleChanged = true;
                $fields[] = 'role';
            }
        }

        if ($updates === []) {
            // Nothing changed after normalisation: no write and no audit row.
            return $this->publicRow($target, $this->labCountFor($targetId));
        }

        $updates['updated_at'] = time();
        $this->transaction(function () use ($targetId, $updates, $roleChanged, $target): void {
            if ($roleChanged
                && (string) $target['role'] === 'admin'
                && $this->adminCount() <= 1) {
                throw new HttpException(409, 'Cannot demote the last administrator.');
            }
            $this->applyUpdates($targetId, $updates);
        });

        $details = ['fields' => $fields];
        if ($roleChanged) {
            $details['role'] = (string) $updates['role'];
        }
        $this->audit->log($actorId, $ip, 'user.update', (string) $target['id'], 'success', $details);

        return $this->publicRow($this->findRow($targetId), $this->labCountFor($targetId));
    }

    /**
     * @return array{password: string|null} The typed password is never echoed;
     *                                       only a generated one is returned.
     */
    public function resetPassword(string $targetId, mixed $password, string $actorId, string $ip): array
    {
        $target = $this->findRow($targetId);

        $generated = $password === null || $password === '';
        if ($generated) {
            $password = $this->generatePassword();
        } else {
            $password = $this->checkedPassword($password);
        }

        $this->transaction(function () use ($targetId, $password): void {
            $statement = $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash, legacy_password_hash = NULL, updated_at = :updated_at '
                . 'WHERE id = :id'
            );
            $statement->execute([
                ':password_hash' => $this->hash($password),
                ':updated_at' => time(),
                ':id' => $targetId,
            ]);
            $revoke = $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id');
            $revoke->execute([':user_id' => $targetId]);
        });

        $this->audit->log($actorId, $ip, 'user.password.reset', (string) $target['id'], 'success', [
            'generated_password' => $generated,
        ]);

        return ['password' => $generated ? $password : null];
    }

    public function revokeSessions(string $targetId, string $actorId, string $ip): int
    {
        $target = $this->findRow($targetId);
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id');
        $statement->execute([':user_id' => $targetId]);
        $revoked = $statement->rowCount();

        $this->audit->log($actorId, $ip, 'user.sessions.revoke', (string) $target['id'], 'success', [
            'revoked' => $revoked,
        ]);

        return $revoked;
    }

    /**
     * @return array{deleted: true, labs_trashed: int}
     */
    public function delete(string $targetId, bool $deleteLabs, string $actorId, string $ip): array
    {
        $target = $this->findRow($targetId);
        if ($targetId === $actorId) {
            throw new HttpException(409, 'You cannot delete your own account.');
        }

        $owned = $deleteLabs ? $this->ownedLabs($targetId) : [];
        if ($deleteLabs) {
            $this->assertNoRunningNodes($owned);
        }

        // Check before any side effect: a last-admin delete must not trash labs
        // on its way to the 409. The same check is repeated inside the
        // transaction below as the authoritative guard.
        if ((string) $target['role'] === 'admin' && $this->adminCount() <= 1) {
            throw new HttpException(409, 'Cannot delete the last administrator.');
        }

        // Trash the owned labs before opening the DB transaction: LabService
        // makes agent HTTP calls and must not hold the SQLite write lock. This
        // is not atomic with the row deletion; if a later step fails, labs that
        // were already trashed stay in the 30-day trash (recoverable, never
        // hard-deleted). The running-node pre-check above covers the expected
        // failure mode.
        $trashed = 0;
        if ($deleteLabs) {
            foreach ($owned as $lab) {
                $this->labService->delete((string) $lab['id'], $actorId);
                $trashed++;
            }
        }

        $this->transaction(function () use ($target, $targetId): void {
            if ((string) $target['role'] === 'admin' && $this->adminCount() <= 1) {
                throw new HttpException(409, 'Cannot delete the last administrator.');
            }
            // Foreign keys cascade, but removing the rows explicitly keeps the
            // behavior independent of the connection's foreign_keys pragma.
            foreach (['api_tokens', 'permissions', 'lab_locks', 'sessions'] as $table) {
                $statement = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = :user_id');
                $statement->execute([':user_id' => $targetId]);
            }
            $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $statement->execute([':id' => $targetId]);
        });

        $this->audit->log($actorId, $ip, 'user.delete', (string) $target['id'], 'success', [
            'delete_labs' => $deleteLabs,
            'labs_trashed' => $trashed,
        ]);

        return ['deleted' => true, 'labs_trashed' => $trashed];
    }

    /**
     * @return array<string, mixed>
     */
    private function findRow(string $id): array
    {
        $statement = $this->pdo->prepare(self::SELECT_USER . ' WHERE u.id = :id');
        $statement->execute([':now' => time(), ':id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new HttpException(404, 'User not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row, int $labCount): array
    {
        $email = $row['email'] ?? null;
        $name = $row['name'] ?? null;
        $lastSeen = $row['last_seen_at'] ?? null;

        return [
            'id' => (string) $row['id'],
            'username' => (string) $row['username'],
            'email' => is_string($email) ? $email : null,
            'name' => is_string($name) ? $name : null,
            'role' => (string) $row['role'],
            'expiration' => (int) $row['expiration'],
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'session_count' => (int) ($row['session_count'] ?? 0),
            'last_seen_at' => $lastSeen === null ? null : (int) $lastSeen,
            'lab_count' => $labCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function labCounts(): array
    {
        $counts = [];
        foreach ($this->labs->all() as $lab) {
            $owner = $lab['attributes']['owner'] ?? null;
            if (!is_string($owner) || $owner === '') {
                continue;
            }
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }

        return $counts;
    }

    private function labCountFor(string $userId): int
    {
        $count = 0;
        foreach ($this->labs->all() as $lab) {
            if (($lab['attributes']['owner'] ?? null) === $userId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownedLabs(string $userId): array
    {
        return array_values(array_filter(
            $this->labs->all(),
            static fn (array $lab): bool => ($lab['attributes']['owner'] ?? null) === $userId,
        ));
    }

    /**
     * @param list<array<string, mixed>> $labs
     */
    private function assertNoRunningNodes(array $labs): void
    {
        $running = [];
        foreach ($labs as $lab) {
            foreach ($this->labService->nodes((string) $lab['id'])['nodes'] as $node) {
                if (($node['state'] ?? '') === 'running') {
                    $running[] = (string) $lab['name'];
                    break;
                }
            }
        }
        if ($running !== []) {
            throw new HttpException(409, 'Stop the labs before deleting this user.', ['labs' => $running]);
        }
    }

    private function adminCount(): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function usernameTaken(string $username): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $statement->execute([':username' => $username]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, string|int|null> $updates
     */
    private function applyUpdates(string $userId, array $updates): void
    {
        $assignments = [];
        $parameters = [':id' => $userId];
        foreach ($updates as $column => $value) {
            $assignments[] = $column . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }
        $statement = $this->pdo->prepare(
            'UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($parameters);
    }

    private function transaction(callable $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            $operation();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function username(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::USERNAME_PATTERN, $value) !== 1) {
            throw new HttpException(422, 'Invalid username.', [
                'username' => ['Use 3-32 characters: letters, digits, dots, dashes, or underscores.'],
            ]);
        }

        return $value;
    }

    private function role(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ROLES, true)) {
            throw new HttpException(422, 'Invalid role.', [
                'role' => ['Role must be admin, editor, or user.'],
            ]);
        }

        return $value;
    }

    private function email(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'email must be a string.', ['email' => ['Invalid email address.']]);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > self::MAX_EMAIL_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'Invalid email address.', ['email' => ['Invalid email address.']]);
        }

        return $value;
    }

    private function name(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'name must be a string.', ['name' => ['Invalid display name.']]);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > self::MAX_NAME_LENGTH) {
            throw new HttpException(422, 'Invalid display name.', [
                'name' => ['Display name must be at most ' . self::MAX_NAME_LENGTH . ' characters.'],
            ]);
        }

        return $value;
    }

    private function expiration(mixed $value): int
    {
        if ($value === null) {
            return -1;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || ($value < 0 && $value !== -1)) {
            throw new HttpException(422, 'Invalid expiration.', [
                'expiration' => ['Expiration must be -1 or a non-negative unix timestamp.'],
            ]);
        }

        return $value;
    }

    private function checkedPassword(mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'password must be a string.', ['password' => ['Invalid password.']]);
        }
        $errors = $this->passwordPolicy->validate($value);
        if ($errors !== []) {
            throw new HttpException(422, 'Password does not meet the policy.', ['password' => $errors]);
        }

        return $value;
    }

    private function generatePassword(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'Tl-' . bin2hex(random_bytes(16)) . '!9Aa';
            if ($this->passwordPolicy->validate($candidate) === []) {
                return $candidate;
            }
        }

        throw new HttpException(500, 'Cannot generate a compliant password.');
    }

    private function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 3,
            'threads' => 2,
        ]);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $info = $exception->errorInfo;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 19) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
