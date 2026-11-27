<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\UnlParser;

final class AccessServiceTest extends TestCase
{
    private string $root;
    private PDO $pdo;
    private AccessService $access;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-access-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs/one', 0770, true);
        mkdir($this->root . '/labs/two', 0770, true);

        $this->writeLab($this->root . '/labs/public.unl', 'lab-public', 'Public Lab', 'owner-id');
        // Two labs with the same basename in different folders: the SEC-11
        // collision that must never be addressable by basename.
        $this->writeLab($this->root . '/labs/one/core.unl', 'core-one', 'Core One', 'owner-id');
        $this->writeLab($this->root . '/labs/two/core.unl', 'core-two', 'Core Two', 'other-id');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE users (id TEXT PRIMARY KEY, username TEXT NOT NULL, role TEXT NOT NULL)'
        );
        $this->pdo->exec(
            'CREATE TABLE permissions ('
            . 'lab_id TEXT NOT NULL, '
            . 'user_id TEXT NOT NULL, '
            . 'role TEXT NOT NULL, '
            . 'created_at INTEGER NOT NULL, '
            . 'PRIMARY KEY (lab_id, user_id)'
            . ')'
        );
        foreach (['admin-id', 'editor-id', 'user-id', 'owner-id', 'other-id'] as $id) {
            $statement = $this->pdo->prepare('INSERT INTO users (id, username, role) VALUES (:id, :id, :role)');
            $role = match ($id) {
                'admin-id' => 'admin',
                'user-id' => 'user',
                default => 'editor',
            };
            $statement->execute([':id' => $id, ':role' => $role]);
        }

        $this->access = new AccessService(
            $this->pdo,
            new LabRepository($this->root . '/labs', new UnlParser()),
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testGlobalAdminOwnerAndRoleMatrix(): void
    {
        $admin = $this->user('admin-id', 'admin');
        $owner = $this->user('owner-id', 'editor');
        $editor = $this->user('editor-id', 'editor');
        $user = $this->user('user-id', 'user');

        self::assertSame('admin', $this->access->labRole($admin, $this->lab('lab-public')));
        self::assertSame('admin', $this->access->labRole($owner, $this->lab('lab-public')));

        // A global editor without a permission row has no implicit lab grant.
        self::assertSame('user', $this->access->labRole($editor, $this->lab('lab-public')));
        $this->assertDenied($editor, 'lab-public', 'user');

        $this->access->grant('lab-public', 'editor-id', 'user');
        self::assertSame('user', $this->access->labRole($editor, $this->lab('lab-public')));
        self::assertSame('lab-public', $this->access->assertLabAccessWithRole($editor, 'lab-public', 'user')['id']);
        $this->assertDenied($editor, 'lab-public', 'editor');

        $this->access->grant('lab-public', 'editor-id', 'editor');
        self::assertSame('editor', $this->access->labRole($editor, $this->lab('lab-public')));
        self::assertSame('lab-public', $this->access->assertLabAccessWithRole($editor, 'lab-public', 'editor')['id']);
        $this->assertDenied($editor, 'lab-public', 'admin');

        // A local admin lifts a global editor to admin.
        $this->access->grant('lab-public', 'editor-id', 'admin');
        self::assertSame('admin', $this->access->labRole($editor, $this->lab('lab-public')));
        self::assertSame('lab-public', $this->access->assertLabAccessWithRole($editor, 'lab-public', 'admin')['id']);

        // A global user stays read-only even with a local editor row.
        $this->access->grant('lab-public', 'user-id', 'editor');
        self::assertSame('user', $this->access->labRole($user, $this->lab('lab-public')));
        self::assertSame('lab-public', $this->access->assertLabAccessWithRole($user, 'lab-public', 'user')['id']);
        $this->assertDenied($user, 'lab-public', 'editor');

        // A local admin does not lift a global user above read.
        $this->access->grant('lab-public', 'user-id', 'admin');
        self::assertSame('user', $this->access->labRole($user, $this->lab('lab-public')));
        $this->assertDenied($user, 'lab-public', 'editor');
    }

    public function testInvalidStoredRoleIsReadAsUser(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO permissions (lab_id, user_id, role, created_at) VALUES (:lab, :user, :role, :now)'
        );
        $statement->execute([
            ':lab' => 'lab-public',
            ':user' => 'editor-id',
            ':role' => 'superuser',
            ':now' => time(),
        ]);

        $editor = $this->user('editor-id', 'editor');
        self::assertSame('user', $this->access->labRole($editor, $this->lab('lab-public')));
        self::assertSame(
            'lab-public',
            $this->access->assertLabAccessWithRole($editor, 'lab-public', 'user')['id'],
        );
        $this->assertDenied($editor, 'lab-public', 'editor');

        $rows = $this->access->permissions('lab-public');
        self::assertCount(1, $rows);
        self::assertSame('user', $rows[0]['role']);
    }

    public function testFilterLabsRequiresAGrantAndHonoursRole(): void
    {
        $admin = $this->user('admin-id', 'admin');
        $editor = $this->user('editor-id', 'editor');
        $user = $this->user('user-id', 'user');

        self::assertSame([], $this->access->filterLabs($editor, $this->labs()));
        self::assertSame([], $this->access->filterLabs($user, $this->labs()));
        self::assertCount(3, $this->access->filterLabs($admin, $this->labs()));

        $this->access->grant('core-one', 'editor-id', 'user');
        $ids = array_column($this->access->filterLabs($editor, $this->labs()), 'id');
        self::assertSame(['core-one'], $ids);
    }

    public function testAuthenticatedLookupAcceptsOnlyTheCanonicalId(): void
    {
        $admin = $this->user('admin-id', 'admin');

        // 'core' is a shared basename, not a canonical id.
        try {
            $this->access->assertLabAccessWithRole($admin, 'core', 'user');
            self::fail('a basename must not resolve to a canonical lab');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }

        self::assertSame(
            'core-one',
            $this->access->assertLabAccessWithRole($admin, 'core-one', 'user')['id'],
        );
        self::assertSame(
            'core-two',
            $this->access->assertLabAccessWithRole($admin, 'core-two', 'user')['id'],
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertDenied(array $user, string $labId, string $minimum): void
    {
        try {
            $this->access->assertLabAccessWithRole($user, $labId, $minimum);
            self::fail('expected access to be denied at ' . $minimum);
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $id, string $role): array
    {
        return ['id' => $id, 'username' => $id, 'role' => $role];
    }

    /**
     * @return array<string, mixed>
     */
    private function lab(string $id): array
    {
        foreach ($this->labs() as $lab) {
            if ((string) $lab['id'] === $id) {
                return $lab;
            }
        }
        self::fail('lab not found: ' . $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function labs(): array
    {
        return (new LabRepository($this->root . '/labs', new UnlParser()))->all();
    }

    private function writeLab(string $path, string $id, string $name, string $owner): void
    {
        file_put_contents(
            $path,
            '<?xml version="1.0" encoding="UTF-8"?><lab name="' . $name . '" id="' . $id
            . '" version="1" owner="' . $owner . '">'
            . '<topology><nodes/><networks/></topology></lab>',
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
