<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use PDO;
use Throwable;
use TeraLab\Api\Config;
use TeraLab\Api\Database\BusyRetry;
use TeraLab\Api\Labs\LabService;

/**
 * Interval-gated retention cleanup for the control-plane database.
 *
 * The service is deliberately not on the hot request path: App::create() never
 * calls it, and the lazy demo sweep only invokes runIfDue(), which is one
 * primary-key read until the configured interval has elapsed. Administrators
 * can force a run through POST /api/v2/system/maintenance.
 *
 * All deletes are bounded and idempotent. Active jobs are never removed, and
 * lab files are only pruned through the existing trash expiry.
 */
final class MaintenanceService
{
    public const LAST_RUN_KEY = 'maintenance_last_run_at';

    public const DEFAULT_INTERVAL_SECONDS = 3600;

    /** Retention defaults; the canonical values live in SettingsService. */
    public const DEFAULT_LOGIN_ATTEMPTS_DAYS = 30;

    public const DEFAULT_AUDIT_LOG_DAYS = 90;

    public const DEFAULT_JOBS_DAYS = 30;

    public const DEFAULT_JOBS_MAX_ROWS = 1000;

    public const DEFAULT_SNAPSHOTS_PER_LAB = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly ?LabService $labs = null,
    ) {
    }

    /**
     * Run only when the maintenance interval has elapsed.
     *
     * @return array<string, int>|null the run summary, or null when not due
     */
    public function runIfDue(): ?array
    {
        $now = time();
        $lastRun = $this->lastRun();
        $interval = max(60, $this->settings->int(
            'maintenance_interval_seconds',
            self::DEFAULT_INTERVAL_SECONDS,
        ));
        if ($lastRun !== null && $now - $lastRun < $interval) {
            return null;
        }

        return $this->runNow();
    }

    /**
     * @return array<string, int>
     */
    public function runNow(): array
    {
        $now = time();
        $loginDays = max(1, $this->settings->int(
            'retention_login_attempts_days',
            self::DEFAULT_LOGIN_ATTEMPTS_DAYS,
        ));
        $auditDays = max(1, $this->settings->int(
            'retention_audit_log_days',
            self::DEFAULT_AUDIT_LOG_DAYS,
        ));
        $jobsDays = max(1, $this->settings->int(
            'retention_jobs_days',
            self::DEFAULT_JOBS_DAYS,
        ));
        $jobRows = max(100, $this->settings->int(
            'retention_jobs_max_rows',
            self::DEFAULT_JOBS_MAX_ROWS,
        ));
        $snapshotCap = max(1, $this->settings->int(
            'demo_max_snapshots_per_lab',
            self::DEFAULT_SNAPSHOTS_PER_LAB,
        ));
        $idleCutoff = $now - max(300, $this->config->idleTtl);

        $summary = [
            'sessions_deleted' => 0,
            'login_attempts_deleted' => 0,
            'audit_log_deleted' => 0,
            'jobs_deleted' => 0,
            'snapshots_deleted' => 0,
            'trash_deleted' => 0,
        ];

        BusyRetry::retry(function () use (
            $now,
            $idleCutoff,
            $loginDays,
            $auditDays,
            $jobsDays,
            $jobRows,
            $snapshotCap,
            &$summary,
        ): void {
            $this->pdo->beginTransaction();
            try {
                $summary['sessions_deleted'] = $this->delete(
                    'DELETE FROM sessions WHERE expires_at < :now OR last_seen_at < :idle',
                    [':now' => $now, ':idle' => $idleCutoff],
                );
                $summary['login_attempts_deleted'] = $this->delete(
                    'DELETE FROM login_attempts WHERE attempted_at < :cutoff',
                    [':cutoff' => $now - $loginDays * 86400],
                );
                $summary['audit_log_deleted'] = $this->delete(
                    'DELETE FROM audit_log WHERE ts < :cutoff',
                    [':cutoff' => $now - $auditDays * 86400],
                );
                $summary['jobs_deleted'] = $this->delete(
                    "DELETE FROM jobs WHERE state NOT IN ('queued', 'running') AND created_at < :cutoff",
                    [':cutoff' => $now - $jobsDays * 86400],
                );
                $summary['jobs_deleted'] += $this->retainNewestJobs($jobRows);
                $summary['snapshots_deleted'] = $this->retainNewestSnapshots($snapshotCap);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $exception;
            }
        });

        if ($this->labs !== null) {
            $summary['trash_deleted'] = $this->labs->purgeExpiredTrash();
        }
        $this->writeLastRun($now);

        return $summary;
    }

    public function lastRun(): ?int
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute([':key' => self::LAST_RUN_KEY]);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function delete(string $sql, array $parameters): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    private function retainNewestJobs(int $maxRows): int
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM jobs WHERE state NOT IN ('queued', 'running') AND id NOT IN ("
            . "SELECT id FROM jobs WHERE state NOT IN ('queued', 'running') "
            . 'ORDER BY created_at DESC, id DESC LIMIT :limit)'
        );
        $statement->bindValue(':limit', $maxRows, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    private function retainNewestSnapshots(int $perLab): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM lab_snapshots WHERE id NOT IN ('
            . 'SELECT id FROM ('
            . 'SELECT id, ROW_NUMBER() OVER (PARTITION BY lab_id ORDER BY created_at DESC, id DESC) AS rank '
            . 'FROM lab_snapshots) WHERE rank <= :limit)'
        );
        $statement->bindValue(':limit', $perLab, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    private function writeLastRun(int $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([
            ':key' => self::LAST_RUN_KEY,
            ':value' => (string) $now,
            ':updated_at' => $now,
        ]);
    }
}
