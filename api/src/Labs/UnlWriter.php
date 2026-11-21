<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use DOMDocument;
use DOMElement;
use TeraLab\Api\Image\ImageReference;

/**
 * Writes TeraLab's portable lab format.
 *
 * The result is an EVE-NG-compatible .unl file: EVE-NG reads the topology,
 * interfaces, configs and objects, and ignores the TeraLab attributes and the
 * <extensions> element. Image/ISO/kernel/initrd values are single path
 * segments (never absolute paths), except on docker nodes where `image` is a
 * container reference (registry/repository:tag) stored verbatim, matching the
 * EVE-NG convention.
 */
final class UnlWriter
{
    private const ROOT_MANAGED = [
        'name', 'id', 'version', 'format', 'revision', 'author',
        'scripttimeout', 'countdown', 'linkwidth', 'lock', 'grid', 'sat',
    ];
    private const NODE_MANAGED = [
        'id', 'name', 'type', 'template', 'image', 'iso', 'kernel', 'initrd', 'append',
        'console', 'cpu', 'ram', 'ethernet', 'nvram', 'serial', 'uuid', 'platform', 'nic',
        'machine', 'delay', 'sat', 'icon', 'config', 'left', 'top',
    ];
    private const INTERFACE_MANAGED = ['id', 'name', 'type', 'network_id'];
    private const NETWORK_MANAGED = ['id', 'name', 'type', 'left', 'top', 'visibility', 'icon'];

    /**
     * @param array<string, mixed> $lab
     */
    public function write(array $lab): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElement('lab');
        $this->applyRootAttributes($root, $lab);
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
        $configs = [];
        $textObjects = [];
        $nextTextId = $this->nextNodeId($lab);
        foreach (($lab['node_list'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? 'qemu');
            if ($type === 'text') {
                $textObjects[] = ['id' => (string) ($nextTextId++), 'node' => $node];
                continue;
            }
            if ($type === 'picture') {
                continue;
            }
            $element = $this->nodeElement($document, $node);
            $nodes->appendChild($element);
            $config = (string) ($node['config'] ?? '');
            if ($config !== '') {
                $configs[] = ['id' => (string) ($node['id'] ?? ''), 'config' => $config];
            }
        }

        $networks = $document->createElement('networks');
        $topology->appendChild($networks);
        foreach (($lab['network_list'] ?? []) as $network) {
            if (!is_array($network)) {
                continue;
            }
            $networks->appendChild($this->networkElement($document, $network));
        }

        $objects = $this->objectsElement($document, $configs, $textObjects, $lab);
        if ($objects !== null) {
            $root->appendChild($objects);
        }

        foreach (($lab['extra_xml'] ?? []) as $xml) {
            $this->appendFragment($root, $xml);
        }

        $root->appendChild($this->extensionsElement($document, $lab));

        return (string) $document->saveXML();
    }

    /**
     * @param array<string, mixed> $lab
     */
    private function applyRootAttributes(DOMElement $root, array $lab): void
    {
        $values = [
            'name' => (string) ($lab['name'] ?? 'Untitled lab'),
            'id' => (string) ($lab['id'] ?? ''),
            'version' => '1',
            'format' => '2',
            'revision' => (string) ($lab['revision'] ?? 1),
            'scripttimeout' => '300',
            'countdown' => '0',
            'linkwidth' => '2',
            'lock' => '0',
            'grid' => '1',
            'sat' => '-1',
        ];
        if (($lab['author'] ?? '') !== '') {
            $values['author'] = (string) $lab['author'];
        }
        foreach (($lab['attributes'] ?? []) as $name => $value) {
            if (!is_string($name) || $name === '' || in_array($name, self::ROOT_MANAGED, true)) {
                continue;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $values[$name] = (string) $value;
            }
        }
        foreach ($values as $name => $value) {
            $root->setAttribute($name, $value);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeElement(DOMDocument $document, array $node): DOMElement
    {
        $type = (string) ($node['type'] ?? 'qemu');
        $interfaces = is_array($node['interfaces'] ?? null) ? array_values($node['interfaces']) : [];
        $element = $document->createElement('node');
        $element->setAttribute('id', (string) ($node['id'] ?? ''));
        $element->setAttribute('name', (string) ($node['name'] ?? $node['id'] ?? ''));
        $element->setAttribute('type', $type);
        if (($node['template'] ?? '') !== '') {
            $element->setAttribute('template', (string) $node['template']);
        }
        foreach (['image', 'iso', 'kernel', 'initrd'] as $key) {
            $value = (string) ($node[$key] ?? '');
            if ($value !== '') {
                $element->setAttribute(
                    $key,
                    $type === 'docker' ? $value : ImageReference::portable($value),
                );
            }
        }
        $element->setAttribute('console', (string) ($node['console'] ?? 'telnet'));
        $element->setAttribute('cpu', (string) (int) ($node['cpu'] ?? 1));
        $element->setAttribute('ram', (string) (int) ($node['ram'] ?? 512));
        $ports = count($interfaces);
        $ethernet = $type === 'iol' ? (int) ceil($ports / 4) : $ports;
        $element->setAttribute('ethernet', (string) $ethernet);
        if (isset($node['nvram']) && (int) $node['nvram'] > 0) {
            $element->setAttribute('nvram', (string) (int) $node['nvram']);
        }
        $element->setAttribute('serial', (string) (int) ($node['serial'] ?? 0));
        $element->setAttribute('delay', (string) (int) ($node['delay'] ?? 0));
        $element->setAttribute('sat', (string) (int) ($node['sat'] ?? -1));
        if (($node['icon'] ?? '') !== '') {
            $element->setAttribute('icon', (string) $node['icon']);
        }
        $element->setAttribute('config', ((string) ($node['config'] ?? '')) !== '' ? '1' : '0');
        $element->setAttribute('left', (string) (int) ($node['left'] ?? 0));
        $element->setAttribute('top', (string) (int) ($node['top'] ?? 0));
        foreach (['uuid', 'platform', 'nic', 'machine', 'append'] as $key) {
            if (($node[$key] ?? '') !== '') {
                $element->setAttribute($key, (string) $node[$key]);
            }
        }
        $this->applyAttributes($element, $node['attributes'] ?? [], self::NODE_MANAGED);

        foreach ($interfaces as $index => $interface) {
            if (!is_array($interface)) {
                continue;
            }
            $element->appendChild($this->interfaceElement($document, $type, $index, $interface));
        }
        foreach (($node['extra_xml'] ?? []) as $xml) {
            $this->appendFragment($element, $xml);
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $interface
     */
    private function interfaceElement(DOMDocument $document, string $type, int $index, array $interface): DOMElement
    {
        $element = $document->createElement('interface');
        if ($type === 'iol') {
            $slot = intdiv($index, 4);
            $port = $index % 4;
            $element->setAttribute('id', (string) (($port << 4) | $slot));
            $element->setAttribute('name', 'e' . $slot . '/' . $port);
        } else {
            $element->setAttribute('id', (string) $index);
            $name = (string) ($interface['name'] ?? '');
            if ($name === '') {
                $name = $type === 'vpcs' ? 'eth' . $index : 'e' . $index;
            }
            $element->setAttribute('name', $name);
        }
        $element->setAttribute('type', (string) ($interface['type'] ?? 'ethernet'));
        $networkId = (string) ($interface['network_id'] ?? '');
        $element->setAttribute('network_id', $networkId !== '' ? $networkId : '0');
        $this->applyAttributes($element, $interface['attributes'] ?? [], self::INTERFACE_MANAGED);

        return $element;
    }

    /**
     * @param array<string, mixed> $network
     */
    private function networkElement(DOMDocument $document, array $network): DOMElement
    {
        $attributes = is_array($network['attributes'] ?? null) ? $network['attributes'] : [];
        $element = $document->createElement('network');
        $element->setAttribute('id', (string) ($network['id'] ?? ''));
        $element->setAttribute('type', (string) ($network['type'] ?? 'bridge'));
        $element->setAttribute('name', (string) ($network['name'] ?? ''));
        $element->setAttribute('left', (string) (int) ($network['left'] ?? ($attributes['left'] ?? 0)));
        $element->setAttribute('top', (string) (int) ($network['top'] ?? ($attributes['top'] ?? 0)));
        $element->setAttribute('visibility', (string) ($attributes['visibility'] ?? '0'));
        $element->setAttribute('icon', (string) ($attributes['icon'] ?? 'lan.png'));
        $this->applyAttributes($element, $attributes, self::NETWORK_MANAGED);

        return $element;
    }

    /**
     * @param list<array{id: string, config: string}> $configs
     * @param list<array{id: string, node: array<string, mixed>}> $textObjects
     * @param array<string, mixed> $lab
     */
    private function objectsElement(DOMDocument $document, array $configs, array $textObjects, array $lab): ?DOMElement
    {
        $extra = array_values(array_filter(
            is_array($lab['objects_extra'] ?? null) ? $lab['objects_extra'] : [],
            'is_string'
        ));
        if ($configs === [] && $textObjects === [] && $extra === []) {
            return null;
        }

        $objects = $document->createElement('objects');
        if ($configs !== []) {
            $configsElement = $document->createElement('configs');
            foreach ($configs as $config) {
                $element = $document->createElement('config');
                $element->setAttribute('id', $config['id']);
                $element->appendChild($document->createTextNode(base64_encode($config['config'])));
                $configsElement->appendChild($element);
            }
            $objects->appendChild($configsElement);
        }
        if ($textObjects !== []) {
            $textElement = $document->createElement('textobjects');
            foreach ($textObjects as $textObject) {
                $node = $textObject['node'];
                $element = $document->createElement('textobject');
                $element->setAttribute('id', $textObject['id']);
                $element->setAttribute('name', (string) ($node['name'] ?? 'Text'));
                $element->setAttribute('type', 'text');
                $html = '<div style="position:absolute;left:' . (int) ($node['left'] ?? 200)
                    . 'px;top:' . (int) ($node['top'] ?? 200) . 'px;">'
                    . htmlspecialchars((string) ($node['name'] ?? 'Text'), ENT_QUOTES | ENT_XML1, 'UTF-8')
                    . '</div>';
                $data = $document->createElement('data');
                $data->appendChild($document->createTextNode(base64_encode($html)));
                $element->appendChild($data);
                $textElement->appendChild($element);
            }
            $objects->appendChild($textElement);
        }
        foreach ($extra as $xml) {
            $this->appendFragment($objects, $xml);
        }

        return $objects;
    }

    /**
     * @param array<string, mixed> $lab
     */
    private function extensionsElement(DOMDocument $document, array $lab): DOMElement
    {
        $extensions = is_array($lab['extensions'] ?? null) ? $lab['extensions'] : [];
        unset($extensions['pictures']);
        $pictures = [];
        foreach (($lab['node_list'] ?? []) as $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'picture') {
                continue;
            }
            $pictures[] = [
                'id' => (string) ($node['id'] ?? ''),
                'name' => (string) ($node['name'] ?? 'Picture'),
                'image' => (string) ($node['image'] ?? ''),
                'left' => (int) ($node['left'] ?? 200),
                'top' => (int) ($node['top'] ?? 200),
            ];
        }
        if ($pictures !== []) {
            $extensions['pictures'] = $pictures;
        }
        $extensions['version'] = 2;

        $element = $document->createElement('extensions');
        $element->setAttribute('format', '2');
        $json = json_encode($extensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $element->appendChild($document->createTextNode($json));

        return $element;
    }

    /**
     * @param array<string, mixed> $lab
     */
    private function nextNodeId(array $lab): int
    {
        $max = 0;
        foreach (($lab['node_list'] ?? []) as $node) {
            if (!is_array($node) || in_array($node['type'] ?? '', ['text', 'picture'], true)) {
                continue;
            }
            if (is_numeric($node['id'] ?? null)) {
                $max = max($max, (int) $node['id']);
            }
        }

        return $max + 1;
    }

    private function appendFragment(DOMElement $parent, mixed $xml): void
    {
        if (!is_string($xml) || $xml === '') {
            return;
        }
        $fragment = $parent->ownerDocument->createDocumentFragment();
        if (@$fragment->appendXML($xml)) {
            $parent->appendChild($fragment);
        }
    }

    /**
     * @param mixed $attributes
     * @param list<string> $managed
     */
    private function applyAttributes(DOMElement $element, mixed $attributes, array $managed): void
    {
        if (!is_array($attributes)) {
            return;
        }
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || $name === '' || in_array($name, $managed, true)) {
                continue;
            }
            if (is_scalar($value)) {
                $element->setAttribute($name, (string) $value);
            }
        }
    }
}
