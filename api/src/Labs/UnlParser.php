<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Tolerant .unl reader.
 *
 * Accepts three dialects with one model:
 *  - legacy TeraLab/UNetLab files (per-node <config>, paths in image fields)
 *  - modern EVE-NG files (<objects><configs> base64, encoded interface ids,
 *    cloud network types)
 *  - TeraLab v2 files (format="2", portable image references)
 */
final class UnlParser
{
    private const MAX_BYTES = 10485760;
    private const MAX_CONFIG_BYTES = 1048576;
    private const MAX_PORTS = 16;

    private const ROOT_MANAGED = ['id', 'name', 'author', 'version', 'revision', 'format'];
    private const NODE_MANAGED = [
        'id', 'name', 'type', 'template', 'icon', 'image', 'iso', 'kernel', 'initrd', 'append',
        'console', 'cpu', 'ram', 'ethernet', 'nvram', 'serial', 'uuid', 'platform', 'nic',
        'machine', 'delay', 'sat', 'config', 'left', 'top',
    ];
    private const INTERFACE_MANAGED = ['id', 'name', 'type', 'network_id'];
    private const NETWORK_MANAGED = ['id', 'name', 'type'];

    /**
     * @return array<string, mixed>
     */
    public function parse(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('Lab file is too large.');
        }
        $xml = file_get_contents($path);
        if ($xml === false) {
            throw new RuntimeException('Cannot read the lab file.');
        }

        $lab = $this->parseXml($xml);
        $lab['filename'] = basename($path);
        $lab['modified'] = filemtime($path) ?: time();

        return $lab;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            throw new RuntimeException('Invalid lab XML.');
        }

        $root = $document->documentElement;
        if ($root->tagName !== 'lab') {
            throw new RuntimeException('Unexpected lab root element.');
        }

        $format = $this->detectFormat($root);
        /** @var list<array<string, string>> $warnings */
        $warnings = [];

        $name = trim($root->getAttribute('name'));
        if ($name === '') {
            $name = 'Untitled lab';
        }

        $description = '';
        $body = '';
        $extraXml = [];
        $extensions = [];
        $objectsExtra = [];
        /** @var array<string, string> $configs */
        $configs = [];
        /** @var list<array<string, string>> $textObjects */
        $textObjects = [];
        $topology = null;

        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->tagName) {
                case 'description':
                    $description = trim($child->textContent);
                    break;
                case 'body':
                    $body = trim($child->textContent);
                    break;
                case 'topology':
                    $topology = $child;
                    break;
                case 'objects':
                    $this->parseObjects($child, $configs, $textObjects, $objectsExtra);
                    break;
                case 'extensions':
                    $extensions = $this->parseExtensions($child, $warnings);
                    break;
                default:
                    $extraXml[] = $document->saveXML($child) ?: '';
            }
        }

        $nodes = [];
        $networks = [];
        $networkIds = [];
        if ($topology instanceof DOMElement) {
            foreach ($topology->getElementsByTagName('network') as $network) {
                $id = trim($network->getAttribute('id'));
                $networkIds[$id] = true;
                $networks[] = [
                    'id' => $id,
                    'name' => $network->getAttribute('name'),
                    'type' => $network->getAttribute('type') !== '' ? $network->getAttribute('type') : 'bridge',
                    'left' => (int) ($network->getAttribute('left') !== '' ? $network->getAttribute('left') : 0),
                    'top' => (int) ($network->getAttribute('top') !== '' ? $network->getAttribute('top') : 0),
                    'attributes' => $this->extraAttributes($network, self::NETWORK_MANAGED),
                ];
            }
            foreach ($topology->getElementsByTagName('node') as $node) {
                $nodes[] = $this->parseNode($node, $format, $configs, $networkIds, $warnings);
            }
        }

        $nextId = 1;
        foreach ($nodes as $node) {
            if (is_numeric($node['id'])) {
                $nextId = max($nextId, (int) $node['id'] + 1);
            }
        }
        foreach ($textObjects as $textObject) {
            $nodes[] = $this->textNode($textObject, $nextId++);
        }
        foreach ($this->pictures($extensions) as $picture) {
            $nodes[] = $this->pictureNode($picture, $nextId++);
        }

        $revision = (int) ($root->getAttribute('revision') !== '' ? $root->getAttribute('revision') : 1);

        return [
            'id' => trim($root->getAttribute('id')) !== '' ? trim($root->getAttribute('id')) : '',
            'name' => $name,
            'author' => trim($root->getAttribute('author')),
            'description' => $description,
            'body' => $body,
            'version' => trim($root->getAttribute('version')),
            'format' => $format,
            'revision' => max(1, $revision),
            'attributes' => $this->extraAttributes($root, self::ROOT_MANAGED),
            'extra_xml' => $extraXml,
            'objects_extra' => $objectsExtra,
            'extensions' => $extensions,
            'warnings' => $warnings,
            'node_list' => $nodes,
            'network_list' => $networks,
            'nodes' => count($nodes),
            'networks' => count($networks),
        ];
    }

    private function detectFormat(DOMElement $root): string
    {
        if ($root->getAttribute('format') === '2') {
            return 'teralab-2';
        }
        if ($root->getElementsByTagName('objects')->length > 0
            || $root->getAttribute('scripttimeout') !== ''
            || $root->getAttribute('countdown') !== ''
            || $root->getAttribute('linkwidth') !== ''
            || $root->getAttribute('grid') !== ''
        ) {
            return 'eve-ng';
        }

        return 'legacy';
    }

    /**
     * @param array<string, string> $configs
     * @param list<array<string, string>> $textObjects
     * @param list<string> $objectsExtra
     */
    private function parseObjects(DOMElement $objects, array &$configs, array &$textObjects, array &$objectsExtra): void
    {
        $document = $objects->ownerDocument;
        foreach ($objects->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->tagName) {
                case 'configs':
                    foreach ($child->getElementsByTagName('config') as $config) {
                        $id = trim($config->getAttribute('id'));
                        if ($id === '') {
                            continue;
                        }
                        $configs[$id] = $this->decodeText((string) $config->textContent);
                    }
                    break;
                case 'textobjects':
                    foreach ($child->getElementsByTagName('textobject') as $textObject) {
                        $data = '';
                        foreach ($textObject->childNodes as $dataNode) {
                            if ($dataNode instanceof DOMElement && $dataNode->tagName === 'data') {
                                $data = (string) $dataNode->textContent;
                                break;
                            }
                        }
                        $textObjects[] = [
                            'id' => trim($textObject->getAttribute('id')),
                            'name' => $textObject->getAttribute('name'),
                            'type' => $textObject->getAttribute('type'),
                            'data' => $data,
                        ];
                    }
                    break;
                case 'pictures':
                    $objectsExtra[] = $document->saveXML($child) ?: '';
                    break;
                default:
                    $objectsExtra[] = $document->saveXML($child) ?: '';
            }
        }
    }

    /**
     * @param list<array<string, string>> $warnings
     * @return array<string, mixed>
     */
    private function parseExtensions(DOMElement $extensions, array &$warnings): array
    {
        $json = trim($extensions->textContent);
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $warnings[] = ['type' => 'extensions_invalid', 'message' => 'Ignored malformed TeraLab extensions.'];

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $configs
     * @param array<string, bool> $networkIds
     * @param list<array<string, string>> $warnings
     * @return array<string, mixed>
     */
    private function parseNode(
        DOMElement $node,
        string $format,
        array $configs,
        array $networkIds,
        array &$warnings,
    ): array {
        $type = $node->getAttribute('type') !== '' ? $node->getAttribute('type') : 'qemu';
        $id = trim($node->getAttribute('id'));
        $config = '';
        $childXml = [];
        $rawInterfaces = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->tagName === 'config') {
                $config = (string) $child->textContent;
                continue;
            }
            if ($child->tagName === 'interface') {
                $rawInterfaces[] = $this->parseInterface($child);
                continue;
            }
            if ($child->tagName === 'interfaces') {
                foreach ($child->getElementsByTagName('interface') as $interface) {
                    $rawInterfaces[] = $this->parseInterface($interface);
                }
                continue;
            }
            $childXml[] = $node->ownerDocument->saveXML($child) ?: '';
        }
        if ($config === '' && isset($configs[$id])) {
            $config = $configs[$id];
        }

        $declared = (int) ($node->getAttribute('ethernet') !== '' ? $node->getAttribute('ethernet') : 0);
        $interfaces = $this->normaliseInterfaces($type, $rawInterfaces, $declared, $format, $networkIds, $warnings, (string) $node->getAttribute('name'));

        return [
            'id' => $id,
            'name' => $node->getAttribute('name'),
            'type' => $type,
            'template' => $node->getAttribute('template'),
            'icon' => $node->getAttribute('icon'),
            'image' => $node->getAttribute('image'),
            'iso' => $node->getAttribute('iso'),
            'kernel' => $node->getAttribute('kernel'),
            'initrd' => $node->getAttribute('initrd'),
            'append' => $node->getAttribute('append'),
            'console' => $node->getAttribute('console') !== '' ? $node->getAttribute('console') : 'telnet',
            'cpu' => (int) ($node->getAttribute('cpu') !== '' ? $node->getAttribute('cpu') : 1),
            'ram' => (int) ($node->getAttribute('ram') !== '' ? $node->getAttribute('ram') : 512),
            'ethernet' => count($interfaces),
            'serial' => (int) ($node->getAttribute('serial') !== '' ? $node->getAttribute('serial') : 0),
            'uuid' => $node->getAttribute('uuid'),
            'config' => $config,
            'platform' => $node->getAttribute('platform'),
            'nic' => $node->getAttribute('nic'),
            'machine' => $node->getAttribute('machine'),
            'delay' => (int) ($node->getAttribute('delay') !== '' ? $node->getAttribute('delay') : 0),
            'sat' => (int) ($node->getAttribute('sat') !== '' ? $node->getAttribute('sat') : -1),
            'left' => (int) ($node->getAttribute('left') !== '' ? $node->getAttribute('left') : 0),
            'top' => (int) ($node->getAttribute('top') !== '' ? $node->getAttribute('top') : 0),
            'interfaces' => $interfaces,
            'extra_xml' => $childXml,
            'attributes' => $this->extraAttributes($node, self::NODE_MANAGED),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseInterface(DOMElement $interface): array
    {
        $rawId = trim($interface->getAttribute('id'));

        return [
            'id' => is_numeric($rawId) ? (int) $rawId : 0,
            'name' => $interface->getAttribute('name'),
            'type' => $interface->getAttribute('type') !== '' ? $interface->getAttribute('type') : 'ethernet',
            'network_id' => $interface->getAttribute('network_id'),
            'attributes' => $this->extraAttributes($interface, self::INTERFACE_MANAGED),
        ];
    }

    /**
     * Place interfaces on their real port index and fill gaps.
     *
     * EVE-NG omits unconnected ports and encodes IOL ports as
     * slot | port << 4; legacy TeraLab files list every port in order.
     *
     * @param list<array<string, mixed>> $raw
     * @param array<string, bool> $networkIds
     * @param list<array<string, string>> $warnings
     * @return list<array<string, mixed>>
     */
    private function normaliseInterfaces(
        string $type,
        array $raw,
        int $declared,
        string $format,
        array $networkIds,
        array &$warnings,
        string $nodeName = '',
    ): array {
        $placed = [];
        $ordinal = 0;
        foreach ($raw as $interface) {
            $index = $this->interfaceIndex($type, (string) $interface['name'], $ordinal);
            $index = max(0, $index);
            while (isset($placed[$index])) {
                $index++;
            }
            $placed[$index] = $interface;
            $ordinal++;
        }

        $count = $placed === [] ? 0 : max(array_keys($placed)) + 1;
        if ($type === 'iol' && $format === 'eve-ng' && $declared > 0) {
            $count = max($count, $declared * 4);
        }
        if ($type !== 'iol' && $declared > $count) {
            $count = $declared;
        }
        if ($type === 'iol' && $format === 'legacy' && $declared > $count) {
            $count = $declared;
        }
        if ($count > self::MAX_PORTS) {
            $warnings[] = [
                'type' => 'port_limit',
                'message' => 'Node "' . ($nodeName !== '' ? $nodeName : 'unnamed') . '" was limited to '
                    . self::MAX_PORTS . ' interfaces.',
            ];
            $count = self::MAX_PORTS;
        }

        $interfaces = [];
        $inserted = 0;
        for ($index = 0; $index < $count; $index++) {
            if (isset($placed[$index])) {
                $interface = $placed[$index];
                $networkId = (string) $interface['network_id'];
                if ($networkId === '0' && !isset($networkIds['0'])) {
                    $networkId = '';
                }
                $interface['id'] = $index;
                $interface['network_id'] = $networkId;
                $interfaces[] = $interface;
                continue;
            }
            $inserted++;
            $interfaces[] = $this->syntheticInterface($type, $index);
        }
        if ($inserted > 0) {
            $warnings[] = [
                'type' => 'interface_gap',
                'message' => $inserted . ' unconnected interface(s) were restored from the port layout.',
            ];
        }

        return $interfaces;
    }

    private function interfaceIndex(string $type, string $name, int $ordinal): int
    {
        if (preg_match('/^(?:ethernet|e)(\d+)\/(\d+)$/i', trim($name), $match) === 1) {
            $index = ((int) $match[1]) * 4 + (int) $match[2];

            return $index <= 255 ? $index : $ordinal;
        }
        if (preg_match('/^(?:eth|ethernet|e)(\d+)$/i', trim($name), $match) === 1) {
            $index = (int) $match[1];

            return $index <= 255 ? $index : $ordinal;
        }

        return $ordinal;
    }

    /**
     * @return array<string, mixed>
     */
    private function syntheticInterface(string $type, int $index): array
    {
        if ($type === 'iol') {
            $name = 'e' . intdiv($index, 4) . '/' . ($index % 4);
        } elseif ($type === 'vpcs') {
            $name = 'eth' . $index;
        } else {
            $name = 'e' . $index;
        }

        return [
            'id' => $index,
            'name' => $name,
            'type' => 'ethernet',
            'network_id' => '',
            'attributes' => [],
        ];
    }

    /**
     * @param array<string, string> $textObject
     * @return array<string, mixed>
     */
    private function textNode(array $textObject, int $id): array
    {
        $html = $this->decodeText($textObject['data'] ?? '');
        $left = 200;
        $top = 200;
        if (preg_match('/left:\s*(-?\d+(?:\.\d+)?)px/i', $html, $match) === 1) {
            $left = (int) round((float) $match[1]);
        }
        if (preg_match('/top:\s*(-?\d+(?:\.\d+)?)px/i', $html, $match) === 1) {
            $top = (int) round((float) $match[1]);
        }
        $plain = (string) preg_replace('#<svg\b.*?</svg>#is', ' ', $html);
        $plain = (string) preg_replace('#<style\b.*?</style>#is', ' ', $plain);
        $plain = str_replace(['<br>', '<br/>', '<br />'], ' ', $plain);
        $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/\s+/', ' ', $plain));
        if (stripos($plain, 'does not support inline svg') !== false) {
            $plain = '';
        }
        if (mb_strlen($plain) > 500) {
            $plain = mb_substr($plain, 0, 500);
        }
        $name = $plain !== '' ? $plain : ($textObject['name'] !== '' ? $textObject['name'] : 'Text');

        return [
            'id' => (string) $id,
            'name' => $name,
            'type' => 'text',
            'template' => '',
            'icon' => '',
            'image' => '',
            'iso' => '',
            'kernel' => '',
            'initrd' => '',
            'append' => '',
            'console' => 'telnet',
            'cpu' => 1,
            'ram' => 64,
            'ethernet' => 0,
            'serial' => 0,
            'uuid' => '',
            'config' => '',
            'platform' => '',
            'nic' => '',
            'machine' => '',
            'delay' => 0,
            'sat' => -1,
            'left' => $left,
            'top' => $top,
            'interfaces' => [],
            'extra_xml' => [],
            'attributes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $picture
     * @return array<string, mixed>
     */
    private function pictureNode(array $picture, int $id): array
    {
        return [
            'id' => (string) $id,
            'name' => is_string($picture['name'] ?? null) ? $picture['name'] : 'Picture',
            'type' => 'picture',
            'template' => '',
            'icon' => '',
            'image' => is_string($picture['image'] ?? null) ? $picture['image'] : '',
            'iso' => '',
            'kernel' => '',
            'initrd' => '',
            'append' => '',
            'console' => 'telnet',
            'cpu' => 1,
            'ram' => 64,
            'ethernet' => 0,
            'serial' => 0,
            'uuid' => '',
            'config' => '',
            'platform' => '',
            'nic' => '',
            'machine' => '',
            'delay' => 0,
            'sat' => -1,
            'left' => (int) ($picture['left'] ?? 200),
            'top' => (int) ($picture['top'] ?? 200),
            'interfaces' => [],
            'extra_xml' => [],
            'attributes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $extensions
     * @return list<array<string, mixed>>
     */
    private function pictures(array $extensions): array
    {
        $pictures = $extensions['pictures'] ?? null;

        return is_array($pictures) ? array_values(array_filter($pictures, 'is_array')) : [];
    }

    private function decodeText(string $data): string
    {
        $data = trim($data);
        if ($data === '') {
            return '';
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return $data;
        }
        if (strlen($decoded) > self::MAX_CONFIG_BYTES) {
            return '';
        }
        if (preg_match('//u', $decoded) !== 1) {
            return $data;
        }

        return $decoded;
    }

    /**
     * @param list<string> $known
     * @return array<string, string>
     */
    private function extraAttributes(DOMElement $element, array $known): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            if (!in_array($attribute->name, $known, true)) {
                $attributes[$attribute->name] = $attribute->value;
            }
        }

        return $attributes;
    }
}
