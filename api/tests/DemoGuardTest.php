<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\System\DemoGuard;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\System\HostMemory;
use TeraLab\Api\System\SettingsService;
use TeraLab\Api\Templates\TemplateRepository;

final class DemoGuardTest extends TestCase
{
    private const DOCKER_IMAGE = 'quay.io/example/test:1';

    private string $root;
    private Config $config;
    private PDO $pdo;
    private SettingsService $settings;
    private DemoGuard $guard;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-demo-guard-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($this->config))->pdo();
        (new Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
        $this->settings = new SettingsService($this->pdo);
        $this->writeTemplate('vpcs');
        $this->guard = $this->guard();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testActorResolvesTheGuestRowAndReturnsNullWhenAbsent(): void
    {
        $expiration = time() + 600;
        $id = $this->insertUser('guest-actor', 'editor', 1, $expiration);

        $actor = $this->guard->actor($id);
        self::assertIsArray($actor);
        self::assertSame($id, $actor['id']);
        self::assertSame('editor', $actor['role']);
        self::assertSame(1, (int) $actor['guest']);
        self::assertSame($expiration, (int) $actor['expiration']);

        self::assertNull($this->guard->actor('missing-user'));
    }

    public function testGuestNodeCountCapUsesTheExactMessage(): void
    {
        $guest = $this->insertUser('guest-count', 'editor', 1, time() + 600);
        $nodes = [];
        for ($index = 1; $index <= 6; $index++) {
            $nodes[] = $this->node((string) $index, 'PC' . $index);
        }

        try {
            $this->guard->assertCanAuthor($guest, $nodes);
            self::fail('six nodes must exceed the default cap of five');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'The demo allows at most 5 nodes per lab; this lab has 6.',
                $exception->getMessage(),
            );
        }

        $this->guard->assertCanAuthor($guest, array_slice($nodes, 0, 5));
    }

    public function testGuestTemplateMustBeAllowlistedAndKnown(): void
    {
        $this->settings->update(['demo_allowed_templates' => 'vpcs,ghost']);
        $guest = $this->insertUser('guest-template', 'editor', 1, time() + 600);

        foreach (['iol', 'ghost'] as $template) {
            try {
                $this->guard->assertCanAuthor($guest, [
                    $this->node('1', 'R1', ['template' => $template]),
                ]);
                self::fail('template "' . $template . '" must be rejected');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
                self::assertSame(
                    'Template "' . $template . '" is not available on the demo.',
                    $exception->getMessage(),
                );
            }
        }

        // 'vpcs' both exists in the catalogue and is allowlisted.
        $this->guard->assertCanAuthor($guest, [$this->node('1', 'PC1')]);
    }

    public function testUnknownAllowlistIdsAreIgnored(): void
    {
        $this->settings->update(['demo_allowed_templates' => 'ghost,vpcs']);
        $guest = $this->insertUser('guest-unknown', 'editor', 1, time() + 600);

        self::assertSame(['ghost', 'vpcs'], $this->guard->allowedTemplates());
        $this->guard->assertCanAuthor($guest, [$this->node('1', 'PC1')]);

        try {
            $this->guard->assertCanAuthor($guest, [
                $this->node('1', 'R1', ['template' => 'ghost']),
            ]);
            self::fail('an allowlisted id that is absent from the catalogue must be ignored');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'Template "ghost" is not available on the demo.',
                $exception->getMessage(),
            );
        }
    }

    public function testGuestRamCapRejectsWithTheNodeName(): void
    {
        $this->settings->update(['demo_max_ram_per_node' => 1024]);
        $guest = $this->insertUser('guest-ram', 'editor', 1, time() + 600);

        try {
            $this->guard->assertCanAuthor($guest, [
                $this->node('1', 'PC1', ['ram' => 2048]),
            ]);
            self::fail('2048 MB must exceed the 1024 MB cap');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'The demo allows at most 1024 MB of RAM per node; "PC1" requests 2048 MB.',
                $exception->getMessage(),
            );
        }

        // Zero/absent RAM means "no explicit request" and is allowed.
        $this->guard->assertCanAuthor($guest, [
            $this->node('1', 'ZERO', ['ram' => 0]),
            ['id' => '2', 'name' => 'ABSENT', 'type' => 'vpcs', 'template' => 'vpcs'],
            $this->node('3', 'AT-CAP', ['ram' => 1024]),
        ]);
    }

    public function testImportClampsRamAndReportsADemoLimitedWarning(): void
    {
        $this->settings->update(['demo_max_ram_per_node' => 1024]);
        $guest = $this->insertUser('guest-import', 'editor', 1, time() + 600);
        $lab = [
            'node_list' => [
                $this->node('1', 'PC1', ['ram' => 2048]),
                $this->node('2', 'PC2', ['ram' => 512]),
            ],
            'warnings' => [['type' => 'existing', 'message' => 'keep me']],
        ];

        $result = $this->guard->prepareImport($guest, $lab);

        self::assertSame(1024, $result['lab']['node_list'][0]['ram']);
        self::assertSame(512, $result['lab']['node_list'][1]['ram']);
        self::assertCount(1, $result['warnings']);
        self::assertSame([
            'type' => 'demo_limited',
            'node' => 'PC1',
            'message' => 'RAM clamped from 2048 MB to 1024 MB for the demo.',
        ], $result['warnings'][0]);
        // Existing parser warnings are left for the caller to merge with.
        self::assertSame([['type' => 'existing', 'message' => 'keep me']], $result['lab']['warnings']);
    }

    public function testImportStillRejectsCountAndTemplateViolations(): void
    {
        $guest = $this->insertUser('guest-import-violation', 'editor', 1, time() + 600);
        $nodes = [];
        for ($index = 1; $index <= 6; $index++) {
            $nodes[] = $this->node((string) $index, 'PC' . $index);
        }

        try {
            $this->guard->prepareImport($guest, ['node_list' => $nodes]);
            self::fail('an over-cap import must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('at most 5 nodes per lab', $exception->getMessage());
        }

        try {
            $this->guard->prepareImport($guest, [
                'node_list' => [$this->node('1', 'R1', ['template' => 'iol'])],
            ]);
            self::fail('a non-allowlisted import template must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'Template "iol" is not available on the demo.',
                $exception->getMessage(),
            );
        }
    }

    public function testExpiredGuestIsExempt(): void
    {
        $guest = $this->insertUser('guest-expired', 'editor', 1, time() - 10);
        $nodes = [];
        for ($index = 1; $index <= 6; $index++) {
            $nodes[] = $this->node((string) $index, 'PC' . $index, [
                'template' => 'iol',
                'ram' => 8192,
            ]);
        }
        $lab = ['node_list' => $nodes];

        $this->guard->assertCanAuthor($guest, $nodes);
        $prepared = $this->guard->prepareImport($guest, $lab);
        self::assertSame($lab, $prepared['lab']);
        self::assertSame([], $prepared['warnings']);
    }

    public function testNonGuestAndMissingActorsAreExempt(): void
    {
        $editor = $this->insertUser('editor-exempt', 'editor', 0, -1);
        $admin = $this->insertUser('admin-exempt', 'admin', 0, -1);
        $nodes = [];
        for ($index = 1; $index <= 6; $index++) {
            $nodes[] = $this->node((string) $index, 'PC' . $index, [
                'template' => 'iol',
                'ram' => 8192,
            ]);
        }

        foreach ([$editor, $admin, 'missing-user'] as $userId) {
            $this->guard->assertCanAuthor($userId, $nodes);
            $prepared = $this->guard->prepareImport($userId, ['node_list' => $nodes]);
            self::assertSame(['node_list' => $nodes], $prepared['lab']);
            self::assertSame([], $prepared['warnings']);
        }

        // CLI/system paths pass no actor at all.
        $this->guard->assertCanAuthor(null, $nodes);
        self::assertSame([], $this->guard->prepareImport(null, ['node_list' => $nodes])['warnings']);
    }

    public function testTextNodesAndTemplateLessNodesAreCountedButNotTemplateChecked(): void
    {
        $guest = $this->insertUser('guest-text', 'editor', 1, time() + 600);
        $notes = [
            'id' => '1',
            'name' => 'Notes',
            'type' => 'text',
            'template' => '',
            'ram' => 0,
        ];
        $withoutKey = ['id' => '2', 'name' => 'Image', 'type' => 'picture', 'ram' => 0];

        $this->guard->assertCanAuthor($guest, [$notes, $withoutKey]);
        $prepared = $this->guard->prepareImport($guest, ['node_list' => [$notes, $withoutKey]]);
        self::assertSame([], $prepared['warnings']);
    }

    public function testTemplateKindMustMatchTheNodeType(): void
    {
        $this->writeTemplate('frr', 'docker');
        $this->settings->update(['demo_allowed_templates' => 'vpcs,frr']);
        $guest = $this->insertUser('guest-kind', 'editor', 1, time() + 600);

        try {
            $this->guard->assertCanAuthor($guest, [
                $this->node('1', 'R1', ['type' => 'docker', 'template' => 'vpcs']),
            ]);
            self::fail('a docker node must not use the vpcs template');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'Template "vpcs" does not match the node type "docker".',
                $exception->getMessage(),
            );
        }

        try {
            $this->guard->prepareImport($guest, [
                'node_list' => [$this->node('1', 'R1', ['type' => 'docker', 'template' => 'vpcs'])],
            ]);
            self::fail('an imported docker node must not use the vpcs template');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'Template "vpcs" does not match the node type "docker".',
                $exception->getMessage(),
            );
        }

        // Matching kinds pass, including case-insensitive type spellings and an
        // empty type left to the caller's default.
        $this->guard->assertCanAuthor($guest, [
            $this->node('1', 'PC1', ['type' => 'vpcs', 'template' => 'vpcs']),
            $this->node('2', 'R2', ['type' => ' docker ', 'template' => 'frr']),
            $this->node('3', 'PC3', ['type' => '', 'template' => 'vpcs']),
        ]);
    }

    public function testGuestCannotOverrideADockerTemplateImage(): void
    {
        $this->writeTemplate('frr-bgp', 'docker');
        $this->settings->update(['demo_allowed_templates' => 'frr-bgp']);
        $guest = $this->insertUser('guest-docker-image', 'editor', 1, time() + 600);
        $custom = $this->node('1', 'R1', [
            'type' => 'docker',
            'template' => 'frr-bgp',
            'image' => 'alpine',
            'ram' => 256,
        ]);

        try {
            $this->guard->assertCanAuthor($guest, [$custom]);
            self::fail('a guest must not override an allowlisted docker template image');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'The demo does not allow custom container images; use template "frr-bgp".',
                $exception->getMessage(),
            );
        }

        try {
            $this->guard->prepareImport($guest, ['node_list' => [$custom]]);
            self::fail('a guest import must not override an allowlisted docker template image');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'The demo does not allow custom container images; use template "frr-bgp".',
                $exception->getMessage(),
            );
        }

        // The template image and an empty node image both pass.
        $this->guard->assertCanAuthor($guest, [
            $this->node('1', 'R1', [
                'type' => 'docker',
                'template' => 'frr-bgp',
                'image' => self::DOCKER_IMAGE,
                'ram' => 256,
            ]),
            $this->node('2', 'R2', [
                'type' => 'docker',
                'template' => 'frr-bgp',
                'image' => '',
                'ram' => 256,
            ]),
        ]);

        // Non-guests keep the override; LabService::dockerSpec handles it.
        $editor = $this->insertUser('editor-docker-image', 'editor', 0, -1);
        $this->guard->assertCanAuthor($editor, [$custom]);
        self::assertSame(
            ['node_list' => [$custom]],
            $this->guard->prepareImport($editor, ['node_list' => [$custom]])['lab'],
        );
    }

    public function testAnnotationsAreExemptFromTemplateKindAndRamChecks(): void
    {
        $this->settings->update(['demo_max_ram_per_node' => 64]);
        $guest = $this->insertUser('guest-annotation', 'editor', 1, time() + 600);
        $notes = [
            'id' => '1',
            'name' => 'Notes',
            'type' => 'text',
            'template' => 'not-allowlisted',
            'ram' => 512,
        ];
        $picture = [
            'id' => '2',
            'name' => 'Picture',
            'type' => 'picture',
            'template' => '',
            'ram' => 512,
        ];

        $this->guard->assertCanAuthor($guest, [$notes, $picture]);
        $prepared = $this->guard->prepareImport($guest, ['node_list' => [$notes, $picture]]);
        self::assertSame([], $prepared['warnings']);
        self::assertSame(512, $prepared['lab']['node_list'][0]['ram']);
        self::assertSame(512, $prepared['lab']['node_list'][1]['ram']);
    }

    public function testMemoryFloorOnlyGuardsActiveGuestsAndFailsOpen(): void
    {
        $this->settings->update(['demo_memory_floor_mb' => 2048]);
        $guest = $this->insertUser('guest-floor', 'editor', 1, time() + 600);
        $expired = $this->insertUser('guest-floor-expired', 'editor', 1, time() - 10);
        $editor = $this->insertUser('editor-floor', 'editor', 0, -1);

        $low = $this->guardWithMemory(1024);
        try {
            $low->assertMemoryFloor($guest);
            self::fail('a guest must be refused below the memory floor');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame(
                'The demo is at capacity right now; try again shortly.',
                $exception->getMessage(),
            );
        }
        // Non-guests and expired guests are exempt, like every demo cap.
        $low->assertMemoryFloor($editor);
        $low->assertMemoryFloor($expired);
        $low->assertMemoryFloor(null);

        // At or above the floor, and when the probe is unavailable, the guard
        // fails open instead of blocking the demo.
        $this->guardWithMemory(4096)->assertMemoryFloor($guest);
        $this->guardWithMemory(null)->assertMemoryFloor($guest);
    }

    public function testAllowedTemplatesNormalisesTheCsv(): void
    {
        $this->settings->update(['demo_allowed_templates' => ' vpcs , , iol ,vpcs ']);
        self::assertSame(['vpcs', 'iol'], $this->guard->allowedTemplates());
        self::assertSame(['a', 'b'], DemoGuard::parseTemplateIds(' a,b ,a,,'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function node(string $id, string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name,
            'type' => 'vpcs',
            'template' => 'vpcs',
            'ram' => 512,
        ], $overrides);
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
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);

        return $id;
    }

    private function writeTemplate(string $id, string $kind = 'vpcs'): void
    {
        $directory = $this->config->templatesDir;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $yaml = "schema: 1\nid: " . $id . "\nname: " . $id . "\nkind: " . $kind . "\n";
        if ($kind === 'docker') {
            $yaml .= "options:\n  docker:\n    image: " . self::DOCKER_IMAGE . "\n";
        }
        file_put_contents($directory . '/' . $id . '.yaml', $yaml);
    }

    public function testGuestLabCountAndByteCaps(): void
    {
        $this->settings->update(['demo_max_labs_per_guest' => 2]);
        $guest = $this->insertUser('guest-lab-cap', 'editor', 1, time() + 600);
        $editor = $this->insertUser('editor-lab-cap', 'editor', 0, -1);
        $this->writeLab('first', 'lab-one', $guest);
        $this->writeLab('second', 'lab-two', $guest);
        $this->writeLab('editor', 'lab-editor', $editor);

        try {
            $this->guard->assertCanAuthor($guest, [$this->node('1', 'PC1')]);
            self::fail('a third guest lab must exceed the default count cap');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'The demo allows at most 2 labs per guest; delete a lab before creating another.',
                $exception->getMessage(),
            );
        }
        // Replacing an existing lab does not count itself.
        $this->guard->assertCanAuthor($guest, [$this->node('1', 'PC1')], 'lab-one');
        // Non-guests are exempt.
        $this->guard->assertCanAuthor($editor, [$this->node('1', 'PC1')]);

        $this->settings->update([
            'demo_max_labs_per_guest' => 20,
            'demo_max_lab_bytes_per_guest' => 1048576,
        ]);
        try {
            $this->guard->assertCanWriteLab($guest, 1048577);
            self::fail('a write above the byte cap must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'The demo allows at most 1048576 bytes of lab data per guest; this lab would exceed it.',
                $exception->getMessage(),
            );
        }
        $this->guard->assertCanWriteLab($guest, 1024);
        // Editors keep an unbounded byte budget.
        $this->guard->assertCanWriteLab($editor, 10485760);
    }

    public function testLabRepositoryOwnedStatsExcludesTrashAndOtherOwners(): void
    {
        $guest = $this->insertUser('guest-stats', 'editor', 1, time() + 600);
        $editor = $this->insertUser('editor-stats', 'editor', 0, -1);
        $this->writeLab('guest-one', 'stats-one', $guest);
        $this->writeLab('editor-one', 'stats-editor', $editor);
        mkdir($this->config->labsDir . '/.trash', 0770, true);
        $this->writeLab('trashed', 'stats-trashed', $guest, '/.trash');

        $repository = new LabRepository($this->config->labsDir, new UnlParser());
        $stats = $repository->ownedStats($guest);

        self::assertSame(1, $stats['count']);
        self::assertSame(['stats-one'], $stats['ids']);
        self::assertGreaterThan(0, $stats['bytes']);
        self::assertSame(0, $repository->ownedStats($guest, 'stats-one')['count']);
    }

    private function writeLab(string $name, string $id, string $owner, string $subdirectory = ''): void
    {
        $directory = $this->config->labsDir . $subdirectory;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        file_put_contents(
            $directory . '/' . $name . '.unl',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<lab name="' . $name . '" id="' . $id . '" version="1" owner="' . $owner . '">'
            . '<topology><nodes/><networks/></topology></lab>'
        );
    }

    private function guard(): DemoGuard
    {
        return new DemoGuard(
            $this->pdo,
            $this->settings,
            new DemoPolicy(),
            new TemplateRepository($this->config),
            new LabRepository($this->config->labsDir, new UnlParser()),
        );
    }

    private function guardWithMemory(?int $availableMb): DemoGuard
    {
        $path = $this->root . '/meminfo-' . bin2hex(random_bytes(4));
        if ($availableMb !== null) {
            file_put_contents($path, 'MemAvailable: ' . ($availableMb * 1024) . " kB\n");
        }

        return new DemoGuard(
            $this->pdo,
            $this->settings,
            new DemoPolicy(),
            new TemplateRepository($this->config),
            new LabRepository($this->config->labsDir, new UnlParser()),
            new HostMemory($path),
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
