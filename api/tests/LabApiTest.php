<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

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

final class LabApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private string $cookie = '';
    private string $csrf = '';
    private \PDO $pdo;

    /** @var list<array{path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $runningNodes = [];

    /** @var array<string, bool> Agent node id => running. */
    private array $running = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-labapi-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $config = Config::forTests($this->root, $this->root . '/data');
        $agent = new AgentClient($config, function (string $method, string $path, array $payload): array {
            $this->calls[] = ['path' => $path, 'payload' => $payload];
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
                return [200, json_encode([
                    'ok' => true,
                    'result' => ['nodes' => $this->runningNodes],
                ], JSON_THROW_ON_ERROR)];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        $this->pdo = (new Database($config))->pdo();
        $this->app = (new App($config, $agent))->create();
        $this->registerImage('base.qcow2');
        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        $this->cookie = $this->cookie($setup);
        $this->csrf = (string) $this->json($setup)['csrf_token'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testCreateStartExportImportAndV1NodeLifecycle(): void
    {
        $create = $this->request('POST', '/api/v2/labs', [
            'name' => 'API Lab',
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [[
                'id' => '1',
                'name' => 'R1',
                'type' => 'qemu',
                'image' => 'base.qcow2',
                'cpu' => 1,
                'ram' => 512,
                'interfaces' => [['id' => 0, 'network_id' => '0']],
            ]],
        ]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $lab = $this->json($create)['data'];
        self::assertSame(1, $lab['revision']);

        $start = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/start');
        self::assertSame(202, $start->getStatusCode(), (string) $start->getBody());
        self::assertCount(1, $this->json($start)['data']['nodes']);
        self::assertNotSame([], $this->calls);

        $nodes = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/nodes');
        self::assertSame(200, $nodes->getStatusCode());
        self::assertSame('running', $this->json($nodes)['data']['nodes'][0]['state']);
        self::assertTrue($this->json($nodes)['data']['nodes'][0]['ready']);

        $export = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/export');
        self::assertSame(200, $export->getStatusCode());
        self::assertStringContainsString('<node id="1"', (string) $export->getBody());
        self::assertStringContainsString('network_id="0"', (string) $export->getBody());

        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload((string) $export->getBody(), 'import.unl'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        self::assertNotSame($lab['id'], $this->json($import)['data']['id']);

        // Legacy v1 core routes: login, auth, labs, node start/stop/wipe.
        $login = $this->request('POST', '/api/auth/login', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $login->getStatusCode());
        $v1Cookie = $this->cookie($login);

        $auth = $this->request('GET', '/api/auth', [], [], $v1Cookie);
        self::assertSame(200, $auth->getStatusCode());
        self::assertSame('admin', $this->json($auth)['data']['username']);

        $labs = $this->request('GET', '/api/labs', [], [], $v1Cookie);
        self::assertSame(200, $labs->getStatusCode());
        self::assertNotEmpty($this->json($labs)['data']);

        $path = (string) $lab['path'];
        $v1Start = $this->request('GET', '/api/labs/' . $path . '/nodes/1/start', [], [], $v1Cookie);
        self::assertSame(200, $v1Start->getStatusCode(), (string) $v1Start->getBody());
        self::assertSame('success', $this->json($v1Start)['status']);

        $v1Stop = $this->request('GET', '/api/labs/' . $path . '/nodes/1/stop', [], [], $v1Cookie);
        self::assertSame(200, $v1Stop->getStatusCode());

        $v1Wipe = $this->request('GET', '/api/labs/' . $path . '/nodes/1/wipe', [], [], $v1Cookie);
        self::assertSame(200, $v1Wipe->getStatusCode());
    }

    public function testSaveIncrementsRevisionAndRejectsStaleWrites(): void
    {
        $create = $this->request('POST', '/api/v2/labs', ['name' => 'Revision Lab']);
        $lab = $this->json($create)['data'];

        $save = $this->request('PUT', '/api/v2/labs/' . $lab['id'], [
            'name' => 'Revision Lab 2',
            'revision' => 1,
        ]);
        self::assertSame(200, $save->getStatusCode());
        self::assertSame(2, $this->json($save)['data']['revision']);

        $stale = $this->request('PUT', '/api/v2/labs/' . $lab['id'], [
            'name' => 'Stale',
            'revision' => 1,
        ]);
        self::assertSame(409, $stale->getStatusCode());
    }

    public function testNodesResponseIncludesReadyFlag(): void
    {
        $create = $this->request('POST', '/api/v2/labs', [
            'name' => 'Ready Lab',
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'cpu' => 1,
                'ram' => 64,
                'interfaces' => [],
            ]],
        ]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $lab = $this->json($create)['data'];

        $nodes = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/nodes');
        self::assertSame(200, $nodes->getStatusCode());
        $node = $this->json($nodes)['data']['nodes'][0];
        self::assertArrayHasKey('ready', $node);
        self::assertFalse($node['ready']);
        self::assertSame('stopped', $node['state']);
    }

    public function testLabListReportsRunningState(): void
    {
        $create = $this->request('POST', '/api/v2/labs', ['name' => 'State Lab']);
        $lab = $this->json($create)['data'];

        $list = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        $row = $this->labRow($list, (string) $lab['id']);
        self::assertFalse($row['running']);
        self::assertSame(0, $row['running_nodes']);

        $this->runningNodes = [
            ['id' => 'a-1', 'lab_id' => $lab['id'], 'state' => 'running'],
            ['id' => 'a-2', 'lab_id' => $lab['id'], 'state' => 'stopped'],
            ['id' => 'b-1', 'lab_id' => 'another-lab', 'state' => 'running'],
        ];

        $list = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        $row = $this->labRow($list, (string) $lab['id']);
        self::assertTrue($row['running']);
        self::assertSame(1, $row['running_nodes']);
    }

    public function testCrossUserLabIsolation(): void
    {
        $lab = $this->createLab('Private Lab');
        $labID = (string) $lab['id'];
        $this->createUser('user-b', 'Teralab-User-B-2026!', 'editor');
        [$bCookie, $bCsrf] = $this->login('user-b', 'Teralab-User-B-2026!');

        // B is an editor: role gates pass, so every 403 below comes from the
        // per-lab ownership check rather than the editor middleware.
        $requests = [
            ['GET', '/api/v2/labs/' . $labID, []],
            ['PUT', '/api/v2/labs/' . $labID, ['revision' => 1]],
            ['GET', '/api/v2/labs/' . $labID . '/export', []],
            ['GET', '/api/v2/labs/' . $labID . '/nodes', []],
            ['POST', '/api/v2/labs/' . $labID . '/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/wipe', []],
            ['POST', '/api/v2/labs/' . $labID . '/move', ['folder' => 'elsewhere']],
            ['POST', '/api/v2/labs/' . $labID . '/duplicate', []],
            ['DELETE', '/api/v2/labs/' . $labID, []],
            ['GET', '/api/v2/labs/' . $labID . '/snapshots', []],
            ['POST', '/api/v2/labs/' . $labID . '/snapshots', ['name' => 'intruder']],
            ['POST', '/api/v2/labs/' . $labID . '/snapshots/whatever/restore', []],
            ['DELETE', '/api/v2/labs/' . $labID . '/snapshots/whatever', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/start', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/stop', []],
            ['POST', '/api/v2/labs/' . $labID . '/nodes/1/wipe', []],
        ];
        foreach ($requests as [$method, $path, $body]) {
            $response = $this->request($method, $path, $body, [], $bCookie, null, $bCsrf);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }

        $bIndex = $this->json($this->request('GET', '/api/v2/labs', [], [], $bCookie))['data'];
        self::assertNotContains($labID, array_column($bIndex, 'id'));

        $adminIndex = $this->json($this->request('GET', '/api/v2/labs'))['data'];
        self::assertContains($labID, array_column($adminIndex, 'id'));
        self::assertSame(200, $this->request('GET', '/api/v2/labs/' . $labID)->getStatusCode());
    }

    public function testYamlImportCreatesLabWithProvenanceAndWarnings(): void
    {
        $this->writeBuiltinTemplate('iol');
        $yaml = <<<'YAML'
        schema: 1
        name: YAML Import
        author: YAML Author
        description: Imported description
        tags: [yaml, import]
        defaults:
          template: iol
          image: missing-image.bin
          ports: 2
        links:
          - [R1.e0/0, R2.e0/0]
        nodes:
          R1:
            ram: 768
            config: |
              hostname R1
          R2: {}
        YAML;

        $response = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($yaml, 'yml-lab.yaml'));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $lab = $body['data'];
        self::assertSame('YAML Import', $lab['name']);
        self::assertSame('YAML Author', $lab['author']);
        self::assertSame(2, $lab['nodes']);
        self::assertSame(1, $lab['networks']);
        self::assertSame(['yaml', 'import'], $lab['tags']);
        self::assertSame(1, $lab['revision']);
        $types = array_column($body['meta']['warnings'], 'type');
        self::assertContains('image_unmapped', $types);

        $statement = $this->pdo->prepare('SELECT details_json FROM audit_log WHERE action = :action AND target = :target');
        $statement->execute([':action' => 'lab.import', ':target' => (string) $lab['id']]);
        $details = (string) $statement->fetchColumn();
        self::assertStringContainsString('"source":"yaml"', $details);

        $show = $this->json($this->request('GET', '/api/v2/labs/' . $lab['id']))['data'];
        $source = $show['extensions']['source'];
        self::assertSame('yaml', $source['kind']);
        self::assertSame('yml-lab.yaml', $source['name']);
        self::assertSame(1, $source['revision']);
        self::assertSame(hash('sha256', $yaml), $source['sha256']);
        self::assertSame($yaml, $source['text']);
        self::assertSame('hostname R1', trim((string) $show['node_list'][0]['config']));
    }

    public function testYamlImportDetectionUsesExtensionThenContent(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $yaml = "schema: 1\nname: Sniffed YAML\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n";

        $byContent = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($yaml, 'lab.dat'));
        self::assertSame(201, $byContent->getStatusCode(), (string) $byContent->getBody());
        self::assertSame('Sniffed YAML', $this->json($byContent)['data']['name']);

        $xml = (string) file_get_contents(__DIR__ . '/fixtures/sample.unl');
        $byXmlContent = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($xml, 'lab.dat'));
        self::assertSame(201, $byXmlContent->getStatusCode(), (string) $byXmlContent->getBody());
        self::assertNotSame('Sniffed YAML', $this->json($byXmlContent)['data']['name']);
    }

    public function testImportFallsBackToYamlAndReportsBothErrors(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $yaml = "schema: 1\nname: Wrong Extension\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n";

        $fallback = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($yaml, 'lab.unl'));
        self::assertSame(201, $fallback->getStatusCode(), (string) $fallback->getBody());
        self::assertSame('Wrong Extension', $this->json($fallback)['data']['name']);

        $broken = $this->request(
            'POST',
            '/api/v2/labs/import',
            [],
            [],
            null,
            $this->upload("schema: 1\nname: Broken\nnodes: [\n", 'broken.unl'),
        );
        self::assertSame(422, $broken->getStatusCode());
        $message = (string) $this->json($broken)['message'];
        self::assertStringContainsString('XML:', $message);
        self::assertStringContainsString('YAML:', $message);
    }

    public function testValidateEndpointCompilesWithoutWriting(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $before = count($this->json($this->request('GET', '/api/v2/labs'))['data']);

        $valid = $this->request('POST', '/api/v2/labs/validate', [
            'yaml' => "schema: 1\nname: Dry Run\ndefaults: {template: vpcs, image: absent.bin}\nnodes: {PC1: {}}\n",
            'filename' => 'dry-run.yaml',
        ]);
        self::assertSame(200, $valid->getStatusCode(), (string) $valid->getBody());
        $data = $this->json($valid)['data'];
        self::assertTrue($data['valid']);
        self::assertSame([], $data['errors']);
        self::assertSame(['nodes' => 1, 'networks' => 0, 'links' => 0], $data['counts']);
        self::assertContains('image_unmapped', array_column($data['warnings'], 'type'));

        $invalid = $this->request('POST', '/api/v2/labs/validate', [
            'yaml' => "schema: 1\nname: Bad\nnodes:\n  R1:\n    template: nope\n",
        ]);
        self::assertSame(200, $invalid->getStatusCode());
        $bad = $this->json($invalid)['data'];
        self::assertFalse($bad['valid']);
        self::assertNotEmpty($bad['errors']);
        self::assertStringContainsString('Node "R1"', (string) $bad['errors'][0]);
        self::assertSame([], $bad['warnings']);

        self::assertSame(422, $this->request('POST', '/api/v2/labs/validate', [])->getStatusCode());
        self::assertSame($before, count($this->json($this->request('GET', '/api/v2/labs'))['data']));
    }

    public function testApplyReplacesTopologyPreservesIdentityAndCleansOrphans(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $first = "schema: 1\nname: Applied Lab\ndescription: v1\ntags: [yaml]\ndefaults: {template: vpcs}\n"
            . "networks: {lan: {}}\nnodes:\n  R1:\n    links: {e0: lan}\n  R2:\n    links: {e0: lan}\n";
        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($first, 'first.yaml'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        $lab = $this->json($import)['data'];
        $labID = (string) $lab['id'];

        $moved = $this->request('POST', '/api/v2/labs/' . $labID . '/move', ['folder' => 'demo']);
        self::assertSame(200, $moved->getStatusCode(), (string) $moved->getBody());
        $movedLab = $this->json($moved)['data'];
        $createdAt = $movedLab['created_at'];
        $owner = $movedLab['owner'];
        $path = $movedLab['path'];
        self::assertStringStartsWith('demo/', $path);

        $second = "schema: 1\nname: Applied Lab v2\ndescription: v2\ntags: [yaml, v2]\n"
            . "defaults: {template: vpcs}\nnetworks: {lan: {}}\nnodes:\n  R1:\n    links: {e0: lan}\n";
        $apply = $this->request('POST', '/api/v2/labs/' . $labID . '/apply', [
            'yaml' => $second,
            'filename' => 'second.yaml',
            'revision' => 1,
        ]);
        self::assertSame(200, $apply->getStatusCode(), (string) $apply->getBody());
        self::assertNotContains(
            'run_state_reset',
            array_column($this->json($apply)['meta']['warnings'], 'type'),
        );
        $applied = $this->json($apply)['data'];
        self::assertSame($labID, $applied['id']);
        self::assertSame($path, $applied['path']);
        self::assertSame($owner, $applied['owner']);
        self::assertSame($createdAt, $applied['created_at']);
        self::assertSame(2, $applied['revision']);
        self::assertSame('Applied Lab v2', $applied['name']);
        self::assertSame(['yaml', 'v2'], $applied['tags']);

        $show = $this->json($this->request('GET', '/api/v2/labs/' . $labID))['data'];
        self::assertSame(['R1'], array_column($show['node_list'], 'name'));
        self::assertSame(['1'], array_column($show['node_list'], 'id'));
        self::assertSame('second.yaml', $show['extensions']['source']['name']);
        self::assertSame(2, $show['extensions']['source']['revision']);
        self::assertSame($second, $show['extensions']['source']['text']);

        $orphanID = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $labID), 0, 60) . '-2';
        $wiped = [];
        foreach ($this->calls as $call) {
            if ($call['path'] === '/v1/node.wipe' || $call['path'] === '/v1/node.stop') {
                $wiped[] = (string) ($call['payload']['id'] ?? '');
            }
        }
        self::assertContains($orphanID, $wiped);
        self::assertNotContains($labID . '-1', $wiped);
    }

    public function testApplyRejectsRunningLabAndStaleRevision(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $yaml = "schema: 1\nname: Guarded Lab\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n";
        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($yaml, 'guarded.yaml'));
        $lab = $this->json($import)['data'];
        $labID = (string) $lab['id'];

        self::assertSame(202, $this->request('POST', '/api/v2/labs/' . $labID . '/start')->getStatusCode());
        $running = $this->request('POST', '/api/v2/labs/' . $labID . '/apply', ['yaml' => $yaml]);
        self::assertSame(409, $running->getStatusCode());
        self::assertSame(
            'Stop and wipe the lab before applying YAML.',
            (string) $this->json($running)['message'],
        );

        self::assertSame(200, $this->request('POST', '/api/v2/labs/' . $labID . '/stop')->getStatusCode());
        $save = $this->request('PUT', '/api/v2/labs/' . $labID, ['name' => 'Guarded Lab 2', 'revision' => 1]);
        self::assertSame(200, $save->getStatusCode());
        self::assertSame(2, $this->json($save)['data']['revision']);

        $stale = $this->request('POST', '/api/v2/labs/' . $labID . '/apply', [
            'yaml' => $yaml,
            'revision' => 1,
        ]);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(2, $this->json($stale)['details']['revision'] ?? null);

        $fresh = $this->request('POST', '/api/v2/labs/' . $labID . '/apply', [
            'yaml' => $yaml,
            'revision' => 2,
        ]);
        self::assertSame(200, $fresh->getStatusCode(), (string) $fresh->getBody());
        self::assertSame(3, $this->json($fresh)['data']['revision']);
    }

    public function testUnlImportAndSaveRemainUnchanged(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/fixtures/sample.unl');
        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($xml, 'classic.unl'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        $lab = $this->json($import)['data'];
        self::assertArrayNotHasKey('extensions', $lab);
        self::assertSame(1, $lab['revision']);

        $save = $this->request('PUT', '/api/v2/labs/' . $lab['id'], ['revision' => 1, 'description' => 'saved']);
        self::assertSame(200, $save->getStatusCode(), (string) $save->getBody());
        $saved = $this->json($save)['data'];
        self::assertSame(2, $saved['revision']);
        self::assertSame('saved', $saved['description']);

        $export = $this->request('GET', '/api/v2/labs/' . $lab['id'] . '/export');
        self::assertSame(200, $export->getStatusCode());
        self::assertStringContainsString('<lab ', (string) $export->getBody());
    }

    public function testImportRejectsXmlUnsafeTextWithoutWriting(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $yaml = "schema: 1\nname: Unsafe import\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n"
            . "description: \"\\x01\"\n";

        $response = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($yaml, 'unsafe.yaml'));
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('description', (string) $this->json($response)['message']);

        $author = "schema: 1\nname: Unsafe author\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\nauthor: \"\\x01\"\n";
        $authorResponse = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($author, 'author.yaml'));
        self::assertSame(422, $authorResponse->getStatusCode());
        self::assertStringContainsString('author', (string) $this->json($authorResponse)['message']);

        $tag = "schema: 1\nname: Unsafe tag\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\ntags: [\"\\x01\"]\n";
        $tagResponse = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($tag, 'tag.yaml'));
        self::assertSame(422, $tagResponse->getStatusCode());
        self::assertStringContainsString('tags', (string) $this->json($tagResponse)['message']);

        $node = "schema: 1\nname: Unsafe node\ndefaults: {template: vpcs}\nnodes:\n  PC1:\n    image: \"\\x01\"\n";
        $nodeResponse = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($node, 'node.yaml'));
        self::assertSame(422, $nodeResponse->getStatusCode());
        self::assertStringContainsString('Node "PC1": image', (string) $this->json($nodeResponse)['message']);

        self::assertSame([], glob($this->root . '/labs/*.unl') ?: []);
        self::assertSame([], glob($this->root . '/labs/.lab-*') ?: []);
        self::assertSame([], $this->json($this->request('GET', '/api/v2/labs'))['data']);
    }

    public function testOversizedDescriptionAndConfigAreRejectedBeforeWritingOrApplying(): void
    {
        $this->writeBuiltinTemplate('vpcs');

        // Just under the YAML cap, but the serialized .unl crosses 10 MB.
        $description = str_repeat('x', 10485500);
        $oversized = "schema: 1\nname: Oversized\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n"
            . "description: |\n  " . $description . "\n";
        self::assertLessThanOrEqual(10485760, strlen($oversized));

        $response = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($oversized, 'oversized.yaml'));
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('10 MB .unl limit', (string) $this->json($response)['message']);
        self::assertSame([], glob($this->root . '/labs/*.unl') ?: []);
        self::assertSame([], glob($this->root . '/labs/.lab-*') ?: []);

        $bigConfig = str_repeat('a', 1048577);
        $configYaml = "schema: 1\nname: Big config\ndefaults: {template: vpcs}\nnodes:\n  PC1:\n"
            . "    config: \"" . $bigConfig . "\"\n";
        $configResponse = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($configYaml, 'config.yaml'));
        self::assertSame(422, $configResponse->getStatusCode(), (string) $configResponse->getBody());
        self::assertStringContainsString('larger than 1 MB', (string) $this->json($configResponse)['message']);
        self::assertSame([], glob($this->root . '/labs/*.unl') ?: []);

        $normal = "schema: 1\nname: Apply Guard\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n";
        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($normal, 'guard.yaml'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        $lab = $this->json($import)['data'];
        $path = (string) $lab['path'];

        $apply = $this->request('POST', '/api/v2/labs/' . $lab['id'] . '/apply', [
            'yaml' => $oversized,
            'filename' => 'oversized.yaml',
            'revision' => 1,
        ]);
        self::assertSame(422, $apply->getStatusCode(), (string) $apply->getBody());
        self::assertStringContainsString('10 MB .unl limit', (string) $this->json($apply)['message']);

        $show = $this->json($this->request('GET', '/api/v2/labs/' . $lab['id']))['data'];
        self::assertSame(1, $show['revision']);
        self::assertSame('Apply Guard', $show['name']);
        self::assertSame($path, $show['path']);
    }

    public function testApplyReorderedTopologyWipesAllRunState(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $first = "schema: 1\nname: Reordered\ndefaults: {template: vpcs}\nnetworks: {lan: {}}\nnodes:\n"
            . "  A:\n    links: {e0: lan}\n  B:\n    links: {e0: lan}\n  C:\n    links: {e0: lan}\n";
        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($first, 'ordered.yaml'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        $lab = $this->json($import)['data'];
        $labID = (string) $lab['id'];

        $second = "schema: 1\nname: Reordered\ndefaults: {template: vpcs}\nnetworks: {lan: {}}\nnodes:\n"
            . "  C:\n    links: {e0: lan}\n  A:\n    links: {e0: lan}\n  B:\n    links: {e0: lan}\n";
        $apply = $this->request('POST', '/api/v2/labs/' . $labID . '/apply', [
            'yaml' => $second,
            'filename' => 'reordered.yaml',
            'revision' => 1,
        ]);
        self::assertSame(200, $apply->getStatusCode(), (string) $apply->getBody());
        $body = $this->json($apply);
        self::assertSame(['C', 'A', 'B'], array_column($this->json($this->request('GET', '/api/v2/labs/' . $labID))['data']['node_list'], 'name'));
        self::assertContains('run_state_reset', array_column($body['meta']['warnings'], 'type'));

        $wiped = [];
        foreach ($this->calls as $call) {
            if ($call['path'] === '/v1/node.wipe') {
                $wiped[] = (string) ($call['payload']['id'] ?? '');
            }
        }
        foreach (['1', '2', '3'] as $nodeID) {
            self::assertContains($labID . '-' . $nodeID, $wiped);
        }

        // Renaming a node in place must also invalidate every old run dir: id 2
        // now means A2, so inheriting A's directory would be wrong.
        $renamed = "schema: 1\nname: Reordered\ndefaults: {template: vpcs}\nnetworks: {lan: {}}\nnodes:\n"
            . "  C:\n    links: {e0: lan}\n  A2:\n    links: {e0: lan}\n  B:\n    links: {e0: lan}\n";
        $renameApply = $this->request('POST', '/api/v2/labs/' . $labID . '/apply', [
            'yaml' => $renamed,
            'filename' => 'renamed.yaml',
            'revision' => 2,
        ]);
        self::assertSame(200, $renameApply->getStatusCode(), (string) $renameApply->getBody());
        self::assertContains('run_state_reset', array_column($this->json($renameApply)['meta']['warnings'], 'type'));
        self::assertSame(
            ['C', 'A2', 'B'],
            array_column($this->json($this->request('GET', '/api/v2/labs/' . $labID))['data']['node_list'], 'name'),
        );

        $wipedAfterRename = [];
        foreach ($this->calls as $call) {
            if ($call['path'] === '/v1/node.wipe') {
                $wipedAfterRename[] = (string) ($call['payload']['id'] ?? '');
            }
        }
        foreach (['1', '2', '3'] as $nodeID) {
            self::assertContains($labID . '-' . $nodeID, $wipedAfterRename);
        }
    }

    public function testTagTruncationKeepsUtf8Intact(): void
    {
        $this->writeBuiltinTemplate('vpcs');
        $tag = str_repeat("\xC3\xA9", 15) . 'a' . "\xC3\xA9"; // 33 bytes; byte 32 splits the last é
        self::assertSame(33, strlen($tag));
        $yaml = "schema: 1\nname: Multibyte tags\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n"
            . "tags: [\"" . $tag . "\"]\n";

        $import = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($yaml, 'tags.yaml'));
        self::assertSame(201, $import->getStatusCode(), (string) $import->getBody());
        $stored = (string) $this->json($import)['data']['tags'][0];
        self::assertSame(1, preg_match('//u', $stored));
        self::assertSame(str_repeat("\xC3\xA9", 15) . 'a', $stored);
        self::assertLessThanOrEqual(32, strlen($stored));
    }

    /**
     * @param list<array<string, mixed>> $labs
     * @return array<string, mixed>
     */
    private function labRow(array $labs, string $id): array
    {
        foreach ($labs as $lab) {
            if ((string) $lab['id'] === $id) {
                return $lab;
            }
        }
        self::fail('lab not found in listing: ' . $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function createLab(string $name): array
    {
        $response = $this->request('POST', '/api/v2/labs', [
            'name' => $name,
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [[
                'id' => '1',
                'name' => 'PC1',
                'type' => 'vpcs',
                'interfaces' => [['id' => 0, 'network_id' => '0']],
            ]],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function createUser(string $username, string $password, string $role): string
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

    private function writeBuiltinTemplate(string $id): void
    {
        $directory = $this->root . '/data/templates';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $path = dirname(__DIR__) . '/templates/builtin/' . $id . '.yaml';
        self::assertFileExists($path);
        file_put_contents($directory . '/' . $id . '.yaml', (string) file_get_contents($path));
    }

    private function registerImage(string $reference): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO images (id, kind, name, reference, path, sha256, size, virtual_size, format, created_at) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, :created_at)'
        );
        $statement->execute([
            ':id' => 'img-' . substr(hash('sha256', $reference), 0, 16),
            ':kind' => 'qemu',
            ':name' => $reference,
            ':reference' => $reference,
            ':path' => $this->root . '/data/images/' . $reference,
            ':sha256' => hash('sha256', $reference),
            ':size' => 1,
            ':virtual_size' => 1,
            ':format' => 'qcow2',
            ':created_at' => time(),
        ]);
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
        ?UploadedFile $file = null,
        ?string $csrf = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->cookie;
        $effectiveCsrf = $csrf ?? $this->csrf;
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
            'application/xml',
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
