<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

use TeraLab\Api\Image\ImageReference;

/**
 * Add portable image references and backfill them from existing files.
 *
 * A reference is a single path segment (EVE-NG style) and is what lab files
 * store instead of absolute paths.
 */
return static function (PDO $pdo): void {
    $columns = $pdo->query('PRAGMA table_info(images)')->fetchAll();
    $hasColumn = false;
    foreach ($columns as $column) {
        if (is_array($column) && ($column['name'] ?? '') === 'reference') {
            $hasColumn = true;
            break;
        }
    }
    if (!$hasColumn) {
        $pdo->exec('ALTER TABLE images ADD COLUMN reference TEXT');
    }

    $exists = $pdo->prepare('SELECT 1 FROM images WHERE reference = :reference LIMIT 1');
    $update = $pdo->prepare('UPDATE images SET reference = :reference WHERE id = :id');
    $rows = $pdo->query('SELECT id, path, name, reference FROM images ORDER BY created_at, id')->fetchAll();

    /** @var array<string, bool> $assigned */
    $assigned = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $reference = is_string($row['reference'] ?? null) ? trim((string) $row['reference']) : '';
        if ($reference !== '') {
            $assigned[$reference] = true;
            continue;
        }

        $source = (string) ($row['path'] ?? '');
        if ($source === '') {
            $source = (string) ($row['name'] ?? '');
        }
        $candidate = ImageReference::unique(
            ImageReference::portable($source),
            static function (string $value) use ($assigned, $exists): bool {
                if (isset($assigned[$value])) {
                    return true;
                }
                $exists->execute([':reference' => $value]);

                return $exists->fetchColumn() !== false;
            }
        );
        $assigned[$candidate] = true;
        $update->execute([':reference' => $candidate, ':id' => (string) $row['id']]);
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS images_reference ON images (reference)');
};
