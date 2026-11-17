<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\Labs\UnlParser;
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
    }

    public function testLegacySampleLabParses(): void
    {
        $fixture = dirname(__DIR__, 2) . '/labs/Featured/Cisco/Basic/STP.unl';
        if (!is_file($fixture)) {
            self::markTestSkipped('legacy lab fixture is not present');
        }

        $lab = $this->parser->parse($fixture);
        self::assertSame('STP', $lab['name']);
        self::assertGreaterThanOrEqual(5, count($lab['node_list']));
        self::assertArrayHasKey('interfaces', $lab['node_list'][0]);
        self::assertNotSame([], $lab['network_list']);

        $xml = $this->writer->write($lab);
        $round = $this->parser->parseXml($xml);
        self::assertSame(count($lab['node_list']), count($round['node_list']));
        self::assertSame($lab['node_list'][0]['name'], $round['node_list'][0]['name']);
        self::assertSame($lab['node_list'][0]['interfaces'][0]['network_id'], $round['node_list'][0]['interfaces'][0]['network_id']);
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
        $agent = new AgentClient($this->config, function (string $method, string $path, array $payload): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
            if ($path === '/v1/lab.ensure') {
                return [200, '{"ok":true,"result":{"lab_id":"lab","bridges":{"0":"tl-test-bridge-0","1":"tl-test-bridge-1"}}}'];
            }
            if ($path === '/v1/node.start') {
                return [200, json_encode([
                    'ok' => true,
                    'result' => [
                        'id' => $payload['id'] ?? '',
                        'name' => $payload['name'] ?? '',
                        'engine' => $payload['engine'] ?? '',
                        'state' => 'running',
                    ],
                ], JSON_THROW_ON_ERROR)];
            }
            if ($path === '/v1/node.stop' || $path === '/v1/node.wipe') {
                return [200, '{"ok":true,"result":{"state":"ok"}}'];
            }
            if ($path === '/v1/cleanup.stale') {
                return [200, '{"ok":true,"result":[]}'];
            }

            return [200, '{"ok":true,"result":{}}'];
        });

        return new LabService($this->config, $this->parser, $this->writer, $agent, new LabLocks($this->pdo));
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
