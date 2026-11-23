<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use PDO;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Templates\DockerOptions;
use TeraLab\Api\Templates\TemplateRepository;

/**
 * Per-lab policy caps for demo guests.
 *
 * The guard resolves the actor from the user id the authoring service already
 * receives, so every write path (editor, .unl import, YAML apply) inherits the
 * caps without controller-specific wiring. Caps are policy, not authorisation:
 * missing actors (CLI/system) and non-guest accounts are always exempt.
 */
final class DemoGuard
{
    private const ANNOTATION_NODES = ['text', 'picture'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly DemoPolicy $policy,
        private readonly TemplateRepository $templates,
        private readonly ?HostMemory $memory = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function actor(string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, role, guest, expiration FROM users WHERE id = :id'
        );
        $statement->execute([':id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Reject an authoring request that exceeds the demo caps.
     *
     * @param array<array-key, mixed> $nodes
     */
    public function assertCanAuthor(?string $userId, array $nodes): void
    {
        if (!$this->isGuarded($userId)) {
            return;
        }

        $this->assertNodeCount($nodes);
        $allowed = $this->resolvedAllowedTemplates();
        $ramCap = $this->ramCap();
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ($this->isAnnotationNode($node)) {
                continue;
            }
            $this->assertNodeTemplate($node, $allowed);
            $ram = $this->nodeRam($node);
            if ($ram > $ramCap) {
                throw new HttpException(422, $this->ramMessage($node, $ram, $ramCap));
            }
        }
    }

    /**
     * Refuse a new guest workload while the host is below the demo floor.
     *
     * Only active guests are guarded; an unreadable or malformed memory
     * reading fails open so a probe problem can never block the demo.
     */
    public function assertMemoryFloor(?string $userId): void
    {
        if (!$this->isGuarded($userId) || $this->memory === null) {
            return;
        }
        $available = $this->memory->availableMb();
        if ($available === null) {
            return;
        }
        $floor = $this->settings->int('demo_memory_floor_mb', 2048);
        if ($available < $floor) {
            throw new HttpException(409, 'The demo is at capacity right now; try again shortly.');
        }
    }

    /**
     * Apply the demo policy to an imported lab.
     *
     * Structural violations (node count, template allowlist) still reject the
     * import. RAM is clamped to the per-node cap instead, because a foreign
     * file should not fail on a memory value; every clamp is reported through
     * a `demo_limited` warning.
     *
     * @param array<string, mixed> $lab
     * @return array{
     *   lab: array<string, mixed>,
     *   warnings: list<array{type: string, node: string, message: string}>
     * }
     */
    public function prepareImport(?string $userId, array $lab): array
    {
        if (!$this->isGuarded($userId)) {
            return ['lab' => $lab, 'warnings' => []];
        }

        $rawNodes = is_array($lab['node_list'] ?? null) ? $lab['node_list'] : [];
        $nodes = [];
        foreach ($rawNodes as $index => $node) {
            if (is_array($node)) {
                $nodes[$index] = $node;
            }
        }
        $this->assertNodeCount($nodes);

        $allowed = $this->resolvedAllowedTemplates();
        $ramCap = $this->ramCap();
        $warnings = [];
        foreach ($nodes as $index => $node) {
            if ($this->isAnnotationNode($node)) {
                continue;
            }
            $this->assertNodeTemplate($node, $allowed);
            $ram = $this->nodeRam($node);
            if ($ram <= $ramCap) {
                continue;
            }
            $lab['node_list'][$index]['ram'] = $ramCap;
            $warnings[] = [
                'type' => 'demo_limited',
                'node' => $this->nodeLabel($node),
                'message' => 'RAM clamped from ' . $ram . ' MB to ' . $ramCap . ' MB for the demo.',
            ];
        }

        return ['lab' => $lab, 'warnings' => $warnings];
    }

    /**
     * Configured template ids, trimmed, non-empty and de-duplicated.
     *
     * The ids are not resolved here: resolvedAllowedTemplates() intersects them
     * with the real catalogue before authoring checks.
     *
     * @return list<string>
     */
    public function allowedTemplates(): array
    {
        return self::parseTemplateIds($this->settings->string('demo_allowed_templates', ''));
    }

    /**
     * Normalise a comma-separated template allowlist.
     *
     * @return list<string>
     */
    public static function parseTemplateIds(string $csv): array
    {
        $ids = [];
        foreach (explode(',', $csv) as $entry) {
            $id = trim($entry);
            if ($id === '' || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function isGuarded(?string $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }
        $actor = $this->actor($userId);
        if ($actor === null) {
            return false;
        }

        return $this->policy::isGuest($actor) && !$this->policy::isExpired($actor);
    }

    /**
     * @param array<array-key, mixed> $nodes
     */
    private function assertNodeCount(array $nodes): void
    {
        $cap = $this->settings->int('demo_max_nodes_per_lab', 5);
        $count = count($nodes);
        if ($count > $cap) {
            throw new HttpException(
                422,
                'The demo allows at most ' . $cap . ' nodes per lab; this lab has ' . $count . '.',
            );
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array{kind: string, image: string}> $allowed
     */
    private function assertNodeTemplate(array $node, array $allowed): void
    {
        if (!array_key_exists('template', $node)) {
            return;
        }
        $template = $node['template'] ?? '';
        $id = is_string($template) ? trim($template) : '';
        if ($id === '') {
            // Text and picture annotations are not engine nodes and carry no
            // template; count them but do not require an allowlisted id.
            if ($this->isAnnotationNode($node)) {
                return;
            }
            throw new HttpException(422, 'Template "" is not available on the demo.');
        }
        if (!isset($allowed[$id])) {
            throw new HttpException(422, 'Template "' . $id . '" is not available on the demo.');
        }

        $definition = $allowed[$id];
        $type = $this->nodeType($node);
        if ($type !== '' && $definition['kind'] !== '' && $definition['kind'] !== $type) {
            throw new HttpException(
                422,
                'Template "' . $id . '" does not match the node type "' . $type . '".',
            );
        }

        if ($definition['kind'] !== 'docker') {
            return;
        }
        $image = $node['image'] ?? '';
        if (!is_string($image) || trim($image) === '') {
            // An empty node image lets the service fill in the template image.
            return;
        }
        if (trim($image) !== trim($definition['image'])) {
            throw new HttpException(
                422,
                'The demo does not allow custom container images; use template "' . $id . '".',
            );
        }
    }

    /**
     * @return array<string, array{kind: string, image: string}> Allowlisted id => template policy.
     */
    private function resolvedAllowedTemplates(): array
    {
        $available = [];
        foreach ($this->templates->all() as $template) {
            $id = $template['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }
            $kind = $template['kind'] ?? '';
            $options = is_array($template['options'] ?? null) ? $template['options'] : [];
            $available[$id] = [
                'kind' => is_string($kind) ? $kind : '',
                'image' => DockerOptions::image($options) ?? '',
            ];
        }

        $allowed = [];
        foreach ($this->allowedTemplates() as $id) {
            if (isset($available[$id])) {
                $allowed[$id] = $available[$id];
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeType(array $node): string
    {
        return strtolower(trim((string) ($node['type'] ?? '')));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isAnnotationNode(array $node): bool
    {
        return in_array($this->nodeType($node), self::ANNOTATION_NODES, true);
    }

    private function ramCap(): int
    {
        return $this->settings->int('demo_max_ram_per_node', 1024);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeRam(array $node): int
    {
        return (int) ($node['ram'] ?? 0);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeLabel(array $node): string
    {
        $name = $node['name'] ?? '';
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }
        $id = $node['id'] ?? '';

        return is_string($id) ? $id : '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function ramMessage(array $node, int $ram, int $cap): string
    {
        return 'The demo allows at most ' . $cap . ' MB of RAM per node; "'
            . $this->nodeLabel($node) . '" requests ' . $ram . ' MB.';
    }
}
