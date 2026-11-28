<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Jobs;

use PDO;
use Throwable;
use TeraLab\Api\Database\BusyRetry;
use TeraLab\Api\Http\HttpException;

final class JobService
{
    public const RECONCILE_INTERVAL_SECONDS = 300;

    public const LAST_RECONCILE_KEY = 'maintenance_last_reconcile_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function start(string $type, ?string $userId, array $payload = []): string
    {
        $id = bin2hex(random_bytes(16));
        $now = time();
        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (id, type, state, progress, payload_json, created_by, created_at, started_at) '
            . 'VALUES (:id, :type, :state, 0, :payload, :user, :created, :started)'
        );
        $statement->execute([
            ':id' => $id,
            ':type' => $type,
            ':state' => 'running',
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':user' => $userId,
            ':created' => $now,
            ':started' => $now,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function succeed(string $id, array $result = []): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET state = :state, progress = 100, result_json = :result, finished_at = :finished WHERE id = :id'
        );
        $statement->execute([
            ':state' => 'succeeded',
            ':result' => json_encode($result, JSON_THROW_ON_ERROR),
            ':finished' => time(),
            ':id' => $id,
        ]);
    }

    public function fail(string $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET state = :state, error = :error, finished_at = :finished WHERE id = :id'
        );
        $statement->execute([':state' => 'failed', ':error' => $error, ':finished' => time(), ':id' => $id]);
    }

    public function cancel(string $id): void
    {
        $job = $this->find($id);
        if ($job === null) {
            throw new HttpException(404, 'Job not found.');
        }
        if ($job['state'] !== 'queued') {
            throw new HttpException(
                409,
                'Only queued jobs can be cancelled; running synchronous operations cannot be aborted.',
            );
        }
        $statement = $this->pdo->prepare('UPDATE jobs SET state = :state, finished_at = :finished WHERE id = :id');
        $statement->execute([':state' => 'cancelled', ':finished' => time(), ':id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['progress'] = (int) $row['progress'];
        $row['payload'] = $row['payload_json'] !== null ? json_decode((string) $row['payload_json'], true) : null;
        $row['result'] = $row['result_json'] !== null ? json_decode((string) $row['result_json'], true) : null;
        unset($row['payload_json'], $row['result_json']);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $limit = 100): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM jobs ORDER BY created_at DESC LIMIT :limit');
        $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $jobs = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $job = $this->find((string) $id);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function reconcile(): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE jobs SET state = 'failed', error = 'interrupted by restart', finished_at = :finished "
            . "WHERE state IN ('queued', 'running') AND created_at < :cutoff"
        );
        $statement->execute([
            ':finished' => time(),
            ':cutoff' => time() - 3600,
        ]);

        return $statement->rowCount();
    }

    /**
     * Reconcile at most once per interval, using a settings row as the
     * persisted last-run timestamp. This keeps the startup bookkeeping off the
     * per-request write path while still recovering interrupted jobs.
     */
    public function reconcileIfDue(int $interval = self::RECONCILE_INTERVAL_SECONDS): ?int
    {
        $now = time();
        $lastRun = $this->lastReconcileAt();
        if ($lastRun !== null && $now - $lastRun < max(60, $interval)) {
            return null;
        }

        $reconciled = $this->reconcile();
        BusyRetry::retry(function () use ($now): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
                . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
            );
            $statement->execute([
                ':key' => self::LAST_RECONCILE_KEY,
                ':value' => (string) $now,
                ':updated_at' => $now,
            ]);
        });

        return $reconciled;
    }

    public function lastReconcileAt(): ?int
    {
        try {
            $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
            $statement->execute([':key' => self::LAST_RECONCILE_KEY]);
            $value = $statement->fetchColumn();
            $statement->closeCursor();
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
