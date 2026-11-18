<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use DOMDocument;
use DOMElement;

final class UnlWriter
{
    /**
     * @param array<string, mixed> $lab
     */
    public function write(array $lab): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElement('lab');
        $root->setAttribute('name', (string) ($lab['name'] ?? 'Untitled lab'));
        $root->setAttribute('id', (string) ($lab['id'] ?? ''));
        $root->setAttribute('version', (string) ($lab['version'] ?? '1'));
        if (($lab['author'] ?? '') !== '') {
            $root->setAttribute('author', (string) $lab['author']);
        }
        $root->setAttribute('revision', (string) ($lab['revision'] ?? 1));
        $this->applyAttributes($root, $lab['attributes'] ?? []);
        $document->appendChild($root);

        $description = $document->createElement('description');
        $description->appendChild($document->createTextNode((string) ($lab['description'] ?? '')));
        $root->appendChild($description);

        $body = $document->createElement('body');
        $body->appendChild($document->createTextNode((string) ($lab['body'] ?? '')));
        $root->appendChild($body);

        $topology = $document->createElement('topology');
        $root->appendChild($topology);

        $nodes = $document->createElement('nodes');
        $topology->appendChild($nodes);
        foreach (($lab['node_list'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $element = $document->createElement('node');
            $element->setAttribute('id', (string) ($node['id'] ?? ''));
            $element->setAttribute('name', (string) ($node['name'] ?? ''));
            $element->setAttribute('type', (string) ($node['type'] ?? 'qemu'));
            foreach ([
                'template' => 'template',
                'icon' => 'icon',
                'image' => 'image',
                'iso' => 'iso',
                'kernel' => 'kernel',
                'initrd' => 'initrd',
                'append' => 'append',
                'console' => 'console',
                'cpu' => 'cpu',
                'ram' => 'ram',
                'ethernet' => 'ethernet',
                'serial' => 'serial',
                'uuid' => 'uuid',
                'platform' => 'platform',
                'nic' => 'nic',
                'left' => 'left',
                'top' => 'top',
            ] as $key => $attribute) {
                if (isset($node[$key]) && $node[$key] !== '') {
                    $element->setAttribute($attribute, (string) $node[$key]);
                }
            }
            $this->applyAttributes($element, $node['attributes'] ?? []);
            foreach (($node['interfaces'] ?? []) as $interface) {
                if (!is_array($interface)) {
                    continue;
                }
                $child = $document->createElement('interface');
                $child->setAttribute('id', (string) ($interface['id'] ?? 0));
                if (($interface['name'] ?? '') !== '') {
                    $child->setAttribute('name', (string) $interface['name']);
                }
                $child->setAttribute('type', (string) ($interface['type'] ?? 'ethernet'));
                $child->setAttribute('network_id', (string) ($interface['network_id'] ?? ''));
                $this->applyAttributes($child, $interface['attributes'] ?? []);
                $element->appendChild($child);
            }
            if (isset($node['config']) && $node['config'] !== '') {
                $config = $document->createElement('config');
                $config->appendChild($document->createTextNode((string) $node['config']));
                $element->appendChild($config);
            }
            $nodes->appendChild($element);
        }

        $networks = $document->createElement('networks');
        $topology->appendChild($networks);
        foreach (($lab['network_list'] ?? []) as $network) {
            if (!is_array($network)) {
                continue;
            }
            $element = $document->createElement('network');
            $element->setAttribute('id', (string) ($network['id'] ?? ''));
            $element->setAttribute('name', (string) ($network['name'] ?? ''));
            $element->setAttribute('type', (string) ($network['type'] ?? 'bridge'));
            $this->applyAttributes($element, $network['attributes'] ?? []);
            $networks->appendChild($element);
        }

        foreach (($lab['extra_xml'] ?? []) as $xml) {
            if (!is_string($xml) || $xml === '') {
                continue;
            }
            $fragment = $document->createDocumentFragment();
            if (@$fragment->appendXML($xml)) {
                $root->appendChild($fragment);
            }
        }

        return (string) $document->saveXML();
    }

    /**
     * @param mixed $attributes
     */
    private function applyAttributes(DOMElement $element, mixed $attributes): void
    {
        if (!is_array($attributes)) {
            return;
        }
        foreach ($attributes as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $element->setAttribute($name, (string) $value);
            }
        }
    }
}
