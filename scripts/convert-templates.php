<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors
//
// Convert legacy PHP templates into the YAML template format.
// Run once from the repository root: php scripts/convert-templates.php

declare(strict_types=1);

require dirname(__DIR__) . '/api/vendor/autoload.php';

$source = dirname(__DIR__) . '/html/templates';
$target = dirname(__DIR__) . '/api/templates/builtin';
if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
    fwrite(STDERR, "cannot create $target\n");
    exit(1);
}

$defaults = ['cpu', 'ram', 'ethernet', 'serial', 'console'];
$meta = ['name', 'type', 'icon'];
$count = 0;

foreach (glob($source . '/*.php') ?: [] as $file) {
    $id = basename($file, '.php');
    $p = [];
    include $file;
    if (!is_array($p) || $p === []) {
        fwrite(STDERR, "skip empty template: $id\n");
        continue;
    }

    $template = [
        'schema' => 1,
        'id' => $id,
        'name' => (string) ($p['name'] ?? $id),
        'kind' => (string) ($p['type'] ?? 'qemu'),
        'license' => 'BSD-3-Clause',
        'icon' => (string) ($p['icon'] ?? ''),
        'defaults' => [],
        'options' => [],
    ];
    foreach ($defaults as $key) {
        if (isset($p[$key]) && is_scalar($p[$key]) && $p[$key] !== '') {
            $template['defaults'][$key] = is_numeric($p[$key]) ? (int) $p[$key] : (string) $p[$key];
        }
    }
    foreach ($p as $key => $value) {
        if (in_array($key, $defaults, true) || in_array($key, $meta, true)) {
            continue;
        }
        $template['options'][$key] = $value;
    }

    $yaml = \Symfony\Component\Yaml\Yaml::dump($template, 8, 2);
    $header = "# SPDX-License-Identifier: BSD-3-Clause\n"
        . "# Derived from UNetLab template html/templates/$id.php.\n"
        . "# Converted to YAML by TeraLab contributors, 2026-11-17.\n";
    if (file_put_contents($target . '/' . $id . '.yaml', $header . $yaml) === false) {
        fwrite(STDERR, "cannot write $id.yaml\n");
        exit(1);
    }
    $count++;
}

printf("converted %d templates into %s\n", $count, $target);
