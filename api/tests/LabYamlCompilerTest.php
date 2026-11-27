<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\Labs\LabYamlCompiler;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Labs\UnlWriter;
use TeraLab\Api\Templates\TemplateRepository;
use TeraLab\Api\Tests\Support\StaticAssets;

final class LabYamlCompilerTest extends TestCase
{
    use StaticAssets;

    private string $root;
    private Config $config;
    private LabYamlCompiler $compiler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-yaml-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        mkdir($this->root . '/data/templates', 0770, true);
        $this->installStaticAssets($this->root);
        $this->config = Config::forTests($this->root, $this->root . '/data');
        foreach (['iol', 'vpcs', 'docker', 'linux'] as $id) {
            $this->template($id, (string) file_get_contents(dirname(__DIR__) . '/templates/builtin/' . $id . '.yaml'));
        }
        $this->compiler = new LabYamlCompiler(new TemplateRepository($this->config));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testOspfTriangleFixtureCompilesDeterministically(): void
    {
        $yaml = $this->fixture('ospf-triangle.yaml');

        $first = $this->compiler->compile($yaml, 'ospf-triangle.yaml');
        $second = $this->compiler->compile($yaml, 'ospf-triangle.yaml');

        self::assertSame($first['payload'], $second['payload']);
        self::assertSame($first['source']['sha256'], hash('sha256', $yaml));
        self::assertSame('ospf-triangle.yaml', $first['source']['name']);
        self::assertSame(['nodes' => 3, 'networks' => 5, 'links' => 10], $first['counts']);

        $payload = $first['payload'];
        self::assertSame('OSPF triangle', $payload['name']);
        self::assertSame('TeraLab contributors', $payload['author']);
        self::assertSame(['ospf', 'ccna'], $payload['tags']);

        $nodes = $this->nodes($payload);
        self::assertSame(['1', '2', '3'], array_column($nodes, 'id'));
        self::assertSame(['R1', 'R2', 'R3'], array_column($nodes, 'name'));
        self::assertSame(['iol', 'iol', 'iol'], array_column($nodes, 'type'));
        self::assertSame(768, $nodes[0]['ram']);
        self::assertSame(512, $nodes[1]['ram']);
        self::assertStringContainsString('hostname R1', (string) $nodes[0]['config']);
        self::assertSame(4, count($this->interfaces($nodes[0])));

        $networks = $this->networks($payload);
        self::assertSame(['1', '2', '3', '4', '5'], array_column($networks, 'id'));
        self::assertSame(['lan-a', 'Branch LAN', 'mgmt', 'link-1', 'r2-r3'], array_column($networks, 'name'));
        self::assertSame('0', $networks[1]['attributes']['visibility']);

        // R1 keeps its authored position; the tree layout fills the rest.
        self::assertSame([480, 160], [$nodes[0]['left'], $nodes[0]['top']]);
        self::assertSame([320, 80], [$nodes[1]['left'], $nodes[1]['top']]);
        self::assertSame([320, 320], [$nodes[2]['left'], $nodes[2]['top']]);

        // The same payload writes byte-identical .unl output. The lab id and
        // timestamps are assigned by the service, so fix them here: topology,
        // configs, positions and extensions are what the compiler determines.
        $writer = new UnlWriter();
        $xml = $writer->write($this->labFor($first['payload']));
        self::assertSame($xml, $writer->write($this->labFor($second['payload'])));
        self::assertStringContainsString('<node id="1"', $xml);
        self::assertStringContainsString('name="R1"', $xml);
        self::assertStringContainsString('<config id="1">', $xml);
        self::assertStringContainsString('<network id="1"', $xml);
    }

    public function testMixedFixtureUsesTemplateDefaultsAndDeclaredNetworks(): void
    {
        $compiled = $this->compiler->compile($this->fixture('mixed-iol-vpcs.yaml'));
        $payload = $compiled['payload'];
        $nodes = $this->nodes($payload);
        $networks = $this->networks($payload);

        self::assertSame(['iol', 'iol', 'vpcs'], array_column($nodes, 'type'));
        // IOL template default ethernet is 2, but the YAML defaults say 4.
        self::assertSame(4, count($this->interfaces($nodes[0])));
        // VPCS keeps the template interface count.
        self::assertSame(1, count($this->interfaces($nodes[2])));
        self::assertSame(2, $nodes[0]['serial']);
        self::assertSame('telnet', $nodes[2]['console']);

        self::assertSame('Access LAN', $networks[0]['name']);
        self::assertSame('bridge', $networks[0]['type']);
        self::assertSame('wan', $networks[1]['name']);
        self::assertSame('link-1', $networks[2]['name']);
        self::assertSame(['nodes' => 3, 'networks' => 3, 'links' => 6], $compiled['counts']);
    }

    public function testDockerTemplateKindAndGenericImage(): void
    {
        $yaml = <<<'YAML'
        schema: 1
        name: Container lab
        defaults:
          template: docker
        nodes:
          C1:
            image: alpine:3.20
            config: |
              hostname c1
        YAML;
        $payload = $this->compiler->compile($yaml)['payload'];
        $node = $this->nodes($payload)[0];
        self::assertSame('docker', $node['type']);
        self::assertSame('alpine:3.20', $node['image']);
        self::assertSame(1, count($this->interfaces($node)));
    }

    public function testDefaultsMergeAndClamps(): void
    {
        $this->template('heavy', <<<'YAML'
        schema: 1
        id: heavy
        name: Heavy
        kind: qemu
        icon: Server.png
        defaults:
          cpu: 2
          ram: 2048
          ethernet: 3
          serial: 4
          console: telnet
        options: {}
        YAML);
        $yaml = <<<'YAML'
        schema: 1
        name: Merge
        defaults:
          template: heavy
          ram: 768
        nodes:
          A: {}
          B:
            cpu: 0
            ram: 1
            serial: 99
            nvram: 99999
            delay: -5
        YAML;
        $nodes = $this->nodes($this->compiler->compile($yaml)['payload']);

        // YAML defaults override the template; node values override both.
        self::assertSame(2, $nodes[0]['cpu']);
        self::assertSame(768, $nodes[0]['ram']);
        self::assertSame(3, count($this->interfaces($nodes[0])));
        self::assertSame('Server.png', $nodes[0]['icon']);

        self::assertSame(1, $nodes[1]['cpu']);
        self::assertSame(64, $nodes[1]['ram']);
        self::assertSame(8, $nodes[1]['serial']);
        self::assertSame(4096, $nodes[1]['nvram']);
        self::assertSame(0, $nodes[1]['delay']);
    }

    public function testIconsMustBeStaticBasenames(): void
    {
        foreach ([
            '../../about.php',
            '../../../api/v2/x',
            '//evil',
            'https://x/y',
            'Router.png/..',
            'missing.png',
        ] as $icon) {
            $yaml = "schema: 1\nname: Icon\nnodes:\n  A:\n    template: vpcs\n    icon: "
                . Yaml::dump($icon) . "\n";
            self::assertStringContainsString('icon', $this->compileError($yaml), $icon);
        }

        $yaml = "schema: 1\nname: Icon\nnodes:\n  A:\n    template: vpcs\n    icon: "
            . Yaml::dump('Cisco ACS.png') . "\n";
        $nodes = $this->nodes($this->compiler->compile($yaml)['payload']);
        self::assertSame('Cisco ACS.png', $nodes[0]['icon']);
    }

    public function testInterfaceGrammarExtendsTheInterfaceList(): void
    {
        $yaml = <<<'YAML'
        schema: 1
        name: Ports
        defaults:
          template: iol
          ports: 1
        networks:
          lan: {}
        nodes:
          R1:
            links:
              e0/0: lan
              "2": lan
              ethernet1: lan
          R2:
            template: vpcs
            links:
              eth0: lan
        YAML;
        $nodes = $this->nodes($this->compiler->compile($yaml)['payload']);

        $r1 = $this->interfaces($nodes[0]);
        self::assertCount(3, $r1);
        self::assertSame('e0/0', $r1[0]['name']);
        self::assertSame('1', $r1[0]['network_id']);
        self::assertSame('1', $r1[1]['network_id']);
        self::assertSame('1', $r1[2]['network_id']);

        // R2 asked for eth0 on a VPCS node: the canonical name is generated.
        $r2 = $this->interfaces($nodes[1]);
        self::assertCount(1, $r2);
        self::assertSame('eth0', $r2[0]['name']);
    }

    public function testDuplicatePortSpellingsAreRejected(): void
    {
        $error = $this->compileError(<<<'YAML'
        schema: 1
        name: Duplicate
        defaults: {template: vpcs}
        networks: {lan: {}}
        nodes:
          A:
            links:
              e0: lan
              eth0: lan
        YAML);
        self::assertStringContainsString('Node "A"', $error);
        self::assertStringContainsString('linked more than once', $error);
    }

    public function testUnknownAndUnparseablePortsAreRejected(): void
    {
        self::assertStringContainsString('cannot be parsed', $this->compileError(<<<'YAML'
        schema: 1
        name: Bad port
        defaults: {template: vpcs}
        networks: {lan: {}}
        nodes:
          A:
            links: {foo: lan}
        YAML));

        self::assertStringContainsString('16-port limit', $this->compileError(<<<'YAML'
        schema: 1
        name: Too high
        defaults: {template: iol}
        networks: {lan: {}}
        nodes:
          A:
            links: {e4/0: lan}
        YAML));
    }

    public function testUnknownNetworkAndNodeReferencesAreRejected(): void
    {
        self::assertStringContainsString('network "missing" is not declared', $this->compileError(<<<'YAML'
        schema: 1
        name: Unknown network
        defaults: {template: vpcs}
        nodes:
          A:
            links: {e0: missing}
        YAML));

        self::assertStringContainsString('node "Z" is not declared', $this->compileError(<<<'YAML'
        schema: 1
        name: Unknown node
        defaults: {template: vpcs}
        nodes:
          A: {}
        links:
          - [A.e0, Z.e0]
        YAML));
    }

    public function testSelfLinksAndDuplicatePointToPointPortsAreRejected(): void
    {
        self::assertStringContainsString('cannot link to itself', $this->compileError(<<<'YAML'
        schema: 1
        name: Self link
        defaults: {template: vpcs}
        nodes:
          A: {}
        links:
          - [A.e0, A.e1]
        YAML));

        self::assertStringContainsString('linked more than once', $this->compileError(<<<'YAML'
        schema: 1
        name: Duplicate link
        defaults: {template: vpcs}
        nodes:
          A: {}
          B: {}
        links:
          - [A.e0, B.e0]
          - [A.e0, B.e0]
        YAML));
    }

    public function testUnknownTemplateNamesTheNode(): void
    {
        $error = $this->compileError(<<<'YAML'
        schema: 1
        name: Missing template
        nodes:
          R1:
            template: does-not-exist
        YAML);
        self::assertStringContainsString('Node "R1"', $error);
        self::assertStringContainsString('does-not-exist', $error);
    }

    public function testTemplateIsRequired(): void
    {
        $error = $this->compileError(<<<'YAML'
        schema: 1
        name: No template
        nodes:
          R1: {}
        YAML);
        self::assertStringContainsString('template is required', $error);
    }

    public function testStructureErrors(): void
    {
        $cases = [
            "{\n" => 'Invalid YAML',
            "schema: 2\nname: X\nnodes: {A: {template: vpcs}}\n" => 'schema: 1',
            "schema: 1\nname: X\nmystery: 1\nnodes: {A: {template: vpcs}}\n" => 'Unknown top-level field "mystery"',
            "schema: 1\nname: X\nnodes: []\n" => 'must be a mapping',
            "schema: 1\nname: \nnodes: {A: {template: vpcs}}\n" => 'Lab name is required',
            "schema: 1\nname: X\nnodes: {A: {template: vpcs, bogus: 1}}\n" => 'unknown field "bogus"',
            "schema: 1\nname: X\nlayout: {mode: organic}\nnodes: {A: {template: vpcs}}\n" => 'layout.mode',
            "schema: 1\nname: X\nlayout: {mode: tree, root: Z}\nnodes: {A: {template: vpcs}}\n" => 'layout root "Z"',
            "schema: 1\nname: X\ndefaults: {template: vpcs, ram: nope}\nnodes: {A: {}}\n" => 'defaults.ram',
            "schema: 1\nname: X\nnetworks: [{lan: {}}]\nnodes: {A: {template: vpcs}}\n" => 'networks must be a mapping',
            "schema: 1\nname: X\nnetworks: {lan: {visibility: 9}}\nnodes: {A: {template: vpcs}}\n" => 'visibility',
            "schema: 1\nname: X\ntags: nope\nnodes: {A: {template: vpcs}}\n" => 'tags must be a list',
            "schema: 1\nname: X\nlinks: nope\nnodes: {A: {template: vpcs}}\n" => 'links must be a list',
            "schema: 1\nname: X\ndefaults: {template: vpcs}\nnodes: {A: {ports: 17}}\n" => '16 ports',
        ];
        foreach ($cases as $yaml => $needle) {
            self::assertStringContainsString($needle, $this->compileError($yaml), $yaml);
        }
    }

    public function testConfigAndInputCaps(): void
    {
        $large = str_repeat('x', 1048577);
        $yaml = "schema: 1\nname: Big config\ndefaults: {template: vpcs}\nnodes:\n  A:\n    config: \""
            . $large . "\"\n";
        self::assertStringContainsString('larger than 1 MB', $this->compileError($yaml));

        self::assertStringContainsString(
            'larger than 10 MB',
            $this->compileError(str_repeat('# padding', 1200000))
        );
    }

    public function testNonUtf8ConfigIsRejected(): void
    {
        $yaml = "schema: 1\nname: Binary\ndefaults: {template: vpcs}\nnodes:\n  A:\n    config: !!binary /w==\n";
        self::assertStringContainsString('UTF-8', $this->compileError($yaml));
    }

    public function testNetworkCapIsEnforced(): void
    {
        $yaml = "schema: 1\nname: Many networks\ndefaults: {template: vpcs}\nnetworks:\n";
        for ($index = 0; $index < 65; $index++) {
            $yaml .= '  n' . $index . ": {}\n";
        }
        $yaml .= "nodes: {A: {}}\n";
        self::assertStringContainsString('At most 64 declared networks', $this->compileError($yaml));
    }

    public function testXmlUnsafeTextIsRejected(): void
    {
        $nonCharacter = "\xEF\xBF\xBE"; // U+FFFE
        $cases = [
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes: {A: {}}\nauthor: \"\\x01\"\n" => 'author',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes: {A: {}}\ntags: [\"\\x01\"]\n" => 'tags',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnetworks:\n  lan:\n    name: \"\\x01\"\nnodes: {A: {}}\n" => 'Network "lan" name',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes:\n  A:\n    image: \"\\x01\"\n" => 'Node "A": image',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes:\n  A:\n    icon: \"\\x01\"\n" => 'Node "A": icon',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes:\n  A:\n    console: \"\\x01\"\n" => 'Node "A": console',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnetworks:\n  lan:\n    type: \"\\x01\"\nnodes: {A: {}}\n" => 'Network "lan" type',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnetworks:\n  lan:\n    icon: \"\\x01\"\nnodes: {A: {}}\n" => 'Network "lan" icon',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes: {A: {}}\nbody: \"\\x01\"\n" => 'body',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes: {A: {}}\nauthor: !!binary /w==\n" => 'author',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes: {A: {}}\ndescription: \"" . $nonCharacter . "\"\n" => 'description',
            "schema: 1\nname: Unsafe\ndefaults: {template: vpcs}\nnodes:\n  \"" . $nonCharacter . "\": {}\n" => 'Node name',
        ];
        foreach ($cases as $yaml => $needle) {
            self::assertStringContainsString($needle, $this->compileError($yaml), $yaml);
        }
    }

    public function testSpacingIsCapped(): void
    {
        $yaml = "schema: 1\nname: Spacing\nlayout: {mode: grid, spacing: 10001}\n"
            . "defaults: {template: vpcs}\nnodes: {A: {}}\n";
        self::assertStringContainsString('between 1 and 10000', $this->compileError($yaml));

        $accepted = $this->compiler->compile(
            "schema: 1\nname: Spacing\nlayout: {mode: grid, spacing: 10000}\ndefaults: {template: vpcs}\nnodes: {A: {}, B: {}}\n"
        );
        self::assertSame(10080, $this->nodes($accepted['payload'])[1]['left']);
    }

    public function testLargeAliasBombIsRejected(): void
    {
        $yaml = "schema: 1\nname: Big alias bomb\ndefaults:\n  template: vpcs\nnodes:\n  A: &node\n    template: vpcs\n";
        for ($index = 0; $index < 160; $index++) {
            $yaml .= '  N' . $index . ": *node\n";
        }
        self::assertStringContainsString('aliases', $this->compileError($yaml));
    }

    public function testAliasExpansionIsBoundedByTheNodeCap(): void
    {
        $yaml = "schema: 1\nname: Alias bomb\ndefaults:\n  template: vpcs\nnodes:\n  A: &node\n    template: vpcs\n";
        for ($index = 0; $index < 64; $index++) {
            $yaml .= '  N' . $index . ": *node\n";
        }
        self::assertStringContainsString('At most 64 nodes', $this->compileError($yaml));
    }

    public function testGridLayoutIsDeterministic(): void
    {
        $yaml = <<<'YAML'
        schema: 1
        name: Grid
        layout:
          mode: grid
          spacing: 200
        defaults: {template: vpcs}
        nodes:
          A: {}
          B: {}
          C: {}
          D: {}
          E:
            position: {left: 1000, top: 1000}
        YAML;
        $first = $this->nodes($this->compiler->compile($yaml)['payload']);
        $second = $this->nodes($this->compiler->compile($yaml)['payload']);
        self::assertSame($first, $second);
        self::assertSame([80, 80], [$first[0]['left'], $first[0]['top']]);
        self::assertSame([680, 80], [$first[3]['left'], $first[3]['top']]);
        self::assertSame([1000, 1000], [$first[4]['left'], $first[4]['top']]);
    }

    public function testTreeLayoutUsesBfsAndATailRowForUnconnectedNodes(): void
    {
        $yaml = <<<'YAML'
        schema: 1
        name: Tree
        layout:
          mode: tree
          root: A
        defaults: {template: vpcs}
        networks: {lan: {}}
        links:
          - [A.e0, B.e0]
          - [A.e1, C.e0]
        nodes:
          A: {}
          B: {}
          C: {}
          D: {}
        YAML;
        $nodes = $this->nodes($this->compiler->compile($yaml)['payload']);
        self::assertSame([80, 80], [$nodes[0]['left'], $nodes[0]['top']]);
        self::assertSame([320, 80], [$nodes[1]['left'], $nodes[1]['top']]);
        self::assertSame([320, 320], [$nodes[2]['left'], $nodes[2]['top']]);
        // Unconnected D lands in the tail row below the two levels.
        self::assertSame([80, 560], [$nodes[3]['left'], $nodes[3]['top']]);
    }

    public function testNetworksSitAtTheCentroidOfTheirMembers(): void
    {
        $yaml = <<<'YAML'
        schema: 1
        name: Centroid
        layout:
          mode: grid
          spacing: 200
        defaults: {template: vpcs}
        networks:
          lan: {}
          placed:
            position: {left: 10, top: 20}
        nodes:
          A:
            links: {e0: lan}
          B:
            links: {e0: lan}
          C:
            links: {e0: lan}
        YAML;
        $payload = $this->compiler->compile($yaml)['payload'];
        $networks = $this->networks($payload);
        // A=(80,80), B=(280,80), C=(480,80): the centroid is B's position.
        self::assertSame([280, 80], [$networks[0]['left'], $networks[0]['top']]);
        self::assertSame([10, 20], [$networks[1]['left'], $networks[1]['top']]);
    }

    public function testValidateYamlReusesImportWarnings(): void
    {
        $pdo = (new Database($this->config))->pdo();
        (new Migrator($pdo, $this->config->migrationsDir()))->migrate();
        $service = new LabService(
            $this->config,
            new UnlParser(),
            new UnlWriter(),
            new AgentClient($this->config, static fn (string $method, string $path, array $payload): array => [200, '{"ok":true,"result":{}}']),
            new LabLocks($pdo),
            new ImageReferences($pdo, $this->config),
            null,
            new TemplateRepository($this->config),
            $this->compiler,
        );

        $yaml = <<<'YAML'
        schema: 1
        name: Warnings
        defaults:
          template: vpcs
          image: missing-image.bin
        networks:
          cloud:
            type: cloud
        nodes:
          A:
            links: {e0: cloud}
          B:
            links: {e0: cloud}
        YAML;
        $result = $service->validateYaml($yaml, 'warnings.yaml');
        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
        self::assertSame(['nodes' => 2, 'networks' => 1, 'links' => 2], $result['counts']);
        $types = array_column($result['warnings'], 'type');
        self::assertContains('image_unmapped', $types);
        self::assertContains('network_unsupported', $types);

        $invalid = $service->validateYaml("schema: 1\nname: X\n", 'bad.yaml');
        self::assertFalse($invalid['valid']);
        self::assertSame([], $invalid['warnings']);
        self::assertNotEmpty($invalid['errors']);
        self::assertSame(['nodes' => 0, 'networks' => 0, 'links' => 0], $invalid['counts']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function labFor(array $payload): array
    {
        return [
            'id' => '00000000-0000-4000-8000-000000000000',
            'name' => (string) ($payload['name'] ?? ''),
            'author' => (string) ($payload['author'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'version' => '1',
            'revision' => 1,
            'node_list' => $payload['nodes'] ?? [],
            'network_list' => $payload['networks'] ?? [],
            'attributes' => ['tags' => implode(',', (array) ($payload['tags'] ?? []))],
            'extra_xml' => [],
        ];
    }

    private function compileError(string $yaml): string
    {
        try {
            $this->compiler->compile($yaml);
            self::fail('expected the compiler to reject the YAML');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);

            return $exception->getMessage();
        }
    }

    private function fixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/labs/' . $name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function template(string $id, string $yaml): void
    {
        $directory = $this->config->templatesDir;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        file_put_contents($directory . '/' . $id . '.yaml', $yaml);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function nodes(array $payload): array
    {
        $nodes = $payload['nodes'] ?? [];
        self::assertIsArray($nodes);

        /** @var list<array<string, mixed>> $nodes */
        return $nodes;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function networks(array $payload): array
    {
        $networks = $payload['networks'] ?? [];
        self::assertIsArray($networks);

        /** @var list<array<string, mixed>> $networks */
        return $networks;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private function interfaces(array $node): array
    {
        $interfaces = $node['interfaces'] ?? [];
        self::assertIsArray($interfaces);

        /** @var list<array<string, mixed>> $interfaces */
        return $interfaces;
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
