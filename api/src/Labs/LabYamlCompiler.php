<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Templates\TemplateRepository;

/**
 * Pure YAML -> `LabService::create()` payload compiler.
 *
 * One YAML document describes one lab. Node and network keys are the identity;
 * numeric ids are assigned deterministically (declared networks in declaration
 * order, then one auto network per p2p link entry, then nodes in declaration
 * order). The compiler writes nothing and does no image-store lookup: unknown
 * image references stay portable strings and surface later as warnings.
 */
final class LabYamlCompiler
{
    public const MAX_YAML_BYTES = 10485760;
    public const MAX_CONFIG_BYTES = 1048576;
    public const MAX_NODES = 64;
    public const MAX_NETWORKS = 64;
    public const MAX_PORTS = 16;

    private const TOP_LEVEL = [
        'schema', 'name', 'author', 'description', 'body', 'tags',
        'layout', 'defaults', 'networks', 'links', 'nodes',
    ];

    private const NODE_FIELDS = [
        'template', 'image', 'ram', 'cpu', 'ports', 'console', 'serial',
        'nvram', 'delay', 'icon', 'position', 'config', 'links',
    ];

    private const DEFAULT_FIELDS = [
        'template', 'image', 'ram', 'cpu', 'ports', 'console', 'serial',
        'nvram', 'delay', 'icon',
    ];

    private const NETWORK_FIELDS = ['name', 'type', 'visibility', 'icon', 'position'];
    private const LAYOUT_FIELDS = ['mode', 'root', 'spacing'];
    private const POSITION_FIELDS = ['left', 'top'];
    private const LINK_FIELDS = ['endpoints', 'name'];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly LabYamlLayout $layout = new LabYamlLayout(),
    ) {
    }

    /**
     * @return array{
     *   payload: array<string, mixed>,
     *   source: array{kind: string, name: string, sha256: string, text: string},
     *   counts: array{nodes: int, networks: int, links: int}
     * }
     */
    public function compile(string $yaml, string $filename = 'lab.yaml'): array
    {
        if (strlen($yaml) > self::MAX_YAML_BYTES) {
            throw new HttpException(422, 'The YAML document is larger than 10 MB.');
        }
        if (trim($yaml) === '') {
            throw new HttpException(422, 'The YAML document is empty.');
        }
        $document = $this->parse($yaml);
        foreach (array_keys($document) as $key) {
            if (!in_array($key, self::TOP_LEVEL, true)) {
                throw new HttpException(422, 'Unknown top-level field "' . (string) $key . '".');
            }
        }
        if (($document['schema'] ?? null) !== 1) {
            throw new HttpException(422, 'The YAML document must declare schema: 1.');
        }

        $name = LabService::labName($document['name'] ?? null);
        $author = $this->optionalText($document, 'author', 'author');
        $description = $this->optionalText($document, 'description', 'description') ?? '';
        $body = $this->optionalText($document, 'body', 'body') ?? '';
        $tags = $this->tagList($document['tags'] ?? null);

        // Every one of these values ends up in an XML attribute or text node.
        $this->assertXmlSafe($name, 'Lab name');
        if ($author !== null) {
            $this->assertXmlSafe($author, 'author');
        }
        $this->assertXmlSafe($description, 'description');
        $this->assertXmlSafe($body, 'body');

        $layout = $this->layoutOptions($document['layout'] ?? null);
        $defaults = $this->defaultsOptions($document['defaults'] ?? null);
        $declared = $this->declaredNetworks($document['networks'] ?? null);
        $nodeSpecs = $this->nodeSpecs($document['nodes'] ?? null, $defaults);

        $links = $this->linkEntries($document['links'] ?? null, $nodeSpecs);

        $totalNetworks = count($declared) + count($links);
        if ($totalNetworks > self::MAX_NETWORKS) {
            throw new HttpException(
                422,
                'At most ' . self::MAX_NETWORKS . ' networks are allowed (declared networks plus generated link networks).'
            );
        }
        if ($layout['root'] !== null && !isset($nodeSpecs[$layout['root']])) {
            throw new HttpException(422, 'The layout root "' . $layout['root'] . '" is not a declared node.');
        }

        $networks = $this->networkPayload($declared, $links);
        $networkIDs = [];
        $networkNumber = 0;
        foreach (array_keys($declared) as $key) {
            $networkNumber++;
            $networkIDs[$key] = (string) $networkNumber;
        }

        $nodeIDs = [];
        foreach (array_keys($nodeSpecs) as $index => $key) {
            $nodeIDs[$key] = (string) ($index + 1);
        }

        $attachments = $this->attachments($nodeSpecs, $links, $networkIDs, count($declared));

        $nodes = [];
        $linkCount = 0;
        foreach ($nodeSpecs as $key => $spec) {
            $ports = $attachments[$key] ?? [];
            $linkCount += count($ports);
            $highest = $ports === [] ? -1 : max(array_keys($ports));
            $count = max($spec['ports'], $highest + 1);
            $interfaces = [];
            for ($index = 0; $index < $count; $index++) {
                $interfaces[] = [
                    'id' => $index,
                    'name' => $this->interfaceName($spec['kind'], $index),
                    'type' => 'ethernet',
                    'network_id' => $ports[$index] ?? '',
                ];
            }
            $node = [
                'id' => $nodeIDs[$key],
                'name' => $key,
                'type' => $spec['kind'],
                'template' => $spec['template'],
                'icon' => $spec['icon'],
                'image' => $spec['image'],
                'console' => $spec['console'],
                'cpu' => $spec['cpu'],
                'ram' => $spec['ram'],
                'serial' => $spec['serial'],
                'nvram' => $spec['nvram'],
                'delay' => $spec['delay'],
                'config' => $spec['config'],
                'interfaces' => $interfaces,
                'attributes' => [],
            ];
            foreach (['left', 'top'] as $axis) {
                if (isset($spec['position'][$axis])) {
                    $node[$axis] = $spec['position'][$axis];
                }
            }
            $nodes[] = $node;
        }

        [$nodes, $networks] = $this->layout->apply($nodes, $networks, $layout);

        // The full source is stored in the extensions marker, so it must be
        // representable in XML even when a control character sits in a comment.
        // Field-specific checks above already name the offending field.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $yaml) === 1
            || str_contains($yaml, "\u{FFFE}")
            || str_contains($yaml, "\u{FFFF}")
        ) {
            throw new HttpException(422, 'The YAML document contains characters that cannot be stored in the lab file.');
        }

        $payload = [
            'name' => $name,
            'description' => $description,
            'body' => $body,
            'tags' => $tags,
            'nodes' => $nodes,
            'networks' => $networks,
        ];
        if ($author !== null && $author !== '') {
            $payload['author'] = $author;
        }

        $sourceName = $this->sourceName($filename);

        return [
            'payload' => $payload,
            'source' => [
                'kind' => 'yaml',
                'name' => $sourceName,
                'sha256' => hash('sha256', $yaml),
                'text' => $yaml,
            ],
            'counts' => [
                'nodes' => count($nodes),
                'networks' => count($networks),
                'links' => $linkCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            $line = $exception->getParsedLine();
            $prefix = $line > 0 ? 'Invalid YAML at line ' . $line . ': ' : 'Invalid YAML: ';
            throw new HttpException(422, $prefix . $exception->getMessage());
        }
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw new HttpException(422, 'The YAML document must be a mapping.');
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function optionalText(array $document, string $key, string $label): ?string
    {
        if (!array_key_exists($key, $document) || $document[$key] === null) {
            return null;
        }
        if (!is_string($document[$key])) {
            throw new HttpException(422, ucfirst($label) . ' must be text.');
        }

        return $document[$key];
    }

    /**
     * @return list<string>
     */
    private function tagList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new HttpException(422, 'tags must be a list of strings.');
        }
        $tags = [];
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                throw new HttpException(422, 'tags must be a list of strings.');
            }
            if (trim($tag) !== '') {
                $this->assertXmlSafe($tag, 'tags');
                $tags[] = trim($tag);
            }
        }

        return $tags;
    }

    /**
     * @return array{mode: string, root: ?string, spacing: int}
     */
    private function layoutOptions(mixed $value): array
    {
        $options = ['mode' => 'tree', 'root' => null, 'spacing' => 240];
        if ($value === null) {
            return $options;
        }
        $map = $this->mapping($value, 'layout');
        $this->assertKnownFields($map, self::LAYOUT_FIELDS, 'layout');

        if (array_key_exists('mode', $map) && $map['mode'] !== null) {
            if (!is_string($map['mode']) || !in_array($map['mode'], ['grid', 'tree'], true)) {
                throw new HttpException(422, 'layout.mode must be "grid" or "tree".');
            }
            $options['mode'] = $map['mode'];
        }
        if (array_key_exists('root', $map) && $map['root'] !== null) {
            if (!is_string($map['root']) || trim($map['root']) === '') {
                throw new HttpException(422, 'layout.root must be a node name.');
            }
            $this->assertXmlSafe(trim($map['root']), 'layout.root');
            $options['root'] = trim($map['root']);
        }
        if (array_key_exists('spacing', $map) && $map['spacing'] !== null) {
            if (!is_int($map['spacing']) || $map['spacing'] < 1 || $map['spacing'] > 10000) {
                throw new HttpException(422, 'layout.spacing must be an integer between 1 and 10000.');
            }
            $options['spacing'] = $map['spacing'];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsOptions(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $map = $this->mapping($value, 'defaults');
        $this->assertKnownFields($map, self::DEFAULT_FIELDS, 'defaults');
        $defaults = [];
        foreach ($map as $key => $default) {
            if ($default === null) {
                continue;
            }
            if (in_array($key, ['cpu', 'ram', 'ports', 'serial', 'nvram', 'delay'], true)) {
                if (!is_int($default)) {
                    throw new HttpException(422, 'defaults.' . $key . ' must be an integer.');
                }
            } elseif (!is_string($default)) {
                throw new HttpException(422, 'defaults.' . $key . ' must be text.');
            }
            $defaults[$key] = $default;
        }

        return $defaults;
    }

    /**
     * @return array<string, array{name: string, type: string, attributes: array<string, string>, position: array<string, int>}>
     */
    private function declaredNetworks(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new HttpException(422, 'networks must be a mapping of network keys.');
        }
        $networks = [];
        foreach ($value as $key => $body) {
            if (!is_string($key) || !$this->validKey($key)) {
                throw new HttpException(422, 'Network keys must be non-empty text without control characters.');
            }
            if (array_key_exists($key, $networks)) {
                throw new HttpException(422, 'Duplicate network key "' . $key . '".');
            }
            // The key is the default network name, so it reaches XML too.
            $this->assertXmlSafe($key, 'Network name');
            $networks[$key] = $this->networkOptions($key, $body);
        }
        if (count($networks) > self::MAX_NETWORKS) {
            throw new HttpException(422, 'At most ' . self::MAX_NETWORKS . ' declared networks are allowed.');
        }

        return $networks;
    }

    /**
     * @return array{name: string, type: string, attributes: array<string, string>, position: array<string, int>}
     */
    private function networkOptions(string $key, mixed $value): array
    {
        $context = 'Network "' . $key . '"';
        $body = $value === null ? [] : $this->mapping($value, $context);
        $this->assertKnownFields($body, self::NETWORK_FIELDS, $context);

        $name = $key;
        if (array_key_exists('name', $body) && $body['name'] !== null) {
            if (!is_string($body['name'])) {
                throw new HttpException(422, $context . ': name must be text.');
            }
            if (trim($body['name']) !== '') {
                $name = trim($body['name']);
            }
        }
        $this->assertXmlSafe($name, 'Network "' . $key . '" name');
        $type = 'bridge';
        if (array_key_exists('type', $body) && $body['type'] !== null) {
            if (!is_string($body['type']) || trim($body['type']) === '') {
                throw new HttpException(422, $context . ': type must be text.');
            }
            $type = trim($body['type']);
        }
        $this->assertXmlSafe($type, 'Network "' . $key . '" type');
        $attributes = [];
        if (array_key_exists('visibility', $body) && $body['visibility'] !== null) {
            $visibility = $body['visibility'];
            if (is_bool($visibility)) {
                $visibility = $visibility ? 1 : 0;
            }
            if (!is_int($visibility) || !in_array($visibility, [0, 1], true)) {
                throw new HttpException(422, $context . ': visibility must be 0 or 1.');
            }
            $attributes['visibility'] = (string) $visibility;
        }
        if (array_key_exists('icon', $body) && $body['icon'] !== null) {
            if (!is_string($body['icon'])) {
                throw new HttpException(422, $context . ': icon must be text.');
            }
            if (trim($body['icon']) !== '') {
                $this->assertXmlSafe(trim($body['icon']), 'Network "' . $key . '" icon');
                $attributes['icon'] = trim($body['icon']);
            }
        }

        return [
            'name' => $name,
            'type' => $type,
            'attributes' => $attributes,
            'position' => $this->position($body['position'] ?? null, $context),
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, array{
     *   template: string,
     *   kind: string,
     *   icon: string,
     *   image: string,
     *   console: string,
     *   cpu: int,
     *   ram: int,
     *   serial: int,
     *   nvram: int,
     *   delay: int,
     *   ports: int,
     *   config: string,
     *   position: array<string, int>,
     *   links: array<string, string>
     * }>
     */
    private function nodeSpecs(mixed $value, array $defaults): array
    {
        if ($value === null) {
            throw new HttpException(422, 'nodes is required.');
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new HttpException(422, 'nodes must be a mapping of node keys.');
        }
        if (count($value) > self::MAX_NODES) {
            throw new HttpException(422, 'At most ' . self::MAX_NODES . ' nodes are allowed.');
        }

        $nodes = [];
        foreach ($value as $key => $body) {
            if (!is_string($key) || !$this->validKey($key)) {
                throw new HttpException(422, 'Node keys must be non-empty text without control characters.');
            }
            if (array_key_exists($key, $nodes)) {
                throw new HttpException(422, 'Duplicate node key "' . $key . '".');
            }
            // The key is the node name written to the .unl file.
            $this->assertXmlSafe($key, 'Node name');
            $nodes[$key] = $this->nodeSpec($key, $body, $defaults);
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array{
     *   template: string,
     *   kind: string,
     *   icon: string,
     *   image: string,
     *   console: string,
     *   cpu: int,
     *   ram: int,
     *   serial: int,
     *   nvram: int,
     *   delay: int,
     *   ports: int,
     *   config: string,
     *   position: array<string, int>,
     *   links: array<string, string>
     * }
     */
    private function nodeSpec(string $key, mixed $value, array $defaults): array
    {
        $context = 'Node "' . $key . '"';
        $body = $value === null ? [] : $this->mapping($value, $context);
        $this->assertKnownFields($body, self::NODE_FIELDS, $context);

        $templateID = null;
        foreach ([['Node "' . $key . '"', $body], ['defaults', $defaults]] as [$owner, $source]) {
            if (!array_key_exists('template', $source) || $source['template'] === null) {
                continue;
            }
            if (!is_string($source['template']) || trim($source['template']) === '') {
                throw new HttpException(422, $owner . ': template must be a template id.');
            }
            $templateID = trim($source['template']);
            break;
        }
        if ($templateID === null) {
            throw new HttpException(
                422,
                $context . ': template is required (set "template" on the node or in "defaults").'
            );
        }
        $this->assertXmlSafe($templateID, 'template id');
        $template = $this->templates->find($templateID);
        if ($template === null) {
            throw new HttpException(422, $context . ': template "' . $templateID . '" was not found.');
        }
        $templateDefaults = is_array($template['defaults'] ?? null) ? $template['defaults'] : [];
        $kind = is_string($template['kind'] ?? null) ? $template['kind'] : 'qemu';

        $templateContext = 'template "' . $templateID . '"';
        $cpu = $this->intChain('cpu', [
            [$context, $body, 'cpu'],
            ['defaults', $defaults, 'cpu'],
            [$templateContext, $templateDefaults, 'cpu'],
        ], 1);
        $ram = $this->intChain('ram', [
            [$context, $body, 'ram'],
            ['defaults', $defaults, 'ram'],
            [$templateContext, $templateDefaults, 'ram'],
        ], 512);
        $serial = $this->intChain('serial', [
            [$context, $body, 'serial'],
            ['defaults', $defaults, 'serial'],
            [$templateContext, $templateDefaults, 'serial'],
        ], 0);
        $nvram = $this->intChain('nvram', [
            [$context, $body, 'nvram'],
            ['defaults', $defaults, 'nvram'],
        ], 0);
        $delay = $this->intChain('delay', [
            [$context, $body, 'delay'],
            ['defaults', $defaults, 'delay'],
        ], 0);
        $ports = $this->intChain('ports', [
            [$context, $body, 'ports'],
            ['defaults', $defaults, 'ports'],
            [$templateContext, $templateDefaults, 'ethernet'],
        ], 0);
        if ($ports < 0) {
            throw new HttpException(422, $context . ': ports must be 0 or more.');
        }
        if ($ports > self::MAX_PORTS) {
            throw new HttpException(422, $context . ': at most ' . self::MAX_PORTS . ' ports are allowed.');
        }

        $image = $this->stringChain('image', [
            [$context, $body],
            ['defaults', $defaults],
        ], '');
        $console = $this->stringChain('console', [
            [$context, $body],
            ['defaults', $defaults],
            [$templateContext, $templateDefaults],
        ], 'telnet');
        $icon = $this->stringChain('icon', [
            [$context, $body],
            ['defaults', $defaults],
            [$templateContext, $templateDefaults],
        ], '');
        if ($icon === '' && is_string($template['icon'] ?? null)) {
            $icon = (string) $template['icon'];
        }
        $this->assertXmlSafe($image, $context . ': image');
        $this->assertXmlSafe($console, $context . ': console');
        $this->assertXmlSafe($icon, $context . ': icon');

        $config = '';
        if (array_key_exists('config', $body) && $body['config'] !== null) {
            if (!is_string($body['config'])) {
                throw new HttpException(422, $context . ': config must be text.');
            }
            $config = $body['config'];
            if (strlen($config) > self::MAX_CONFIG_BYTES) {
                throw new HttpException(422, $context . ': config is larger than 1 MB.');
            }
            if ($config !== '' && preg_match('//u', $config) !== 1) {
                throw new HttpException(422, $context . ': config must be UTF-8 text.');
            }
        }

        $links = [];
        if (array_key_exists('links', $body) && $body['links'] !== null) {
            $map = $this->mapping($body['links'], $context . ': links');
            foreach ($map as $portName => $network) {
                $portName = (string) $portName;
                if (!is_string($network)) {
                    throw new HttpException(422, $context . ': links must map interface names to network keys.');
                }
                if (trim($portName) === '' || trim($network) === '') {
                    throw new HttpException(422, $context . ': links must map interface names to network keys.');
                }
                $links[trim($portName)] = trim($network);
            }
        }

        return [
            'template' => $templateID,
            'kind' => $kind,
            'icon' => $icon,
            'image' => $image,
            'console' => $console,
            'cpu' => max(1, $cpu),
            'ram' => max(64, $ram),
            'serial' => max(0, min(8, $serial)),
            'nvram' => max(0, min(4096, $nvram)),
            'delay' => max(0, min(3600, $delay)),
            'ports' => $ports,
            'config' => $config,
            'position' => $this->position($body['position'] ?? null, $context),
            'links' => $links,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @return list<array{endpoints: list<array{node: string, port: int, port_name: string}>, name: ?string}>
     */
    private function linkEntries(mixed $value, array $nodes): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new HttpException(422, 'links must be a list of point-to-point entries.');
        }
        $links = [];
        foreach ($value as $index => $entry) {
            $links[] = $this->linkEntry($index + 1, $entry, $nodes);
        }

        return $links;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @return array{endpoints: list<array{node: string, port: int, port_name: string}>, name: ?string}
     */
    private function linkEntry(int $ordinal, mixed $value, array $nodes): array
    {
        $context = 'Link ' . $ordinal;
        if (!is_array($value)) {
            throw new HttpException(422, $context . ': expected a two-endpoint list or a mapping with "endpoints".');
        }

        $name = null;
        if (array_is_list($value)) {
            if (count($value) !== 2) {
                throw new HttpException(422, $context . ': a point-to-point link needs exactly two endpoints.');
            }
            $endpoints = $value;
        } else {
            $this->assertKnownFields($value, self::LINK_FIELDS, $context);
            $endpoints = $value['endpoints'] ?? null;
            if (!is_array($endpoints) || !array_is_list($endpoints) || count($endpoints) !== 2) {
                throw new HttpException(422, $context . ': endpoints must list exactly two interfaces.');
            }
            if (array_key_exists('name', $value) && $value['name'] !== null) {
                if (!is_string($value['name'])) {
                    throw new HttpException(422, $context . ': name must be text.');
                }
                if (trim($value['name']) !== '') {
                    $name = trim($value['name']);
                }
            }
        }

        if ($name !== null) {
            $this->assertXmlSafe($name, $context . ': name');
        }

        $resolved = [];
        foreach ($endpoints as $endpoint) {
            if (!is_string($endpoint)) {
                throw new HttpException(422, $context . ': endpoints must be "node.interface" strings.');
            }
            $this->assertXmlSafe($endpoint, $context . ': endpoint');
            $dot = strrpos($endpoint, '.');
            if ($dot === false || $dot === 0 || $dot === strlen($endpoint) - 1) {
                throw new HttpException(
                    422,
                    $context . ': endpoint "' . $endpoint . '" must be written as "node.interface".'
                );
            }
            $nodeKey = substr($endpoint, 0, $dot);
            $portName = substr($endpoint, $dot + 1);
            if (!isset($nodes[$nodeKey])) {
                throw new HttpException(422, $context . ': node "' . $nodeKey . '" is not declared.');
            }
            $resolved[] = [
                'node' => $nodeKey,
                'port' => $this->linkedPort($nodeKey, $portName),
                'port_name' => $portName,
            ];
        }
        if ($resolved[0]['node'] === $resolved[1]['node']) {
            throw new HttpException(422, $context . ': a node cannot link to itself.');
        }

        return ['endpoints' => $resolved, 'name' => $name];
    }

    /**
     * @param array<string, array{name: string, type: string, attributes: array<string, string>, position: array<string, int>}> $declared
     * @param list<array{endpoints: list<array{node: string, port: int, port_name: string}>, name: ?string}> $links
     * @return list<array<string, mixed>>
     */
    private function networkPayload(array $declared, array $links): array
    {
        $networks = [];
        foreach ($declared as $options) {
            $network = [
                'id' => (string) (count($networks) + 1),
                'name' => $options['name'],
                'type' => $options['type'],
                'attributes' => $options['attributes'],
            ];
            if (isset($options['attributes']['visibility'])) {
                $network['visibility'] = (int) $options['attributes']['visibility'];
            }
            if (isset($options['attributes']['icon'])) {
                $network['icon'] = $options['attributes']['icon'];
            }
            foreach (['left', 'top'] as $axis) {
                if (isset($options['position'][$axis])) {
                    $network[$axis] = $options['position'][$axis];
                }
            }
            $networks[] = $network;
        }
        foreach ($links as $index => $link) {
            $networks[] = [
                'id' => (string) (count($networks) + 1),
                'name' => $link['name'] ?? ('link-' . ($index + 1)),
                'type' => 'bridge',
                'attributes' => [],
            ];
        }

        return $networks;
    }

    /**
     * Resolve every authored link to a concrete interface index and network id.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array{endpoints: list<array{node: string, port: int, port_name: string}>, name: ?string}> $links
     * @param array<string, string> $networkIDs
     * @return array<string, array<int, string>>
     */
    private function attachments(array $nodes, array $links, array $networkIDs, int $declaredCount): array
    {
        $attachments = [];
        foreach ($nodes as $key => $spec) {
            /** @var array<string, string> $nodeLinks */
            $nodeLinks = $spec['links'];
            foreach ($nodeLinks as $portName => $networkKey) {
                $portName = (string) $portName;
                if (!isset($networkIDs[$networkKey])) {
                    throw new HttpException(422, 'Node "' . $key . '": network "' . $networkKey . '" is not declared.');
                }
                $port = $this->linkedPort($key, $portName);
                $this->assertFreePort($attachments, $key, $port, $portName);
                $attachments[$key][$port] = $networkIDs[$networkKey];
            }
        }
        foreach ($links as $index => $link) {
            $networkID = (string) ($declaredCount + $index + 1);
            foreach ($link['endpoints'] as $endpoint) {
                $this->assertFreePort($attachments, $endpoint['node'], $endpoint['port'], $endpoint['port_name']);
                $attachments[$endpoint['node']][$endpoint['port']] = $networkID;
            }
        }

        return $attachments;
    }

    /**
     * @param array<string, array<int, string>> $attachments
     */
    private function assertFreePort(array $attachments, string $nodeKey, int $port, string $portName): void
    {
        if (isset($attachments[$nodeKey][$port])) {
            throw new HttpException(
                422,
                'Node "' . $nodeKey . '": interface "' . $portName . '" is linked more than once.'
            );
        }
    }

    private function linkedPort(string $nodeKey, string $portName): int
    {
        $index = $this->portIndex($portName);
        if ($index === null) {
            throw new HttpException(
                422,
                'Node "' . $nodeKey . '": interface "' . $portName . '" cannot be parsed.'
            );
        }
        if ($index >= self::MAX_PORTS) {
            throw new HttpException(
                422,
                'Node "' . $nodeKey . '": interface "' . $portName . '" exceeds the '
                    . self::MAX_PORTS . '-port limit.'
            );
        }

        return $index;
    }

    /**
     * The same interface grammar the .unl parser accepts: `e0/0`, `eth0`,
     * `e0`, `ethernet0/0`, or a plain integer index.
     */
    private function portIndex(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (preg_match('/^(?:ethernet|e)(\d+)\/(\d+)$/i', $name, $match) === 1) {
            $slot = (int) $match[1];
            $port = (int) $match[2];
            if ($slot > 255 || $port > 255) {
                return null;
            }

            return $slot * 4 + $port;
        }
        if (preg_match('/^(?:eth|ethernet|e)(\d+)$/i', $name, $match) === 1) {
            $index = (int) $match[1];

            return $index <= 255 ? $index : null;
        }
        if (preg_match('/^\d+$/', $name) === 1) {
            $index = (int) $name;

            return $index <= 255 ? $index : null;
        }

        return null;
    }

    private function interfaceName(string $kind, int $index): string
    {
        return match ($kind) {
            'iol' => 'e' . intdiv($index, 4) . '/' . ($index % 4),
            'vpcs' => 'eth' . $index,
            default => 'e' . $index,
        };
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $sources
     */
    private function intChain(string $field, array $sources, int $default): int
    {
        foreach ($sources as [$owner, $map, $key]) {
            if (!array_key_exists($key, $map) || $map[$key] === null) {
                continue;
            }
            if (!is_int($map[$key])) {
                throw new HttpException(422, $owner . ': ' . $field . ' must be an integer.');
            }

            return $map[$key];
        }

        return $default;
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $sources
     */
    private function stringChain(string $field, array $sources, string $default): string
    {
        foreach ($sources as [$owner, $map]) {
            if (!array_key_exists($field, $map) || $map[$field] === null) {
                continue;
            }
            if (!is_string($map[$field])) {
                throw new HttpException(422, $owner . ': ' . $field . ' must be text.');
            }

            return $map[$field];
        }

        return $default;
    }

    /**
     * @return array<string, int>
     */
    private function position(mixed $value, string $context): array
    {
        if ($value === null) {
            return [];
        }
        $map = $this->mapping($value, $context . ': position');
        $this->assertKnownFields($map, self::POSITION_FIELDS, $context . ': position');
        $position = [];
        foreach (self::POSITION_FIELDS as $axis) {
            if (!array_key_exists($axis, $map) || $map[$axis] === null) {
                continue;
            }
            if (!is_int($map[$axis])) {
                throw new HttpException(422, $context . ': position.' . $axis . ' must be an integer.');
            }
            $position[$axis] = max(0, $map[$axis]);
        }

        return $position;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(mixed $value, string $context): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new HttpException(422, $context . ' must be a mapping.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $known
     */
    private function assertKnownFields(array $body, array $known, string $context): void
    {
        foreach (array_keys($body) as $field) {
            if (!in_array($field, $known, true)) {
                throw new HttpException(422, $context . ': unknown field "' . (string) $field . '".');
            }
        }
    }

    /**
     * The filename is stored verbatim in the extensions JSON, so it must not
     * carry bytes that json_encode/DOMDocument cannot represent.
     */
    private function sourceName(string $filename): string
    {
        $name = basename($filename);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'lab.yaml';
        }
        if (preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || str_contains($name, "\u{FFFE}")
            || str_contains($name, "\u{FFFF}")
        ) {
            return 'lab.yaml';
        }

        return $name;
    }

    /**
     * Values that reach the XML writer must be consumable by DOMDocument.
     *
     * Symfony YAML already rejects most malformed byte sequences, but aliases
     * and binary tags can still smuggle invalid UTF-8 or XML control
     * characters into a string; catching it here keeps a compile failure a
     * clean 422 instead of an unhandled writer error.
     */
    private function assertXmlSafe(string $value, string $context): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new HttpException(422, $context . ' must be valid UTF-8.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new HttpException(
                422,
                $context . ' contains control characters that cannot be stored in the lab file.'
            );
        }
        if (str_contains($value, "\u{FFFE}") || str_contains($value, "\u{FFFF}")) {
            throw new HttpException(
                422,
                $context . ' contains Unicode non-characters that cannot be stored in the lab file.'
            );
        }
    }

    private function validKey(string $key): bool
    {
        return trim($key) !== ''
            && strlen($key) <= 64
            && preg_match('/[\x00-\x1f]/', $key) !== 1
            && $key === trim($key);
    }
}
