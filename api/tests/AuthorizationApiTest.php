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
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Http\V1Session;
use TeraLab\Api\Tests\Support\SetupToken;
use TeraLab\Api\Tests\Support\StaticAssets;

final class AuthorizationApiTest extends TestCase
{
    use SetupToken;
    use StaticAssets;

    private const PASSWORD = 'Teralab-Admin-2026!';

    private const USER_PASSWORD = 'Teralab-User-2026!';

    private string $root;
    private Config $config;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private AgentClient $agent;
    /** @var list<array{method: string, path: string, payload: array<string, mixed>}> */
    private array $calls = [];
    private string $adminCookie = '';
    private string $adminCsrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-authz-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        mkdir($this->root . '/data/templates', 0770, true);
        $this->installStaticAssets($this->root);
        copy(
            dirname(__DIR__) . '/templates/builtin/vpcs.yaml',
            $this->root . '/data/templates/vpcs.yaml',
        );
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->installSetupToken($this->config);
        $this->agent = new AgentClient($this->config, function (string $method, string $path, array $payload): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

            return match (true) {
                $path === '/v1/system/status' => [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'nodes' => [],
                        'load1' => 0.5,
                        'load5' => 0.4,
                        'load15' => 0.3,
                        'disk_free_bytes' => 1000,
                        'disk_total_bytes' => 2000,
                    ],
                ], JSON_THROW_ON_ERROR)],
                $path === '/v1/node.start' => [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'id' => (string) ($payload['id'] ?? ''),
                        'name' => (string) ($payload['name'] ?? ''),
                        'state' => 'running',
                    ],
                ], JSON_THROW_ON_ERROR)],
                str_starts_with($path, '/v1/node.status') => [200, json_encode([
                    'ok' => true,
                    'result' => ['id' => 'node', 'state' => 'stopped', 'ready' => false],
                ], JSON_THROW_ON_ERROR)],
                $path === '/v1/node.stop' => [200, '{"ok":true,"result":{"state":"stopped"}}'],
                $path === '/v1/node.wipe' => [200, '{"ok":true,"result":{"state":"wiped"}}'],
                $path === '/v1/console.open' => [200, '{"ok":true,"result":{"kind":"telnet","token":"token1","expires_in":300}}'],
                $path === '/v1/lab.ensure' => [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'bridges' => array_fill_keys(
                            array_map(
                                static fn (array $network): string => (string) ($network['id'] ?? ''),
                                is_array($payload['networks'] ?? null) ? $payload['networks'] : [],
                            ),
                            'tl-test-bridge',
                        ),
                    ],
                ], JSON_THROW_ON_ERROR)],
                $path === '/v1/lab.cleanup' => [200, '{"ok":true,"result":{"state":"ok"}}'],
                $path === '/v1/docker/images' => [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'images' => [[
                            'reference' => 'frrouting/frr:v8.4.1',
                            'id' => 'sha256:9f2d0c1a',
                            'size' => '158MB',
                            'created' => '2026-01-02T03:04:05Z',
                            'path' => '/var/lib/docker/overlay2/secret',
                        ]],
                    ],
                ], JSON_THROW_ON_ERROR)],
                default => [200, '{"ok":true,"result":{}}'],
            };
        });

        $this->app = (new App($this->config, $this->agent))->create();
        $this->pdo = (new Database($this->config))->pdo();
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
            'setup_token' => $this->setupToken(),
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $this->adminCookie = $this->cookie($setup);
        $this->adminCsrf = (string) $this->json($setup)['csrf_token'];

        foreach (['editor-a', 'editor-b', 'editor-x'] as $username) {
            $this->insertUser($username, self::USER_PASSWORD, 'editor');
        }
        $this->insertUser('user-y', self::USER_PASSWORD, 'user');
        $this->insertUser('viewer', self::USER_PASSWORD, 'user');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testUserACannotAccessUserBLabButTheOwnerCan(): void
    {
        [$aCookie, $aCsrf] = $this->login('editor-a', self::USER_PASSWORD);
        [$bCookie, $bCsrf] = $this->login('editor-b', self::USER_PASSWORD);
        $lab = $this->createLabAs($bCookie, $bCsrf, 'B Private Lab');
        $labID = (string) $lab['id'];

        $this->calls = [];
        $denied = [
            ['GET', '/api/v2/labs/' . $labID, []],
            ['PUT', '/api/v2/labs/' . $labID, ['revision' => 1]],
            ['GET', '/api/v2/labs/' . $labID . '/export', []],
            ['GET', '/api/v2/labs/' . $labID . '/nodes', []],
            ['GET', '/api/v2/labs/' . $labID . '/snapshots', []],
            ['POST', '/api/v2/labs/' . $labID . '/snapshots', ['name' => 'intruder']],
            ['POST', '/api/v2/labs/' . $labID . '/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/wipe', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/wipe', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/console', []],
            ['POST', '/api/v2/labs/' . $labID . '/duplicate', []],
            ['POST', '/api/v2/labs/' . $labID . '/move', ['folder' => 'elsewhere']],
            ['GET', '/api/v2/labs/' . $labID . '/permissions', []],
            ['POST', '/api/v2/labs/' . $labID . '/permissions', ['username' => 'viewer', 'role' => 'user']],
            ['DELETE', '/api/v2/labs/' . $labID . '/permissions/viewer', []],
            ['DELETE', '/api/v2/labs/' . $labID, []],
        ];
        foreach ($denied as [$method, $path, $body]) {
            $response = $this->request($method, $path, $body, [], $aCookie, $aCsrf);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
        self::assertSame([], $this->calls, 'a denied lab action must never reach the agent');

        // The owner still has full access through the canonical lab id.
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID, [], [], $bCookie)->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID . '/export', [], [], $bCookie)->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID . '/nodes', [], [], $bCookie)->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID . '/snapshots', [], [], $bCookie)->getStatusCode());

        self::assertSame(202, $this->request('POST', '/api/v2/labs/' . $labID . '/start', [], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/stop', [], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/wipe', [], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/nodes/1/start', [], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/nodes/1/stop', [], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/nodes/1/wipe', [], [], $bCookie, $bCsrf)->getStatusCode());

        $console = $this->request('POST', '/api/v2/labs/' . $labID . '/nodes/1/console', [], [], $bCookie, $bCsrf);
        self::assertSame(200, $console->getStatusCode(), (string) $console->getBody());
        self::assertSame('token1', $this->json($console)['data']['token']);

        $duplicate = $this->request('POST', '/api/v2/labs/' . $labID . '/duplicate', [], [], $bCookie, $bCsrf);
        self::assertSame(201, $duplicate->getStatusCode(), (string) $duplicate->getBody());
        self::assertNotSame($labID, $this->json($duplicate)['data']['id']);

        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/move', ['folder' => 'archive'], [], $bCookie, $bCsrf)->getStatusCode());
        $grant = $this->request('POST', '/api/v2/labs/' . $labID . '/permissions', [
            'username' => 'viewer',
            'role' => 'user',
        ], [], $bCookie, $bCsrf);
        self::assertSame(200, $grant->getStatusCode(), (string) $grant->getBody());
        self::assertSame(200, $this->request('DELETE', '/api/v2/labs/' . $labID . '/permissions/viewer', [], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(200, $this->request('DELETE', '/api/v2/labs/' . $labID, [], [], $bCookie, $bCsrf)->getStatusCode());
    }

    public function testGuestACannotReachGuestBNodesOrTheV1LabList(): void
    {
        $this->insertGuest('guest-a');
        $this->insertGuest('guest-b');
        [$aCookie, $aCsrf] = $this->login('guest-a', self::USER_PASSWORD);
        [$bCookie, $bCsrf] = $this->login('guest-b', self::USER_PASSWORD);
        $lab = $this->createLabAs($bCookie, $bCsrf, 'Guest B Lab');
        $labID = (string) $lab['id'];

        $this->calls = [];
        foreach ([
            ['GET', '/api/v2/labs/' . $labID, []],
            ['GET', '/api/v2/labs/' . $labID . '/nodes', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/console', []],
            ['POST', '/api/v2/labs/' . $labID . '/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/wipe', []],
        ] as [$method, $path, $body]) {
            $response = $this->request($method, $path, $body, [], $aCookie, $aCsrf);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
        self::assertSame([], $this->calls, 'guest A must not reach guest B\'s node');

        $this->enableLegacyV1();
        $list = $this->request('GET', '/api/labs', [], [], $aCookie);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        self::assertNotContains($labID, array_column($this->json($list)['data'], 'id'));
        self::assertStringNotContainsString($labID, (string) $list->getBody());

        $bList = $this->request('GET', '/api/labs', [], [], $bCookie);
        self::assertSame(200, $bList->getStatusCode());
        self::assertContains($labID, array_column($this->json($bList)['data'], 'id'));

        $path = (string) $lab['path'];
        self::assertSame(403, $this->request('GET', '/api/labs/' . $path, [], [], $aCookie)->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/labs/' . $path, [], [], $bCookie)->getStatusCode());
    }

    public function testPerLabRoleMatrix(): void
    {
        [$xCookie, $xCsrf] = $this->login('editor-x', self::USER_PASSWORD);
        [$yCookie, $yCsrf] = $this->login('user-y', self::USER_PASSWORD);
        $lab = $this->createLabAs($this->adminCookie, $this->adminCsrf, 'Role Matrix Lab');
        $labID = (string) $lab['id'];

        // A global editor with no permission row has no implicit access.
        self::assertSame(403, $this->request('GET', '/api/v2/labs/' . $labID, [], [], $xCookie)->getStatusCode());

        $this->grant($labID, 'editor-x', 'user');
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID, [], [], $xCookie)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v2/labs/' . $labID, ['revision' => 1], [], $xCookie, $xCsrf)->getStatusCode());

        $this->grant($labID, 'editor-x', 'editor');
        $revision = (int) $this->json($this->request('GET', '/api/v2/labs/' . $labID, [], [], $xCookie))['data']['revision'];
        $save = $this->request('PUT', '/api/v2/labs/' . $labID, ['name' => 'Edited by local editor', 'revision' => $revision], [], $xCookie, $xCsrf);
        self::assertSame(200, $save->getStatusCode(), (string) $save->getBody());

        $this->grant($labID, 'editor-x', 'admin');
        $grant = $this->request('POST', '/api/v2/labs/' . $labID . '/permissions', [
            'username' => 'user-y',
            'role' => 'user',
        ], [], $xCookie, $xCsrf);
        self::assertSame(200, $grant->getStatusCode(), (string) $grant->getBody());

        $this->grant($labID, 'user-y', 'editor');
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID, [], [], $yCookie)->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/api/v2/labs/' . $labID, ['revision' => 2], [], $yCookie, $yCsrf)->getStatusCode());

        // The owner remains full even without a permissions row.
        $owned = $this->createLabAs($xCookie, $xCsrf, 'Local Owner Lab');
        $ownedID = (string) $owned['id'];
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $ownedID, [], [], $xCookie)->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/api/v2/labs/' . $ownedID, ['name' => 'Owner Saved', 'revision' => 1], [], $xCookie, $xCsrf)->getStatusCode());
    }

    public function testBasenameCollisionCannotRedirectASave(): void
    {
        $first = $this->createLabAs($this->adminCookie, $this->adminCsrf, 'Core');
        $firstID = (string) $first['id'];
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $firstID . '/move', ['folder' => 'one'], [], $this->adminCookie, $this->adminCsrf)->getStatusCode());
        $second = $this->createLabAs($this->adminCookie, $this->adminCsrf, 'Core');
        $secondID = (string) $second['id'];
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $secondID . '/move', ['folder' => 'two'], [], $this->adminCookie, $this->adminCsrf)->getStatusCode());

        $firstShow = $this->json($this->request('GET', '/api/v2/labs/' . $firstID))['data'];
        $secondShow = $this->json($this->request('GET', '/api/v2/labs/' . $secondID))['data'];
        self::assertSame('one/Core.unl', $firstShow['path']);
        self::assertSame('two/Core.unl', $secondShow['path']);
        self::assertSame(basename((string) $firstShow['path']), basename((string) $secondShow['path']));

        $firstFile = $this->root . '/labs/' . $firstShow['path'];
        $secondFile = $this->root . '/labs/' . $secondShow['path'];
        $firstBefore = (string) file_get_contents($firstFile);
        $secondBefore = (string) file_get_contents($secondFile);

        // 'Core' is a basename, never a canonical lab id.
        $collision = $this->request('PUT', '/api/v2/labs/Core', ['name' => 'Collision Write', 'revision' => 1]);
        self::assertSame(404, $collision->getStatusCode(), (string) $collision->getBody());
        self::assertStringNotContainsString('Collision Write', (string) file_get_contents($firstFile));
        self::assertStringNotContainsString('Collision Write', (string) file_get_contents($secondFile));
        self::assertSame($firstBefore, (string) file_get_contents($firstFile));
        self::assertSame($secondBefore, (string) file_get_contents($secondFile));

        // A canonical save writes exactly one lab.
        $saved = $this->request('PUT', '/api/v2/labs/' . $firstID, ['name' => 'Core One Saved', 'revision' => 1]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('Core One Saved', $this->json($this->request('GET', '/api/v2/labs/' . $firstID))['data']['name']);
        self::assertSame('Core', $this->json($this->request('GET', '/api/v2/labs/' . $secondID))['data']['name']);
    }

    public function testV1DisabledIs404AndEnabledIsFilteredReadOnly(): void
    {
        [$aCookie, $aCsrf] = $this->login('editor-a', self::USER_PASSWORD);
        [$bCookie, $bCsrf] = $this->login('editor-b', self::USER_PASSWORD);
        $aLab = $this->createLabAs($aCookie, $aCsrf, 'A Hidden Lab');
        $bLab = $this->createLabAs($bCookie, $bCsrf, 'B Visible Lab');

        foreach ([
            ['GET', '/api/labs'],
            ['GET', '/api/auth'],
            ['GET', '/api/status'],
            ['GET', '/api/folders'],
            ['POST', '/api/auth/login'],
        ] as [$method, $path]) {
            self::assertSame(404, $this->request($method, $path)->getStatusCode(), $method . ' ' . $path . ' must be absent while v1 is disabled');
        }

        $this->enableLegacyV1();
        $v1Cookie = $this->v1Login('editor-b');
        $list = $this->request('GET', '/api/labs', [], [], $v1Cookie);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $ids = array_column($this->json($list)['data'], 'id');
        self::assertContains((string) $bLab['id'], $ids);
        self::assertNotContains((string) $aLab['id'], $ids);
        self::assertStringNotContainsString((string) $aLab['id'], (string) $list->getBody());

        self::assertSame(200, $this->request('GET', '/api/labs/' . $bLab['path'], [], [], $v1Cookie)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/labs/' . $aLab['path'], [], [], $v1Cookie)->getStatusCode());

        self::assertSame(405, $this->request('GET', '/api/labs/' . $bLab['path'] . '/nodes/1/start', [], [], $v1Cookie)->getStatusCode());
        self::assertSame(405, $this->request('POST', '/api/labs', [], [], $v1Cookie)->getStatusCode());
        self::assertSame(405, $this->request('PUT', '/api/labs/' . $bLab['path'], [], [], $v1Cookie)->getStatusCode());
        self::assertSame(405, $this->request('DELETE', '/api/labs/' . $bLab['path'], [], [], $v1Cookie)->getStatusCode());

        $folders = $this->request('GET', '/api/folders', [], [], $v1Cookie);
        self::assertSame(200, $folders->getStatusCode(), (string) $folders->getBody());
        $labFiles = array_column($this->json($folders)['data']['labs'], 'file');
        self::assertContains((string) $bLab['filename'], $labFiles);
        self::assertNotContains((string) $aLab['filename'], $labFiles);

        $adminList = $this->request('GET', '/api/labs', [], [], $this->v1Login('admin'));
        self::assertSame(200, $adminList->getStatusCode());
        $adminIds = array_column($this->json($adminList)['data'], 'id');
        self::assertContains((string) $aLab['id'], $adminIds);
        self::assertContains((string) $bLab['id'], $adminIds);
    }

    public function testNetworkOperationsRequireLabEditAndDeclaredNetworks(): void
    {
        [$aCookie, $aCsrf] = $this->login('editor-a', self::USER_PASSWORD);
        [$bCookie, $bCsrf] = $this->login('editor-b', self::USER_PASSWORD);
        $lab = $this->createNetworkLabAs($bCookie, $bCsrf, 'Network Lab');
        $labID = (string) $lab['id'];

        $before = count($this->calls);
        self::assertSame(403, $this->request('POST', '/api/v2/labs/' . $labID . '/networks', ['networks' => [['id' => '0']]], [], $aCookie, $aCsrf)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v2/labs/' . $labID . '/networks/cleanup', [], [], $aCookie, $aCsrf)->getStatusCode());
        self::assertCount($before, $this->calls);

        self::assertSame(422, $this->request('POST', '/api/v2/labs/' . $labID . '/networks', ['networks' => [['id' => '99']]], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertSame(422, $this->request('POST', '/api/v2/labs/' . $labID . '/networks', ['networks' => [['id' => '0', 'bridge' => 'docker0']]], [], $bCookie, $bCsrf)->getStatusCode());
        self::assertCount($before, $this->calls);

        $ensure = $this->request('POST', '/api/v2/labs/' . $labID . '/networks', [
            'networks' => [['id' => '0', 'name' => 'Net-1']],
        ], [], $bCookie, $bCsrf);
        self::assertSame(200, $ensure->getStatusCode(), (string) $ensure->getBody());
        $ensureCalls = array_slice($this->calls, $before);
        self::assertCount(1, $ensureCalls);
        self::assertSame('/v1/lab.ensure', $ensureCalls[0]['path']);
        self::assertSame($labID, $ensureCalls[0]['payload']['lab_id']);
        self::assertSame([['id' => '0', 'name' => 'Net-1']], $ensureCalls[0]['payload']['networks']);

        $beforeCleanup = count($this->calls);
        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/networks/cleanup', [], [], $bCookie, $bCsrf)->getStatusCode());
        $cleanupCalls = array_slice($this->calls, $beforeCleanup);
        self::assertCount(1, $cleanupCalls);
        self::assertSame('/v1/lab.cleanup', $cleanupCalls[0]['path']);
        self::assertSame($labID, $cleanupCalls[0]['payload']['lab_id']);
    }

    public function testJobsAreFilteredToTheCreatorAndAdminsSeeAll(): void
    {
        [$aCookie, $aCsrf] = $this->login('editor-a', self::USER_PASSWORD);
        [$bCookie, $bCsrf] = $this->login('editor-b', self::USER_PASSWORD);
        $aLab = $this->createLabAs($aCookie, $aCsrf, 'A Job Lab');
        $bLab = $this->createLabAs($bCookie, $bCsrf, 'B Job Lab');

        $aStart = $this->request('POST', '/api/v2/labs/' . $aLab['id'] . '/start', [], [], $aCookie, $aCsrf);
        self::assertSame(202, $aStart->getStatusCode(), (string) $aStart->getBody());
        $aJob = (string) $this->json($aStart)['job_id'];
        $bStart = $this->request('POST', '/api/v2/labs/' . $bLab['id'] . '/start', [], [], $bCookie, $bCsrf);
        self::assertSame(202, $bStart->getStatusCode(), (string) $bStart->getBody());
        $bJob = (string) $this->json($bStart)['job_id'];

        $aJobs = array_column($this->json($this->request('GET', '/api/v2/jobs', [], [], $aCookie))['data'], 'id');
        self::assertContains($aJob, $aJobs);
        self::assertNotContains($bJob, $aJobs);

        $bJobs = array_column($this->json($this->request('GET', '/api/v2/jobs', [], [], $bCookie))['data'], 'id');
        self::assertContains($bJob, $bJobs);
        self::assertNotContains($aJob, $bJobs);

        $adminJobs = array_column($this->json($this->request('GET', '/api/v2/jobs'))['data'], 'id');
        self::assertContains($aJob, $adminJobs);
        self::assertContains($bJob, $adminJobs);

        self::assertSame(403, $this->request('GET', '/api/v2/jobs/' . $bJob, [], [], $aCookie)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/api/v2/jobs/' . $bJob . '/cancel', [], [], $aCookie, $aCsrf)->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v2/jobs/' . $aJob)->getStatusCode());
        self::assertSame(409, $this->request('POST', '/api/v2/jobs/' . $aJob . '/cancel', [], [], $this->adminCookie, $this->adminCsrf)->getStatusCode());
    }

    public function testHostStatusAndDockerImagesAreAdminOnly(): void
    {
        [$aCookie] = $this->login('editor-a', self::USER_PASSWORD);

        self::assertSame(403, $this->request('GET', '/api/v2/system/host', [], [], $aCookie)->getStatusCode());

        $this->calls = [];
        self::assertSame(403, $this->request('GET', '/api/v2/docker/images', [], [], $aCookie)->getStatusCode());
        self::assertSame([], $this->calls, 'a non-admin must not reach the Docker image list');

        $host = $this->request('GET', '/api/v2/system/host');
        self::assertSame(200, $host->getStatusCode(), (string) $host->getBody());
        self::assertSame(0.5, $this->json($host)['data']['load1']);

        $images = $this->request('GET', '/api/v2/docker/images');
        self::assertSame(200, $images->getStatusCode(), (string) $images->getBody());
        self::assertSame([['reference' => 'frrouting/frr:v8.4.1']], $this->json($images)['data']);
        self::assertStringNotContainsString('/var/lib/docker', (string) $images->getBody());
    }

    public function testRawNodeRoutesAreAbsentAndDoNotReachTheAgent(): void
    {
        [$bCookie, $bCsrf] = $this->login('editor-b', self::USER_PASSWORD);
        $this->calls = [];

        foreach ([
            ['POST', '/api/v2/nodes/start', ['id' => 'node1', 'name' => 'R1']],
            ['POST', '/api/v2/nodes/stop', ['id' => 'node1']],
            ['POST', '/api/v2/nodes/wipe', ['id' => 'node1']],
            ['GET', '/api/v2/nodes/status?id=node1', []],
            ['POST', '/api/v2/nodes/console', ['node_id' => 'node1']],
        ] as [$method, $path, $body]) {
            self::assertSame(404, $this->request($method, $path, $body, [], $bCookie, $bCsrf)->getStatusCode(), $method . ' ' . $path);
        }
        self::assertSame([], $this->calls);
    }

    /**
     * @return array<string, mixed>
     */
    private function createLabAs(string $cookie, string $csrf, string $name): array
    {
        $response = $this->request('POST', '/api/v2/labs', [
            'name' => $name,
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'template' => 'vpcs',
                'ram' => 128,
                'interfaces' => [],
            ]],
        ], [], $cookie, $csrf);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function createNetworkLabAs(string $cookie, string $csrf, string $name): array
    {
        $response = $this->request('POST', '/api/v2/labs', [
            'name' => $name,
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [
                ['id' => '1', 'name' => 'PC1', 'type' => 'vpcs', 'template' => 'vpcs', 'interfaces' => [['id' => 0, 'network_id' => '0']]],
                ['id' => '2', 'name' => 'PC2', 'type' => 'vpcs', 'template' => 'vpcs', 'interfaces' => [['id' => 0, 'network_id' => '0']]],
            ],
        ], [], $cookie, $csrf);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $lab = $this->json($response)['data'];
        self::assertCount(1, $lab['network_list']);

        return $lab;
    }

    private function grant(string $labID, string $username, string $role): void
    {
        $response = $this->request('POST', '/api/v2/labs/' . $labID . '/permissions', [
            'username' => $username,
            'role' => $role,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function enableLegacyV1(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) '
            . 'ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :updated_at'
        );
        $statement->execute([
            ':key' => 'legacy_v1_enabled',
            ':value' => '1',
            ':updated_at' => time(),
        ]);
        $this->app = (new App($this->config, $this->agent))->create();
    }

    private function v1Login(string $username): string
    {
        $response = $this->request('POST', '/api/auth/login', [
            'username' => $username,
            'password' => $username === 'admin' ? self::PASSWORD : self::USER_PASSWORD,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->cookieNamed($response, V1Session::LEGACY_COOKIE);
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

    private function insertUser(string $username, string $password, string $role): string
    {
        $id = bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $username,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => $role,
            ':now' => time(),
        ]);

        return $id;
    }

    private function insertGuest(string $username): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :expiration, 1, :now, :now)'
        );
        $statement->execute([
            ':id' => $username,
            ':username' => $username,
            ':password_hash' => password_hash(self::USER_PASSWORD, PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':expiration' => time() + 3600,
            ':now' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param SlimApp<\DI\Container>|null $app
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $cookie = null,
        ?string $csrf = null,
        ?SlimApp $app = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->adminCookie;
        $effectiveCsrf = $csrf ?? $this->adminCsrf;
        if ($effectiveCookie !== '') {
            $request = $request->withCookieParams([
                Config::COOKIE_NAME => $effectiveCookie,
                V1Session::LEGACY_COOKIE => $effectiveCookie,
            ]);
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

        return ($app ?? $this->app)->handle($request);
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
        return $this->cookieNamed($response, Config::COOKIE_NAME);
    }

    private function cookieNamed(ResponseInterface $response, string $name): string
    {
        if (preg_match('/' . preg_quote($name, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches) !== 1) {
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
