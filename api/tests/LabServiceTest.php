<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Yaml\Yaml;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Labs\UnlWriter;

final class LabServiceTest extends TestCase
{
    private string $root;
    private Config $config;
    private UnlParser $parser;
    private UnlWriter $writer;
    private \PDO $pdo;

    /** @var list<array{method: string, path: string, payload: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, string> Agent node id => node name, for running nodes. */
    private array $running = [];

    /** @var list<string> Node names whose /v1/node.start must fail. */
    private array $failingStarts = [];

    /** @var list<string> Node names whose /v1/node.stop must fail. */
    private array $failingStops = [];

    /**
     * Agent node id suffixes (`<lab-id>-<node-id>`) whose /v1/node.status must
     * fail with HTTP 500, so the service reports an `unknown` node state.
     *
     * @var list<string>
     */
    private array $failingStatuses = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-labsvc-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        $this->parser = new UnlParser();
        $this->writer = new UnlWriter();
        $this->pdo = (new \TeraLab\Api\Database\Database($this->config))->pdo();
        (new \TeraLab\Api\Database\Migrator($this->pdo, $this->config->migrationsDir()))->migrate();
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testCreateSaveAndRevisionConflict(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Vertical Slice',
            'description' => 'desc',
            'networks' => [['id' => '0', 'name' => 'Net-1'], ['id' => '1', 'name' => 'Net-2']],
            'nodes' => [
                $this->node('1', 'QEMU1', 'qemu', 'alpine.qcow2', [0]),
                $this->node('2', 'PC1', 'vpcs', '', [1]),
                $this->node('3', 'IOL1', 'iol', 'iol.bin', [0]),
            ],
        ]);

        self::assertSame(1, $lab['revision']);
        self::assertSame(3, $lab['nodes']);
        self::assertFileExists($this->config->labsDir . '/' . $lab['path']);

        $saved = $service->save((string) $lab['id'], [
            'name' => 'Vertical Slice 2',
            'revision' => 1,
        ], 1);
        self::assertSame(2, $saved['revision']);
        self::assertSame('Vertical Slice 2', $saved['name']);

        try {
            $service->save((string) $lab['id'], ['revision' => 1], 1);
            self::fail('expected a revision conflict');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
        }
    }

    public function testExportImportRoundTripPreservesTopology(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Round Trip',
            'networks' => [['id' => '7', 'name' => 'Net-7']],
            'nodes' => [
                $this->node('1', 'R1', 'qemu', 'router.qcow2', [7]),
                $this->node('2', 'SW1', 'qemu', 'switch.qcow2', [7]),
            ],
        ]);

        $export = $service->export((string) $lab['id']);
        $parsed = $this->parser->parseXml($export['xml']);
        self::assertSame('Round Trip', $parsed['name']);
        self::assertCount(2, $parsed['node_list']);
        self::assertCount(1, $parsed['network_list']);
        self::assertSame('7', $parsed['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame('router.qcow2', $parsed['node_list'][0]['image']);

        $import = $service->import($this->upload($export['xml'], 'round-trip.unl'), 'admin');
        $imported = $this->parser->parse($this->config->labsDir . '/' . $import['path']);
        self::assertNotSame($lab['id'], $import['id']);
        self::assertCount(2, $imported['node_list']);
        self::assertSame('switch.qcow2', $imported['node_list'][1]['image']);
        self::assertSame('7', $imported['network_list'][0]['id']);
    }

    public function testStartMapsNetworksAndNodes(): void
    {
        $this->registerImage('base.qcow2');
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Start Lab',
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [
                $this->node('1', 'QEMU1', 'qemu', '/var/lib/teralab/images/qemu/base.qcow2', [0]),
                $this->node('2', 'PC1', 'vpcs', '', [0]),
            ],
        ]);

        $result = $service->start((string) $lab['id']);

        self::assertCount(2, $result['nodes']);
        self::assertSame('/v1/lab.ensure', $this->calls[0]['path']);
        $starts = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.start',
        ));
        self::assertCount(2, $starts);
        self::assertSame('qemu', $starts[0]['payload']['engine']);
        self::assertSame(['tl-test-bridge-0'], $starts[0]['payload']['networks']);
        self::assertSame('vpcs', $starts[1]['payload']['engine']);
        self::assertSame(['tl-test-bridge-0'], $starts[1]['payload']['networks']);
        self::assertStringStartsWith((string) $lab['id'] . '-', (string) $starts[0]['payload']['id']);
        foreach ($starts as $start) {
            self::assertArrayNotHasKey('docker', $start['payload'], 'non-docker specs carry no docker block');
            self::assertArrayNotHasKey('config', $start['payload'], 'non-docker nodes have no config here');
        }
    }

    public function testFailedNodeStartKeepsLabLinks(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Already Running Lab',
            'nodes' => [$this->node('1', 'PC1', 'vpcs', '', [])],
        ]);
        $labID = (string) $lab['id'];
        $service->start($labID);
        $this->calls = [];

        try {
            $service->startNode($labID, '1');
            self::fail('starting an already running node must fail');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }

        self::assertSame([], $this->cleanupCalls(), 'a rejected node start must not delete the lab links');
        self::assertNotSame([], $this->calls);
    }

    public function testFailedFullStartKeepsLinksWhileANodeStillRuns(): void
    {
        $this->failingStarts = ['PC2'];
        $this->failingStops = ['PC1'];
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Rollback Lab',
            'nodes' => [
                $this->node('1', 'PC1', 'vpcs', '', []),
                $this->node('2', 'PC2', 'vpcs', '', []),
            ],
        ]);

        try {
            $service->start((string) $lab['id']);
            self::fail('the start must fail when the second node cannot start');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }

        $stops = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.stop',
        ));
        self::assertCount(1, $stops, 'the rollback must still try to stop the started node');
        self::assertSame([], $this->cleanupCalls(), 'a surviving node must keep the lab links');
    }

    public function testFailedFullStartCleansUpAfterRollback(): void
    {
        $this->failingStarts = ['PC2'];
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Rollback Cleanup Lab',
            'nodes' => [
                $this->node('1', 'PC1', 'vpcs', '', []),
                $this->node('2', 'PC2', 'vpcs', '', []),
            ],
        ]);

        try {
            $service->start((string) $lab['id']);
            self::fail('the start must fail when the second node cannot start');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }

        self::assertCount(1, $this->cleanupCalls(), 'a fully rolled back start must release the lab links');
    }

    public function testFailedFullStartKeepsLinksWhenNodeStateIsUnknown(): void
    {
        $this->failingStarts = ['PC2'];
        $this->failingStatuses = ['1'];
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Rollback Unknown State Lab',
            'nodes' => [
                $this->node('1', 'PC1', 'vpcs', '', []),
                $this->node('2', 'PC2', 'vpcs', '', []),
            ],
        ]);

        try {
            $service->start((string) $lab['id']);
            self::fail('the start must fail when the second node cannot start');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }

        self::assertSame([], $this->cleanupCalls(), 'an unknown node state must keep the lab links');
    }

    public function testTextObjectsAreSavedButNotStarted(): void
    {
        $service = $this->service();
        $text = [
            'id' => '9',
            'name' => 'Notes',
            'type' => 'text',
            'image' => '',
            'console' => 'telnet',
            'cpu' => 1,
            'ram' => 64,
            'interfaces' => [],
        ];
        $engine = $this->node('1', 'PC1', 'vpcs', '', []);
        $engine['config'] = "hostname PC1\n";
        $lab = $service->create([
            'name' => 'Text Lab',
            'nodes' => [$text, $engine],
        ]);

        $result = $service->start((string) $lab['id']);
        self::assertCount(1, $result['nodes']);
        $starts = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.start',
        ));
        self::assertCount(1, $starts);

        $export = $service->export((string) $lab['id']);
        self::assertStringContainsString('type="text"', $export['xml']);
        self::assertStringContainsString('name="Notes"', $export['xml']);
        self::assertStringContainsString(base64_encode("hostname PC1\n"), $export['xml']);
        self::assertStringNotContainsString('<node id="2"', $export['xml']);
        $parsed = $this->parser->parseXml($export['xml']);
        $engine = null;
        foreach ($parsed['node_list'] as $parsedNode) {
            if (($parsedNode['type'] ?? '') !== 'text') {
                $engine = $parsedNode;
            }
        }
        self::assertIsArray($engine);
        self::assertSame("hostname PC1\n", $engine['config']);
    }

    public function testImportNormalisesLegacyPathsAndWritesPortableFormat(): void
    {
        $service = $this->service();
        $xml = (string) file_get_contents(__DIR__ . '/fixtures/legacy-lab.unl');
        $lab = $service->import($this->upload($xml, 'legacy.unl'), 'tester');

        self::assertSame('teralab-2', $lab['format']);
        $export = $service->export((string) $lab['id'])['xml'];
        self::assertStringNotContainsString('/var/lib/teralab', $export);
        self::assertStringContainsString('image="x86_64.bin"', $export);
        self::assertStringContainsString(base64_encode("hostname R1\n!"), $export);
        self::assertStringContainsString('<config id="1">', $export);
    }

    public function testImportEveNgReportsWarningsAndPreservesObjects(): void
    {
        $service = $this->service();
        $xml = (string) file_get_contents(__DIR__ . '/fixtures/eve-ng-lab.unl');
        $lab = $service->import($this->upload($xml, 'eve.unl'), 'tester');

        self::assertSame('teralab-2', $lab['format']);
        $types = array_column($lab['warnings'], 'type');
        self::assertContains('image_unmapped', $types);
        self::assertContains('interface_gap', $types);
        // The unused EVE-NG cloud has no lab endpoints, so link pruning drops it.
        self::assertNotContains('network_unsupported', $types);

        $export = $service->export((string) $lab['id'])['xml'];
        self::assertStringContainsString('<configsets>', $export);
        self::assertStringNotContainsString('type="pnet0"', $export);
        self::assertDoesNotMatchRegularExpression('/<node[^>]*type="text"/', $export);
    }

    public function testDeleteMovesToTrashAndRestore(): void
    {
        $service = $this->service();
        $lab = $service->create(['name' => 'Trash Me']);
        $service->delete((string) $lab['id'], 'user-1');

        self::assertFileDoesNotExist($this->config->labsDir . '/' . $lab['path']);
        $trash = $service->trash();
        self::assertCount(1, $trash);
        self::assertSame($lab['id'], $trash[0]['id']);
        self::assertSame('Trash Me', $trash[0]['name']);
        self::assertSame('user-1', $trash[0]['deleted_by']);

        $restored = $service->restore((string) $lab['id']);
        self::assertSame($lab['id'], $restored['id']);
        self::assertSame('Trash Me', $restored['name']);
        self::assertSame([], $service->trash());
        self::assertFileExists($this->config->labsDir . '/' . $restored['path']);
    }

    public function testNicSerialAndTemplateRoundTrip(): void
    {
        $node = $this->node('1', 'R1', 'qemu', 'router.qcow2', [0]);
        $node['nic'] = 'virtio-net-pci';
        $node['serial'] = 3;
        $node['template'] = 'linux';
        $this->registerImage('router.qcow2');
        $service = $this->service();
        $lab = $service->create(['name' => 'NIC Lab', 'networks' => [['id' => '0']], 'nodes' => [$node]]);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertSame('virtio-net-pci', $parsed['node_list'][0]['nic']);
        self::assertSame(3, $parsed['node_list'][0]['serial']);

        $service->start((string) $lab['id']);
        $starts = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.start',
        ));
        self::assertSame('virtio', $starts[0]['payload']['nic'], 'legacy NIC aliases are canonicalised');
        self::assertSame(3, $starts[0]['payload']['serial']);
    }

    public function testLegacyLabRoundTrip(): void
    {
        $nodes = '';
        $networks = '';
        for ($index = 1; $index <= 5; $index++) {
            $networks .= '<network id="' . $index . '" type="bridge" name="Net-' . $index . '"/>';
            $nodes .= '<node id="' . $index . '" name="R' . $index . '" type="iol" template="iol"'
                . ' image="/var/lib/teralab/images/iol/' . $index . '/l3.bin" ethernet="1">'
                . '<interface id="0" name="Ethernet0/0" type="ethernet" network_id="' . $index . '"/>'
                . '<config>hostname R' . $index . '</config>'
                . '</node>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><lab name="Legacy Round Trip" id="legacy-rt" version="1">'
            . '<topology><nodes>' . $nodes . '</nodes><networks>' . $networks . '</networks></topology></lab>';

        $lab = $this->parser->parseXml($xml);
        self::assertSame('legacy', $lab['format']);
        self::assertCount(5, $lab['node_list']);
        self::assertCount(5, $lab['network_list']);
        self::assertArrayHasKey('interfaces', $lab['node_list'][0]);
        self::assertSame('hostname R1', $lab['node_list'][0]['config']);

        $round = $this->parser->parseXml($this->writer->write($lab));
        self::assertCount(count($lab['node_list']), $round['node_list']);
        self::assertSame('R1', $round['node_list'][0]['name']);
        self::assertSame('1', $round['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame('hostname R1', $round['node_list'][0]['config']);
        self::assertStringNotContainsString('/var/lib/teralab', $this->writer->write($lab));
    }

    public function testFiftyNodeLabParsesWithinBudget(): void
    {
        $nodes = [];
        $networks = [];
        for ($index = 0; $index < 50; $index++) {
            $networks[] = ['id' => (string) $index, 'name' => 'Net-' . $index];
            $nodes[] = $this->node((string) ($index + 1), 'N' . $index, 'qemu', 'image.qcow2', [$index]);
        }
        $service = $this->service();
        $lab = $service->create(['name' => 'Perf 50', 'nodes' => $nodes, 'networks' => $networks]);

        $start = microtime(true);
        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        $elapsed = microtime(true) - $start;

        self::assertCount(50, $parsed['node_list']);
        self::assertLessThan(1.5, $elapsed, '50-node lab parse must stay under 1.5s');
    }

    public function testTemplateQemuOptionsReachTheEngineSpec(): void
    {
        $this->template('c8000v', (string) file_get_contents(dirname(__DIR__) . '/templates/builtin/c8000v.yaml'));
        $service = $this->service();
        $node = $this->node('1', 'C8K', 'qemu', '', []);
        $node['template'] = 'c8000v';
        $lab = $service->create(['name' => 'C8000V Lab', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);

        $payload = $this->startPayload();
        self::assertSame('vmxnet3', $payload['nic']);
        self::assertSame('pc', $payload['machine']);
        self::assertSame('x86_64', $payload['arch']);
        self::assertSame('host', $payload['cpu_model']);
        self::assertSame('kvm', $payload['accel']);
        self::assertArrayNotHasKey('extra_options', $payload);
    }

    public function testNodeNicOverridesTheTemplate(): void
    {
        $this->template('c8000v', (string) file_get_contents(dirname(__DIR__) . '/templates/builtin/c8000v.yaml'));
        $service = $this->service();
        $node = $this->node('1', 'C8K', 'qemu', '', []);
        $node['template'] = 'c8000v';
        $node['nic'] = 'e1000';
        $lab = $service->create(['name' => 'C8000V Override', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);

        $payload = $this->startPayload();
        self::assertSame('e1000', $payload['nic'], 'the node attribute wins over the template');
        self::assertSame('pc', $payload['machine'], 'other template values still apply');
    }

    public function testRawQemuOptionsBecomeExtraOptions(): void
    {
        $this->template(
            'legacy-qemu',
            "schema: 1\nid: legacy-qemu\nname: Legacy\nkind: qemu\noptions:\n  qemu_nic: virtio\n  qemu_options: '-no-acpi'\n",
        );
        $service = $this->service();
        $node = $this->node('1', 'LEG', 'qemu', '', []);
        $node['template'] = 'legacy-qemu';
        $lab = $service->create(['name' => 'Legacy QEMU', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);

        $payload = $this->startPayload();
        self::assertSame('virtio', $payload['nic']);
        self::assertSame('-no-acpi', $payload['extra_options']);
    }

    public function testNodeQemuOverridesArePersistedAndReachTheSpec(): void
    {
        $service = $this->service();
        $node = $this->node('1', 'R1', 'qemu', '', []);
        $node['arch'] = 'x86_64';
        $node['cpu_model'] = 'qemu64,+ssse3';
        $node['accel'] = 'tcg';
        $node['disk_interface'] = 'scsi';
        $lab = $service->create(['name' => 'Node QEMU Overrides', 'nodes' => [$node]]);

        // A save must keep the overrides through the existing attribute mechanism.
        $lab = $service->save((string) $lab['id'], ['nodes' => [$node]], null);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        $attributes = $parsed['node_list'][0]['attributes'];
        self::assertSame('x86_64', $attributes['qemu_arch']);
        self::assertSame('qemu64,+ssse3', $attributes['qemu_cpu']);
        self::assertSame('tcg', $attributes['qemu_accel']);
        self::assertSame('scsi', $attributes['qemu_disk_interface']);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();
        self::assertSame('x86_64', $payload['arch']);
        self::assertSame('qemu64,+ssse3', $payload['cpu_model']);
        self::assertSame('tcg', $payload['accel']);
        self::assertSame('scsi', $payload['disk_interface']);
    }

    public function testInvalidNodeQemuOverrideIsRejected(): void
    {
        $node = $this->node('1', 'R1', 'qemu', '', []);
        $node['accel'] = 'hax';

        try {
            $this->service()->create(['name' => 'Bad Override', 'nodes' => [$node]]);
            self::fail('an invalid node override must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('qemu_accel', $exception->getMessage());
        }
    }

    public function testNodeNicIsCanonicalisedForTheEngine(): void
    {
        $service = $this->service();
        $node = $this->node('1', 'R1', 'qemu', '', []);
        $node['nic'] = 'i82559er';
        $lab = $service->create(['name' => 'Legacy NIC', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);

        self::assertSame('e1000', $this->startPayload()['nic']);
    }

    public function testCpuModelFeatureListReachesTheEngineSpec(): void
    {
        $this->template(
            'features',
            "schema: 1\nid: features\nname: Features\nkind: qemu\noptions:\n  qemu_cpu: 'qemu64,+ssse3'\n",
        );
        $service = $this->service();
        $node = $this->node('1', 'FEAT', 'qemu', '', []);
        $node['template'] = 'features';
        $lab = $service->create(['name' => 'CPU Features', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);

        $payload = $this->startPayload();
        self::assertSame('qemu64,+ssse3', $payload['cpu_model']);
    }

    public function testTemplateQemuDisksReachTheEngineSpec(): void
    {
        $this->registerImage('catmanager-20.16.1-virtiob.qcow2');
        $this->registerImage('catmanager-20.16.1-virtiod.qcow2');
        $this->template('catmanager-disks', $this->qemuDisksTemplateYaml('catmanager-disks', [
            ['slot' => 'virtiob', 'image' => 'catmanager-20.16.1-virtiob.qcow2'],
            ['slot' => 'virtioc', 'size_gb' => 40],
            ['slot' => 'virtiod', 'image' => 'catmanager-20.16.1-virtiod.qcow2'],
        ]));
        $service = $this->service();
        $node = $this->node('1', 'C8K', 'qemu', '', []);
        $node['template'] = 'catmanager-disks';
        $lab = $service->create(['name' => 'Disks Lab', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();

        self::assertSame('qemu', $payload['engine']);
        self::assertSame([
            [
                'slot' => 'virtiob',
                'image' => $this->config->imagesDir . '/qemu/catmanager-20.16.1-virtiob.qcow2',
            ],
            ['slot' => 'virtioc', 'size_gb' => 40],
            [
                'slot' => 'virtiod',
                'image' => $this->config->imagesDir . '/qemu/catmanager-20.16.1-virtiod.qcow2',
            ],
        ], $payload['disks'], 'the template disk layout reaches the engine with resolved image paths');
    }

    public function testUnresolvedTemplateDiskImageFailsTheNodeStart(): void
    {
        $this->template('missing-disk', $this->qemuDisksTemplateYaml('missing-disk', [
            ['slot' => 'virtiob', 'image' => 'absent-virtiob.qcow2'],
        ]));
        $service = $this->service();
        $node = $this->node('1', 'R1', 'qemu', '', []);
        $node['template'] = 'missing-disk';
        $lab = $service->create(['name' => 'Missing Disk Image', 'nodes' => [$node]]);

        try {
            $service->start((string) $lab['id']);
            self::fail('an unresolved template disk image must fail the node start');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'Node R1: template "missing-disk" disk virtiob references image "absent-virtiob.qcow2",'
                    . ' which is not available in the image store.',
                $exception->getMessage(),
                'the disk error names the node once and the template, slot and reference',
            );
        }

        $starts = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.start',
        ));
        self::assertSame([], $starts, 'the node must not reach the agent without its disks');
    }

    public function testDeletedTemplateDiskImageFileFailsTheNodeStart(): void
    {
        $this->registerImage('pruned-virtiob.qcow2');
        $path = $this->config->imagesDir . '/qemu/pruned-virtiob.qcow2';
        self::assertFileExists($path);
        unlink($path);
        $this->template('pruned-disk', $this->qemuDisksTemplateYaml('pruned-disk', [
            ['slot' => 'virtioc', 'image' => 'pruned-virtiob.qcow2'],
        ]));
        $service = $this->service();
        $node = $this->node('1', 'R1', 'qemu', '', []);
        $node['template'] = 'pruned-disk';
        $lab = $service->create(['name' => 'Pruned Disk Image', 'nodes' => [$node]]);

        try {
            $service->start((string) $lab['id']);
            self::fail('a stored reference whose backing file is gone must fail the node start');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(
                'Node R1: template "pruned-disk" disk virtioc references image "pruned-virtiob.qcow2",'
                    . ' which is not available in the image store.',
                $exception->getMessage(),
                'a stale image-store row is rejected like an unknown reference',
            );
        }

        $starts = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.start',
        ));
        self::assertSame([], $starts, 'the node must not reach the agent without its disks');
    }

    public function testTemplateWithoutQemuDisksEmitsNoDisksKey(): void
    {
        $this->template(
            'plain-qemu',
            "schema: 1\nid: plain-qemu\nname: Plain\nkind: qemu\noptions:\n  qemu_nic: virtio\n",
        );
        $service = $this->service();
        $node = $this->node('1', 'PLAIN', 'qemu', '', []);
        $node['template'] = 'plain-qemu';
        $lab = $service->create(['name' => 'No Disks', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();

        self::assertSame('virtio', $payload['nic']);
        self::assertArrayNotHasKey('disks', $payload, 'a template without qemu_disks keeps the payload unchanged');
    }

    public function testNonQemuNodesIgnoreTemplateDisks(): void
    {
        $this->template('vpcs-disks', $this->qemuDisksTemplateYaml('vpcs-disks', [
            ['slot' => 'virtiob', 'size_gb' => 5],
        ], 'vpcs'));
        $service = $this->service();
        $node = $this->node('1', 'PC1', 'vpcs', '', []);
        $node['template'] = 'vpcs-disks';
        $lab = $service->create(['name' => 'VPCS Disks', 'nodes' => [$node]]);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();

        self::assertSame('vpcs', $payload['engine']);
        self::assertArrayNotHasKey('disks', $payload, 'only qemu specs carry the disk layout');
    }

    public function testDeletingANodePrunesTheLinkAndNetwork(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Prune Link',
            'networks' => [['id' => '1', 'name' => 'Net-1']],
            'nodes' => [
                $this->node('1', 'R1', 'qemu', '', [1]),
                $this->node('2', 'R2', 'qemu', '', [1]),
            ],
        ]);

        $service->save((string) $lab['id'], ['nodes' => [$this->node('1', 'R1', 'qemu', '', [1])]], null);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertCount(1, $parsed['node_list']);
        self::assertSame('', $parsed['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame([], $parsed['network_list']);
    }

    public function testSharedSegmentSurvivesANodeDeletion(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Shared Segment',
            'networks' => [['id' => '1', 'name' => 'Net-1']],
            'nodes' => [
                $this->node('1', 'R1', 'qemu', '', [1]),
                $this->node('2', 'R2', 'qemu', '', [1]),
                $this->node('3', 'R3', 'qemu', '', [1]),
            ],
        ]);

        $service->save((string) $lab['id'], [
            'nodes' => [
                $this->node('1', 'R1', 'qemu', '', [1]),
                $this->node('2', 'R2', 'qemu', '', [1]),
            ],
        ], null);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertCount(1, $parsed['network_list']);
        self::assertSame('1', $parsed['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame('1', $parsed['node_list'][1]['interfaces'][0]['network_id']);
    }

    public function testDanglingNetworkReferenceIsDetached(): void
    {
        $service = $this->service();
        $node = $this->node('1', 'R1', 'qemu', '', [1]);
        $node['interfaces'][] = ['id' => 1, 'name' => 'eth1', 'type' => 'ethernet', 'network_id' => '9'];
        $lab = $service->create([
            'name' => 'Dangling Link',
            'networks' => [['id' => '1', 'name' => 'Net-1']],
            'nodes' => [$node, $this->node('2', 'R2', 'qemu', '', [1])],
        ]);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertCount(1, $parsed['network_list']);
        self::assertSame('1', $parsed['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame('', $parsed['node_list'][0]['interfaces'][1]['network_id']);
    }

    public function testTwoInterfacesOnOneNetworkCountAsTwo(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Two Ports',
            'networks' => [['id' => '1', 'name' => 'Net-1']],
            'nodes' => [$this->node('1', 'R1', 'qemu', '', [1, 1])],
        ]);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertCount(1, $parsed['network_list']);
        self::assertSame('1', $parsed['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame('1', $parsed['node_list'][0]['interfaces'][1]['network_id']);
    }

    public function testPartialSavePrunesAgainstStoredNetworks(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Partial Save',
            'networks' => [['id' => '1', 'name' => 'Net-1'], ['id' => '2', 'name' => 'Net-2']],
            'nodes' => [
                $this->node('1', 'R1', 'qemu', '', [1]),
                $this->node('2', 'R2', 'qemu', '', [1]),
                $this->node('3', 'R3', 'qemu', '', [2]),
                $this->node('4', 'R4', 'qemu', '', [2]),
            ],
        ]);

        // Only "nodes" is sent: the stored network list must still be pruned.
        $service->save((string) $lab['id'], [
            'nodes' => [
                $this->node('1', 'R1', 'qemu', '', [1]),
                $this->node('3', 'R3', 'qemu', '', [2]),
                $this->node('4', 'R4', 'qemu', '', [2]),
            ],
        ], null);

        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertCount(1, $parsed['network_list']);
        self::assertSame('2', $parsed['network_list'][0]['id']);
        self::assertSame('', $parsed['node_list'][0]['interfaces'][0]['network_id']);
        self::assertSame('2', $parsed['node_list'][1]['interfaces'][0]['network_id']);
    }

    public function testImportPrunesSingleEndedNetworks(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><lab name="Single End" id="single-end" version="1">'
            . '<topology><nodes><node id="1" name="R1" type="iol" template="iol" image="l3.bin" ethernet="1">'
            . '<interface id="0" name="e0/0" type="ethernet" network_id="1"/>'
            . '</node></nodes><networks><network id="1" type="bridge" name="Net-1"/></networks></topology></lab>';

        $service = $this->service();
        $lab = $service->import($this->upload($xml, 'single.unl'), 'tester');

        self::assertSame(0, $lab['networks']);
        $parsed = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertSame([], $parsed['network_list']);
        self::assertSame('', $parsed['node_list'][0]['interfaces'][0]['network_id']);
    }

    public function testDockerNodeStartPayloadUsesTheRawContainerReference(): void
    {
        $this->template('frr', $this->dockerTemplateYaml('frr', 'frrouting/frr:10.0.1'));
        $service = $this->service();
        $node = $this->node('1', 'R1', 'docker', '', [0, 0, 0]);
        $node['template'] = 'frr';
        $node['ram'] = 256;
        $lab = $service->create([
            'name' => 'Docker Lab',
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [$node],
        ]);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();

        self::assertSame('docker', $payload['engine']);
        self::assertSame('frrouting/frr:10.0.1', $payload['image'], 'the container reference is never resolved through the image store');
        self::assertSame(1, $payload['cpu']);
        self::assertSame(256, $payload['ram']);
        self::assertSame(3, $payload['ethernet']);
        self::assertSame(['tl-test-bridge-0', 'tl-test-bridge-0', 'tl-test-bridge-0'], $payload['networks']);
        self::assertSame([
            'console_command' => '/usr/bin/vtysh',
            'cap_add' => ['NET_ADMIN'],
            'pull' => true,
        ], $payload['docker']);
        foreach (['iso', 'kernel', 'initrd', 'append', 'nvram', 'platform', 'serial'] as $key) {
            self::assertArrayNotHasKey($key, $payload, 'docker specs must not carry ' . $key);
        }
    }

    public function testDockerFrrNodeEmitsTheDaemonBlockAndTheNodeConfig(): void
    {
        $this->template('frr', (string) file_get_contents(dirname(__DIR__) . '/templates/builtin/frr.yaml'));
        $service = $this->service();
        $node = $this->node('1', 'R1', 'docker', '', [0, 0, 0]);
        $node['template'] = 'frr';
        $node['config'] = "frr version 10.7.1\nhostname R1\nrouter ospf\n network 10.0.0.0/8 area 0\n!\n";
        $lab = $service->create([
            'name' => 'FRR Lab',
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [$node],
        ]);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();

        self::assertSame('docker', $payload['engine']);
        self::assertSame('quay.io/frrouting/frr:10.7.1', $payload['image']);
        self::assertSame([
            'console_command' => '/usr/bin/vtysh',
            'cap_add' => ['NET_ADMIN', 'SYS_ADMIN'],
            'daemons' => ['zebra', 'ospfd', 'bgpd'],
            'config_path' => '/etc/frr/frr.conf',
            'pull' => true,
        ], $payload['docker']);
        self::assertSame($node['config'], $payload['config'], 'the node startup config reaches the engine verbatim');
        foreach (['iso', 'kernel', 'initrd', 'append', 'nvram', 'platform', 'serial'] as $key) {
            self::assertArrayNotHasKey($key, $payload, 'docker specs must not carry ' . $key);
        }
    }

    public function testDockerNodeWithoutAConfigOmitsTheConfigField(): void
    {
        $this->template('frr-ospf', (string) file_get_contents(dirname(__DIR__) . '/templates/builtin/frr-ospf.yaml'));
        $service = $this->service();
        $node = $this->node('1', 'R1', 'docker', '', [0]);
        $node['template'] = 'frr-ospf';
        $lab = $service->create([
            'name' => 'FRR OSPF Lab',
            'networks' => [['id' => '0', 'name' => 'Net-1']],
            'nodes' => [$node],
        ]);

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();

        self::assertSame(['zebra', 'ospfd'], $payload['docker']['daemons']);
        self::assertSame('/etc/frr/frr.conf', $payload['docker']['config_path']);
        self::assertArrayNotHasKey('config', $payload, 'an empty node config must not be emitted');
    }

    public function testDockerNodeStartsWithATelnetConsoleWhateverTheStoredMode(): void
    {
        $service = $this->service();
        $node = $this->node('1', 'R1', 'docker', 'frrouting/frr:10.0.1', []);
        $node['console'] = 'vnc';
        $lab = $service->create(['name' => 'Docker Console', 'nodes' => [$node]]);

        $stored = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertSame('vnc', $stored['node_list'][0]['console'], 'the lab file keeps the stored mode');

        $service->start((string) $lab['id']);

        self::assertSame('telnet', $this->startPayload()['console'], 'a docker console is always a telnet PTY');
    }

    public function testDockerNodeImageOverridesTheTemplateAndSurvivesSave(): void
    {
        $this->template('frr', $this->dockerTemplateYaml('frr', 'frrouting/frr:10.0.1'));
        $service = $this->service();
        $node = $this->node('1', 'R1', 'docker', 'frrouting/frr:9.1.0', []);
        $node['template'] = 'frr';
        $lab = $service->create(['name' => 'Docker Override', 'nodes' => [$node]]);

        $stored = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);
        self::assertSame('frrouting/frr:9.1.0', $stored['node_list'][0]['image'], 'the container reference is stored verbatim');

        $service->start((string) $lab['id']);
        $payload = $this->startPayload();
        self::assertSame('frrouting/frr:9.1.0', $payload['image'], 'the node image wins over the template default');
        self::assertSame([
            'console_command' => '/usr/bin/vtysh',
            'cap_add' => ['NET_ADMIN'],
            'pull' => true,
        ], $payload['docker'], 'the template still contributes the docker block');
    }

    public function testDockerNodeWithoutAReferenceIsRejectedAtStart(): void
    {
        $service = $this->service();
        $lab = $service->create([
            'name' => 'Docker Without Image',
            'nodes' => [$this->node('1', 'R1', 'docker', '', [])],
        ]);

        try {
            $service->start((string) $lab['id']);
            self::fail('a docker node without a container reference must not start');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('no container image reference', $exception->getMessage());
        }
        self::assertSame([], array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/node.start',
        )));
    }

    public function testImportedDockerNodeKeepsItsContainerReference(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><lab name="Docker Import" id="docker-import" version="1">'
            . '<topology><nodes><node id="1" name="R1" type="docker" template="docker"'
            . ' image="frrouting/frr:10.0.1" console="telnet" cpu="1" ram="256" ethernet="0"/>'
            . '</nodes><networks/></topology></lab>';

        $lab = $this->service()->import($this->upload($xml, 'docker.unl'), 'tester');
        $stored = $this->parser->parse($this->config->labsDir . '/' . $lab['path']);

        self::assertSame(
            'frrouting/frr:10.0.1',
            $stored['node_list'][0]['image'],
            'an EVE-NG docker reference must survive the portable-reference normalisation',
        );
        $types = array_column($lab['warnings'], 'type');
        self::assertNotContains('image_unmapped', $types);
        self::assertNotContains('node_unsupported', $types);
    }

    public function testDockerWarningsUseImageMissingAndNotTheImageStore(): void
    {
        $this->template('frr', $this->dockerTemplateYaml('frr', 'frrouting/frr:10.0.1'));
        $service = $this->service();

        $withTemplate = $this->node('1', 'R1', 'docker', '', []);
        $withTemplate['template'] = 'frr';
        $withImage = $this->node('2', 'R2', 'docker', 'frrouting/frr:10.0.1', []);
        $withoutImage = $this->node('3', 'R3', 'docker', '', []);
        $qemu = $this->node('4', 'Q1', 'qemu', 'missing.qcow2', []);
        $switch = $this->node('5', 'SW1', 'eth-switch', '', []);

        $lab = $service->create([
            'name' => 'Docker Warnings',
            'nodes' => [$withTemplate, $withImage, $withoutImage, $qemu, $switch],
        ]);

        $byNode = [];
        foreach ($lab['warnings'] as $warning) {
            if (is_array($warning) && isset($warning['node'])) {
                $byNode[(string) $warning['node']][] = (string) $warning['type'];
            }
        }

        self::assertArrayNotHasKey('R1', $byNode, 'the template default satisfies the docker reference');
        self::assertArrayNotHasKey('R2', $byNode, 'a container reference is not an image-store lookup');
        self::assertSame(['image_missing'], $byNode['R3'] ?? []);
        self::assertSame(['image_unmapped'], $byNode['Q1'] ?? []);
        self::assertSame(['node_unsupported'], $byNode['SW1'] ?? []);
    }

    private function template(string $id, string $yaml): void
    {
        $directory = $this->config->templatesDir;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            self::fail('cannot create the templates directory');
        }
        file_put_contents($directory . '/' . $id . '.yaml', $yaml);
    }

    /**
     * @param list<array<string, mixed>> $disks
     */
    private function qemuDisksTemplateYaml(string $id, array $disks, string $kind = 'qemu'): string
    {
        return Yaml::dump([
            'schema' => 1,
            'id' => $id,
            'name' => 'QEMU disks',
            'kind' => $kind,
            'options' => ['qemu_disks' => $disks],
        ], 8, 2);
    }

    private function dockerTemplateYaml(string $id, string $image): string
    {
        return "schema: 1\nid: " . $id . "\nname: Docker\nkind: docker\noptions:\n  docker:\n"
            . "    image: " . $image . "\n    console_command: /usr/bin/vtysh\n"
            . "    cap_add: [NET_ADMIN]\n    pull: true\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function startPayload(): array
    {
        foreach ($this->calls as $call) {
            if ($call['path'] === '/v1/node.start') {
                return $call['payload'];
            }
        }

        throw new \RuntimeException('the agent did not receive a node start');
    }

    private function registerImage(string $reference): void
    {
        $path = $this->config->imagesDir . '/qemu/' . $reference;
        $statement = $this->pdo->prepare(
            'INSERT INTO images (id, kind, name, reference, path, sha256, size, virtual_size, format, created_at) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, :created_at)'
        );
        $statement->execute([
            ':id' => 'img-' . substr(hash('sha256', $reference), 0, 16),
            ':kind' => 'qemu',
            ':name' => $reference,
            ':reference' => $reference,
            ':path' => $path,
            ':sha256' => hash('sha256', $reference),
            ':size' => 1,
            ':virtual_size' => 1,
            ':format' => 'qcow2',
            ':created_at' => time(),
        ]);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            self::fail('cannot create the image directory');
        }
        file_put_contents($path, "QFI\xfb" . str_repeat("\0", 32));
    }

    /**
     * @param list<int> $networks
     * @return array<string, mixed>
     */
    private function node(string $id, string $name, string $type, string $image, array $networks): array
    {
        $interfaces = [];
        foreach ($networks as $index => $network) {
            $interfaces[] = [
                'id' => $index,
                'name' => 'eth' . $index,
                'type' => 'ethernet',
                'network_id' => (string) $network,
            ];
        }

        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'image' => $image,
            'console' => 'telnet',
            'cpu' => 1,
            'ram' => 512,
            'interfaces' => $interfaces,
        ];
    }

    private function service(): LabService
    {
        $this->calls = [];
        $this->running = [];
        $agent = new AgentClient($this->config, function (string $method, string $path, array $payload): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
            if ($path === '/v1/lab.ensure') {
                return [200, '{"ok":true,"result":{"lab_id":"lab","bridges":{"0":"tl-test-bridge-0","1":"tl-test-bridge-1"}}}'];
            }
            if ($path === '/v1/node.start') {
                $id = (string) ($payload['id'] ?? '');
                $name = (string) ($payload['name'] ?? '');
                if (in_array($name, $this->failingStarts, true) || isset($this->running[$id])) {
                    return [400, '{"ok":false,"error":{"code":"start_failed","message":"node is already running"}}'];
                }
                $this->running[$id] = $name;

                return [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'id' => $id,
                        'name' => $name,
                        'engine' => $payload['engine'] ?? '',
                        'state' => 'running',
                    ],
                ], JSON_THROW_ON_ERROR)];
            }
            if ($path === '/v1/node.stop') {
                $id = (string) ($payload['id'] ?? '');
                if (in_array($this->running[$id] ?? '', $this->failingStops, true)) {
                    return [500, '{"ok":false,"error":{"code":"stop_failed","message":"the node cannot be stopped"}}'];
                }
                unset($this->running[$id]);

                return [200, '{"ok":true,"result":{"state":"ok"}}'];
            }
            if (str_starts_with($path, '/v1/node.status')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $id = (string) ($query['id'] ?? '');
                foreach ($this->failingStatuses as $suffix) {
                    if (str_ends_with($id, '-' . $suffix)) {
                        return [500, '{"ok":false,"error":{"code":"status_failed","message":"agent error"}}'];
                    }
                }
                if (isset($this->running[$id])) {
                    return [200, '{"ok":true,"result":{"state":"running"}}'];
                }

                return [404, '{"ok":false,"error":{"code":"not_found","message":"node not found"}}'];
            }
            if ($path === '/v1/node.wipe') {
                return [200, '{"ok":true,"result":{"state":"ok"}}'];
            }
            if ($path === '/v1/cleanup.stale') {
                return [200, '{"ok":true,"result":[]}'];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        return new LabService($this->config, $this->parser, $this->writer, $agent, new LabLocks($this->pdo), new ImageReferences($this->pdo, $this->config));
    }

    /**
     * @return list<array{method: string, path: string, payload: array<string, mixed>}>
     */
    private function cleanupCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['path'] === '/v1/lab.cleanup',
        ));
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
