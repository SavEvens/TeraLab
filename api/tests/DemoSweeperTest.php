<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Labs\UnlWriter;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\System\DemoSweeper;
use TeraLab\Api\System\HostMemory;
use TeraLab\Api\System\SettingsService;

final class DemoSweeperTest extends TestCase
{
    private const NOW = 1760000000;

    private string $root;
    private Config $config;
    private PDO $pdo;
    private SettingsService $settings;
    private HostMemory $memory;
    private AgentClient $agent;
    private LabService $labs;
    private AuditLogger $audit;
    private LabRepository $repository;
    private DemoSweeper $sweeper;
    private string $meminfoPath;

    /** @var list<array<string, mixed>> */
    private array $agentNodes = [];

    /** @var list<string> */
    private array $stops = [];

    /** @var list<string> */
    private array $wipes = [];

    private bool $statusFails = false;

    private ?string $stopFailPrefix = null;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onStop = null;

    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-demo-sweeper-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
        $this->settings = new SettingsService($this->pdo);
        $this->writeTemplate('vpcs');
        $this->meminfoPath = $this->root . '/meminfo';
        $this->setMemAvailable(4096);
        $this->memory = new HostMemory($this->meminfoPath);

        $this->agent = new AgentClient(
            $this->config,
            function (string $method, string $path, array $payload): array {
                return $this->agentResponse($method, $path, $payload);
            },
        );
        $this->labs = new LabService(
            $this->config,
            new UnlParser(),
            new UnlWriter(),
            $this->agent,
            new LabLocks($this->pdo),
            new ImageReferences($this->pdo, $this->config),
        );
        $this->audit = new AuditLogger($this->pdo);
        $this->repository = new LabRepository($this->config->labsDir, new UnlParser());
        $this->sweeper = $this->sweeperWith($this->memory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testTtlPurgeWipesLabsSessionsAndUser(): void
    {
        $guest = $this->insertUser('guest-ttl', 'editor', 1, self::NOW - 10);
        $this->insertSession($guest, self::NOW - 5);
        $lab = $this->createLab('Guest TTL', $guest, ['1', '2']);
        $labID = (string) $lab['id'];

        $summary = $this->sweeper->sweepNow();

        self::assertSame([
            'guests_purged' => 1,
            'labs_wiped' => 1,
            'labs_failed' => 0,
            'nodes_reaped' => 0,
            'mem_available_mb' => 4096,
        ], $summary);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame([
            $this->agentNodeID($labID, '1'),
            $this->agentNodeID($labID, '2'),
        ], $this->stops);
        self::assertSame($this->stops, $this->wipes);
        // Stop/wipe only: lab files are never deleted by the sweeper.
        self::assertFileExists($this->config->labsDir . '/' . (string) $lab['path']);
        self::assertSame(self::NOW, $this->lastSweepAt());

        $audit = $this->auditRows('demo.sweep');
        self::assertCount(1, $audit);
        self::assertSame($guest, $audit[0]['target']);
        self::assertSame('ttl', $audit[0]['details']['reason']);
        self::assertSame($guest, $audit[0]['details']['guest']);
    }

    public function testIdlePurgeUsesTheLatestSessionBeat(): void
    {
        $guest = $this->insertUser('guest-idle', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 6 * 3600 - 1);

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['guests_purged']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame('idle', $this->auditRows('demo.sweep')[0]['details']['reason']);
    }

    public function testGuestWithNoSessionsAndNoRunningNodesIsIdle(): void
    {
        $guest = $this->insertUser('guest-absent', 'editor', 1, self::NOW + 3600);

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['guests_purged']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testGuestWithoutSessionSurvivesWhileCreationIsRecent(): void
    {
        // TTL is 48h, so this guest was created ~60s ago. Logout removed the
        // session row; the creation estimate must keep it alive.
        $guest = $this->insertUser(
            'guest-logout-fresh',
            'editor',
            1,
            self::NOW + 48 * 3600 - 60,
        );

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['guests_purged']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame([], $this->auditRows('demo.sweep'));
    }

    public function testGuestWithoutSessionIsPurgedOnceIdleSinceCreation(): void
    {
        $guest = $this->insertUser(
            'guest-logout-stale',
            'editor',
            1,
            self::NOW + 48 * 3600 - (6 * 3600 + 60),
        );

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['guests_purged']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame('idle', $this->auditRows('demo.sweep')[0]['details']['reason']);
    }

    public function testTtlPurgesAGuestWithoutSessions(): void
    {
        $guest = $this->insertUser('guest-ttl-no-session', 'editor', 1, self::NOW - 10);

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['guests_purged']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $audit = $this->auditRows('demo.sweep');
        self::assertCount(1, $audit);
        self::assertSame('ttl', $audit[0]['details']['reason']);
    }

    public function testGuestAuditCreationWinsOverTheEstimate(): void
    {
        // The expiration-derived estimate is ~47h old, but the recorded
        // demo.guest creation was a minute ago, so the guest must survive.
        $guest = $this->insertUser('guest-audit-fresh', 'editor', 1, self::NOW + 3600);
        $this->insertGuestAudit($guest, self::NOW - 60);

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['guests_purged']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame([], $this->auditRows('demo.sweep'));
    }

    public function testOldGuestAuditCreationIsIdlePurgedEvenWhenTheEstimateIsRecent(): void
    {
        // The estimate is ~60s old, but the recorded creation is older than
        // the idle window: the audit timestamp wins over the estimate.
        $guest = $this->insertUser(
            'guest-audit-old',
            'editor',
            1,
            self::NOW + 48 * 3600 - 60,
        );
        $this->insertGuestAudit($guest, self::NOW - 6 * 3600 - 60);

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['guests_purged']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame('idle', $this->auditRows('demo.sweep')[0]['details']['reason']);
    }

    public function testFreshConsoleActivityKeepsAnOtherwiseIdleGuest(): void
    {
        $guest = $this->insertUser('guest-console', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 6 * 3600 - 1);
        $lab = $this->createLab('Guest Console', $guest, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $lab['id'], '1', self::NOW - 300, self::NOW - 60),
        ];

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['guests_purged']);
        self::assertSame(0, $summary['labs_wiped']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame([], $this->auditRows('demo.sweep'));
    }

    public function testAgentStatusFailureEnforcesOnlyTheHardTtl(): void
    {
        $active = $this->insertUser('guest-agent-active', 'editor', 1, self::NOW + 3600);
        $this->insertSession($active, self::NOW - 60);
        $stale = $this->insertUser('guest-agent-stale', 'editor', 1, self::NOW + 3600);
        $this->insertSession($stale, self::NOW - 6 * 3600 - 1);
        $expired = $this->insertUser('guest-agent-expired', 'editor', 1, self::NOW - 10);
        $this->insertSession($expired, self::NOW - 60);
        $this->statusFails = true;

        $summary = $this->sweeper->sweepNow();

        // With the agent unavailable, idle activity is unknown: only the hard
        // TTL may reclaim a guest.
        self::assertSame(1, $summary['guests_purged']);
        $ids = $this->pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$active, $stale], $ids);
        $audit = $this->auditRows('demo.sweep');
        self::assertCount(1, $audit);
        self::assertSame($expired, $audit[0]['target']);
        self::assertSame('ttl', $audit[0]['details']['reason']);
    }

    public function testUnknownNodeActivityKeepsTheGuestButTtlStillPurges(): void
    {
        $kept = $this->insertUser('guest-unknown-kept', 'editor', 1, self::NOW + 3600);
        $this->insertSession($kept, self::NOW - 6 * 3600 - 1);
        $keptLab = $this->createLab('Guest Unknown Kept', $kept, ['1']);
        $expired = $this->insertUser('guest-unknown-expired', 'editor', 1, self::NOW - 10);
        $this->insertSession($expired, self::NOW - 6 * 3600 - 1);
        $expiredLab = $this->createLab('Guest Unknown Expired', $expired, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $keptLab['id'], '1', self::NOW - 300, null),
            $this->runningNode((string) $expiredLab['id'], '1', self::NOW - 300, null),
        ];

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['guests_purged']);
        self::assertSame($kept, $this->pdo->query('SELECT id FROM users')->fetchColumn());
        $audit = $this->auditRows('demo.sweep');
        self::assertCount(1, $audit);
        self::assertSame($expired, $audit[0]['target']);
        self::assertSame('ttl', $audit[0]['details']['reason']);
    }

    public function testFailedPurgeKeepsTheGuestForRetry(): void
    {
        $guest = $this->insertUser('guest-retry', 'editor', 1, self::NOW - 10);
        $this->insertSession($guest, self::NOW - 60);
        $lab = $this->createLab('Guest Retry', $guest, ['1']);
        $labID = (string) $lab['id'];
        $this->stopFailPrefix = $labID;

        $failed = $this->sweeper->sweepNow();

        self::assertSame(0, $failed['guests_purged']);
        self::assertSame(0, $failed['labs_wiped']);
        self::assertSame(1, $failed['labs_failed']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        $failure = $this->auditRows('demo.sweep');
        self::assertCount(1, $failure);
        self::assertSame('failed', $failure[0]['result']);
        self::assertSame($guest, $failure[0]['target']);
        self::assertSame('ttl', $failure[0]['details']['reason']);
        self::assertSame([$labID], $failure[0]['details']['lab_ids']);

        // The next sweep retries and completes the purge.
        $this->stopFailPrefix = null;
        $retry = $this->sweeper->sweepNow();

        self::assertSame(1, $retry['guests_purged']);
        self::assertSame(1, $retry['labs_wiped']);
        self::assertSame(0, $retry['labs_failed']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $success = array_values(array_filter(
            $this->auditRows('demo.sweep'),
            static fn (array $row): bool => $row['result'] === 'success',
        ));
        self::assertCount(1, $success);
    }

    public function testLabIdleWipeSkipsUnknownNodeActivity(): void
    {
        $guest = $this->insertUser('guest-lab-unknown', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 121 * 60);
        $lab = $this->createLab('Guest Lab Unknown', $guest, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $lab['id'], '1', self::NOW - 600, null),
        ];

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['labs_wiped']);
        self::assertSame([], $this->stops);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame([], $this->auditRows('demo.sweep'));
    }

    public function testSweepIfDueSwallowsHousekeepingFailures(): void
    {
        $log = $this->root . '/sweep-error.log';
        $previous = ini_set('error_log', $log);
        try {
            // Break the sweep after the interval gate has already passed.
            $this->pdo->exec('DROP TABLE sessions');
            $this->sweeper->sweepIfDue();
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
        }

        self::assertNull($this->lastSweepAt());
        self::assertFileExists($log);
        self::assertStringContainsString('teralab demo sweep failed:', (string) file_get_contents($log));
    }

    public function testLabIdleWipeKeepsTheGuestIdentity(): void
    {
        $guest = $this->insertUser('guest-lab-idle', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 121 * 60);
        $lab = $this->createLab('Guest Lab Idle', $guest, ['1', '2']);
        $labID = (string) $lab['id'];
        $this->agentNodes = [
            $this->runningNode($labID, '1', self::NOW - 600, self::NOW - 130 * 60),
            $this->runningNode($labID, '2', self::NOW - 500, self::NOW - 140 * 60),
        ];

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['guests_purged']);
        self::assertSame(1, $summary['labs_wiped']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame([
            $this->agentNodeID($labID, '1'),
            $this->agentNodeID($labID, '2'),
        ], $this->stops);

        $audit = $this->auditRows('demo.sweep');
        self::assertCount(1, $audit);
        self::assertSame($labID, $audit[0]['target']);
        self::assertSame('lab_idle', $audit[0]['details']['reason']);
        self::assertSame($guest, $audit[0]['details']['guest']);
    }

    public function testLabIdleWipeSkipsWhenTheOwnerSessionIsActive(): void
    {
        $guest = $this->insertUser('guest-lab-owner-active', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 60);
        $lab = $this->createLab('Guest Owner Active', $guest, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $lab['id'], '1', self::NOW - 600, self::NOW - 130 * 60),
        ];

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['labs_wiped']);
        self::assertSame([], $this->stops);
    }

    public function testLabIdleWipeSkipsWhenNodeActivityIsActive(): void
    {
        $guest = $this->insertUser('guest-lab-node-active', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 121 * 60);
        $lab = $this->createLab('Guest Node Active', $guest, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $lab['id'], '1', self::NOW - 600, self::NOW - 60),
        ];

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['labs_wiped']);
        self::assertSame([], $this->stops);
    }

    public function testReaperStopsTheNewestGuestNodeAndStopsAtTheFloor(): void
    {
        $guest = $this->insertUser('guest-reap-floor', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 60);
        $admin = $this->insertUser('admin-reap', 'admin', 0, -1);
        $guestLab = $this->createLab('Guest Reap Floor', $guest, ['1', '2', '3']);
        $adminLab = $this->createLab('Admin Reap Floor', $admin, ['1']);
        $guestLabID = (string) $guestLab['id'];
        $this->agentNodes = [
            $this->runningNode($guestLabID, '1', self::NOW - 300, self::NOW - 60),
            $this->runningNode($guestLabID, '2', self::NOW - 200, self::NOW - 60),
            $this->runningNode($guestLabID, '3', self::NOW - 100, self::NOW - 60),
            $this->runningNode((string) $adminLab['id'], '1', self::NOW - 10, self::NOW - 10),
        ];
        $this->setMemAvailable(1024);
        $this->onStop = function (string $id): void {
            self::assertSame($this->agentNodeID((string) $this->agentNodes[0]['lab_id'], '3'), $id);
            $this->setMemAvailable(4096);
        };

        $summary = $this->sweeper->sweepNow();

        self::assertSame(1, $summary['nodes_reaped']);
        self::assertSame(4096, $summary['mem_available_mb']);
        self::assertSame([$this->agentNodeID($guestLabID, '3')], $this->stops);
        $reaps = $this->auditRows('demo.reap');
        self::assertCount(1, $reaps);
        self::assertSame($guestLabID, $reaps[0]['details']['lab_id']);
    }

    public function testReaperStopsAllGuestNodesNewestFirstAndIgnoresAdminAndEditorLabs(): void
    {
        $guest = $this->insertUser('guest-reap-all', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 60);
        $editor = $this->insertUser('editor-reap', 'editor', 0, -1);
        $guestLab = $this->createLab('Guest Reap All', $guest, ['1', '2', '3']);
        $editorLab = $this->createLab('Editor Reap All', $editor, ['1']);
        $guestLabID = (string) $guestLab['id'];
        $this->agentNodes = [
            $this->runningNode($guestLabID, '1', self::NOW - 300, self::NOW - 60),
            $this->runningNode($guestLabID, '2', self::NOW - 200, self::NOW - 60),
            $this->runningNode($guestLabID, '3', self::NOW - 100, self::NOW - 60),
            $this->runningNode((string) $editorLab['id'], '1', self::NOW - 10, self::NOW - 10),
        ];
        $this->setMemAvailable(512);

        $summary = $this->sweeper->sweepNow();

        self::assertSame(3, $summary['nodes_reaped']);
        self::assertSame([
            $this->agentNodeID($guestLabID, '3'),
            $this->agentNodeID($guestLabID, '2'),
            $this->agentNodeID($guestLabID, '1'),
        ], $this->stops);
        self::assertSame(512, $summary['mem_available_mb']);
        self::assertCount(3, $this->auditRows('demo.reap'));
    }

    public function testReaperSkipsWhenMemAvailableIsUnreadable(): void
    {
        $guest = $this->insertUser('guest-reap-unknown', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 60);
        $lab = $this->createLab('Guest Reap Unknown', $guest, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $lab['id'], '1', self::NOW - 100, self::NOW - 60),
        ];
        $this->sweeper = $this->sweeperWith(new HostMemory($this->root . '/missing-meminfo'));

        $summary = $this->sweeper->sweepNow();

        self::assertSame(0, $summary['nodes_reaped']);
        self::assertNull($summary['mem_available_mb']);
        self::assertSame([], $this->stops);
    }

    public function testSweepIfDueHonoursTheIntervalAndRunsWhenMissing(): void
    {
        $this->settings->update(['demo_sweep_interval' => 300]);
        $this->setLastSweep(self::NOW - 60);

        $this->sweeper->sweepIfDue();
        self::assertSame(self::NOW - 60, $this->lastSweepAt());

        $this->setLastSweep(self::NOW - 400);
        $this->sweeper->sweepIfDue();
        self::assertSame(self::NOW, $this->lastSweepAt());

        $this->pdo->exec("DELETE FROM settings WHERE key = '" . DemoSweeper::LAST_SWEEP_KEY . "'");
        $this->sweeper->sweepIfDue();
        self::assertSame((string) self::NOW, $this->lastSweepRaw());
    }

    public function testStatusReportsGuestCountsAndBookkeeping(): void
    {
        $guest = $this->insertUser('guest-status', 'editor', 1, self::NOW + 3600);
        $this->insertSession($guest, self::NOW - 60);
        $admin = $this->insertUser('admin-status', 'admin', 0, -1);
        $guestLab = $this->createLab('Guest Status', $guest, ['1']);
        $adminLab = $this->createLab('Admin Status', $admin, ['1']);
        $this->agentNodes = [
            $this->runningNode((string) $guestLab['id'], '1', self::NOW - 300, self::NOW - 60),
            $this->runningNode((string) $adminLab['id'], '1', self::NOW - 300, self::NOW - 60),
        ];
        $this->setMemAvailable(3072);

        self::assertSame([
            'last_sweep_at' => null,
            'mem_available_mb' => 3072,
            'guests_active' => 1,
            'demo_nodes_running' => 1,
        ], $this->sweeper->status());

        $this->setLastSweep(self::NOW - 90);
        self::assertSame(self::NOW - 90, $this->sweeper->status()['last_sweep_at']);
    }

    public function testSweepBookkeepingKeyIsNotASettingsDefault(): void
    {
        self::assertArrayNotHasKey(DemoSweeper::LAST_SWEEP_KEY, SettingsService::DEFAULTS);

        $this->sweeper->sweepNow();

        self::assertSame(self::NOW, $this->lastSweepAt());
        self::assertArrayNotHasKey(DemoSweeper::LAST_SWEEP_KEY, $this->settings->all());
    }

    private function sweeperWith(HostMemory $memory): DemoSweeper
    {
        return new DemoSweeper(
            $this->pdo,
            $this->settings,
            new DemoPolicy(),
            $this->agent,
            $this->labs,
            $this->audit,
            $memory,
            $this->repository,
            fn (): int => $this->now,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: string}
     */
    private function agentResponse(string $method, string $path, array $payload): array
    {
        if ($this->statusFails && $path === '/v1/system/status') {
            return [503, json_encode(['ok' => false, 'error' => ['message' => 'agent down']], JSON_THROW_ON_ERROR)];
        }
        if ($path === '/v1/system/status') {
            return $this->ok(['nodes' => $this->agentNodes]);
        }
        if ($path === '/v1/node.stop') {
            $id = (string) ($payload['id'] ?? '');
            if ($this->stopFailPrefix !== null && str_starts_with($id, $this->stopFailPrefix . '-')) {
                return [500, json_encode(['ok' => false, 'error' => ['message' => 'stop failed']], JSON_THROW_ON_ERROR)];
            }
            $this->stops[] = $id;
            foreach ($this->agentNodes as $index => $node) {
                if (($node['id'] ?? null) === $id) {
                    $this->agentNodes[$index]['state'] = 'stopped';
                }
            }
            if ($this->onStop !== null) {
                ($this->onStop)($id);
            }

            return $this->ok([]);
        }
        if ($path === '/v1/node.wipe') {
            $this->wipes[] = (string) ($payload['id'] ?? '');

            return $this->ok([]);
        }

        return $this->ok([]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array{0: int, 1: string}
     */
    private function ok(array $result): array
    {
        return [200, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR)];
    }

    /**
     * @param list<string> $nodeIDs
     * @return array<string, mixed>
     */
    private function createLab(string $name, string $owner, array $nodeIDs): array
    {
        $nodes = [];
        foreach ($nodeIDs as $nodeID) {
            $nodes[] = [
                'id' => $nodeID,
                'name' => 'PC' . $nodeID,
                'type' => 'vpcs',
                'template' => 'vpcs',
                'ram' => 256,
                'interfaces' => [],
            ];
        }

        return $this->labs->create(['name' => $name, 'nodes' => $nodes], $owner, $owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function runningNode(string $labID, string $nodeID, int $started, ?int $activity): array
    {
        $node = [
            'id' => $this->agentNodeID($labID, $nodeID),
            'lab_id' => $labID,
            'name' => 'PC' . $nodeID,
            'state' => 'running',
            'started' => gmdate('c', $started),
        ];
        if ($activity !== null) {
            $node['last_activity'] = gmdate('c', $activity);
        }

        return $node;
    }

    private function agentNodeID(string $labID, string $nodeID): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $labID . '-' . $nodeID);

        return substr(ltrim($value, '.-'), 0, 64);
    }

    private function insertUser(string $id, string $role, int $guest, int $expiration): string
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :expiration, :guest, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $id,
            ':password_hash' => 'unused',
            ':role' => $role,
            ':expiration' => $expiration,
            ':guest' => $guest,
            ':created_at' => self::NOW,
            ':updated_at' => self::NOW,
        ]);

        return $id;
    }

    private function insertGuestAudit(string $guestId, int $timestamp): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (id, ts, user_id, ip, action, target, result, details_json) '
            . 'VALUES (:id, :ts, :user_id, :ip, :action, :target, :result, :details_json)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':ts' => $timestamp,
            ':user_id' => $guestId,
            ':ip' => '127.0.0.1',
            ':action' => 'demo.guest',
            ':target' => $guestId,
            ':result' => 'success',
            ':details_json' => '{}',
        ]);
    }

    private function insertSession(string $userId, int $lastSeen): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id_hash, user_id, csrf_hash, ip, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:id_hash, :user_id, :csrf_hash, :ip, :user_agent, :created_at, :last_seen_at, :expires_at)'
        );
        $statement->execute([
            ':id_hash' => bin2hex(random_bytes(16)),
            ':user_id' => $userId,
            ':csrf_hash' => bin2hex(random_bytes(16)),
            ':ip' => '127.0.0.1',
            ':user_agent' => 'test',
            ':created_at' => self::NOW - 600,
            ':last_seen_at' => $lastSeen,
            ':expires_at' => self::NOW + 3600,
        ]);
    }

    private function setMemAvailable(int $megabytes): void
    {
        file_put_contents(
            $this->meminfoPath,
            "MemTotal:       16384000 kB\nMemAvailable:   " . ($megabytes * 1024) . " kB\n",
        );
    }

    private function setLastSweep(int $timestamp): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([
            ':key' => DemoSweeper::LAST_SWEEP_KEY,
            ':value' => (string) $timestamp,
            ':updated_at' => $timestamp,
        ]);
    }

    private function lastSweepRaw(): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute([':key' => DemoSweeper::LAST_SWEEP_KEY]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function lastSweepAt(): ?int
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute([':key' => DemoSweeper::LAST_SWEEP_KEY]);
        $value = $statement->fetchColumn();

        return is_string($value) ? (int) $value : null;
    }

    /**
     * @return list<array{action: string, target: string|null, result: string, details: array<string, mixed>}>
     */
    private function auditRows(string $action): array
    {
        $statement = $this->pdo->prepare(
            'SELECT action, target, result, details_json FROM audit_log WHERE action = :action ORDER BY ts, id'
        );
        $statement->execute([':action' => $action]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $details = json_decode((string) ($row['details_json'] ?? '{}'), true);
            $rows[] = [
                'action' => (string) ($row['action'] ?? ''),
                'target' => isset($row['target']) ? (string) $row['target'] : null,
                'result' => (string) ($row['result'] ?? ''),
                'details' => is_array($details) ? $details : [],
            ];
        }

        return $rows;
    }

    private function writeTemplate(string $id): void
    {
        $directory = $this->config->templatesDir;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        file_put_contents(
            $directory . '/' . $id . '.yaml',
            "schema: 1\nid: " . $id . "\nname: " . $id . "\nkind: vpcs\n",
        );
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
