<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Auth\PasswordPolicy;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;

final class UsersApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private string $adminCookie = '';
    private string $adminCsrf = '';
    private string $adminId = '';

    /** @var array<string, bool> Agent node id => running. */
    private array $running = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-users-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, function (string $method, string $path, array $payload): array {
            if ($path === '/v1/lab.ensure') {
                return [200, '{"ok":true,"result":{"bridges":{"0":"tl-test-bridge-0"}}}'];
            }
            if ($path === '/v1/node.start') {
                $id = (string) ($payload['id'] ?? '');
                $this->running[$id] = true;

                return [200, json_encode([
                    'ok' => true,
                    'result' => ['id' => $id, 'state' => 'running', 'ready' => true],
                ], JSON_THROW_ON_ERROR)];
            }
            if (str_starts_with($path, '/v1/node.status')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $id = (string) ($query['id'] ?? '');
                if (!isset($this->running[$id])) {
                    return [404, '{"ok":false,"error":{"code":"not_found","message":"node not found"}}'];
                }

                return [200, '{"ok":true,"result":{"id":"node","state":"running","ready":true}}'];
            }
            if ($path === '/v1/node.stop' || $path === '/v1/node.wipe') {
                unset($this->running[(string) ($payload['id'] ?? '')]);

                return [200, '{"ok":true,"result":{"state":"ok"}}'];
            }
            if ($path === '/v1/system/status') {
                return [200, '{"ok":true,"result":{"nodes":[]}}'];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->app = (new App($config, $agent))->create();
        $this->pdo = (new Database($config))->pdo();

        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $this->adminCookie = $this->cookie($setup);
        $setupBody = $this->json($setup);
        $this->adminCsrf = (string) $setupBody['csrf_token'];
        $this->adminId = (string) $setupBody['user']['id'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testCreateWithTypedAndGeneratedPasswords(): void
    {
        $typed = $this->createUser([
            'username' => 'editor-one',
            'role' => 'editor',
            'password' => 'Teralab-Editor-2026!',
            'email' => 'editor@example.test',
            'name' => 'Editor One',
            'expiration' => 1893456000,
        ]);
        self::assertArrayNotHasKey('password', $typed);
        $user = $typed['user'];
        self::assertSame('editor-one', $user['username']);
        self::assertSame('editor', $user['role']);
        self::assertSame('editor@example.test', $user['email']);
        self::assertSame('Editor One', $user['name']);
        self::assertSame(1893456000, $user['expiration']);
        self::assertSame(0, $user['session_count']);
        self::assertSame(0, $user['lab_count']);
        self::assertNull($user['last_seen_at']);
        foreach (['id', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $user);
        }
        self::assertArrayNotHasKey('password_hash', $user);

        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute([':id' => $user['id']]);
        $hash = (string) $statement->fetchColumn();
        self::assertNotSame('', $hash);
        self::assertTrue(password_verify('Teralab-Editor-2026!', $hash));

        $generatedResponse = $this->request('POST', '/api/v2/users', [
            'username' => 'viewer-one',
            'role' => 'user',
        ]);
        self::assertSame(201, $generatedResponse->getStatusCode(), (string) $generatedResponse->getBody());
        self::assertSame('no-store', $generatedResponse->getHeaderLine('Cache-Control'));
        $generated = $this->json($generatedResponse)['data'];
        self::assertArrayHasKey('password', $generated);
        $password = $generated['password'];
        self::assertIsString($password);
        self::assertGreaterThanOrEqual(16, strlen($password));
        self::assertSame([], (new PasswordPolicy())->validate($password));
        self::assertSame(-1, $generated['user']['expiration']);

        [$cookie] = $this->login('viewer-one', $password);
        self::assertNotSame('', $cookie);

        $this->assertValidationError(422, [
            'username' => 'viewer-one',
            'role' => 'user',
        ]);
        $this->assertValidationError(422, ['username' => 'ab', 'role' => 'user']);
        $this->assertValidationError(422, ['username' => 'bad-role', 'role' => 'owner']);
        $this->assertValidationError(422, [
            'username' => 'bad-email',
            'role' => 'user',
            'email' => 'not-an-email',
        ]);
        $this->assertValidationError(422, [
            'username' => 'bad-expiry',
            'role' => 'user',
            'expiration' => -2,
        ]);
        $this->assertValidationError(422, [
            'username' => 'weak-password',
            'role' => 'user',
            'password' => 'short',
        ]);
        $this->assertValidationError(422, ['username' => 'no-role']);
    }

    public function testListReportsSessionAndLabCounts(): void
    {
        $created = $this->createUser([
            'username' => 'counter',
            'role' => 'editor',
            'password' => 'Teralab-Counter-2026!',
        ]);
        [$cookie, $csrf] = $this->login('counter', 'Teralab-Counter-2026!');
        $this->login('counter', 'Teralab-Counter-2026!');

        $lab = $this->request('POST', '/api/v2/labs', ['name' => 'Counter Lab'], [], $cookie, $csrf);
        self::assertSame(201, $lab->getStatusCode(), (string) $lab->getBody());

        $response = $this->request('GET', '/api/v2/users');
        self::assertSame(200, $response->getStatusCode());
        $row = $this->userRow($this->json($response)['data'], (string) $created['user']['id']);
        self::assertSame(2, $row['session_count']);
        self::assertSame(1, $row['lab_count']);
        self::assertIsInt($row['last_seen_at']);
        self::assertArrayNotHasKey('password_hash', $row);
    }

    public function testUpdateFieldsAndRole(): void
    {
        $created = $this->createUser([
            'username' => 'patchme',
            'role' => 'user',
            'password' => 'Teralab-Patch-2026!',
        ]);
        $id = (string) $created['user']['id'];

        $response = $this->request('PATCH', '/api/v2/users/' . $id, [
            'email' => 'patched@example.test',
            'name' => 'Patched',
            'expiration' => 0,
            'role' => 'editor',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $user = $this->json($response)['data'];
        self::assertSame('patched@example.test', $user['email']);
        self::assertSame('Patched', $user['name']);
        self::assertSame(0, $user['expiration']);
        self::assertSame('editor', $user['role']);

        // Re-sending the same normalised values is a no-op: no write, no audit.
        $auditsBefore = $this->auditCount('user.update');
        $noop = $this->request('PATCH', '/api/v2/users/' . $id, [
            'email' => 'patched@example.test',
            'name' => 'Patched',
            'expiration' => 0,
            'role' => 'editor',
        ]);
        self::assertSame(200, $noop->getStatusCode(), (string) $noop->getBody());
        self::assertSame((int) $user['updated_at'], (int) $this->json($noop)['data']['updated_at']);
        self::assertSame($auditsBefore, $this->auditCount('user.update'));

        self::assertSame(422, $this->request('PATCH', '/api/v2/users/' . $id, ['email' => 'nope'])->getStatusCode());
        self::assertSame(422, $this->request('PATCH', '/api/v2/users/' . $id, ['role' => 'root'])->getStatusCode());
        self::assertSame(422, $this->request('PATCH', '/api/v2/users/' . $id, ['expiration' => -3])->getStatusCode());
        self::assertSame(404, $this->request('PATCH', '/api/v2/users/does-not-exist', ['name' => 'x'])->getStatusCode());

        $actions = $this->pdo->query('SELECT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('user.update', $actions);
    }

    public function testSelfAndLastAdminGuards(): void
    {
        self::assertSame(409, $this->request('PATCH', '/api/v2/users/' . $this->adminId, ['role' => 'editor'])->getStatusCode());
        self::assertSame(409, $this->request('PATCH', '/api/v2/users/' . $this->adminId, ['role' => 'admin'])->getStatusCode());
        self::assertSame(200, $this->request('PATCH', '/api/v2/users/' . $this->adminId, ['email' => 'admin@example.test'])->getStatusCode());
        self::assertSame(409, $this->request('DELETE', '/api/v2/users/' . $this->adminId)->getStatusCode());

        $second = $this->createUser(['username' => 'admin-two', 'role' => 'admin']);
        self::assertArrayHasKey('password', $second);
        [$secondCookie, $secondCsrf] = $this->login('admin-two', (string) $second['password']);

        // The second admin cannot delete themselves either.
        self::assertSame(409, $this->request(
            'DELETE',
            '/api/v2/users/' . $second['user']['id'],
            [],
            [],
            $secondCookie,
            $secondCsrf,
        )->getStatusCode());

        // With two admins the first may delete the second, leaving one admin.
        $deleteSecond = $this->request('DELETE', '/api/v2/users/' . $second['user']['id']);
        self::assertSame(200, $deleteSecond->getStatusCode(), (string) $deleteSecond->getBody());
        self::assertSame(409, $this->request('PATCH', '/api/v2/users/' . $this->adminId, ['role' => 'user'])->getStatusCode());
        self::assertSame(409, $this->request('DELETE', '/api/v2/users/' . $this->adminId)->getStatusCode());
    }

    public function testResetPasswordRevokesTargetSessions(): void
    {
        $created = $this->createUser([
            'username' => 'resetme',
            'role' => 'user',
            'password' => 'Teralab-Reset-Old-2026!',
        ]);
        $id = (string) $created['user']['id'];
        [$firstCookie] = $this->login('resetme', 'Teralab-Reset-Old-2026!');
        [$secondCookie] = $this->login('resetme', 'Teralab-Reset-Old-2026!');

        $reset = $this->request('POST', '/api/v2/users/' . $id . '/password', [
            'password' => 'Teralab-Reset-New-2026!',
        ]);
        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());
        self::assertSame('no-store', $reset->getHeaderLine('Cache-Control'));
        self::assertArrayNotHasKey('password', $this->json($reset)['data']);

        self::assertSame(401, $this->request('GET', '/api/v2/auth/me', [], [], $firstCookie)->getStatusCode());
        self::assertSame(401, $this->request('GET', '/api/v2/auth/me', [], [], $secondCookie)->getStatusCode());
        self::assertSame(401, $this->request('POST', '/api/v2/auth/login', [
            'username' => 'resetme',
            'password' => 'Teralab-Reset-Old-2026!',
        ])->getStatusCode());
        [$newCookie] = $this->login('resetme', 'Teralab-Reset-New-2026!');
        self::assertSame(200, $this->request('GET', '/api/v2/auth/me', [], [], $newCookie)->getStatusCode());

        $generated = $this->request('POST', '/api/v2/users/' . $id . '/password');
        self::assertSame(200, $generated->getStatusCode(), (string) $generated->getBody());
        self::assertSame('no-store', $generated->getHeaderLine('Cache-Control'));
        $password = (string) $this->json($generated)['data']['password'];
        self::assertGreaterThanOrEqual(16, strlen($password));
        self::assertSame([], (new PasswordPolicy())->validate($password));
        self::assertSame(401, $this->request('GET', '/api/v2/auth/me', [], [], $newCookie)->getStatusCode());
        $this->login('resetme', $password);

        self::assertSame(422, $this->request('POST', '/api/v2/users/' . $id . '/password', [
            'password' => 'weak',
        ])->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/v2/users/missing/password', [])->getStatusCode());

        $actions = $this->pdo->query('SELECT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('user.password.reset', $actions);
    }

    public function testForceLogout(): void
    {
        $created = $this->createUser([
            'username' => 'kickme',
            'role' => 'user',
            'password' => 'Teralab-Kick-2026!',
        ]);
        $id = (string) $created['user']['id'];
        [$cookie] = $this->login('kickme', 'Teralab-Kick-2026!');

        $revoke = $this->request('POST', '/api/v2/users/' . $id . '/sessions/revoke');
        self::assertSame(200, $revoke->getStatusCode(), (string) $revoke->getBody());
        self::assertSame(1, $this->json($revoke)['data']['revoked']);
        self::assertSame(401, $this->request('GET', '/api/v2/auth/me', [], [], $cookie)->getStatusCode());

        $actions = $this->pdo->query('SELECT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('user.sessions.revoke', $actions);
        self::assertSame(404, $this->request('POST', '/api/v2/users/missing/sessions/revoke')->getStatusCode());
    }

    public function testMutationsRequireCsrfToken(): void
    {
        $created = $this->createUser([
            'username' => 'csrf-target',
            'role' => 'user',
            'password' => 'Teralab-Csrf-2026!',
        ]);
        $id = (string) $created['user']['id'];

        $cases = [
            ['POST', '/api/v2/users', ['username' => 'csrf-new', 'role' => 'user']],
            ['PATCH', '/api/v2/users/' . $id, ['name' => 'CSRF']],
            ['POST', '/api/v2/users/' . $id . '/password', ['password' => 'Teralab-Csrf-New-2026!']],
            ['POST', '/api/v2/users/' . $id . '/sessions/revoke', []],
            ['DELETE', '/api/v2/users/' . $id, []],
        ];
        foreach ($cases as [$method, $path, $body]) {
            $response = $this->request($method, $path, $body, [], null, '');
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ' without CSRF: ' . (string) $response->getBody());
        }

        $users = $this->json($this->request('GET', '/api/v2/users'))['data'];
        self::assertContains($id, array_column($users, 'id'));
    }

    public function testDeleteKeepsLabsByDefaultAndTrashesWithFlag(): void
    {
        $owner = $this->createUser([
            'username' => 'lab-owner',
            'role' => 'editor',
            'password' => 'Teralab-Owner-2026!',
        ]);
        $ownerId = (string) $owner['user']['id'];
        [$ownerCookie, $ownerCsrf] = $this->login('lab-owner', 'Teralab-Owner-2026!');
        $lab = $this->request('POST', '/api/v2/labs', ['name' => 'Kept Lab'], [], $ownerCookie, $ownerCsrf);
        self::assertSame(201, $lab->getStatusCode(), (string) $lab->getBody());
        $labId = (string) $this->json($lab)['data']['id'];
        $token = $this->request('POST', '/api/v2/system/tokens', [
            'name' => 'owner-token',
            'scopes' => ['read'],
        ], [], $ownerCookie, $ownerCsrf);
        self::assertSame(201, $token->getStatusCode(), (string) $token->getBody());

        $permission = $this->pdo->prepare(
            'INSERT INTO permissions (lab_id, user_id, role, created_at) VALUES (:lab_id, :user_id, :role, :created_at)'
        );
        $permission->execute([
            ':lab_id' => $labId,
            ':user_id' => $ownerId,
            ':role' => 'editor',
            ':created_at' => time(),
        ]);
        $lock = $this->pdo->prepare(
            'INSERT INTO lab_locks (lab_id, user_id, created_at, expires_at) VALUES (:lab_id, :user_id, :created_at, :expires_at)'
        );
        $lock->execute([
            ':lab_id' => 'lock-' . $ownerId,
            ':user_id' => $ownerId,
            ':created_at' => time(),
            ':expires_at' => time() + 600,
        ]);
        foreach (['api_tokens', 'permissions', 'lab_locks', 'sessions'] as $table) {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = :user_id');
            $count->execute([':user_id' => $ownerId]);
            self::assertGreaterThan(0, (int) $count->fetchColumn(), 'expected seeded ' . $table . ' rows');
        }

        $other = $this->createUser([
            'username' => 'other-user',
            'role' => 'user',
            'password' => 'Teralab-Other-2026!',
        ]);
        self::assertSame('user', $other['user']['role']);
        [$otherCookie] = $this->login('other-user', 'Teralab-Other-2026!');
        self::assertSame(403, $this->request('GET', '/api/v2/labs/' . $labId, [], [], $otherCookie)->getStatusCode());
        $otherIndex = $this->json($this->request('GET', '/api/v2/labs', [], [], $otherCookie))['data'];
        self::assertNotContains($labId, array_column($otherIndex, 'id'));

        $deleted = $this->request('DELETE', '/api/v2/users/' . $ownerId);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertTrue($this->json($deleted)['data']['deleted']);
        self::assertSame(0, $this->json($deleted)['data']['labs_trashed']);

        $users = $this->json($this->request('GET', '/api/v2/users'))['data'];
        self::assertNotContains($ownerId, array_column($users, 'id'));
        foreach (['api_tokens', 'permissions', 'lab_locks', 'sessions'] as $table) {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = :user_id');
            $count->execute([':user_id' => $ownerId]);
            self::assertSame(0, (int) $count->fetchColumn(), $table . ' rows remain for deleted user');
        }

        // Labs survive owner removal and stay visible to admins only.
        $adminIndex = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        self::assertContains($labId, array_column($adminIndex, 'id'));
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labId)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/v2/labs/' . $labId, [], [], $otherCookie)->getStatusCode());

        $second = $this->createUser([
            'username' => 'trash-owner',
            'role' => 'editor',
            'password' => 'Teralab-Trash-2026!',
        ]);
        $secondId = (string) $second['user']['id'];
        [$secondCookie, $secondCsrf] = $this->login('trash-owner', 'Teralab-Trash-2026!');
        $trashedLab = $this->request('POST', '/api/v2/labs', ['name' => 'Trashed Lab'], [], $secondCookie, $secondCsrf);
        $trashedLabId = (string) $this->json($trashedLab)['data']['id'];

        $deletedWithLabs = $this->request('DELETE', '/api/v2/users/' . $secondId, ['delete_labs' => true]);
        self::assertSame(200, $deletedWithLabs->getStatusCode(), (string) $deletedWithLabs->getBody());
        self::assertSame(1, $this->json($deletedWithLabs)['data']['labs_trashed']);
        $adminIndex = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        self::assertNotContains($trashedLabId, array_column($adminIndex, 'id'));
        $trash = $this->json($this->request('GET', '/api/v2/labs/trash'))['data'];
        self::assertContains($trashedLabId, array_column($trash, 'id'));

        // The query-string form of the flag is accepted too.
        self::assertSame(404, $this->request('DELETE', '/api/v2/users/missing?delete_labs=true')->getStatusCode());

        $actions = $this->pdo->query('SELECT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('user.delete', $actions);
    }

    public function testDeleteWithRunningLabIsBlocked(): void
    {
        $owner = $this->createUser([
            'username' => 'running-owner',
            'role' => 'editor',
            'password' => 'Teralab-Running-2026!',
        ]);
        $ownerId = (string) $owner['user']['id'];
        [$cookie, $csrf] = $this->login('running-owner', 'Teralab-Running-2026!');
        $lab = $this->request('POST', '/api/v2/labs', [
            'name' => 'Running Lab',
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'interfaces' => [],
            ]],
        ], [], $cookie, $csrf);
        self::assertSame(201, $lab->getStatusCode(), (string) $lab->getBody());
        $labId = (string) $this->json($lab)['data']['id'];

        $start = $this->request('POST', '/api/v2/labs/' . $labId . '/start', [], [], $cookie, $csrf);
        self::assertSame(202, $start->getStatusCode(), (string) $start->getBody());

        $blocked = $this->request('DELETE', '/api/v2/users/' . $ownerId, ['delete_labs' => true]);
        self::assertSame(409, $blocked->getStatusCode(), (string) $blocked->getBody());
        $users = $this->json($this->request('GET', '/api/v2/users'))['data'];
        self::assertContains($ownerId, array_column($users, 'id'));
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labId)->getStatusCode());
    }

    public function testNonAdminIsForbiddenOnEveryRoute(): void
    {
        $this->createUser([
            'username' => 'plain-viewer',
            'role' => 'user',
            'password' => 'Teralab-Viewer-2026!',
        ]);
        [$cookie, $csrf] = $this->login('plain-viewer', 'Teralab-Viewer-2026!');

        self::assertSame(403, $this->request('GET', '/api/v2/users', [], [], $cookie)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v2/users', [
            'username' => 'sneaky',
            'role' => 'admin',
        ], [], $cookie, $csrf)->getStatusCode());
        self::assertSame(403, $this->request('PATCH', '/api/v2/users/' . $this->adminId, [
            'role' => 'user',
        ], [], $cookie, $csrf)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v2/users/' . $this->adminId . '/password', [
            'password' => 'Teralab-Hijack-2026!',
        ], [], $cookie, $csrf)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v2/users/' . $this->adminId . '/sessions/revoke', [], [], $cookie, $csrf)->getStatusCode());
        self::assertSame(403, $this->request('DELETE', '/api/v2/users/' . $this->adminId, [], [], $cookie, $csrf)->getStatusCode());

        // A read-only API token must be rejected by the admin role as well.
        $token = $this->request('POST', '/api/v2/system/tokens', ['name' => 'users-read', 'scopes' => ['read']]);
        self::assertSame(201, $token->getStatusCode(), (string) $token->getBody());
        $bearer = (string) $this->json($token)['data']['token'];
        // The token belongs to the admin, so the role passes; the read-only
        // scope must still block the mutating user routes.
        self::assertSame(403, $this->request('POST', '/api/v2/users', [
            'username' => 'token-user',
            'role' => 'user',
        ], ['Authorization' => 'Bearer ' . $bearer], '')->getStatusCode());
    }

    public function testAuditRowsCarryActorTargetAndNoPasswords(): void
    {
        $created = $this->createUser([
            'username' => 'audited',
            'role' => 'user',
            'password' => 'Teralab-Audit-Old-2026!',
        ]);
        $id = (string) $created['user']['id'];

        $this->request('PATCH', '/api/v2/users/' . $id, ['name' => 'Audited User']);
        $reset = $this->request('POST', '/api/v2/users/' . $id . '/password');
        $generated = (string) $this->json($reset)['data']['password'];
        $this->request('POST', '/api/v2/users/' . $id . '/sessions/revoke');
        $this->request('DELETE', '/api/v2/users/' . $id);

        $statement = $this->pdo->prepare('SELECT action, target, ip, user_id, result, details_json FROM audit_log WHERE action LIKE :prefix');
        $statement->execute([':prefix' => 'user.%']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $actions = array_column($rows, 'action');
        foreach (['user.create', 'user.update', 'user.password.reset', 'user.sessions.revoke', 'user.delete'] as $expected) {
            self::assertContains($expected, $actions);
        }
        foreach ($rows as $row) {
            self::assertSame($this->adminId, (string) $row['user_id']);
            self::assertSame($id, (string) $row['target']);
            self::assertSame('127.0.0.1', (string) $row['ip']);
            self::assertSame('success', (string) $row['result']);
            $details = (string) $row['details_json'];
            self::assertStringNotContainsString($generated, $details);
            self::assertStringNotContainsString('Teralab-Audit-Old-2026!', $details);
            self::assertStringNotContainsString('password_hash', $details);
            self::assertStringNotContainsString('argon2', strtolower($details));
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function createUser(array $body): array
    {
        $response = $this->request('POST', '/api/v2/users', $body);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function auditCount(string $action): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $statement->execute([':action' => $action]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertValidationError(int $status, array $body): void
    {
        $response = $this->request('POST', '/api/v2/users', $body);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param list<array<string, mixed>> $users
     * @return array<string, mixed>
     */
    private function userRow(array $users, string $id): array
    {
        foreach ($users as $user) {
            if ((string) $user['id'] === $id) {
                return $user;
            }
        }
        self::fail('user not found in listing: ' . $id);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function login(string $username, string $password): array
    {
        $response = $this->request('POST', '/api/v2/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return [$this->cookie($response), (string) $this->json($response)['csrf_token']];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $cookie = null,
        ?string $csrf = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->adminCookie;
        $effectiveCsrf = $csrf ?? $this->adminCsrf;
        if ($effectiveCookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $effectiveCookie]);
        }
        if ($effectiveCsrf !== '') {
            $request = $request->withHeader('X-CSRF-Token', $effectiveCsrf);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function cookie(ResponseInterface $response): string
    {
        if (preg_match('/' . preg_quote(Config::COOKIE_NAME, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches) !== 1) {
            return '';
        }

        return $matches[1];
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
