<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\System;

use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use ZipArchive;

final class BackupService
{
    public const DEFAULT_MAX_ARCHIVE_BYTES = 2147483648;

    public const DEFAULT_MAX_ENTRIES = 10000;

    public const DEFAULT_MAX_UNCOMPRESSED_BYTES = 4294967296;

    public const DEFAULT_MAX_DATABASE_BYTES = 1073741824;

    /** Archive/compressed-container extensions that must never be nested. */
    private const NESTED_ARCHIVE_EXTENSIONS = [
        'zip', 'tar', 'gz', 'tgz', 'bz2', 'tbz2', 'xz', 'txz', '7z', 'rar', 'jar', 'war', 'iso',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function create(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'The PHP zip extension is required for backups.');
        }
        $directory = $this->config->dataDir . '/backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the backup directory.');
        }
        @chmod($directory, 0700);
        $filename = 'teralab-backup-' . gmdate('Ymd-His') . '.zip';
        $path = $directory . '/' . $filename;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the backup archive.');
        }

        $database = $directory . '/database-' . bin2hex(random_bytes(4)) . '.sdb';
        try {
            $this->pdo->exec("VACUUM INTO '" . str_replace("'", "''", $database) . "'");
            @chmod($database, 0600);
            $zip->addFile($database, 'database.sdb');
        } catch (\Throwable) {
            // Database snapshot is best-effort; labs/templates still back up.
        }

        $this->addTree($zip, $this->config->labsDir, 'labs', '.unl');
        $this->addTree($zip, $this->config->templatesDir, 'templates', '.yaml');
        $zip->addFromString('settings.json', json_encode($this->settings->all(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $images = $this->pdo->query('SELECT id, kind, name, path, sha256, size, virtual_size, format, backing_file, template, external, created_at FROM images');
        $zip->addFromString('images.json', json_encode($images !== false ? $images->fetchAll() : [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $zip->close();
        @chmod($path, 0600);
        @unlink($database);

        return ['path' => $path, 'filename' => $filename];
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(UploadedFileInterface $file): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'The PHP zip extension is required for restores.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'The backup upload did not complete.');
        }
        $archiveCap = $this->settings->int('backup_max_archive_bytes', self::DEFAULT_MAX_ARCHIVE_BYTES);
        $declaredSize = $file->getSize();
        if ($declaredSize !== null && $declaredSize > $archiveCap) {
            throw new HttpException(413, 'The backup archive exceeds the configured size limit.');
        }
        $temporary = tempnam($this->config->dataDir, 'restore-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary restore file.');
        }
        @chmod($temporary, 0600);
        $stream = $file->getStream();
        $stream->rewind();
        file_put_contents($temporary, $stream->getContents());
        @chmod($temporary, 0600);
        $actualSize = filesize($temporary);
        if ($actualSize !== false && $actualSize > $archiveCap) {
            @unlink($temporary);
            throw new HttpException(413, 'The backup archive exceeds the configured size limit.');
        }
        $zip = new ZipArchive();
        if ($zip->open($temporary) !== true) {
            @unlink($temporary);
            throw new HttpException(422, 'The backup archive is not a valid zip file.');
        }

        // Inspect every entry before extracting anything. A rejected archive
        // must leave no labs, settings, or staged database behind.
        try {
            $this->validateArchive($zip);
        } catch (HttpException $exception) {
            $zip->close();
            @unlink($temporary);
            throw $exception;
        }

        $restored = ['labs' => 0, 'templates' => 0, 'images' => 0, 'images_skipped' => 0, 'settings' => false, 'database' => false];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (!$this->safeEntry($name)) {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            if ($contents === false) {
                continue;
            }
            $databaseCap = $this->settings->int('backup_max_database_bytes', self::DEFAULT_MAX_DATABASE_BYTES);
            if ($name === 'settings.json') {
                $settings = json_decode($contents, true);
                if (is_array($settings)) {
                    $this->settings->update($settings);
                    $restored['settings'] = true;
                }
                continue;
            }
            if ($name === 'images.json') {
                $images = json_decode($contents, true);
                if (is_array($images)) {
                    $result = $this->restoreImages($images);
                    $restored['images'] = $result['restored'];
                    $restored['images_skipped'] = $result['skipped'];
                }
                continue;
            }
            if ($name === 'database.sdb') {
                if (strlen($contents) > $databaseCap) {
                    $zip->close();
                    @unlink($temporary);
                    throw new HttpException(413, 'The backup database exceeds the configured size limit.');
                }
                $directory = $this->config->dataDir . '/restore';
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('Cannot create the restore staging directory.');
                }
                @chmod($directory, 0700);
                file_put_contents($directory . '/database.sdb', $contents);
                @chmod($directory . '/database.sdb', 0600);
                file_put_contents($directory . '/pending', (string) time());
                @chmod($directory . '/pending', 0600);
                $restored['database'] = true;
                continue;
            }
            if (str_starts_with($name, 'labs/') && str_ends_with(strtolower($name), '.unl')) {
                $this->writeUnique($this->config->labsDir, substr($name, 5), $contents);
                $restored['labs']++;
                continue;
            }
            if (str_starts_with($name, 'templates/') && str_ends_with(strtolower($name), '.yaml')) {
                $this->writeUnique($this->config->templatesDir, basename($name), $contents);
                $restored['templates']++;
            }
        }
        $zip->close();
        @unlink($temporary);

        return $restored;
    }

    /**
     * Reject oversized, zip-bomb, nested, or path-traversing archives before
     * the extraction pass reads a single entry.
     */
    private function validateArchive(ZipArchive $zip): void
    {
        $entryCap = $this->settings->int('backup_max_entries', self::DEFAULT_MAX_ENTRIES);
        if ($zip->numFiles > $entryCap) {
            throw new HttpException(413, 'The backup archive contains too many entries.');
        }

        $uncompressedCap = $this->settings->int(
            'backup_max_uncompressed_bytes',
            self::DEFAULT_MAX_UNCOMPRESSED_BYTES,
        );
        $databaseCap = $this->settings->int('backup_max_database_bytes', self::DEFAULT_MAX_DATABASE_BYTES);
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if ($this->unsafeEntry($name)) {
                throw new HttpException(422, 'The backup archive contains an unsafe path.');
            }
            if ($this->nestedArchive($name)) {
                throw new HttpException(422, 'Nested archives are not supported in a backup restore.');
            }
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new HttpException(422, 'The backup archive contains an unreadable entry.');
            }
            $size = $stat['size'];
            if ($size < 0) {
                throw new HttpException(422, 'The backup archive contains an invalid entry size.');
            }
            $total += $size;
            if ($total > $uncompressedCap) {
                throw new HttpException(413, 'The backup archive expands beyond the configured size limit.');
            }
            if ($name === 'database.sdb' && $size > $databaseCap) {
                throw new HttpException(413, 'The backup database exceeds the configured size limit.');
            }
        }
    }

    private function unsafeEntry(string $name): bool
    {
        return $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '..')
            || str_starts_with($name, '/')
            || str_contains($name, '\\');
    }

    private function nestedArchive(string $name): bool
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, self::NESTED_ARCHIVE_EXTENSIONS, true);
    }

    private function addTree(ZipArchive $zip, string $root, string $prefix, string $extension): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== ltrim($extension, '.')) {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, realpath($root) . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = ltrim(substr($path, strlen((string) realpath($root))), DIRECTORY_SEPARATOR);
            if (str_contains($relative, '.trash' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $zip->addFile($path, $prefix . '/' . $relative);
        }
    }

    private function safeEntry(string $name): bool
    {
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
            return false;
        }
        foreach (['labs/', 'templates/'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return in_array($name, ['settings.json', 'images.json', 'database.sdb'], true);
    }

    private function writeUnique(string $root, string $relative, string $contents): void
    {
        $relative = ltrim($relative, '/');
        $target = $root . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create restore directory.');
        }
        if (file_exists($target)) {
            $info = pathinfo($target);
            $target = $directory . '/' . $info['filename'] . '-restored-' . gmdate('YmdHis') . '.' . ($info['extension'] ?? 'dat');
        }
        file_put_contents($target, $contents);
        @chmod($target, 0640);
    }

    /**
     * Linked rows whose target is missing or dangling on the new host are
     * skipped (and counted) instead of restoring a broken symlink.
     *
     * @param list<mixed> $images
     * @return array{restored: int, skipped: int}
     */
    private function restoreImages(array $images): array
    {
        $count = 0;
        $skipped = 0;
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO images (id, kind, name, path, sha256, size, virtual_size, format, backing_file, notes, template, created_at, external) '
            . 'VALUES (:id, :kind, :name, :path, :sha256, :size, :virtual_size, :format, :backing_file, :notes, :template, :created_at, :external)'
        );
        foreach ($images as $image) {
            if (!is_array($image) || !isset($image['path']) || !is_string($image['path'])) {
                continue;
            }
            $external = (int) ($image['external'] ?? 0) === 1;
            if (!is_file($image['path'])) {
                if ($external) {
                    $skipped++;
                }
                continue;
            }
            $statement->execute([
                ':id' => (string) ($image['id'] ?? bin2hex(random_bytes(16))),
                ':kind' => (string) ($image['kind'] ?? 'qemu'),
                ':name' => (string) ($image['name'] ?? basename($image['path'])),
                ':path' => $image['path'],
                ':sha256' => (string) ($image['sha256'] ?? hash_file('sha256', $image['path'])),
                ':size' => (int) ($image['size'] ?? filesize($image['path'])),
                ':virtual_size' => (int) ($image['virtual_size'] ?? 0),
                ':format' => (string) ($image['format'] ?? ''),
                ':backing_file' => (string) ($image['backing_file'] ?? ''),
                ':notes' => $image['notes'] ?? null,
                ':template' => $image['template'] ?? null,
                ':created_at' => (int) ($image['created_at'] ?? time()),
                ':external' => $external ? 1 : 0,
            ]);
            $count++;
        }

        return ['restored' => $count, 'skipped' => $skipped];
    }
}
