<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Snapshots;

use PDO;
use TeraLab\Api\Database\BusyRetry;

final class SnapshotRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $labID, string $name, int $revision, string $xml, ?string $userId): array
    {
        $id = bin2hex(random_bytes(16));
        $now = time();
        $row = [
            ':id' => $id,
            ':lab_id' => $labID,
            ':name' => $name,
            ':revision' => $revision,
            ':xml' => $xml,
            ':created_by' => $userId,
            ':created_at' => $now,
        ];
        // The row carries a fresh random id: a retried insert cannot duplicate.
        BusyRetry::retry(function () use ($row): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO lab_snapshots (id, lab_id, name, revision, xml, created_by, created_at) '
                . 'VALUES (:id, :lab_id, :name, :revision, :xml, :created_by, :created_at)'
            );
            $statement->execute($row);
        });

        return [
            'id' => $id,
            'lab_id' => $labID,
            'name' => $name,
            'revision' => $revision,
            'created_by' => $userId,
            'created_at' => $now,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $labID): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, lab_id, name, revision, created_by, created_at '
            . 'FROM lab_snapshots WHERE lab_id = :lab_id ORDER BY created_at DESC'
        );
        $statement->execute([':lab_id' => $labID]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['revision'] = (int) $row['revision'];
            $row['created_at'] = (int) $row['created_at'];
        }

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $labID, string $snapshotID): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, lab_id, name, revision, xml, created_by, created_at '
            . 'FROM lab_snapshots WHERE lab_id = :lab_id AND id = :id'
        );
        $statement->execute([':lab_id' => $labID, ':id' => $snapshotID]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['revision'] = (int) $row['revision'];
        $row['created_at'] = (int) $row['created_at'];

        return $row;
    }

    public function delete(string $labID, string $snapshotID): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM lab_snapshots WHERE lab_id = :lab_id AND id = :id'
        );
        $statement->execute([':lab_id' => $labID, ':id' => $snapshotID]);

        return $statement->rowCount() > 0;
    }
}
