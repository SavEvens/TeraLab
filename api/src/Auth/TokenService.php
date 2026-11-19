<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use PDO;
use TeraLab\Api\Http\HttpException;

final class TokenService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    public function create(string $userId, string $name, array $scopes, ?int $expiresIn): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 128) {
            throw new HttpException(422, 'Token name is required.');
        }
        $scopes = array_values(array_intersect($scopes ?: ['read', 'write'], ['read', 'write']));
        if ($scopes === []) {
            throw new HttpException(422, 'At least one scope is required.');
        }
        $token = 'tl_' . bin2hex(random_bytes(24));
        $id = bin2hex(random_bytes(16));
        $now = time();
        $expiresAt = $expiresIn !== null ? $now + max(300, min(31536000, $expiresIn)) : null;
        $statement = $this->pdo->prepare(
            'INSERT INTO api_tokens (id, user_id, name, token_hash, scopes, created_at, expires_at) '
            . 'VALUES (:id, :user_id, :name, :hash, :scopes, :created_at, :expires_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':name' => $name,
            ':hash' => hash('sha256', $token),
            ':scopes' => implode(',', $scopes),
            ':created_at' => $now,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'id' => $id,
            'name' => $name,
            'token' => $token,
            'scopes' => $scopes,
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, scopes, created_at, last_used_at, expires_at '
            . 'FROM api_tokens WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $statement->execute([':user_id' => $userId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['scopes'] = array_values(array_filter(explode(',', (string) $row['scopes'])));
            $row['created_at'] = (int) $row['created_at'];
            $row['last_used_at'] = $row['last_used_at'] !== null ? (int) $row['last_used_at'] : null;
            $row['expires_at'] = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
        }

        return array_values($rows);
    }

    public function revoke(string $userId, string $tokenId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM api_tokens WHERE id = :id AND user_id = :user_id');
        $statement->execute([':id' => $tokenId, ':user_id' => $userId]);
        if ($statement->rowCount() === 0) {
            throw new HttpException(404, 'Token not found.');
        }
    }

    /**
     * @return array{user: array<string, mixed>, scopes: list<string>}|null
     */
    public function authenticate(string $token): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.scopes, t.expires_at, u.id AS user_id, u.username, u.role, u.email, u.name '
            . 'FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = :hash'
        );
        $statement->execute([':hash' => hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ($row['expires_at'] !== null && (int) $row['expires_at'] < time()) {
            return null;
        }
        $update = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = :now WHERE id = :id');
        $update->execute([':now' => time(), ':id' => (string) $row['id']]);

        return [
            'user' => [
                'id' => $row['user_id'],
                'username' => $row['username'],
                'role' => $row['role'],
                'email' => $row['email'],
                'name' => $row['name'],
            ],
            'scopes' => array_values(array_filter(explode(',', (string) $row['scopes']))),
        ];
    }
}
