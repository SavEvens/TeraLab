<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use Closure;
use PDO;
use Throwable;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;

/**
 * Reclaims demo resources: expired and idle guests, idle guest labs, and the
 * newest guest nodes when host memory is below the demo floor.
 *
 * The sweeper only ever stops nodes and wipes run state through the existing
 * services; it never deletes lab files or image-store content. Guest scope is
 * derived from lab ownership, so admin and editor labs are never touched.
 *
 * `sweepIfDue()` runs lazily from request paths. Its bookkeeping timestamp
 * lives in the settings table under a key that is not part of
 * `SettingsService::DEFAULTS`, so it can never surface in the settings API.
 */
final class DemoSweeper
{
    public const LAST_SWEEP_KEY = 'demo_last_sweep_at';

    private const SWEEP_INTERVAL_KEY = 'demo_sweep_interval';

    private readonly ?Closure $clock;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly DemoPolicy $policy,
        private readonly AgentClient $agent,
        private readonly LabService $labs,
        private readonly AuditLogger $audit,
        private readonly HostMemory $memory,
        private readonly LabRepository $repository,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? null : $clock(...);
    }

    /**
     * Run a sweep only when the interval has elapsed.
     *
     * The hot path is one primary-key settings read plus an integer compare.
     */
    public function sweepIfDue(): void
    {
        [$lastSweep, $interval] = $this->bookkeeping();
        $now = $this->now();
        if ($lastSweep !== null && $now - $lastSweep <= $interval) {
            return;
        }

        try {
            $this->sweepNow();
        } catch (Throwable $exception) {
            // Housekeeping must never fail the request that happened to be
            // first after the interval. The admin endpoint calls sweepNow()
            // directly so a manual sweep still surfaces errors.
            error_log('teralab demo sweep failed: ' . $exception->getMessage());
        }
    }

    /**
     * @return array{
     *   guests_purged: int,
     *   labs_wiped: int,
     *   labs_failed: int,
     *   nodes_reaped: int,
     *   mem_available_mb: int|null
     * }
     */
    public function sweepNow(): array
    {
        $now = $this->now();
        $guests = $this->guestUsers();
        $guestIds = [];
        foreach (array_keys($guests) as $guestId) {
            $guestIds[(string) $guestId] = true;
        }
        [$ownerByLab, $labsByOwner] = $this->guestLabMap($guestIds);
        $sessions = $this->latestSessions();
        $nodes = $this->runningNodes();

        // a. Effective guest activity: latest session beat or running-node
        // console activity, whichever is newer. Nodes without a reported
        // `last_activity` are unknown: their owner must not be idle-purged on
        // a guess. A failed status call leaves every node unknown, so only the
        // hard TTL is enforced for that sweep.
        $activity = [];
        foreach (array_keys($guests) as $guestId) {
            $guestId = (string) $guestId;
            $activity[$guestId] = $sessions[$guestId] ?? null;
        }
        $unknownOwners = [];
        foreach ($nodes ?? [] as $node) {
            $owner = $ownerByLab[$node['lab_id']] ?? null;
            if ($owner === null) {
                continue;
            }
            if ($node['activity'] === null) {
                $unknownOwners[$owner] = true;
                continue;
            }
            $current = $activity[$owner] ?? null;
            if ($current === null || $node['activity'] > $current) {
                $activity[$owner] = $node['activity'];
            }
        }

        $guestsPurged = 0;
        $labsWiped = 0;
        $labsFailed = 0;
        $nodesReaped = 0;

        // b. Purge expired or idle guests, their labs, sessions, and row.
        //
        // Creation is a lower bound of activity, so a guest who logs out
        // (logout deletes the session row) survives until the idle window has
        // passed since creation. New guests record their real creation time in
        // the `demo.guest` audit row; older rows without a target fall back to
        // `expiration - demo_guest_ttl_hours`, which is only an estimate and
        // moves if the TTL setting changes.
        $createdAt = $this->guestCreationTimes();
        $idleCutoff = $now - $this->settings->int('demo_guest_idle_hours', 6) * 3600;
        $ttlSeconds = $this->settings->int('demo_guest_ttl_hours', 48) * 3600;
        $survivors = [];
        foreach ($guests as $guestId => $expiration) {
            $guestId = (string) $guestId;
            $effective = $activity[$guestId] ?? null;
            $created = $createdAt[$guestId] ?? null;
            if ($created === null && $expiration >= 0) {
                $created = $expiration - $ttlSeconds;
            }
            if ($created !== null && ($effective === null || $created > $effective)) {
                $effective = $created;
            }
            $reason = null;
            if ($expiration >= 0 && $expiration < $now) {
                $reason = 'ttl';
            } elseif ($nodes !== null
                && !isset($unknownOwners[$guestId])
                && ($effective === null || $effective < $idleCutoff)
            ) {
                $reason = 'idle';
            }
            if ($reason === null) {
                $survivors[$guestId] = true;
                continue;
            }

            $failed = [];
            foreach ($labsByOwner[$guestId] ?? [] as $labID) {
                if ($this->wipeLab($labID)) {
                    $labsWiped++;
                } else {
                    $failed[] = $labID;
                }
            }
            if ($failed !== []) {
                // Keep the identity and its sessions so the next sweep can
                // retry; deleting the user now would orphan the lab files.
                $labsFailed += count($failed);
                $survivors[$guestId] = true;
                $this->audit->log(null, '', 'demo.sweep', $guestId, 'failed', [
                    'reason' => $reason,
                    'guest' => $guestId,
                    'lab_ids' => $failed,
                ]);
                continue;
            }
            $this->deleteGuest($guestId);
            $this->audit->log(null, '', 'demo.sweep', $guestId, 'success', [
                'reason' => $reason,
                'guest' => $guestId,
            ]);
            $guestsPurged++;
        }

        // c. Wipe running labs whose nodes and owner have both gone quiet.
        // The guest identity survives until the purge above claims it.
        if ($nodes !== null) {
            $byLab = [];
            foreach ($nodes as $node) {
                if ($node['lab_id'] !== '') {
                    $byLab[$node['lab_id']][] = $node;
                }
            }
            $labIdleCutoff = $now - $this->settings->int('demo_idle_wipe_minutes', 120) * 60;
            foreach (array_keys($survivors) as $guestId) {
                $ownerSeen = $sessions[$guestId] ?? null;
                if ($ownerSeen !== null && $ownerSeen >= $labIdleCutoff) {
                    continue;
                }
                foreach ($labsByOwner[$guestId] ?? [] as $labID) {
                    $running = $byLab[$labID] ?? [];
                    if ($running === []) {
                        continue;
                    }
                    $latest = null;
                    $unknown = false;
                    foreach ($running as $node) {
                        if ($node['activity'] === null) {
                            $unknown = true;
                            break;
                        }
                        if ($latest === null || $node['activity'] > $latest) {
                            $latest = $node['activity'];
                        }
                    }
                    if ($unknown || $latest >= $labIdleCutoff) {
                        continue;
                    }
                    if (!$this->wipeLab($labID)) {
                        continue;
                    }
                    $labsWiped++;
                    $this->audit->log(null, '', 'demo.sweep', $labID, 'success', [
                        'reason' => 'lab_idle',
                        'guest' => $guestId,
                        'lab_id' => $labID,
                    ]);
                }
            }
        }

        // d. Reap the newest running guest-lab nodes while below the floor.
        // A fresh status is read so the purges above are reflected.
        $floor = $this->settings->int('demo_memory_floor_mb', 2048);
        $available = $this->memory->availableMb();
        $reapNodes = $available !== null && $available < $floor ? $this->runningNodes() : null;
        if ($reapNodes !== null) {
            $stopped = [];
            while ($available !== null && $available < $floor) {
                $candidate = $this->newestGuestNode($reapNodes, $ownerByLab, $stopped);
                if ($candidate === null) {
                    break;
                }
                try {
                    $this->agent->stop($candidate['id']);
                } catch (Throwable $exception) {
                    $stopped[$candidate['id']] = true;
                    if ($exception instanceof HttpException && $exception->status === 404) {
                        continue;
                    }
                    break;
                }
                $stopped[$candidate['id']] = true;
                $nodesReaped++;
                $this->audit->log(null, '', 'demo.reap', $candidate['id'], 'success', [
                    'lab_id' => $candidate['lab_id'],
                ]);
                $available = $this->memory->availableMb();
            }
        }

        // e. Record the sweep and report.
        $this->writeLastSweep($now);

        return [
            'guests_purged' => $guestsPurged,
            'labs_wiped' => $labsWiped,
            'labs_failed' => $labsFailed,
            'nodes_reaped' => $nodesReaped,
            'mem_available_mb' => $available,
        ];
    }

    /**
     * @return array{
     *   last_sweep_at: int|null,
     *   mem_available_mb: int|null,
     *   guests_active: int,
     *   demo_nodes_running: int
     * }
     */
    public function status(): array
    {
        $guests = $this->guestUsers();
        $guestIds = [];
        foreach (array_keys($guests) as $guestId) {
            $guestIds[(string) $guestId] = true;
        }
        [$ownerByLab] = $this->guestLabMap($guestIds);

        $running = 0;
        foreach ($this->runningNodes() ?? [] as $node) {
            if (isset($ownerByLab[$node['lab_id']])) {
                $running++;
            }
        }

        return [
            'last_sweep_at' => $this->bookkeeping()[0],
            'mem_available_mb' => $this->memory->availableMb(),
            'guests_active' => count($guests),
            'demo_nodes_running' => $running,
        ];
    }

    /**
     * @return list<array{id: string, lab_id: string, started: int, activity: int|null}>|null
     */
    private function runningNodes(): ?array
    {
        try {
            $status = $this->agent->status();
        } catch (Throwable) {
            return null;
        }

        $nodes = [];
        foreach ((array) ($status['nodes'] ?? []) as $node) {
            if (!is_array($node) || ($node['state'] ?? '') !== 'running') {
                continue;
            }
            $id = isset($node['id']) ? (string) $node['id'] : '';
            if ($id === '') {
                continue;
            }
            $started = $this->timestamp($node['started'] ?? null);
            $nodes[] = [
                'id' => $id,
                'lab_id' => isset($node['lab_id']) ? (string) $node['lab_id'] : '',
                'started' => $started ?? 0,
                'activity' => $this->timestamp($node['last_activity'] ?? null),
            ];
        }

        return $nodes;
    }

    /**
     * @param list<array{id: string, lab_id: string, started: int, activity: int|null}> $nodes
     * @param array<string, string> $ownerByLab
     * @param array<string, true> $stopped
     * @return array{id: string, lab_id: string, started: int, activity: int|null}|null
     */
    private function newestGuestNode(array $nodes, array $ownerByLab, array $stopped): ?array
    {
        $candidates = [];
        foreach ($nodes as $node) {
            if (isset($stopped[$node['id']]) || !isset($ownerByLab[$node['lab_id']])) {
                continue;
            }
            $candidates[] = $node;
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (array $left, array $right): int {
            $order = $right['started'] <=> $left['started'];

            return $order !== 0 ? $order : strcmp($right['id'], $left['id']);
        });

        return $candidates[0];
    }

    /**
     * @return array<string, int> Guest user id => expiration epoch.
     */
    private function guestUsers(): array
    {
        $statement = $this->pdo->query('SELECT id, guest, expiration FROM users WHERE guest = 1');
        if ($statement === false) {
            return [];
        }

        $guests = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) {
                continue;
            }
            if (!$this->policy::isGuest($row)) {
                continue;
            }
            $expiration = $row['expiration'] ?? -1;
            if (is_string($expiration) && is_numeric($expiration)) {
                $expiration = (int) $expiration;
            }
            $guests[$row['id']] = is_int($expiration) ? $expiration : -1;
        }

        return $guests;
    }

    /**
     * Real guest creation time from the audit trail.
     *
     * `AuthService::createGuest()` writes `demo.guest` with the new guest id as
     * its target, so the earliest such row is the creation timestamp. The audit
     * log is append-only, which also makes this stable across TTL changes.
     *
     * @return array<string, int> Guest id => earliest creation epoch.
     */
    private function guestCreationTimes(): array
    {
        $statement = $this->pdo->query(
            "SELECT target, MIN(ts) AS created_at FROM audit_log "
            . "WHERE action = 'demo.guest' AND target IS NOT NULL GROUP BY target"
        );
        if ($statement === false) {
            return [];
        }

        $created = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['target']) || !is_string($row['target'])) {
                continue;
            }
            $value = $row['created_at'] ?? null;
            if (is_numeric($value)) {
                $created[$row['target']] = (int) $value;
            }
        }

        return $created;
    }

    /**
     * @param array<string, true> $guestIds
     * @return array{0: array<string, string>, 1: array<string, list<string>>}
     */
    private function guestLabMap(array $guestIds): array
    {
        $ownerByLab = [];
        $labsByOwner = [];
        foreach ($this->repository->all() as $lab) {
            $labID = $lab['id'] ?? null;
            $attributes = is_array($lab['attributes'] ?? null) ? $lab['attributes'] : [];
            $owner = $attributes['owner'] ?? null;
            if (!is_string($labID) || $labID === '' || !is_string($owner) || !isset($guestIds[$owner])) {
                continue;
            }
            $ownerByLab[$labID] = $owner;
            $labsByOwner[$owner][] = $labID;
        }

        return [$ownerByLab, $labsByOwner];
    }

    /**
     * @return array<string, int> User id => latest session last_seen_at.
     */
    private function latestSessions(): array
    {
        $statement = $this->pdo->query(
            'SELECT user_id, MAX(last_seen_at) AS last_seen FROM sessions GROUP BY user_id'
        );
        if ($statement === false) {
            return [];
        }

        $seen = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['user_id']) || !is_string($row['user_id'])) {
                continue;
            }
            $value = $row['last_seen'] ?? null;
            $seen[$row['user_id']] = is_numeric($value) ? (int) $value : 0;
        }

        return $seen;
    }

    private function wipeLab(string $labID): bool
    {
        try {
            $this->labs->wipe($labID, null, true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deleteGuest(string $guestId): void
    {
        // Explicit dependent cleanup keeps the purge independent of the
        // connection's foreign-key pragma, like UserService::delete().
        foreach (['api_tokens', 'permissions', 'lab_locks', 'sessions'] as $table) {
            $statement = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = :user_id');
            $statement->execute([':user_id' => $guestId]);
        }
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute([':id' => $guestId]);
    }

    /**
     * One primary-key lookup for the lazy-sweep bookkeeping pair.
     *
     * @return array{0: int|null, 1: int}
     */
    private function bookkeeping(): array
    {
        $interval = (int) SettingsService::DEFAULTS[self::SWEEP_INTERVAL_KEY];
        $lastSweep = null;
        $statement = $this->pdo->query(
            "SELECT key, value FROM settings WHERE key IN ('" . self::LAST_SWEEP_KEY . "', '"
            . self::SWEEP_INTERVAL_KEY . "')"
        );
        if ($statement === false) {
            return [$lastSweep, $interval];
        }

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !isset($row['key'], $row['value'])) {
                continue;
            }
            $key = (string) $row['key'];
            $value = trim((string) $row['value']);
            if ($key === self::LAST_SWEEP_KEY) {
                if (ctype_digit($value)) {
                    $lastSweep = (int) $value;
                }
                continue;
            }
            if ($key === self::SWEEP_INTERVAL_KEY && ctype_digit($value) && (int) $value > 0) {
                $interval = (int) $value;
            }
        }

        return [$lastSweep, $interval];
    }

    private function writeLastSweep(int $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([
            ':key' => self::LAST_SWEEP_KEY,
            ':value' => (string) $now,
            ':updated_at' => $now,
        ]);
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            return $value > 0 ? (int) $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        $timestamp = $date->getTimestamp();

        return $timestamp > 0 ? $timestamp : null;
    }

    private function now(): int
    {
        if ($this->clock === null) {
            return time();
        }

        return (int) ($this->clock)();
    }
}
