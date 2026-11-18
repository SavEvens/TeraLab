<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class UnlParser
{
    private const MAX_BYTES = 10485760;

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

        $name = trim($root->getAttribute('name'));
        if ($name === '') {
            $name = 'Untitled lab';
        }

        $description = '';
        $body = '';
        $extraXml = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->tagName === 'description') {
                $description = trim($child->textContent);
            } elseif ($child->tagName === 'body') {
                $body = trim($child->textContent);
            } elseif ($child->tagName !== 'topology') {
                $extraXml[] = $document->saveXML($child) ?: '';
            }
        }

        $nodes = [];
        $networks = [];
        $topology = $root->getElementsByTagName('topology')->item(0);
        if ($topology instanceof DOMElement) {
            foreach ($topology->getElementsByTagName('node') as $node) {
                $nodes[] = $this->parseNode($node);
            }
            foreach ($topology->getElementsByTagName('network') as $network) {
                $networks[] = [
                    'id' => $network->getAttribute('id'),
                    'name' => $network->getAttribute('name'),
                    'type' => $network->getAttribute('type') !== '' ? $network->getAttribute('type') : 'bridge',
                    'attributes' => $this->extraAttributes($network, ['id', 'name', 'type']),
                ];
            }
        }

        $revision = (int) ($root->getAttribute('revision') !== '' ? $root->getAttribute('revision') : 1);

        return [
            'id' => trim($root->getAttribute('id')) !== '' ? trim($root->getAttribute('id')) : '',
            'name' => $name,
            'author' => trim($root->getAttribute('author')),
            'description' => $description,
            'body' => $body,
            'version' => trim($root->getAttribute('version')),
            'revision' => max(1, $revision),
            'attributes' => $this->extraAttributes($root, ['id', 'name', 'author', 'version', 'revision']),
            'extra_xml' => $extraXml,
            'node_list' => $nodes,
            'network_list' => $networks,
            'nodes' => count($nodes),
            'networks' => count($networks),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseNode(DOMElement $node): array
    {
        $config = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'config') {
                $config = (string) $child->textContent;
            }
        }
        $interfaces = [];
        foreach ($node->getElementsByTagName('interface') as $interface) {
            $interfaces[] = [
                'id' => (int) $interface->getAttribute('id'),
                'name' => $interface->getAttribute('name'),
                'type' => $interface->getAttribute('type'),
                'network_id' => $interface->getAttribute('network_id'),
                'attributes' => $this->extraAttributes($interface, ['id', 'name', 'type', 'network_id']),
            ];
        }

        return [
            'id' => $node->getAttribute('id'),
            'name' => $node->getAttribute('name'),
            'type' => $node->getAttribute('type') !== '' ? $node->getAttribute('type') : 'qemu',
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
            'ethernet' => (int) ($node->getAttribute('ethernet') !== '' ? $node->getAttribute('ethernet') : count($interfaces)),
            'serial' => (int) ($node->getAttribute('serial') !== '' ? $node->getAttribute('serial') : 0),
            'uuid' => $node->getAttribute('uuid'),
            'config' => $config,
            'platform' => $node->getAttribute('platform'),
            'nic' => $node->getAttribute('nic'),
            'left' => (int) ($node->getAttribute('left') !== '' ? $node->getAttribute('left') : 0),
            'top' => (int) ($node->getAttribute('top') !== '' ? $node->getAttribute('top') : 0),
            'interfaces' => $interfaces,
            'attributes' => $this->extraAttributes($node, [
                'id', 'name', 'type', 'template', 'icon', 'image', 'console', 'cpu', 'ram',
                'ethernet', 'serial', 'uuid', 'platform', 'nic', 'iso', 'kernel', 'initrd', 'append',
                'left', 'top', 'config',
            ]),
        ];
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
