<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use PDO;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabRepository;

final class AccessService
{
    private const RANK = ['user' => 1, 'editor' => 2, 'admin' => 3];

    public function __construct(
        private readonly PDO $pdo,
        private readonly LabRepository $labs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function user(ServerRequestInterface $request): array
    {
        $user = $request->getAttribute('user');
        if (!is_array($user) || !isset($user['id'], $user['role'])) {
            throw new HttpException(401, 'Authentication required.');
        }

        /** @var array<string, mixed> $user */
        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function requireRole(array $user, string $role): void
    {
        $actual = (string) ($user['role'] ?? 'user');
        if ((self::RANK[$actual] ?? 0) < (self::RANK[$role] ?? 99)) {
            throw new HttpException(403, 'Insufficient permissions.');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /**
     * The effective role for one lab.
     *
     * Global admins and lab owners are admins. A permission row supplies the
     * local ceiling: `user` is read-only, `editor` may edit, and `admin` may
     * grant. A global `user` is capped at read even with a local editor row; a
     * global `editor` is capped at edit unless the local row is admin. An
     * unknown stored role is read as `user` instead of being trusted.
     *
     * A missing permission row is reported as `user`, but it grants no access:
     * callers must use requireLabRole()/assertLabAccessWithRole() or
     * filterLabs(), which enforce the explicit grant.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $lab
     */
    public function labRole(array $user, array $lab): string
    {
        return $this->effectiveRole($user, $lab)[0];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $lab
     */
    public function requireLabRole(array $user, array $lab, string $minimum): void
    {
        [$role, $granted] = $this->effectiveRole($user, $lab);
        if (!$granted || (self::RANK[$role] ?? 0) < (self::RANK[$minimum] ?? 99)) {
            throw new HttpException(403, 'You do not have access to this lab.');
        }
    }

    /**
     * Resolve exactly one canonical lab and require the effective role.
     *
     * The route id is never a basename: authorization and the service call both
     * use the returned `id`.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function assertLabAccessWithRole(array $user, string $labId, string $minimum): array
    {
        $lab = $this->labs->findById($labId);
        if ($lab === null) {
            throw new HttpException(404, 'Lab not found.');
        }
        $this->requireLabRole($user, $lab, $minimum);

        return $lab;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function assertLabAccess(array $user, string $labID): array
    {
        return $this->assertLabAccessWithRole($user, $labID, 'user');
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $labs
     * @return list<array<string, mixed>>
     */
    public function filterLabs(array $user, array $labs): array
    {
        if ($this->isAdmin($user)) {
            return $labs;
        }

        return array_values(array_filter(
            $labs,
            function (array $lab) use ($user): bool {
                [$role, $granted] = $this->effectiveRole($user, $lab);

                return $granted && (self::RANK[$role] ?? 0) >= self::RANK['user'];
            },
        ));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $lab
     * @return array{0: string, 1: bool} Effective role and explicit-grant flag.
     */
    private function effectiveRole(array $user, array $lab): array
    {
        $global = $this->normaliseRole($user['role'] ?? null);
        if ($global === 'admin') {
            return ['admin', true];
        }
        if ($this->isOwner($user, $lab)) {
            return ['admin', true];
        }

        $local = $this->permissionRole($lab, $user);
        $granted = $local !== null;
        if ($global === 'user') {
            return ['user', $granted];
        }
        if ($local === 'admin') {
            return ['admin', true];
        }
        if ($local === 'editor') {
            return ['editor', true];
        }

        return ['user', $granted];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $lab
     */
    private function isOwner(array $user, array $lab): bool
    {
        $owner = $lab['attributes']['owner'] ?? null;

        return is_string($owner) && $owner !== '' && $owner === (string) ($user['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $lab
     * @param array<string, mixed> $user
     */
    private function permissionRole(array $lab, array $user): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT role FROM permissions WHERE lab_id = :lab_id AND user_id = :user_id'
        );
        $statement->execute([
            ':lab_id' => (string) ($lab['id'] ?? ''),
            ':user_id' => (string) ($user['id'] ?? ''),
        ]);
        $role = $statement->fetchColumn();

        return $role === false ? null : $this->normaliseRole($role);
    }

    private function normaliseRole(mixed $role): string
    {
        return is_string($role) && isset(self::RANK[$role]) ? $role : 'user';
    }

    public function grant(string $labID, string $userID, string $role): void
    {
        if (!in_array($role, ['admin', 'editor', 'user'], true)) {
            throw new HttpException(422, 'Invalid permission role.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO permissions (lab_id, user_id, role, created_at) '
            . 'VALUES (:lab_id, :user_id, :role, :created_at) '
            . 'ON CONFLICT(lab_id, user_id) DO UPDATE SET role = :role'
        );
        $statement->execute([
            ':lab_id' => $labID,
            ':user_id' => $userID,
            ':role' => $role,
            ':created_at' => time(),
        ]);
    }

    public function revoke(string $labID, string $userID): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM permissions WHERE lab_id = :lab_id AND user_id = :user_id'
        );
        $statement->execute([':lab_id' => $labID, ':user_id' => $userID]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function permissions(string $labID): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.user_id, p.role, u.username FROM permissions p '
            . 'JOIN users u ON u.id = p.user_id WHERE p.lab_id = :lab_id ORDER BY u.username'
        );
        $statement->execute([':lab_id' => $labID]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            if (is_array($row)) {
                $row['role'] = $this->normaliseRole($row['role'] ?? null);
            }
        }
        unset($row);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, username, role FROM users WHERE username = :username');
        $statement->execute([':username' => $username]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
