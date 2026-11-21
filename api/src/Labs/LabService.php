<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Snapshots\SnapshotRepository;
use TeraLab\Api\Templates\QemuOptions;
use TeraLab\Api\Templates\TemplateRepository;

final class LabService
{
    private const TRASH_TTL = 2592000; // 30 days

    public function __construct(
        private readonly Config $config,
        private readonly UnlParser $parser,
        private readonly UnlWriter $writer,
        private readonly AgentClient $agent,
        private readonly LabLocks $locks,
        private readonly ImageReferences $images,
        private readonly ?SnapshotRepository $snapshots = null,
        private readonly ?TemplateRepository $templates = null,
    ) {
    }

    /** @var array<string, array<string, string>> Template id => resolved QEMU spec fields. */
    private array $templateOptions = [];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?string $author = null, ?string $ownerId = null): array
    {
        $name = $this->labName($input['name'] ?? null);
        [$nodes, $networks] = $this->pruneLinks(
            $this->normalizeNodes($input['nodes'] ?? []),
            $this->normalizeNetworks($input['networks'] ?? []),
        );
        $lab = [
            'id' => $this->uuid(),
            'name' => $name,
            'author' => $author ?? '',
            'description' => $this->text($input['description'] ?? ''),
            'body' => $this->text($input['body'] ?? ''),
            'version' => '1',
            'revision' => 1,
            'node_list' => $nodes,
            'network_list' => $networks,
            'attributes' => array_filter([
                'owner' => $ownerId,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'tags' => $this->tags($input['tags'] ?? []),
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'extra_xml' => [],
        ];

        $path = $this->targetPath($name, null);
        $lab = $this->write($path, $lab);

        return $this->metadata($lab, $path);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(string $id, array $input, ?int $expectedRevision, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab, $path] = $this->load($id);
        if ($userId !== null) {
            $this->locks->assertEditable((string) $lab['id'], $userId, $isAdmin);
        }
        $revision = (int) $lab['revision'];
        if ($expectedRevision !== null && $expectedRevision !== $revision) {
            throw new HttpException(409, 'The lab was modified by another session.', [
                'revision' => $revision,
            ]);
        }

        $lab['name'] = $this->labName($input['name'] ?? $lab['name']);
        $lab['description'] = $this->text($input['description'] ?? $lab['description']);
        $lab['body'] = $this->text($input['body'] ?? $lab['body']);
        $nodes = array_key_exists('nodes', $input)
            ? $this->normalizeNodes($input['nodes'])
            : $this->labNodes($lab);
        $networks = array_key_exists('networks', $input)
            ? $this->normalizeNetworks($input['networks'])
            : $this->labNetworks($lab);
        [$prunedNodes, $prunedNetworks] = $this->pruneLinks($nodes, $networks);
        if (array_key_exists('nodes', $input) || $prunedNodes !== $nodes) {
            $lab['node_list'] = $prunedNodes;
        }
        if (array_key_exists('networks', $input) || $prunedNetworks !== $networks) {
            $lab['network_list'] = $prunedNetworks;
        }
        $lab['revision'] = $revision + 1;
        $lab['attributes']['updated_at'] = gmdate('c');
        if (array_key_exists('tags', $input)) {
            $tags = $this->tags($input['tags']);
            if ($tags === '') {
                unset($lab['attributes']['tags']);
            } else {
                $lab['attributes']['tags'] = $tags;
            }
        }
        $lab = $this->write($path, $lab);

        return $this->metadata($lab, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFileInterface $file, ?string $author, ?string $ownerId = null): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'The lab upload did not complete.');
        }
        $stream = $file->getStream();
        $stream->rewind();
        $xml = $stream->getContents();
        if ($xml === '' || strlen($xml) > 10485760) {
            throw new HttpException(422, 'The lab file is empty or too large.');
        }

        $lab = $this->parser->parseXml($xml);
        [$lab['node_list'], $lab['network_list']] = $this->pruneLinks(
            $this->labNodes($lab),
            $this->labNetworks($lab),
        );
        $lab['id'] = $this->uuid();
        $lab['author'] = $lab['author'] !== '' ? $lab['author'] : ($author ?? '');
        $lab['revision'] = 1;
        if ($ownerId !== null) {
            $lab['attributes']['owner'] = $ownerId;
        }
        $lab['attributes']['created_at'] = gmdate('c');
        $lab['attributes']['updated_at'] = gmdate('c');

        $path = $this->targetPath($this->labName($lab['name']), null);
        $lab = $this->write($path, $lab);

        return $this->metadata($lab, $path);
    }

    /**
     * @return array{filename: string, xml: string}
     */
    public function export(string $id): array
    {
        [$lab] = $this->load($id);

        return [
            'filename' => $this->slug((string) $lab['name']) . '.unl',
            'xml' => $this->writer->write($lab),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshots(string $id): array
    {
        [$lab] = $this->load($id);
        $repository = $this->snapshotRepository();

        return $repository->all((string) $lab['id']);
    }

    /**
     * @return array<string, mixed>
     */
    public function createSnapshot(string $id, string $name, ?string $userId): array
    {
        [$lab] = $this->load($id);
        $name = trim($name);
        if ($name === '' || strlen($name) > 128) {
            throw new HttpException(422, 'Snapshot name is required.');
        }

        return $this->snapshotRepository()->create(
            (string) $lab['id'],
            $name,
            (int) $lab['revision'],
            $this->writer->write($this->normaliseReferences($lab)),
            $userId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreSnapshot(string $id, string $snapshotID, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab, $path] = $this->load($id);
        if ($userId !== null) {
            $this->locks->assertEditable((string) $lab['id'], $userId, $isAdmin);
        }
        $snapshot = $this->snapshotRepository()->find((string) $lab['id'], $snapshotID);
        if ($snapshot === null) {
            throw new HttpException(404, 'Snapshot not found.');
        }
        $restored = $this->parser->parseXml((string) $snapshot['xml']);
        $restored['id'] = $lab['id'];
        $restored['revision'] = (int) $lab['revision'] + 1;
        $restored['attributes']['updated_at'] = gmdate('c');
        $restored = $this->write($path, $restored);

        return $this->metadata($restored, $path);
    }

    public function deleteSnapshot(string $id, string $snapshotID): void
    {
        [$lab] = $this->load($id);
        if (!$this->snapshotRepository()->delete((string) $lab['id'], $snapshotID)) {
            throw new HttpException(404, 'Snapshot not found.');
        }
    }

    private function snapshotRepository(): SnapshotRepository
    {
        if ($this->snapshots === null) {
            throw new HttpException(500, 'Snapshot storage is not configured.');
        }

        return $this->snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    public function duplicate(string $id, ?string $ownerId = null): array
    {
        [$lab] = $this->load($id);
        $lab['id'] = $this->uuid();
        $lab['name'] = $this->labName($lab['name'] . ' copy');
        $lab['revision'] = 1;
        $lab['attributes']['owner'] = $ownerId;
        $lab['attributes']['created_at'] = gmdate('c');
        $lab['attributes']['updated_at'] = gmdate('c');

        $path = $this->targetPath($this->slug((string) $lab['name']), null);
        $lab = $this->write($path, $lab);

        return $this->metadata($lab, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function move(string $id, string $folder, ?string $ownerId = null): array
    {
        [$lab, $oldPath] = $this->load($id);
        $folder = trim($folder, '/');
        if ($folder !== '' && preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]{0,127}$#', $folder) !== 1) {
            throw new HttpException(422, 'Invalid lab folder.');
        }
        if (str_contains($folder, '..')) {
            throw new HttpException(422, 'Invalid lab folder.');
        }
        $directory = $this->config->labsDir . ($folder !== '' ? '/' . $folder : '');
        $path = $this->targetPath($this->slug((string) $lab['name']), $folder === '' ? null : $folder);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the lab folder.');
        }
        $lab['attributes']['updated_at'] = gmdate('c');
        if ($ownerId !== null && !isset($lab['attributes']['owner'])) {
            $lab['attributes']['owner'] = $ownerId;
        }
        $lab = $this->write($path, $lab);
        if ($oldPath !== $path) {
            @unlink($oldPath);
        }

        return $this->metadata($lab, $path);
    }

    public function delete(string $id, ?string $userId = null): void
    {
        [$lab, $path] = $this->load($id);
        $this->assertNoRunningNodes($lab);
        $this->purgeExpiredTrash();

        $trash = $this->trashDir();
        if (!is_dir($trash) && !mkdir($trash, 0770, true) && !is_dir($trash)) {
            throw new RuntimeException('Cannot create the trash directory.');
        }
        $token = bin2hex(random_bytes(8));
        $target = $trash . '/' . $token . '.unl';
        if (!rename($path, $target)) {
            throw new RuntimeException('Cannot move the lab to trash.');
        }
        $metadata = [
            'id' => (string) $lab['id'],
            'name' => (string) $lab['name'],
            'original_path' => ltrim(str_replace($this->config->labsDir, '', $path), '/'),
            'deleted_by' => $userId,
            'deleted_at' => time(),
        ];
        file_put_contents(
            $trash . '/' . $token . '.json',
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trash(): array
    {
        $this->purgeExpiredTrash();
        $directory = $this->trashDir();
        if (!is_dir($directory)) {
            return [];
        }
        $entries = [];
        foreach (glob($directory . '/*.json') ?: [] as $metadataFile) {
            $metadata = json_decode((string) file_get_contents($metadataFile), true);
            if (!is_array($metadata)) {
                continue;
            }
            $token = basename($metadataFile, '.json');
            $labFile = $directory . '/' . $token . '.unl';
            if (!is_file($labFile)) {
                continue;
            }
            try {
                $lab = $this->parser->parse($labFile);
            } catch (\Throwable) {
                continue;
            }
            $entries[] = [
                'id' => (string) $lab['id'],
                'name' => (string) ($metadata['name'] ?? $lab['name']),
                'original_path' => (string) ($metadata['original_path'] ?? ''),
                'deleted_at' => (int) ($metadata['deleted_at'] ?? 0),
                'deleted_by' => $metadata['deleted_by'] ?? null,
                'expires_at' => (int) ($metadata['deleted_at'] ?? 0) + self::TRASH_TTL,
                'trash_token' => $token,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => $b['deleted_at'] <=> $a['deleted_at']);

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(string $id): array
    {
        $this->purgeExpiredTrash();
        $directory = $this->trashDir();
        if (!is_dir($directory)) {
            throw new HttpException(404, 'Lab not found in trash.');
        }
        foreach ($this->trash() as $entry) {
            if ((string) $entry['id'] !== $id && (string) $entry['name'] !== $id) {
                continue;
            }
            $token = (string) $entry['trash_token'];
            $labFile = $directory . '/' . $token . '.unl';
            $lab = $this->parser->parse($labFile);
            $folder = dirname((string) $entry['original_path']);
            $folder = $folder === '.' ? '' : $folder;
            $path = $this->targetPath($this->slug((string) $lab['name']), $folder === '' ? null : $folder);
            if (!rename($labFile, $path)) {
                throw new RuntimeException('Cannot restore the lab.');
            }
            @unlink($directory . '/' . $token . '.json');

            return $this->metadata($lab, $path);
        }

        throw new HttpException(404, 'Lab not found in trash.');
    }

    public function purge(string $id): void
    {
        $directory = $this->trashDir();
        foreach ($this->trash() as $entry) {
            if ((string) $entry['id'] !== $id && (string) $entry['name'] !== $id) {
                continue;
            }
            $token = (string) $entry['trash_token'];
            @unlink($directory . '/' . $token . '.unl');
            @unlink($directory . '/' . $token . '.json');

            return;
        }

        throw new HttpException(404, 'Lab not found in trash.');
    }

    private function trashDir(): string
    {
        return $this->config->labsDir . '/.trash';
    }

    private function purgeExpiredTrash(): void
    {
        $directory = $this->trashDir();
        if (!is_dir($directory)) {
            return;
        }
        $cutoff = time() - self::TRASH_TTL;
        foreach (glob($directory . '/*.json') ?: [] as $metadataFile) {
            $metadata = json_decode((string) file_get_contents($metadataFile), true);
            if (!is_array($metadata) || (int) ($metadata['deleted_at'] ?? 0) > $cutoff) {
                continue;
            }
            $token = basename($metadataFile, '.json');
            @unlink($directory . '/' . $token . '.unl');
            @unlink($metadataFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $id, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab] = $this->load($id);
        $nodes = $this->engineNodes($lab);
        if ($nodes === []) {
            throw new HttpException(422, 'The lab has no engine nodes.');
        }

        if ($userId !== null) {
            $this->locks->acquire((string) $lab['id'], $userId, $isAdmin);
        }
        $bridges = $this->ensureNetworks($lab);
        $started = [];
        foreach ($nodes as $node) {
            try {
                $started[] = $this->agent->start($this->spec($lab, $node, $bridges));
            } catch (HttpException $exception) {
                foreach ($started as $info) {
                    $this->safeStop($this->stringValue($info, 'id'));
                }
                $this->cleanupLabIfStopped((string) $lab['id']);
                if ($userId !== null) {
                    $this->locks->release((string) $lab['id'], $userId, $isAdmin);
                }
                throw new HttpException(
                    $exception->status,
                    'Node ' . ($node['name'] !== '' ? $node['name'] : $node['id']) . ': ' . $exception->getMessage(),
                );
            }
        }

        return ['lab_id' => $lab['id'], 'nodes' => $started];
    }

    /**
     * Running node counts keyed by lab id, from the agent's in-memory state.
     *
     * @return array<string, int>
     */
    public function runningLabs(): array
    {
        try {
            $status = $this->agent->status();
        } catch (HttpException) {
            return [];
        }

        $counts = [];
        foreach ((array) ($status['nodes'] ?? []) as $node) {
            if (!is_array($node) || ($node['state'] ?? '') !== 'running') {
                continue;
            }
            $labID = (string) ($node['lab_id'] ?? '');
            if ($labID === '') {
                continue;
            }
            $counts[$labID] = ($counts[$labID] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $id, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab] = $this->load($id);
        $stopped = [];
        foreach ($this->engineNodes($lab) as $node) {
            $agentID = $this->agentNodeID((string) $lab['id'], (string) $node['id']);
            try {
                $result = $this->agent->stop($agentID);
                $stopped[] = ['node_id' => $node['id'], 'agent_id' => $agentID, 'result' => $result];
            } catch (HttpException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
        }
        $this->safeCleanupLab((string) $lab['id']);
        if ($userId !== null) {
            $this->locks->release((string) $lab['id'], $userId, $isAdmin);
        }

        return ['lab_id' => $lab['id'], 'stopped' => $stopped];
    }

    /**
     * @return array<string, mixed>
     */
    public function wipe(string $id, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab] = $this->load($id);
        $wiped = [];
        foreach ($this->engineNodes($lab) as $node) {
            $agentID = $this->agentNodeID((string) $lab['id'], (string) $node['id']);
            try {
                $this->agent->stop($agentID);
            } catch (HttpException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
            try {
                $result = $this->agent->wipe($agentID);
                $wiped[] = ['node_id' => $node['id'], 'agent_id' => $agentID, 'result' => $result];
            } catch (HttpException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
        }
        $this->safeCleanupLab((string) $lab['id']);
        if ($userId !== null) {
            $this->locks->release((string) $lab['id'], $userId, $isAdmin);
        }

        return ['lab_id' => $lab['id'], 'wiped' => $wiped];
    }

    /**
     * @return array<string, mixed>
     */
    public function startNode(string $id, string $nodeID, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab] = $this->load($id);
        $node = $this->findNode($lab, $nodeID);
        if ($userId !== null) {
            $this->locks->acquire((string) $lab['id'], $userId, $isAdmin);
        }
        $bridges = $this->ensureNetworks($lab);
        try {
            return $this->agent->start($this->spec($lab, $node, $bridges));
        } catch (HttpException $exception) {
            // A failed single-node start must not touch the lab's links: the node
            // may still be running and other nodes of the lab may be running too.
            if ($userId !== null) {
                $this->locks->release((string) $lab['id'], $userId, $isAdmin);
            }
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function stopNode(string $id, string $nodeID, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab] = $this->load($id);
        $this->findNode($lab, $nodeID);
        $result = $this->agent->stop($this->agentNodeID((string) $lab['id'], $nodeID));
        if ($userId !== null) {
            $this->locks->release((string) $lab['id'], $userId, $isAdmin);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function wipeNode(string $id, string $nodeID, ?string $userId = null, bool $isAdmin = false): array
    {
        [$lab] = $this->load($id);
        $this->findNode($lab, $nodeID);
        $agentID = $this->agentNodeID((string) $lab['id'], $nodeID);
        try {
            $this->agent->stop($agentID);
        } catch (HttpException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }

        $result = $this->agent->wipe($agentID);
        if ($userId !== null) {
            $this->locks->release((string) $lab['id'], $userId, $isAdmin);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function nodes(string $id): array
    {
        [$lab] = $this->load($id);
        $result = [];
        foreach ($this->engineNodes($lab) as $node) {
            $agentID = $this->agentNodeID((string) $lab['id'], (string) $node['id']);
            $state = 'stopped';
            $ready = false;
            try {
                $info = $this->agent->nodeStatus($agentID);
                $state = $this->stringValue($info, 'state') ?: 'unknown';
                $ready = ($info['ready'] ?? false) === true;
            } catch (HttpException $exception) {
                if ($exception->status !== 404) {
                    $state = 'unknown';
                }
            }
            $result[] = [
                'node_id' => $node['id'],
                'agent_id' => $agentID,
                'name' => $node['name'],
                'type' => $node['type'],
                'state' => $state,
                'ready' => $ready,
            ];
        }

        return ['lab_id' => $lab['id'], 'nodes' => $result];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function load(string $id): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $id) !== 1) {
            throw new HttpException(422, 'Invalid lab id.');
        }
        $root = realpath($this->config->labsDir);
        if ($root === false || !is_dir($root)) {
            throw new HttpException(404, 'Lab not found.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'unl') {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_contains($path, DIRECTORY_SEPARATOR . '.trash' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            try {
                $lab = $this->parser->parse($path);
            } catch (\Throwable) {
                continue;
            }
            if ((string) $lab['id'] === $id || basename($path, '.unl') === $id) {
                return [$this->normaliseReferences($lab), $path];
            }
        }

        throw new HttpException(404, 'Lab not found.');
    }

    /**
     * @param array<string, mixed> $lab
     * @return list<array<string, mixed>>
     */
    private function engineNodes(array $lab): array
    {
        return array_values(array_filter(
            $this->labNodes($lab),
            static fn (array $node): bool => in_array($node['type'] ?? '', ['qemu', 'vpcs', 'iol', 'dynamips'], true),
        ));
    }

    /**
     * @param array<string, mixed> $lab
     * @return list<array<string, mixed>>
     */
    private function labNodes(array $lab): array
    {
        $nodes = [];
        foreach (($lab['node_list'] ?? []) as $node) {
            if (is_array($node)) {
                /** @var array<string, mixed> $node */
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $lab
     * @return list<array<string, mixed>>
     */
    private function labNetworks(array $lab): array
    {
        $networks = [];
        foreach (($lab['network_list'] ?? []) as $network) {
            if (is_array($network)) {
                $networks[] = $network;
            }
        }

        return $networks;
    }

    /**
     * Enforce the topology invariant: a network is a cable/segment and must
     * have at least two attached interfaces.
     *
     * Rules, in order: `""`/`"0"` without a matching network is unconnected and
     * left alone; a reference to a missing network is dangling and cleared; a
     * network with fewer than two attachments is removed and every interface
     * that pointed at it is detached. All other networks are untouched, so a
     * shared segment survives a node deletion with its remaining members.
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $networks
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function pruneLinks(array $nodes, array $networks): array
    {
        $known = [];
        foreach ($networks as $network) {
            if (isset($network['id']) && is_string($network['id']) && $network['id'] !== '') {
                $known[$network['id']] = true;
            }
        }

        $counts = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node['interfaces'] ?? null)) {
                continue;
            }
            foreach ($node['interfaces'] as $port => $interface) {
                if (!is_array($interface)) {
                    continue;
                }
                $networkID = (string) ($interface['network_id'] ?? '');
                if ($networkID === '') {
                    continue;
                }
                if (!isset($known[$networkID])) {
                    if ($networkID !== '0') {
                        $nodes[$index]['interfaces'][$port]['network_id'] = '';
                    }
                    continue;
                }
                $counts[$networkID] = ($counts[$networkID] ?? 0) + 1;
            }
        }

        $kept = [];
        $removed = [];
        foreach ($networks as $network) {
            $networkID = isset($network['id']) && is_string($network['id']) ? $network['id'] : '';
            if ($networkID !== '' && ($counts[$networkID] ?? 0) < 2) {
                $removed[$networkID] = true;
                continue;
            }
            $kept[] = $network;
        }
        if ($removed === []) {
            return [$nodes, $networks];
        }
        foreach ($nodes as $index => $node) {
            if (!is_array($node['interfaces'] ?? null)) {
                continue;
            }
            foreach ($node['interfaces'] as $port => $interface) {
                if (!is_array($interface)) {
                    continue;
                }
                if (isset($removed[(string) ($interface['network_id'] ?? '')])) {
                    $nodes[$index]['interfaces'][$port]['network_id'] = '';
                }
            }
        }

        return [$nodes, $kept];
    }

    /**
     * QEMU parameters for a node: node attribute, then the template `options`
     * mapping, then nothing (the agent applies its own fallback).
     *
     * @param array<string, mixed> $node
     * @return array<string, string>
     */
    private function qemuParameters(array $node): array
    {
        $parameters = $this->templateParameters((string) ($node['template'] ?? ''));
        $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
        foreach (QemuOptions::MAPPING as $option => $field) {
            $override = $node[$field] ?? null;
            if (is_string($override) && $override !== '') {
                // Stored labs may carry legacy values (virtio-net-pci, i386,
                // …): the engine only ever sees the canonical form.
                $override = QemuOptions::value($option, $override);
            } else {
                $candidate = $attributes[$option] ?? null;
                $override = is_string($candidate) && trim($candidate) !== ''
                    ? QemuOptions::value($option, $candidate)
                    : null;
            }
            if (is_string($override) && $override !== '') {
                $parameters[$field] = $override;
            }
        }
        $raw = $attributes[QemuOptions::RAW_KEY] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $parameters['extra_options'] = QemuOptions::rawValue($raw);
        }

        return $parameters;
    }

    /**
     * @return array<string, string>
     */
    private function templateParameters(string $templateID): array
    {
        if ($templateID === '') {
            return [];
        }
        if (isset($this->templateOptions[$templateID])) {
            return $this->templateOptions[$templateID];
        }
        $repository = $this->templates ?? new TemplateRepository($this->config);
        $template = $repository->find($templateID);
        $options = $template !== null && is_array($template['options'] ?? null) ? $template['options'] : [];

        return $this->templateOptions[$templateID] = QemuOptions::spec($options);
    }

    /**
     * @param array<string, mixed> $lab
     * @return array<string, string>
     */
    private function ensureNetworks(array $lab): array
    {
        $ids = [];
        foreach (($lab['network_list'] ?? []) as $network) {
            if (is_array($network) && isset($network['id']) && is_string($network['id'])) {
                $ids[] = ['id' => $network['id']];
            }
        }
        if ($ids === []) {
            return [];
        }
        $result = $this->agent->ensureLab((string) $lab['id'], $ids);
        $bridges = $result['bridges'] ?? [];
        $map = [];
        if (is_array($bridges)) {
            $first = $bridges[array_key_first($bridges)] ?? null;
            if (array_is_list($bridges) && is_array($first)) {
                foreach ($bridges as $entry) {
                    if (is_array($entry) && isset($entry['id'], $entry['bridge'])) {
                        $map[(string) $entry['id']] = (string) $entry['bridge'];
                    }
                }
            } else {
                foreach ($bridges as $key => $value) {
                    if (is_string($value)) {
                        $map[(string) $key] = $value;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $lab
     * @param array<string, mixed> $node
     * @param array<string, string> $bridges
     * @return array<string, mixed>
     */
    private function spec(array $lab, array $node, array $bridges): array
    {
        $type = (string) ($node['type'] ?? 'qemu');
        if (!in_array($type, ['qemu', 'vpcs', 'iol', 'dynamips'], true)) {
            throw new HttpException(422, 'Unsupported node type: ' . $type);
        }
        $interfaces = is_array($node['interfaces'] ?? null) ? $node['interfaces'] : [];
        $networks = [];
        foreach ($interfaces as $interface) {
            if (!is_array($interface)) {
                continue;
            }
            $networkID = (string) ($interface['network_id'] ?? '');
            $networks[] = $bridges[$networkID] ?? '';
        }

        $spec = [
            'id' => $this->agentNodeID((string) $lab['id'], (string) $node['id']),
            'lab_id' => (string) $lab['id'],
            'name' => (string) ($node['name'] ?? $node['id']),
            'engine' => $type,
            'cpu' => (int) ($node['cpu'] ?? 1),
            'ram' => (int) ($node['ram'] ?? 512),
            'ethernet' => count($networks),
            'networks' => $networks,
            'console' => (string) ($node['console'] ?? 'telnet'),
        ];
        if (($node['uuid'] ?? '') !== '') {
            $spec['uuid'] = (string) $node['uuid'];
        }
        if (($node['platform'] ?? '') !== '') {
            $spec['platform'] = (string) $node['platform'];
        }
        if ($type === 'qemu') {
            $spec = array_merge($spec, $this->qemuParameters($node));
        }
        $spec['serial'] = (int) ($node['serial'] ?? 0);
        if ((int) ($node['nvram'] ?? 0) > 0) {
            $spec['nvram'] = (int) $node['nvram'];
        }
        if (($node['config'] ?? '') !== '') {
            $spec['config'] = (string) $node['config'];
        }
        foreach (['image', 'iso', 'kernel', 'initrd'] as $key) {
            $value = (string) ($node[$key] ?? '');
            $path = $this->imagePath($value);
            if ($path === '' && trim($value) !== '') {
                throw new HttpException(
                    422,
                    $key . ' "' . $value . '" is not available in the image store.',
                );
            }
            if ($path !== '') {
                $spec[$key] = $path;
            }
        }
        if (($node['append'] ?? '') !== '') {
            $spec['append'] = (string) $node['append'];
        }

        return $spec;
    }

    /**
     * Import/runtime warnings: unsupported engines, unmapped images and
     * networks TeraLab cannot realise (clouds, NAT, OVS).
     *
     * @param array<string, mixed> $lab
     * @return list<array<string, string>>
     */
    private function warningsFor(array $lab): array
    {
        $warnings = is_array($lab['warnings'] ?? null) ? $lab['warnings'] : [];
        foreach ((array) ($lab['node_list'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? 'qemu');
            if (in_array($type, ['text', 'picture'], true)) {
                continue;
            }
            if (!in_array($type, ['qemu', 'vpcs', 'iol', 'dynamips'], true)) {
                $warnings[] = [
                    'type' => 'node_unsupported',
                    'node' => (string) ($node['name'] ?? $node['id'] ?? ''),
                    'message' => 'Node type "' . $type . '" is not supported by the engine and will not start.',
                ];
            }
            foreach (['image', 'iso', 'kernel', 'initrd'] as $key) {
                $value = (string) ($node[$key] ?? '');
                if ($value === '' || $this->images->resolve($value) !== null) {
                    continue;
                }
                $warnings[] = [
                    'type' => 'image_unmapped',
                    'node' => (string) ($node['name'] ?? $node['id'] ?? ''),
                    'value' => $value,
                    'message' => $key . ' "' . $value . '" is not mapped to a stored image.',
                ];
            }
        }
        foreach ((array) ($lab['network_list'] ?? []) as $network) {
            if (!is_array($network)) {
                continue;
            }
            $type = (string) ($network['type'] ?? 'bridge');
            if ($type === 'bridge' || $type === '') {
                continue;
            }
            $warnings[] = [
                'type' => 'network_unsupported',
                'network' => (string) ($network['id'] ?? ''),
                'value' => $type,
                'message' => 'Network "' . (string) ($network['name'] ?? $network['id'] ?? '')
                    . '" uses type "' . $type . '"; it will run as an isolated bridge.',
            ];
        }

        $unique = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $key = json_encode($warning, JSON_THROW_ON_ERROR);
            $unique[$key] = array_map(static fn (mixed $value): string => (string) $value, $warning);
        }

        return array_values($unique);
    }

    private function imagePath(string $image): string
    {
        if ($image === '') {
            return '';
        }

        return $this->images->resolve($image) ?? '';
    }

    private function agentNodeID(string $labID, string $nodeID): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $labID . '-' . $nodeID);
        $value = ltrim($value, '.-');

        return substr($value, 0, 64);
    }

    /**
     * @param array<string, mixed> $lab
     * @return array<string, mixed>
     */
    private function findNode(array $lab, string $nodeID): array
    {
        foreach ($this->labNodes($lab) as $node) {
            if ((string) $node['id'] === $nodeID || (string) $node['name'] === $nodeID) {
                return $node;
            }
        }

        throw new HttpException(404, 'Node not found.');
    }

    /**
     * @param array<string, mixed> $lab
     */
    private function assertNoRunningNodes(array $lab): void
    {
        foreach ($this->nodes((string) $lab['id'])['nodes'] as $node) {
            if (($node['state'] ?? '') === 'running') {
                throw new HttpException(409, 'Stop the lab before deleting it.');
            }
        }
    }

    private function safeStop(string $agentID): void
    {
        if ($agentID === '') {
            return;
        }
        try {
            $this->agent->stop($agentID);
        } catch (\Throwable) {
        }
    }

    private function safeCleanupLab(string $labID): void
    {
        try {
            $this->agent->cleanupLab($labID);
        } catch (\Throwable) {
        }
    }

    /**
     * Remove the lab's links only when nothing of the lab is running.
     *
     * A failed start must never tear down bridges or taps that a running node
     * still uses: the engine keeps running, but its data path is destroyed.
     */
    private function cleanupLabIfStopped(string $labID): void
    {
        try {
            $nodes = $this->nodes($labID)['nodes'] ?? [];
        } catch (\Throwable) {
            return;
        }
        foreach ($nodes as $node) {
            // Only a definitive "stopped" is safe: an unknown state may hide a
            // node that is still running, and an agent hiccup must not cost the
            // links of a live lab.
            if (!is_array($node) || ($node['state'] ?? '') !== 'stopped') {
                return;
            }
        }
        $this->safeCleanupLab($labID);
    }

    /**
     * @param array<string, mixed> $lab
     * @return array<string, mixed>
     */
    private function metadata(array $lab, string $path): array
    {
        $lab['path'] = ltrim(str_replace($this->config->labsDir, '', $path), '/');
        $lab['filename'] = basename($path);
        $lab['modified'] = filemtime($path) ?: time();
        $lab['nodes'] = is_array($lab['node_list'] ?? null) ? count($lab['node_list']) : 0;
        $lab['networks'] = is_array($lab['network_list'] ?? null) ? count($lab['network_list']) : 0;
        $lab['owner'] = $lab['attributes']['owner'] ?? null;
        $tags = $lab['attributes']['tags'] ?? '';
        $lab['tags'] = is_string($tags) && $tags !== '' ? array_values(array_filter(explode(',', $tags))) : [];
        $lab['created_at'] = $lab['attributes']['created_at'] ?? null;
        $lab['updated_at'] = $lab['attributes']['updated_at'] ?? null;
        $lab['format'] = (string) ($lab['format'] ?? 'legacy');
        $lab['warnings'] = $this->warningsFor($lab);
        unset($lab['attributes'], $lab['extra_xml'], $lab['objects_extra'], $lab['extensions']);

        return $lab;
    }

    /**
     * Client-facing view of a stored lab: portable image references, the file
     * format and import/runtime warnings.
     *
     * @param array<string, mixed> $lab
     * @return array<string, mixed>
     */
    public function present(array $lab): array
    {
        $lab = $this->normaliseReferences($lab);
        $lab['format'] = (string) ($lab['format'] ?? 'legacy');
        $lab['warnings'] = $this->warningsFor($lab);

        return $lab;
    }

    /**
     * @param array<string, mixed> $lab
     * @return array<string, mixed>
     */
    private function write(string $path, array $lab): array
    {
        $lab = $this->normaliseReferences($lab);
        $lab['format'] = 'teralab-2';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the lab directory.');
        }
        $xml = $this->writer->write($lab);
        $temporary = tempnam($directory, '.lab-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary lab file.');
        }
        if (file_put_contents($temporary, $xml) === false) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the lab file.');
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot move the lab file into place.');
        }

        return $lab;
    }

    /**
     * Lab files never contain absolute paths. Convert image/iso/kernel/initrd
     * values to portable references, resolving known store paths.
     *
     * @param array<string, mixed> $lab
     * @return array<string, mixed>
     */
    private function normaliseReferences(array $lab): array
    {
        if (!is_array($lab['node_list'] ?? null)) {
            return $lab;
        }
        foreach ($lab['node_list'] as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            foreach (['image', 'iso', 'kernel', 'initrd'] as $key) {
                $value = $node[$key] ?? '';
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                $node[$key] = $this->images->portableValue($value);
            }
            $lab['node_list'][$index] = $node;
        }

        return $lab;
    }

    private function targetPath(string $name, ?string $directory): string
    {
        $slug = $this->slug($name);
        $base = $this->config->labsDir . ($directory !== null ? '/' . trim($directory, '/') : '');
        $path = $base . '/' . $slug . '.unl';
        $suffix = 1;
        while (file_exists($path)) {
            $suffix++;
            $path = $base . '/' . $slug . '-' . $suffix . '.unl';
        }

        return $path;
    }

    private function labName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new HttpException(422, 'Lab name is required.');
        }
        $name = trim($value);
        if (strlen($name) > 128 || preg_match('/[\/\\\\\x00-\x1f]/', $name) === 1) {
            throw new HttpException(422, 'Invalid lab name.');
        }

        return $name;
    }

    private function slug(string $name): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $slug = trim($slug, '.-');

        return $slug !== '' ? substr($slug, 0, 64) : 'lab';
    }

    private function tags(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }
        $tags = [];
        foreach ($value as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = substr(trim($tag), 0, 32);
            }
        }

        return implode(',', array_slice($tags, 0, 20));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeNodes(mixed $input): array
    {
        if (!is_array($input)) {
            throw new HttpException(422, 'nodes must be a list.');
        }
        $nodes = [];
        $seen = [];
        foreach ($input as $raw) {
            if (!is_array($raw)) {
                throw new HttpException(422, 'Each node must be an object.');
            }
            $id = isset($raw['id']) ? (string) $raw['id'] : '';
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,31}$/', $id) !== 1) {
                throw new HttpException(422, 'Invalid node id.');
            }
            if (isset($seen[$id])) {
                throw new HttpException(422, 'Duplicate node id: ' . $id);
            }
            $seen[$id] = true;
            $interfaces = [];
            foreach (($raw['interfaces'] ?? []) as $interface) {
                if (!is_array($interface)) {
                    continue;
                }
                $interfaces[] = [
                    'id' => (int) ($interface['id'] ?? 0),
                    'name' => (string) ($interface['name'] ?? ''),
                    'type' => (string) ($interface['type'] ?? 'ethernet'),
                    'network_id' => (string) ($interface['network_id'] ?? ''),
                    'attributes' => $this->normalizeAttributes($interface['attributes'] ?? [], ['id', 'name', 'type', 'network_id']),
                ];
            }
            $attributes = $this->normalizeAttributes($raw['attributes'] ?? [], [
                'id', 'name', 'type', 'template', 'icon', 'image', 'iso', 'kernel', 'initrd', 'append',
                'console', 'cpu', 'ram', 'ethernet', 'nvram', 'serial', 'uuid', 'platform', 'nic',
                'machine', 'delay', 'sat', 'config', 'left', 'top',
            ]);
            foreach (QemuOptions::NODE_FIELDS as $field => $option) {
                $value = $raw[$field] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                $attributes[$option] = QemuOptions::value($option, $value);
            }
            $nodes[] = [
                'id' => $id,
                'name' => (string) ($raw['name'] ?? $id),
                'type' => (string) ($raw['type'] ?? 'qemu'),
                'template' => (string) ($raw['template'] ?? ''),
                'icon' => (string) ($raw['icon'] ?? ''),
                'image' => (string) ($raw['image'] ?? ''),
                'iso' => (string) ($raw['iso'] ?? ''),
                'kernel' => (string) ($raw['kernel'] ?? ''),
                'initrd' => (string) ($raw['initrd'] ?? ''),
                'append' => (string) ($raw['append'] ?? ''),
                'console' => (string) ($raw['console'] ?? 'telnet'),
                'cpu' => max(1, (int) ($raw['cpu'] ?? 1)),
                'ram' => max(64, (int) ($raw['ram'] ?? 512)),
                'ethernet' => count($interfaces),
                'serial' => max(0, min(8, (int) ($raw['serial'] ?? 0))),
                'uuid' => (string) ($raw['uuid'] ?? ''),
                'platform' => (string) ($raw['platform'] ?? ''),
                'nic' => (string) ($raw['nic'] ?? ''),
                'machine' => (string) ($raw['machine'] ?? ''),
                'nvram' => max(0, min(4096, (int) ($raw['nvram'] ?? 0))),
                'delay' => max(0, min(3600, (int) ($raw['delay'] ?? 0))),
                'sat' => max(-1, min(1024, (int) ($raw['sat'] ?? -1))),
                'config' => (string) ($raw['config'] ?? ''),
                'left' => max(0, (int) ($raw['left'] ?? 0)),
                'top' => max(0, (int) ($raw['top'] ?? 0)),
                'interfaces' => $interfaces,
                'extra_xml' => $this->normalizeFragments($raw['extra_xml'] ?? []),
                'attributes' => $attributes,
            ];
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeNetworks(mixed $input): array
    {
        if (!is_array($input)) {
            throw new HttpException(422, 'networks must be a list.');
        }
        $networks = [];
        foreach ($input as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $id = isset($raw['id']) ? (string) $raw['id'] : '';
            if ($id === '') {
                continue;
            }
            $networks[] = [
                'id' => $id,
                'name' => (string) ($raw['name'] ?? ('Net-' . $id)),
                'type' => (string) ($raw['type'] ?? 'bridge'),
                'left' => max(0, (int) ($raw['left'] ?? 0)),
                'top' => max(0, (int) ($raw['top'] ?? 0)),
                'attributes' => $this->normalizeAttributes($raw['attributes'] ?? [], ['id', 'name', 'type', 'left', 'top']),
            ];
        }

        return $networks;
    }

    /**
     * Preserve EVE-NG attributes the editor does not model (link styling,
     * keepalive, qemu options, …).
     *
     * @param mixed $input
     * @param list<string> $managed
     * @return array<string, string>
     */
    private function normalizeAttributes(mixed $input, array $managed): array
    {
        if (!is_array($input)) {
            return [];
        }
        $attributes = [];
        foreach ($input as $name => $value) {
            if (!is_string($name)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/', $name) !== 1
                || in_array($name, $managed, true)
                || !is_scalar($value)
            ) {
                continue;
            }
            $text = (string) $value;
            if (strlen($text) <= 255) {
                $attributes[$name] = $text;
            }
        }

        return $attributes;
    }

    /**
     * @param mixed $input
     * @return list<string>
     */
    private function normalizeFragments(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $fragments = [];
        foreach ($input as $xml) {
            if (!is_string($xml) || trim($xml) === '' || strlen($xml) > 65536) {
                continue;
            }
            if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                continue;
            }
            $fragments[] = $xml;
            if (count($fragments) >= 64) {
                break;
            }
        }

        return $fragments;
    }

    private function stringValue(mixed $value, string $key): string
    {
        if (is_array($value) && isset($value[$key]) && is_string($value[$key])) {
            return $value[$key];
        }

        return '';
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
