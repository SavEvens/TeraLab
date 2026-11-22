<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

/**
 * Deterministic auto-layout for compiled YAML labs.
 *
 * `grid` places declaration order four per row. `tree` runs a breadth-first
 * walk from `root` over the link graph, one column per level and one row per
 * node, in declaration order. Nodes the walk never reaches form a tail row.
 * Only missing `left`/`top` values are filled; authored positions always win.
 * Networks sit at the centroid of their member nodes. No wall clock, no
 * randomness: the same graph always produces the same coordinates.
 */
final class LabYamlLayout
{
    private const ORIGIN = 80;
    private const PER_ROW = 4;

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $networks
     * @param array{mode: string, root: ?string, spacing: int} $layout
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public function apply(array $nodes, array $networks, array $layout): array
    {
        $spacing = $layout['spacing'];
        $positions = $layout['mode'] === 'grid'
            ? $this->gridPositions(count($nodes), $spacing)
            : $this->treePositions($nodes, $networks, $layout['root'], $spacing);

        foreach ($nodes as $index => $node) {
            $nodes[$index]['left'] = array_key_exists('left', $node)
                ? (int) $node['left']
                : $positions[$index][0];
            $nodes[$index]['top'] = array_key_exists('top', $node)
                ? (int) $node['top']
                : $positions[$index][1];
        }

        $centroids = $this->centroids($nodes, $networks);
        foreach ($networks as $index => $network) {
            $networkID = (string) ($network['id'] ?? '');
            $centroid = $centroids[$networkID] ?? [self::ORIGIN, self::ORIGIN];
            $networks[$index]['left'] = array_key_exists('left', $network)
                ? (int) $network['left']
                : $centroid[0];
            $networks[$index]['top'] = array_key_exists('top', $network)
                ? (int) $network['top']
                : $centroid[1];
        }

        return [$nodes, $networks];
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function gridPositions(int $count, int $spacing): array
    {
        $positions = [];
        for ($index = 0; $index < $count; $index++) {
            $positions[] = [
                self::ORIGIN + ($index % self::PER_ROW) * $spacing,
                self::ORIGIN + intdiv($index, self::PER_ROW) * $spacing,
            ];
        }

        return $positions;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $networks
     * @return list<array{0: int, 1: int}>
     */
    private function treePositions(array $nodes, array $networks, ?string $root, int $spacing): array
    {
        $count = count($nodes);
        if ($count === 0) {
            return [];
        }

        $indexByID = [];
        $indexByName = [];
        foreach ($nodes as $index => $node) {
            $indexByID[(string) ($node['id'] ?? '')] = $index;
            $indexByName[(string) ($node['name'] ?? '')] = $index;
        }

        $adjacency = array_fill(0, $count, []);
        foreach ($networks as $network) {
            $networkID = (string) ($network['id'] ?? '');
            if ($networkID === '') {
                continue;
            }
            $members = [];
            foreach ($nodes as $index => $node) {
                foreach ((array) ($node['interfaces'] ?? []) as $interface) {
                    if (!is_array($interface)) {
                        continue;
                    }
                    if ((string) ($interface['network_id'] ?? '') === $networkID) {
                        $members[] = $index;
                        break;
                    }
                }
            }
            foreach ($members as $member) {
                foreach ($members as $other) {
                    if ($other !== $member) {
                        $adjacency[$member][] = $other;
                    }
                }
            }
        }
        foreach ($adjacency as $index => $neighbors) {
            $unique = array_values(array_unique($neighbors));
            sort($unique);
            $adjacency[$index] = $unique;
        }

        $start = 0;
        if ($root !== null && isset($indexByName[$root])) {
            $start = $indexByName[$root];
        }

        /** @var array<int, int> $levelOf Node index => level. */
        $levelOf = [$start => 0];
        $queue = [$start];
        $levels = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $level = $levelOf[$current];
            $levels[$level][] = $current;
            foreach ($adjacency[$current] as $neighbor) {
                if (isset($levelOf[$neighbor])) {
                    continue;
                }
                $levelOf[$neighbor] = $level + 1;
                $queue[] = $neighbor;
            }
        }
        ksort($levels);

        $positions = array_fill(0, $count, [self::ORIGIN, self::ORIGIN]);
        foreach ($levels as $level => $members) {
            sort($members);
            foreach ($members as $row => $index) {
                $positions[$index] = [
                    self::ORIGIN + $level * $spacing,
                    self::ORIGIN + $row * $spacing,
                ];
            }
        }

        $unvisited = [];
        for ($index = 0; $index < $count; $index++) {
            if (!isset($levelOf[$index])) {
                $unvisited[] = $index;
            }
        }
        $tail = self::ORIGIN + count($levels) * $spacing;
        foreach ($unvisited as $row => $index) {
            $positions[$index] = [self::ORIGIN + $row * $spacing, $tail];
        }

        return $positions;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $networks
     * @return array<string, array{0: int, 1: int}>
     */
    private function centroids(array $nodes, array $networks): array
    {
        $sums = [];
        foreach ($nodes as $node) {
            $left = (int) ($node['left'] ?? self::ORIGIN);
            $top = (int) ($node['top'] ?? self::ORIGIN);
            foreach ((array) ($node['interfaces'] ?? []) as $interface) {
                if (!is_array($interface)) {
                    continue;
                }
                $networkID = (string) ($interface['network_id'] ?? '');
                if ($networkID === '') {
                    continue;
                }
                $sums[$networkID] ??= [0, 0, 0];
                $sums[$networkID][0] += $left;
                $sums[$networkID][1] += $top;
                $sums[$networkID][2]++;
            }
        }

        $centroids = [];
        foreach ($networks as $network) {
            $networkID = (string) ($network['id'] ?? '');
            if ($networkID === '' || !isset($sums[$networkID])) {
                continue;
            }
            $count = $sums[$networkID][2];
            $centroids[$networkID] = [
                (int) round($sums[$networkID][0] / $count),
                (int) round($sums[$networkID][1] / $count),
            ];
        }

        return $centroids;
    }
}
