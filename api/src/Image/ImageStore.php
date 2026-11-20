<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Image;

use Closure;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Templates\TemplateRepository;
use TeraLab\Api\System\SettingsService;

/**
 * Content-addressed image store.
 *
 * Uploads are streamed to a temporary file, validated by magic and by the
 * agent's qemu-img inspection, hashed, and only then moved into place.
 */
final class ImageStore
{
    public const KINDS = ['qemu', 'iso', 'iol', 'dynamips'];

    private const EXTENSIONS = [
        'qemu' => 'qcow2',
        'iso' => 'iso',
        'iol' => 'bin',
        'dynamips' => 'bin',
    ];

    /** @var Closure(string): array<string, mixed> */
    private readonly Closure $inspector;

    /**
     * @param (Closure(string): array<string, mixed>)|null $inspector
     */
    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        ?Closure $inspector = null,
        private readonly ?TemplateRepository $templates = null,
        private readonly ?SettingsService $settings = null,
    ) {
        $this->inspector = $inspector ?? static fn (string $path): array => [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, template, created_at '
            . 'FROM images ORDER BY created_at DESC, name'
        );
        if ($statement === false) {
            return [];
        }

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $this->publicRow($row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, template, created_at '
            . 'FROM images WHERE id = :id'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->publicRow($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function store(
        UploadedFileInterface $file,
        string $kind,
        ?string $name,
        ?string $userId,
    ): array {
        if (!in_array($kind, self::KINDS, true)) {
            throw new HttpException(422, 'Unsupported image kind.');
        }
        $error = $file->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpException(413, 'The upload exceeds the server limit.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'The upload did not complete.');
        }

        $clientName = $this->safeName($file->getClientFilename() ?? '');
        $extension = self::EXTENSIONS[$kind];
        if (!str_ends_with(strtolower($clientName), '.' . $extension)) {
            throw new HttpException(422, 'The file extension does not match the image kind.');
        }

        $size = $file->getSize();
        if ($size !== null && $size > $this->maxUploadBytes()) {
            throw new HttpException(413, 'The image exceeds the configured size limit.');
        }

        $temporary = $this->writeTemporary($file);
        try {
            $this->validateMagic($temporary, $extension);

            $inspected = [];
            if ($kind === 'qemu') {
                $inspected = ($this->inspector)($temporary);
                $format = $inspected['format'] ?? '';
                if ($format !== 'qcow2') {
                    throw new HttpException(422, 'The agent could not confirm a qcow2 image.');
                }
                if (($inspected['backing_file'] ?? '') !== '') {
                    throw new HttpException(422, 'Base images must not have a backing file.');
                }
            }

            $sha256 = (string) hash_file('sha256', $temporary);
            if ($this->config->imageQuotaBytes > 0) {
                $used = (int) $this->pdo->query('SELECT COALESCE(SUM(size), 0) FROM images')->fetchColumn();
                if ($used + (filesize($temporary) ?: 0) > $this->config->imageQuotaBytes) {
                    throw new HttpException(507, 'The image store quota is exhausted.');
                }
            }
            $existing = $this->findByChecksum($sha256);
            if ($existing !== null) {
                return $existing;
            }

            $id = $kind . '-' . substr($sha256, 0, 16);
            $finalDir = $this->config->imagesDir . '/' . $kind . '/' . $id;
            if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true) && !is_dir($finalDir)) {
                throw new RuntimeException('Cannot create the image directory.');
            }

            $path = $finalDir . '/' . $clientName;
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot move the image into place.');
            }
            @chmod($path, in_array($kind, ['iol', 'dynamips'], true) ? 0755 : 0644);

            $row = [
                'id' => $id,
                'kind' => $kind,
                'name' => $name !== null && $name !== '' ? $name : $clientName,
                'reference' => $this->uniqueReference(ImageReference::portable($clientName)),
                'path' => $path,
                'sha256' => $sha256,
                'size' => filesize($path) ?: 0,
                'virtual_size' => (int) ($inspected['virtual_size'] ?? (filesize($path) ?: 0)),
                'format' => $kind === 'qemu' ? 'qcow2' : $extension,
                'backing_file' => $inspected['backing_file'] ?? null,
                'notes' => null,
                'created_by' => $userId,
                'created_at' => time(),
            ];

            $this->writeMetadata($finalDir, $row);
            $this->insert($row);
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }

        return $this->find($row['id']) ?? throw new RuntimeException('Image row was not stored.');
    }

    /**
     * @return array<string, mixed>
     */
    public function mapTemplate(string $id, string $template): array
    {
        $image = $this->find($id);
        if ($image === null) {
            throw new HttpException(404, 'Image not found.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $template) !== 1) {
            throw new HttpException(422, 'Invalid template id.');
        }
        if ($this->templates !== null) {
            if ($this->templates->find($template) === null) {
                throw new HttpException(404, 'Template not found.');
            }
        } else {
            $file = $this->config->root . '/html/templates/' . $template . '.php';
            if (!is_file($file)) {
                throw new HttpException(404, 'Template not found.');
            }
        }
        $statement = $this->pdo->prepare('UPDATE images SET template = :template WHERE id = :id');
        $statement->execute([':template' => $template, ':id' => $id]);

        return $this->find($id) ?? throw new HttpException(404, 'Image not found.');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function templates(): array
    {
        $directory = $this->config->root . '/html/templates';
        $templates = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $id = basename($file, '.php');
            $templates[] = ['id' => $id, 'name' => $id];
        }
        usort($templates, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $templates;
    }

    /**
     * @return array<string, mixed>
     */
    public function rename(string $id, string $name, ?string $reference = null): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 128 || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new HttpException(422, 'Invalid image name.');
        }
        $current = $this->find($id);
        if ($current === null) {
            throw new HttpException(404, 'Image not found.');
        }
        if ($reference !== null) {
            $reference = trim($reference);
            if (!ImageReference::isPortable($reference) || $reference === '') {
                throw new HttpException(422, 'Invalid image reference.');
            }
            if (($current['reference'] ?? '') !== $reference && $this->referenceExists($reference)) {
                throw new HttpException(409, 'Another image already uses that reference.');
            }
        }
        $statement = $this->pdo->prepare('UPDATE images SET name = :name, reference = :reference WHERE id = :id');
        $statement->execute([
            ':name' => $name,
            ':reference' => $reference ?? (string) ($current['reference'] ?? ''),
            ':id' => $id,
        ]);

        return $this->find($id) ?? throw new HttpException(404, 'Image not found.');
    }

    /**
     * Rename the portable reference used by lab files. References are unique
     * because labs identify images by them.
     */
    public function setReference(string $id, string $reference): array
    {
        $reference = trim($reference);
        if (!ImageReference::isPortable($reference) || $reference === '') {
            throw new HttpException(422, 'Invalid image reference.');
        }
        $current = $this->find($id);
        if ($current === null) {
            throw new HttpException(404, 'Image not found.');
        }
        if (($current['reference'] ?? '') !== $reference && $this->referenceExists($reference)) {
            throw new HttpException(409, 'Another image already uses that reference.');
        }
        $statement = $this->pdo->prepare('UPDATE images SET reference = :reference WHERE id = :id');
        $statement->execute([':reference' => $reference, ':id' => $id]);

        return $this->find($id) ?? throw new HttpException(404, 'Image not found.');
    }

    /** Resolve a portable reference (or a legacy path) to an absolute path. */
    public function resolveReference(string $value): ?string
    {
        return (new ImageReferences($this->pdo, $this->config))->resolve($value);
    }

    /** The reference belonging to the image stored at an absolute path. */
    public function referenceForPath(string $path): ?string
    {
        return (new ImageReferences($this->pdo, $this->config))->referenceForPath($path);
    }

    private function referenceExists(string $reference): bool
    {
        return (new ImageReferences($this->pdo, $this->config))->exists($reference);
    }

    private function uniqueReference(string $candidate): string
    {
        return (new ImageReferences($this->pdo, $this->config))->unique($candidate);
    }

    /**
     * @param list<string> $activePaths
     */
    public function delete(string $id, array $activePaths = []): void
    {
        $image = $this->find($id);
        if ($image === null) {
            throw new HttpException(404, 'Image not found.');
        }
        $path = (string) $image['path'];
        foreach ($activePaths as $active) {
            if ($active !== '' && realpath($active) === realpath($path)) {
                throw new HttpException(409, 'The image is in use by a running node.');
            }
        }

        $directory = dirname($path);
        $root = realpath($this->config->imagesDir);
        $resolved = realpath($directory);
        if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new HttpException(409, 'Refusing to delete outside the image store.');
        }

        $this->removeDirectory($resolved);
        $statement = $this->pdo->prepare('DELETE FROM images WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO images '
            . '(id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, created_by, created_at) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, :backing_file, :notes, :created_by, :created_at)'
        );
        $statement->execute([
            ':id' => $row['id'],
            ':kind' => $row['kind'],
            ':name' => $row['name'],
            ':reference' => $row['reference'],
            ':path' => $row['path'],
            ':sha256' => $row['sha256'],
            ':size' => $row['size'],
            ':virtual_size' => $row['virtual_size'],
            ':format' => $row['format'],
            ':backing_file' => $row['backing_file'],
            ':notes' => $row['notes'],
            ':created_by' => $row['created_by'],
            ':created_at' => $row['created_at'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByChecksum(string $sha256): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, template, created_at '
            . 'FROM images WHERE sha256 = :sha256'
        );
        $statement->execute([':sha256' => $sha256]);
        $row = $statement->fetch();

        return is_array($row) ? $this->publicRow($row) : null;
    }

    private function maxUploadBytes(): int
    {
        return $this->settings?->int('max_upload_bytes', $this->config->maxUploadBytes) ?? $this->config->maxUploadBytes;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $row['size'] = (int) $row['size'];
        $row['virtual_size'] = (int) $row['virtual_size'];
        $row['created_at'] = (int) $row['created_at'];

        return $row;
    }

    private function writeTemporary(UploadedFileInterface $file): string
    {
        $directory = $this->config->imagesDir . '/tmp';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the upload directory.');
        }
        $temporary = tempnam($directory, 'upload-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);
            throw new RuntimeException('Cannot open the temporary file.');
        }

        $written = 0;
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(1048576);
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $this->maxUploadBytes()) {
                    throw new HttpException(413, 'The image exceeds the configured size limit.');
                }
                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException('Cannot write the uploaded image.');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            @unlink($temporary);
            throw new HttpException(422, 'The uploaded file is empty.');
        }

        return $temporary;
    }

    private function validateMagic(string $path, string $extension): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the uploaded image.');
        }
        try {
            $header = fread($handle, 4);
            if ($extension === 'qcow2' && $header !== "QFI\xfb") {
                throw new HttpException(422, 'The file is not a qcow2 image.');
            }
            if ($extension === 'iso') {
                $found = false;
                foreach ([0x8001, 0x8801] as $offset) {
                    if (fseek($handle, $offset) !== 0) {
                        continue;
                    }
                    if (fread($handle, 5) === 'CD001') {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new HttpException(422, 'The file is not an ISO 9660 image.');
                }
            }
            if ($extension === 'bin' && $header !== "\x7fELF") {
                throw new HttpException(422, 'The file is not an ELF binary.');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeMetadata(string $directory, array $row): void
    {
        @file_put_contents(
            $directory . '/meta.json',
            json_encode($row, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        $base = ltrim($base, '.');
        if ($base === '') {
            $base = 'image';
        }

        return substr($base, 0, 128);
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
