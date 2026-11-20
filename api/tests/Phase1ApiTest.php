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
use Slim\Psr7\UploadedFile;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;

final class Phase1ApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';
    private const EDITOR_PASSWORD = 'Teralab-Editor-2026!';
    private const VIEWER_PASSWORD = 'Teralab-Viewer-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private string $adminCookie = '';
    private string $adminCsrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-phase1-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        mkdir($this->root . '/html/templates', 0770, true);
        file_put_contents($this->root . '/html/templates/linux.php', "<?php\n");
        mkdir($this->root . '/api/templates/builtin', 0770, true);
        file_put_contents(
            $this->root . '/api/templates/builtin/linux.yaml',
            "schema: 1\nid: linux\nname: Linux\nkind: qemu\ndefaults:\n  cpu: 1\n  ram: 512\n  ethernet: 4\noptions:\n  qemu_arch: x86_64\n"
        );
        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, static function (string $method, string $path, array $payload): array {
            if ($path === '/v1/lab.ensure') {
                return [200, '{"ok":true,"result":{"bridges":{"0":"tl-test-bridge-0"}}}'];
            }
            if ($path === '/v1/node.start') {
                if (str_ends_with((string) ($payload['id'] ?? ''), '-boom')) {
                    return [400, '{"ok":false,"error":{"code":"start_failed","message":"node is already running"}}'];
                }

                return [200, json_encode([
                    'ok' => true,
                    'result' => ['id' => $payload['id'] ?? '', 'state' => 'running'],
                ], JSON_THROW_ON_ERROR)];
            }
            if (str_starts_with($path, '/v1/node.status')) {
                return [200, '{"ok":true,"result":{"id":"n","state":"running","memory_bytes":123,"cpu_usage_usec":456,"uptime_seconds":7}}'];
            }
            if ($path === '/v1/node.stop' || $path === '/v1/node.wipe') {
                return [200, '{"ok":true,"result":{"state":"ok"}}'];
            }
            if ($path === '/v1/system/status') {
                return [200, '{"ok":true,"result":{"nodes":[],"load1":0.5,"load5":0.4,"load15":0.3,"disk_free_bytes":1000,"disk_total_bytes":2000}}'];
            }
            if ($path === '/v1/image.inspect') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => ['format' => 'qcow2', 'virtual_size' => 8388608, 'backing_file' => '', 'sha256' => hash_file('sha256', (string) ($payload['path'] ?? ''))],
                ], JSON_THROW_ON_ERROR)];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->app = (new App($config, $agent))->create();
        $this->pdo = (new Database($config))->pdo();

        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        $this->adminCookie = $this->cookie($setup);
        $this->adminCsrf = (string) $this->json($setup)['csrf_token'];

        $this->createUser('editor', self::EDITOR_PASSWORD, 'editor');
        $this->createUser('viewer', self::VIEWER_PASSWORD, 'user');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testRoleAndPerLabPermissions(): void
    {
        $lab = $this->createLab('RBAC Lab');
        [$editorCookie, $editorCsrf] = $this->login('editor', self::EDITOR_PASSWORD);
        [$viewerCookie, $viewerCsrf] = $this->login('viewer', self::VIEWER_PASSWORD);

        // Viewer cannot create labs.
        $denied = $this->request('POST', '/api/v2/labs', ['name' => 'Nope'], [], $viewerCookie, $viewerCsrf);
        self::assertSame(403, $denied->getStatusCode());

        // Viewer cannot see the admin-owned lab.
        $hidden = $this->request('GET', '/api/v2/labs/' . $lab['id'], [], [], $viewerCookie);
        self::assertSame(403, $hidden->getStatusCode());

        // Grant viewer read access; editor gets edit access.
        $grantViewer = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/permissions', [
            'username' => 'viewer',
            'role' => 'user',
        ], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $grantViewer->getStatusCode(), (string) $grantViewer->getBody());
        $grantEditor = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/permissions', [
            'username' => 'editor',
            'role' => 'editor',
        ], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $grantEditor->getStatusCode());

        $visible = $this->request('GET', '/api/v2/labs/' . $lab['id'], [], [], $viewerCookie);
        self::assertSame(200, $visible->getStatusCode());
        $viewerSave = $this->request('PUT', '/api/v2/labs/' . $lab['id'], ['revision' => 1], [], $viewerCookie, $viewerCsrf);
        self::assertSame(403, $viewerSave->getStatusCode());

        $editorSave = $this->request('PUT', '/api/v2/labs/' . $lab['id'], ['revision' => 1], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $editorSave->getStatusCode());

        $duplicate = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/duplicate', [], [], $editorCookie, $editorCsrf);
        self::assertSame(201, $duplicate->getStatusCode(), (string) $duplicate->getBody());
        self::assertNotSame($lab['id'], $this->json($duplicate)['data']['id']);

        $host = $this->request('GET', '/api/v2/system/host', [], [], $this->adminCookie);
        self::assertSame(200, $host->getStatusCode());
        self::assertSame(0.5, $this->json($host)['data']['load1']);
        self::assertSame(1000, $this->json($host)['data']['disk_free_bytes']);

        // Editor may manage images; viewer may not.
        $content = "QFI\xfb" . str_repeat("\0", 64);
        $viewerUpload = $this->request('POST', '/api/v2/images', [], [], $viewerCookie, $viewerCsrf, $this->upload($content, 'base.qcow2'));
        self::assertSame(403, $viewerUpload->getStatusCode());
        $editorUpload = $this->request('POST', '/api/v2/images', [], [], $editorCookie, $editorCsrf, $this->upload($content, 'base.qcow2'));
        self::assertSame(201, $editorUpload->getStatusCode(), (string) $editorUpload->getBody());
        $image = $this->json($editorUpload)['data'];

        $rename = $this->request('PATCH', '/api/v2/images/' . $image['id'], ['name' => 'Renamed image'], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $rename->getStatusCode(), (string) $rename->getBody());
        self::assertSame('Renamed image', $this->json($rename)['data']['name']);

        $map = $this->request('PUT', '/api/v2/images/' . $image['id'] . '/template', ['template' => 'linux'], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $map->getStatusCode(), (string) $map->getBody());
        self::assertSame('linux', $this->json($map)['data']['template']);
        $missing = $this->request('PUT', '/api/v2/images/' . $image['id'] . '/template', ['template' => 'does-not-exist'], [], $editorCookie, $editorCsrf);
        self::assertSame(404, $missing->getStatusCode());

        $customYaml = "schema: 1\nid: custom1\nname: Custom\nkind: qemu\ndefaults:\n  cpu: 2\noptions:\n  qemu_arch: x86_64\n";
        $savedTemplate = $this->request('PUT', '/api/v2/templates/custom1', ['yaml' => $customYaml], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $savedTemplate->getStatusCode(), (string) $savedTemplate->getBody());
        self::assertSame('custom', $this->json($savedTemplate)['data']['source']);
        $list = $this->request('GET', '/api/v2/templates', [], [], $editorCookie);
        self::assertSame(200, $list->getStatusCode());
        $ids = array_column($this->json($list)['data'], 'id');
        self::assertContains('linux', $ids);
        self::assertContains('custom1', $ids);
        $removed = $this->request('DELETE', '/api/v2/templates/custom1', [], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $removed->getStatusCode());
    }

    public function testTemplateImportExportDuplicate(): void
    {
        [$editorCookie, $editorCsrf] = $this->login('editor', self::EDITOR_PASSWORD);
        $yaml = "schema: 1\nid: custom1\nname: Custom One\nkind: qemu\ndefaults:\n  cpu: 2\noptions: {}\n";
        self::assertSame(200, $this->request('PUT', '/api/v2/templates/custom1', ['yaml' => $yaml], [], $editorCookie, $editorCsrf)->getStatusCode());

        $export = $this->request('GET', '/api/v2/templates/custom1/export', [], [], $editorCookie);
        self::assertSame(200, $export->getStatusCode());
        self::assertStringContainsString('id: custom1', (string) $export->getBody());

        $duplicate = $this->request('POST', '/api/v2/templates/custom1/duplicate', [], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $duplicate->getStatusCode(), (string) $duplicate->getBody());
        self::assertSame('custom1-copy', $this->json($duplicate)['data']['id']);

        $builtinCopy = $this->request('POST', '/api/v2/templates/linux/duplicate', [], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $builtinCopy->getStatusCode(), (string) $builtinCopy->getBody());
        self::assertSame('linux-copy', $this->json($builtinCopy)['data']['id']);

        $importYaml = "schema: 1\nid: imported1\nname: Imported\nkind: vpcs\ndefaults:\n  ethernet: 1\noptions: {}\n";
        $import = $this->request('POST', '/api/v2/templates/import', [], [], $editorCookie, $editorCsrf, $this->upload($importYaml, 'imported1.yaml'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        self::assertSame('custom', $this->json($import)['data']['source']);
    }

    public function testVersionEndpointsReportProductVersion(): void
    {
        $version = $this->request('GET', '/api/v2/version');
        self::assertSame(200, $version->getStatusCode());
        self::assertSame('1.5.3', $this->json($version)['version']);
        self::assertSame('AGPL-3.0-or-later', $this->json($version)['license']);
        $health = $this->request('GET', '/api/v2/health');
        self::assertSame('1.5.3', $this->json($health)['version']);
    }

    public function testSnapshotsAndBulkNodeActions(): void
    {
        $lab = $this->createLab('Snapshot Lab', true);
        $snapshot = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/snapshots', ['name' => 'before'], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(201, $snapshot->getStatusCode(), (string) $snapshot->getBody());
        $snapshotId = (string) $this->json($snapshot)['data']['id'];
        $list = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/snapshots', [], [], $this->adminCookie);
        self::assertSame(200, $list->getStatusCode());
        self::assertCount(1, $this->json($list)['data']);

        $saved = $this->request('PUT', '/api/v2/labs/' . $lab['id'], ['name' => 'Changed', 'revision' => 1], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $saved->getStatusCode());
        $restored = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/snapshots/' . $snapshotId . '/restore', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $restored->getStatusCode(), (string) $restored->getBody());
        self::assertSame('Snapshot Lab', $this->json($restored)['data']['name']);
        self::assertSame(3, $this->json($restored)['data']['revision']);

        $start = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/nodes/1/start', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $stop = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/nodes/1/stop', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $stop->getStatusCode());
        $delete = $this->request('DELETE', '/api/v2/labs/' . $lab['id'] . '/snapshots/' . $snapshotId, [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $delete->getStatusCode());
    }

    public function testSettingsTokensLogsAndBackup(): void
    {
        $settings = $this->request('GET', '/api/v2/system/settings', [], [], $this->adminCookie);
        self::assertSame(200, $settings->getStatusCode());
        self::assertSame(3600, $this->json($settings)['data']['session_ttl']);
        self::assertSame(2147483648, $this->json($settings)['data']['max_upload_bytes']);
        $updated = $this->request('PUT', '/api/v2/system/settings', ['session_ttl' => 600, 'console_default' => 'vnc'], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame(600, $this->json($updated)['data']['session_ttl']);
        $invalid = $this->request('PUT', '/api/v2/system/settings', ['session_ttl' => 10], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(422, $invalid->getStatusCode());
        $login = $this->request('POST', '/api/v2/auth/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(600, $this->json($login)['expires_in']);

        if (!is_dir($this->root . '/data/logs')) {
            mkdir($this->root . '/data/logs', 0770, true);
        }
        file_put_contents($this->root . '/data/logs/api.log', "line-one\nline-two\n");
        $logs = $this->request('GET', '/api/v2/system/logs?source=api', [], [], $this->adminCookie);
        self::assertSame(200, $logs->getStatusCode());
        self::assertSame(['line-one', 'line-two'], $this->json($logs)['data']['lines']);
        self::assertSame(422, $this->request('GET', '/api/v2/system/logs?source=../etc/passwd', [], [], $this->adminCookie)->getStatusCode());

        $tokenResponse = $this->request('POST', '/api/v2/system/tokens', ['name' => 'read-only', 'scopes' => ['read']], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(201, $tokenResponse->getStatusCode(), (string) $tokenResponse->getBody());
        $token = $this->json($tokenResponse)['data'];
        $read = $this->request('GET', '/api/v2/labs', [], ['Authorization' => 'Bearer ' . $token['token']], '');
        self::assertSame(200, $read->getStatusCode());
        $write = $this->request('POST', '/api/v2/labs', ['name' => 'Token Lab'], [], '', '');
        // No bearer token at all must still fail.
        self::assertSame(401, $write->getStatusCode());
        $write = $this->request('POST', '/api/v2/labs', ['name' => 'Token Lab'], ['Authorization' => 'Bearer ' . $token['token']], '');
        self::assertSame(403, $write->getStatusCode());
        $revoke = $this->request('DELETE', '/api/v2/system/tokens/' . $token['id'], [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $revoke->getStatusCode());
        $after = $this->request('GET', '/api/v2/labs', [], ['Authorization' => 'Bearer ' . $token['token']], '');
        self::assertSame(401, $after->getStatusCode());

        $backup = $this->request('GET', '/api/v2/system/backup', [], [], $this->adminCookie);
        self::assertSame(200, $backup->getStatusCode());
        self::assertStringStartsWith('PK', (string) $backup->getBody());
    }

    public function testOversizedMultipartGetsActionableError(): void
    {
        $response = $this->request(
            'POST',
            '/api/v2/images',
            [],
            ['Content-Length' => '244265698'],
            $this->adminCookie,
            $this->adminCsrf,
        );
        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('upload_max_filesize', (string) $response->getBody());
    }

    public function testPasswordChangeRevokesOtherSessions(): void
    {
        [$firstCookie, $firstCsrf] = $this->login('editor', self::EDITOR_PASSWORD);
        [$secondCookie] = $this->login('editor', self::EDITOR_PASSWORD);

        $change = $this->request('POST', '/api/v2/auth/password', [
            'current_password' => self::EDITOR_PASSWORD,
            'new_password' => 'Teralab-Editor-New-2026!',
        ], [], $firstCookie, $firstCsrf);
        self::assertSame(200, $change->getStatusCode(), (string) $change->getBody());

        self::assertSame(401, $this->request('GET', '/api/v2/auth/me', [], [], $secondCookie)->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v2/auth/me', [], [], $firstCookie)->getStatusCode());
        self::assertSame(401, $this->request('POST', '/api/v2/auth/login', [
            'username' => 'editor',
            'password' => self::EDITOR_PASSWORD,
        ])->getStatusCode());
        $newLogin = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'editor',
            'password' => 'Teralab-Editor-New-2026!',
        ]);
        self::assertSame(200, $newLogin->getStatusCode());
    }

    public function testLabLockBlocksOtherEditorsUntilStop(): void
    {
        $lab = $this->createLab('Locked Lab', true);
        [$editorCookie, $editorCsrf] = $this->login('editor', self::EDITOR_PASSWORD);
        $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/permissions', [
            'username' => 'editor',
            'role' => 'editor',
        ], [], $this->adminCookie, $this->adminCsrf);

        $start = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/start', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(202, $start->getStatusCode(), (string) $start->getBody());

        $blocked = $this->request('PUT', '/api/v2/labs/' . $lab['id'], ['revision' => 1], [], $editorCookie, $editorCsrf);
        self::assertSame(409, $blocked->getStatusCode());
        $details = $this->json($blocked)['details'];
        self::assertSame('admin', $details['owner']);

        $stop = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/stop', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $stop->getStatusCode());
        $allowed = $this->request('PUT', '/api/v2/labs/' . $lab['id'], ['revision' => 1], [], $editorCookie, $editorCsrf);
        self::assertSame(200, $allowed->getStatusCode());
    }

    public function testAuditAndNodeUsage(): void
    {
        $lab = $this->createLab('Audit Lab', true);
        $start = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/start', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(202, $start->getStatusCode());
        $jobID = (string) $this->json($start)['job_id'];
        $job = $this->request('GET', '/api/v2/jobs/' . $jobID, [], [], $this->adminCookie);
        self::assertSame(200, $job->getStatusCode());
        self::assertSame('succeeded', $this->json($job)['data']['state']);
        self::assertSame(100, $this->json($job)['data']['progress']);
        self::assertNotEmpty($this->json($this->request('GET', '/api/v2/jobs', [], [], $this->adminCookie))['data']);
        $cancel = $this->request('POST', '/api/v2/jobs/' . $jobID . '/cancel', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(409, $cancel->getStatusCode());
        self::assertStringContainsString('cannot be aborted', (string) $cancel->getBody());
        $nodes = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/nodes', [], [], $this->adminCookie);
        self::assertSame(200, $nodes->getStatusCode());

        $status = $this->request('GET', '/api/v2/nodes/status?id=' . rawurlencode((string) $lab['id'] . '-1'), [], [], $this->adminCookie);
        self::assertSame(200, $status->getStatusCode());
        $usage = $this->json($status)['data'];
        self::assertSame(123, $usage['memory_bytes']);
        self::assertSame(456, $usage['cpu_usage_usec']);
        self::assertSame(7, $usage['uptime_seconds']);

        $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/stop', [], [], $this->adminCookie, $this->adminCsrf);
        $actions = $this->pdo->query('SELECT action FROM audit_log ORDER BY ts')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['lab.create', 'lab.start', 'lab.stop'] as $expected) {
            self::assertContains($expected, $actions);
        }
    }

    public function testFailedNodeActionIsAudited(): void
    {
        $create = $this->request('POST', '/api/v2/labs', [
            'name' => 'Node Audit Lab',
            'nodes' => [['id' => 'boom', 'name' => 'PC1', 'type' => 'vpcs', 'interfaces' => []]],
        ], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $lab = $this->json($create)['data'];

        $start = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/nodes/boom/start', [], [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(400, $start->getStatusCode(), (string) $start->getBody());

        $statement = $this->pdo->prepare('SELECT result, details_json FROM audit_log WHERE action = :action AND target = :target');
        $statement->execute([':action' => 'lab.node.start', ':target' => $lab['id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('error', $row['result']);
        $details = json_decode((string) $row['details_json'], true);
        self::assertIsArray($details);
        self::assertSame('boom', $details['node']);
        self::assertStringContainsString('already running', (string) $details['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createLab(string $name, bool $withNode = false): array
    {
        $body = ['name' => $name];
        if ($withNode) {
            $body['networks'] = [['id' => '0', 'name' => 'Net-1']];
            $body['nodes'] = [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'interfaces' => [['id' => 0, 'network_id' => '0']],
            ]];
        }
        $response = $this->request('POST', '/api/v2/labs', $body, [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function createUser(string $username, string $password, string $role): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, -1, :now, :now)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':username' => $username,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => $role,
            ':now' => time(),
        ]);
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
        ?UploadedFile $file = null,
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
        if ($file !== null) {
            $request = $request
                ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
                ->withUploadedFiles(['file' => $file]);
        }

        return $this->app->handle($request);
    }

    private function upload(string $content, string $name): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            'application/octet-stream',
            strlen($content),
            UPLOAD_ERR_OK,
        );
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
