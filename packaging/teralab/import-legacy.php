#!/usr/bin/env php
<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Image\LegacyAddonsImporter;
use TeraLab\Api\Templates\TemplateRepository;

require dirname(__DIR__, 2) . '/api/vendor/autoload.php';

$usage = "usage: import-legacy.php [--legacy-db PATH] [--legacy-labs PATH] [--legacy-addons[=PATH]] [--data-dir PATH] [--root PATH]\n"
    . "       at least one of --legacy-db or --legacy-addons is required\n"
    . "       --legacy-addons adopts images in place and defaults to /opt/unetlab/addons (run as root)\n";

$options = getopt('', ['legacy-db:', 'legacy-labs:', 'data-dir:', 'root:', 'legacy-addons::']);
if (!is_array($options)) {
    fwrite(STDERR, $usage);
    exit(2);
}

$hasDb = isset($options['legacy-db']);
$hasAddons = array_key_exists('legacy-addons', $options);
if (!$hasDb && !$hasAddons) {
    fwrite(STDERR, $usage);
    exit(2);
}
if (isset($options['legacy-labs']) && !$hasDb) {
    fwrite(STDERR, "--legacy-labs requires --legacy-db\n");
    exit(2);
}

$dataDir = (string) ($options['data-dir'] ?? '/var/lib/teralab');
$root = (string) ($options['root'] ?? '/opt/teralab');
$legacyDb = $hasDb ? (string) $options['legacy-db'] : '';
$legacyLabs = isset($options['legacy-labs']) ? (string) $options['legacy-labs'] : '';

if ($hasDb && !is_file($legacyDb)) {
    fwrite(STDERR, "legacy database not found: $legacyDb\n");
    exit(1);
}

putenv('TERALAB_ROOT=' . $root);
putenv('TERALAB_DATA_DIR=' . $dataDir);
$config = Config::fromEnvironment();
$pdo = (new Database($config))->pdo();
(new Migrator($pdo, $config->migrationsDir()))->migrate();

if ($hasDb) {
    $legacy = new PDO('sqlite:' . $legacyDb, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $tables = $legacy->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('users', $tables, true)) {
        fwrite(STDERR, "legacy database has no users table\n");
        exit(1);
    }

    $hasPermissions = in_array('permissions', $tables, true);
    $users = $legacy->query('SELECT username, email, name, password, expiration FROM users')->fetchAll();
    $inserted = 0;
    $skipped = 0;
    $insert = $pdo->prepare(
        'INSERT INTO users '
        . '(id, username, email, name, password_hash, legacy_password_hash, role, expiration, created_at, updated_at) '
        . 'VALUES (:id, :username, :email, :name, :password_hash, :legacy_hash, :role, :expiration, :created_at, :updated_at)'
    );
    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');

    foreach ($users as $user) {
        $username = is_string($user['username'] ?? null) ? $user['username'] : '';
        if ($username === '') {
            continue;
        }
        $exists->execute([':username' => $username]);
        if ((int) $exists->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        $role = $username === 'admin' ? 'admin' : 'editor';
        if ($hasPermissions) {
            $statement = $legacy->prepare(
                "SELECT COUNT(*) FROM permissions WHERE username = :username AND lab_id = '*' AND role = 'admin'"
            );
            $statement->execute([':username' => $username]);
            if ((int) $statement->fetchColumn() > 0) {
                $role = 'admin';
            }
        }

        $legacyHash = is_string($user['password'] ?? null) ? $user['password'] : '';
        $now = time();
        $insert->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':username' => $username,
            ':email' => is_string($user['email'] ?? null) ? $user['email'] : null,
            ':name' => is_string($user['name'] ?? null) ? $user['name'] : null,
            ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
            ':legacy_hash' => $legacyHash !== '' ? $legacyHash : null,
            ':role' => $role,
            ':expiration' => (int) ($user['expiration'] ?? -1),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $inserted++;
    }

    printf("users imported: %d, skipped: %d\n", $inserted, $skipped);

    if ($legacyLabs !== '') {
        if (!is_dir($legacyLabs)) {
            fwrite(STDERR, "legacy labs directory not found: $legacyLabs\n");
            exit(1);
        }
        $rootLabs = realpath($config->labsDir) ?: $config->labsDir;
        if (!is_dir($rootLabs) && !mkdir($rootLabs, 0770, true) && !is_dir($rootLabs)) {
            fwrite(STDERR, "cannot create labs directory: $rootLabs\n");
            exit(1);
        }
        $copied = 0;
        $base = realpath($legacyLabs);
        if ($base === false) {
            fwrite(STDERR, "cannot resolve legacy labs directory\n");
            exit(1);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($legacyLabs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'unl') {
                continue;
            }
            $source = $file->getRealPath();
            if ($source === false) {
                continue;
            }
            if (!str_starts_with($source, $base . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = substr($source, strlen($base) + 1);
            $target = $rootLabs . DIRECTORY_SEPARATOR . $relative;
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                fwrite(STDERR, "cannot create $directory\n");
                exit(1);
            }
            if (!file_exists($target)) {
                if (!copy($source, $target)) {
                    fwrite(STDERR, "cannot copy $source\n");
                    exit(1);
                }
                $copied++;
            }
        }
        printf("labs copied: %d\n", $copied);
    }
}

if ($hasAddons) {
    $addonsValue = $options['legacy-addons'];
    $addonsRoot = is_string($addonsValue) && $addonsValue !== ''
        ? $addonsValue
        : LegacyAddonsImporter::DEFAULT_ROOT;

    if (!is_dir($addonsRoot)) {
        fwrite(STDERR, "legacy addons directory not found: $addonsRoot\n");
        exit(1);
    }

    // Large legacy trees take a long time to hash; the CLI is unbounded.
    set_time_limit(0);

    $agent = new AgentClient($config);
    $importer = new LegacyAddonsImporter(
        $config,
        $pdo,
        static fn (string $path): array => $agent->inspectImage($path),
        new TemplateRepository($config),
    );

    try {
        $scan = $importer->scan($addonsRoot);
        $rootAddons = (string) ($scan['root'] ?? $addonsRoot);
        $scanSummary = is_array($scan['summary'] ?? null) ? $scan['summary'] : [];
        printf("legacy addons: %s\n", $rootAddons);
        printf(
            "scan: new %d, existing %d, duplicate %d, unreadable %d, skipped %d\n",
            (int) ($scanSummary['new'] ?? 0),
            (int) ($scanSummary['existing'] ?? 0),
            (int) ($scanSummary['duplicate'] ?? 0),
            (int) ($scanSummary['unreadable'] ?? 0),
            (int) ($scanSummary['skipped'] ?? 0),
        );

        $files = [];
        foreach ((array) ($scan['entries'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['status'] ?? '') === 'new' && is_string($entry['path'] ?? null)) {
                $files[] = $entry['path'];
            }
        }

        if ($files === []) {
            echo "no new images to import\n";
        } else {
            $result = $importer->import($rootAddons, $files, [], null);
            foreach ((array) ($result['results'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $path = is_string($entry['path'] ?? null) ? $entry['path'] : '';
                $status = is_string($entry['status'] ?? null) ? $entry['status'] : 'failed';
                $error = is_string($entry['error'] ?? null) && $entry['error'] !== ''
                    ? ' (' . $entry['error'] . ')'
                    : '';
                printf("%s: %s%s\n", $path, $status, $error);
            }
            $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            printf(
                "images: imported %d, duplicate %d, skipped %d, failed %d\n",
                (int) ($summary['imported'] ?? 0),
                (int) ($summary['duplicate'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                (int) ($summary['failed'] ?? 0),
            );
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'image import failed: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
