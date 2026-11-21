<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use PDO;
use TeraLab\Api\Database\BusyRetry;
use TeraLab\Api\Http\HttpException;

final class LabLocks
{
    private const TTL_SECONDS = 43200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(string $labID): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.lab_id, l.user_id, l.created_at, l.expires_at, u.username '
            . 'FROM lab_locks l JOIN users u ON u.id = l.user_id WHERE l.lab_id = :lab_id'
        );
        $statement->execute([':lab_id' => $labID]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ((int) $row['expires_at'] < time()) {
            $delete = $this->pdo->prepare('DELETE FROM lab_locks WHERE lab_id = :lab_id');
            $delete->execute([':lab_id' => $labID]);

            return null;
        }
        $row['created_at'] = (int) $row['created_at'];
        $row['expires_at'] = (int) $row['expires_at'];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function acquire(string $labID, string $userID, bool $isAdmin): array
    {
        $lock = $this->current($labID);
        if ($lock !== null && (string) $lock['user_id'] !== $userID && !$isAdmin) {
            throw new HttpException(409, 'The lab is running under another user.', [
                'owner' => $lock['username'],
                'expires_at' => gmdate('c', (int) $lock['expires_at']),
            ]);
        }

        $now = time();
        // Upsert of the same values is idempotent: safe to retry a busy lock.
        BusyRetry::retry(function () use ($labID, $userID, $now): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO lab_locks (lab_id, user_id, created_at, expires_at) '
                . 'VALUES (:lab_id, :user_id, :created_at, :expires_at) '
                . 'ON CONFLICT(lab_id) DO UPDATE SET user_id = :user_id, created_at = :created_at, expires_at = :expires_at'
            );
            $statement->execute([
                ':lab_id' => $labID,
                ':user_id' => $userID,
                ':created_at' => $now,
                ':expires_at' => $now + self::TTL_SECONDS,
            ]);
        });

        return $this->current($labID) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function assertEditable(string $labID, string $userID, bool $isAdmin): ?array
    {
        $lock = $this->current($labID);
        if ($lock !== null && (string) $lock['user_id'] !== $userID && !$isAdmin) {
            throw new HttpException(409, 'The lab is locked by a running session.', [
                'owner' => $lock['username'],
                'expires_at' => gmdate('c', (int) $lock['expires_at']),
            ]);
        }

        return $lock;
    }

    public function release(string $labID, string $userID, bool $isAdmin): void
    {
        $lock = $this->current($labID);
        if ($lock === null) {
            return;
        }
        if (!$isAdmin && (string) $lock['user_id'] !== $userID) {
            return;
        }
        $statement = $this->pdo->prepare('DELETE FROM lab_locks WHERE lab_id = :lab_id');
        $statement->execute([':lab_id' => $labID]);
    }
}
