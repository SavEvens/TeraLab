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
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function assertLabAccess(array $user, string $labID): array
    {
        $lab = $this->labs->find($labID);
        if ($lab === null) {
            throw new HttpException(404, 'Lab not found.');
        }
        if (!$this->canAccess($user, $lab)) {
            throw new HttpException(403, 'You do not have access to this lab.');
        }

        return $lab;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $lab
     */
    public function canAccess(array $user, array $lab): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $owner = $lab['attributes']['owner'] ?? null;
        if (is_string($owner) && $owner === (string) ($user['id'] ?? '')) {
            return true;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM permissions WHERE lab_id = :lab_id AND user_id = :user_id'
        );
        $statement->execute([
            ':lab_id' => (string) ($lab['id'] ?? ''),
            ':user_id' => (string) ($user['id'] ?? ''),
        ]);

        return (int) $statement->fetchColumn() > 0;
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
            fn (array $lab): bool => $this->canAccess($user, $lab),
        ));
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

        return $statement->fetchAll();
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
