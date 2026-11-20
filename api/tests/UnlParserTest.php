<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Labs\UnlWriter;

final class UnlParserTest extends TestCase
{
    public function testParsesLabMetadata(): void
    {
        $lab = (new UnlParser())->parse(__DIR__ . '/fixtures/sample.unl');

        self::assertSame('sample-lab', $lab['id']);
        self::assertSame('Sample Lab', $lab['name']);
        self::assertSame('TeraLab', $lab['author']);
        self::assertSame('1', $lab['version']);
        self::assertSame('sample.unl', $lab['filename']);
        self::assertSame('Sample fixture for API tests.', $lab['description']);
        self::assertSame(1, $lab['nodes']);
        self::assertSame(1, $lab['networks']);
        self::assertSame('legacy', $lab['format']);
    }

    public function testParsesLegacyDialect(): void
    {
        $lab = (new UnlParser())->parse(__DIR__ . '/fixtures/legacy-lab.unl');

        self::assertSame('legacy', $lab['format']);
        self::assertSame(2, $lab['nodes']);
        $node = $lab['node_list'][0];
        self::assertSame('iol', $node['type']);
        self::assertCount(3, $node['interfaces']);
        self::assertSame(['Ethernet0/0', 'Ethernet0/1', 'Ethernet0/2'], array_column($node['interfaces'], 'name'));
        self::assertSame(['0', '', '0'], array_column($node['interfaces'], 'network_id'));
        self::assertSame("hostname R1\n!", $node['config']);
        self::assertSame('/var/lib/teralab/images/iol/abc/x86_64.bin', $node['image']);
    }

    public function testParsesEveNgDialect(): void
    {
        $lab = (new UnlParser())->parse(__DIR__ . '/fixtures/eve-ng-lab.unl');

        self::assertSame('eve-ng', $lab['format']);
        self::assertSame('EVE Lab', $lab['name']);
        self::assertSame('Lab Author', $lab['author']);
        self::assertSame('Notes body.', $lab['body']);

        $iol = $lab['node_list'][0];
        self::assertSame('iol', $iol['type']);
        self::assertCount(4, $iol['interfaces']);
        self::assertSame(['e0/0', 'e0/1', 'e0/2', 'e0/3'], array_column($iol['interfaces'], 'name'));
        self::assertSame(['1', '', '', ''], array_column($iol['interfaces'], 'network_id'));
        self::assertSame("hostname R1\n!\n", $iol['config']);
        self::assertSame('x86_64_crb_linux-adventerprisek9-ms.bin', $iol['image']);

        $text = null;
        foreach ($lab['node_list'] as $node) {
            if ($node['type'] === 'text') {
                $text = $node;
            }
        }
        self::assertIsArray($text);
        self::assertSame('hello note', $text['name']);
        self::assertSame(640, $text['left']);
        self::assertSame(480, $text['top']);

        self::assertSame(['bridge', 'pnet0'], array_column($lab['network_list'], 'type'));
        self::assertCount(1, $lab['objects_extra']);
        self::assertStringContainsString('<configsets>', implode('', $lab['objects_extra']));
        self::assertNotEmpty($lab['warnings']);
    }

    public function testWriterProducesPortableEveNgCompatibleXml(): void
    {
        $parser = new UnlParser();
        $writer = new UnlWriter();
        $lab = $parser->parse(__DIR__ . '/fixtures/eve-ng-lab.unl');

        $xml = $writer->write($lab);
        self::assertStringContainsString('format="2"', $xml);
        self::assertStringContainsString('scripttimeout="300"', $xml);
        self::assertStringContainsString('<lab ', $xml);
        self::assertStringNotContainsString('image="/', $xml);
        self::assertDoesNotMatchRegularExpression('/<node[^>]*type="text"/', $xml);
        self::assertStringContainsString('<textobject id="3" name="hello note" type="text">', $xml);
        self::assertStringContainsString('<network id="2" type="pnet0" name="Cloud"', $xml);
        self::assertStringContainsString('<configsets>', $xml);
        self::assertStringContainsString('<extensions format="2">', $xml);
        self::assertStringContainsString('vid="1"', $xml);
        self::assertStringContainsString('keepalive="0"', $xml);
        self::assertStringContainsString('type="iol"', $xml);
        self::assertStringContainsString('name="e0/3"', $xml);
        self::assertStringContainsString('id="48"', $xml);
        self::assertStringContainsString(base64_encode("hostname R1\n!\n"), $xml);

        $again = $parser->parseXml($xml);
        self::assertSame('teralab-2', $again['format']);
        self::assertSame(2, $again['networks']);
        $iol = $again['node_list'][0];
        self::assertSame("hostname R1\n!\n", $iol['config']);
        self::assertCount(4, $iol['interfaces']);
        self::assertSame(['e0/0', 'e0/1', 'e0/2', 'e0/3'], array_column($iol['interfaces'], 'name'));
        self::assertSame('1', $iol['interfaces'][0]['attributes']['vid'] ?? '');
        self::assertSame('0', $iol['attributes']['keepalive'] ?? '');
    }

    public function testPictureNodesRoundTripThroughExtensions(): void
    {
        $lab = [
            'id' => 'lab-1',
            'name' => 'Pictures',
            'revision' => 1,
            'node_list' => [[
                'id' => '1',
                'name' => 'Diagram',
                'type' => 'picture',
                'image' => 'https://example.test/topology.png',
                'left' => 240,
                'top' => 180,
                'interfaces' => [],
            ]],
            'network_list' => [],
        ];
        $writer = new UnlWriter();
        $parser = new UnlParser();
        $xml = $writer->write($lab);
        self::assertStringContainsString('"pictures"', $xml);
        self::assertStringNotContainsString('type="picture"', $xml);

        $again = $parser->parseXml($xml);
        $picture = null;
        foreach ($again['node_list'] as $node) {
            if ($node['type'] === 'picture') {
                $picture = $node;
            }
        }
        self::assertIsArray($picture);
        self::assertSame('https://example.test/topology.png', $picture['image']);
        self::assertSame(240, $picture['left']);
        self::assertSame(180, $picture['top']);
    }

    public function testWriterNeverEmitsAbsolutePaths(): void
    {
        $lab = [
            'id' => 'lab-1',
            'name' => 'Paths',
            'revision' => 1,
            'node_list' => [[
                'id' => '1',
                'name' => 'R1',
                'type' => 'qemu',
                'image' => '/etc/passwd',
                'iso' => '/tmp/../../secret.iso',
                'interfaces' => [],
            ]],
            'network_list' => [],
        ];
        $xml = (new UnlWriter())->write($lab);

        self::assertStringContainsString('image="passwd"', $xml);
        self::assertStringContainsString('iso="secret.iso"', $xml);
        self::assertStringNotContainsString('/etc/passwd', $xml);
        self::assertStringNotContainsString('/tmp/', $xml);
    }

    public function testRejectsUnexpectedRoot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'teralab-lab-');
        self::assertIsString($path);
        file_put_contents($path, '<?xml version="1.0"?><notlab/>');

        try {
            $this->expectException(\RuntimeException::class);
            (new UnlParser())->parse($path);
        } finally {
            unlink($path);
        }
    }
}
