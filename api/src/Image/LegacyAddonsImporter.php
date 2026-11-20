<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Image;

use Closure;
use PDO;
use RuntimeException;
use Throwable;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Templates\TemplateRepository;

/**
 * Adopts images that already exist on the host, such as a legacy
 * /opt/unetlab/addons tree, without copying a byte.
 *
 * Every adopted image is registered with a path inside the official store;
 * that path is a symlink to the legacy file. The importer never writes to or
 * moves the source tree.
 */
final class LegacyAddonsImporter
{
    public const DEFAULT_ROOT = '/opt/unetlab/addons';

    private const LICENCE_PATTERN = '/iourc|licen[cs]e/i';

    /** @var Closure(string): array<string, mixed> */
    private readonly Closure $inspector;

    /** @var list<string>|null */
    private ?array $templateIds = null;

    /**
     * @param (Closure(string): array<string, mixed>)|null $inspector
     */
    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        ?Closure $inspector = null,
        private readonly ?TemplateRepository $templates = null,
    ) {
        $this->inspector = $inspector ?? static fn (string $path): array => [];
    }

    /**
     * Dry run: describe every recognised image under the root.
     *
     * @return array<string, mixed>
     */
    public function scan(string $root): array
    {
        $root = $this->resolveRoot($root);
        $existing = $this->existingTargets();
        $sizes = $this->existingSizes();

        /** @var array<string, true> $reserved */
        $reserved = [];
        $entries = [];
        foreach ($this->candidates($root) as $candidate) {
            $entries[] = $this->scanCandidate($candidate, $root, $existing, $sizes, $reserved);
        }

        $summary = ['new' => 0, 'existing' => 0, 'duplicate' => 0, 'unreadable' => 0, 'skipped' => 0];
        foreach ($entries as $entry) {
            $status = (string) $entry['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        $warnings = [];
        if (!is_dir($root . '/qemu') && !is_dir($root . '/iol') && !is_dir($root . '/dynamips')) {
            $warnings[] = 'No qemu, iol, or dynamips directory was found under the addons root.';
        }

        return [
            'root' => $root,
            'dry_run' => true,
            'entries' => $entries,
            'warnings' => $warnings,
            'summary' => $summary,
        ];
    }

    /**
     * Adopt the requested files. Each entry is validated again against the
     * root before a store-side symlink is created.
     *
     * @param list<string> $files
     * @param array<string, string> $templateOverrides
     * @return array<string, mixed>
     */
    public function import(string $root, array $files, array $templateOverrides, ?string $userId): array
    {
        $root = $this->resolveRoot($root);

        $results = [];
        $summary = ['imported' => 0, 'duplicate' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($files as $file) {
            $result = $this->importFile($root, $file, $templateOverrides, $userId);
            $results[] = $result;
            $status = (string) $result['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return [
            'root' => $root,
            'dry_run' => false,
            'results' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * @param array{path: string, absolute: string, kind: ?string, reference: string, suggestion: string} $candidate
     * @param array<string, array<string, mixed>> $existing
     * @param array<int, true> $sizes
     * @param array<string, true> $reserved
     * @return array<string, mixed>
     */
    private function scanCandidate(array $candidate, string $root, array $existing, array $sizes, array &$reserved): array
    {
        $relative = $candidate['path'];
        $absolute = $candidate['absolute'];
        $name = basename($absolute);
        $size = @filesize($absolute);
        $suggestion = $this->suggestTemplate($candidate['suggestion']);

        $entry = [
            'path' => $relative,
            'kind' => $candidate['kind'] ?? $this->fallbackKind($relative),
            'size' => is_int($size) ? $size : 0,
            'reference' => null,
            'template' => null,
            'template_suggestion' => $suggestion,
            'status' => 'new',
            'warnings' => [],
            'error' => null,
        ];

        if ($this->isLicence($name)) {
            return $this->skipped($entry, 'Licence files are never registered.');
        }

        $class = $this->classify($relative);
        if ($class === null) {
            return $this->skipped($entry, 'Not a recognised addon image.');
        }
        $entry['kind'] = $class['kind'];

        $real = realpath($absolute);
        if ($real === false) {
            return $this->unreadable($entry, $candidate['reference'], $suggestion, $reserved);
        }
        if (!is_file($real)) {
            return $this->skipped($entry, 'Not a regular file.');
        }
        if (!$this->within($real, $root)) {
            return $this->skipped($entry, 'The file resolves outside the addons root.');
        }
        if (!is_readable($real)) {
            return $this->unreadable($entry, $candidate['reference'], $suggestion, $reserved);
        }
        try {
            $magic = ImageMagic::matches($real, $class['extension']);
        } catch (RuntimeException) {
            return $this->unreadable($entry, $candidate['reference'], $suggestion, $reserved);
        }
        if (!$magic) {
            return $this->skipped($entry, 'The file does not match the expected ' . $class['kind'] . ' format.');
        }

        if (isset($existing[$real])) {
            $row = $existing[$real];
            $reference = is_string($row['reference'] ?? null) && $row['reference'] !== ''
                ? (string) $row['reference']
                : $this->reserveReference($candidate['reference'], $reserved);
            $reserved[$reference] = true;
            $entry['reference'] = $reference;
            $entry['template'] = is_string($row['template'] ?? null) && $row['template'] !== ''
                ? (string) $row['template']
                : $suggestion;
            $entry['status'] = 'existing';

            return $entry;
        }

        $proposed = ImageReference::portable($candidate['reference']);
        $reference = $this->reserveReference($candidate['reference'], $reserved);
        $entry['reference'] = $reference;
        $entry['template'] = $suggestion;
        if ($reference !== $proposed) {
            $entry['warnings'][] = 'Reference ' . $proposed . ' is already in use; proposing ' . $reference . '.';
        }

        // A checksum duplicate necessarily has the same size, so a fresh tree
        // with no matching stored size is not hashed at all.
        if (!isset($sizes[(int) $entry['size']])) {
            return $entry;
        }

        $sha256 = @hash_file('sha256', $real);
        if (!is_string($sha256)) {
            $entry['status'] = 'unreadable';
            $entry['warnings'][] = 'The file is not readable by the API process.';

            return $entry;
        }
        $duplicate = $this->findByChecksum($sha256);
        if ($duplicate !== null) {
            $entry['status'] = 'duplicate';
            $entry['warnings'][] = 'Identical content is already stored as image ' . (string) $duplicate['id'] . '.';
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function skipped(array $entry, string $warning): array
    {
        $entry['status'] = 'skipped';
        $entry['warnings'][] = $warning;

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, true> $reserved
     * @return array<string, mixed>
     */
    private function unreadable(array $entry, string $reference, ?string $suggestion, array &$reserved): array
    {
        $entry['status'] = 'unreadable';
        $entry['reference'] = $this->reserveReference($reference, $reserved);
        $entry['template'] = $suggestion;
        $entry['warnings'][] = 'The file is not readable by the API process.';

        return $entry;
    }

    /**
     * @param array<string, string> $templateOverrides
     * @return array{path: string, status: string, image: array<string, mixed>|null, error: string|null, warnings: list<string>}
     */
    private function importFile(string $root, string $file, array $templateOverrides, ?string $userId): array
    {
        $warnings = [];
        $relative = $file;
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            return $this->failed($relative, 'The path must be relative to the addons root.');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return $this->failed($relative, 'The path must not contain empty, "." or ".." segments.');
            }
        }

        $absolute = $root . '/' . $relative;
        $real = realpath($absolute);
        if ($real === false) {
            return $this->failed($relative, 'The file does not exist.');
        }
        if (!$this->within($real, $root)) {
            return $this->failed($relative, 'The file resolves outside the addons root.');
        }
        if (!is_file($real)) {
            return $this->failed($relative, 'The path is not a regular file.');
        }
        if (!is_readable($real)) {
            return $this->failed($relative, 'The file is not readable.');
        }

        $name = basename($relative);
        if ($this->isLicence($name)) {
            return ['path' => $relative, 'status' => 'skipped', 'image' => null, 'error' => null, 'warnings' => ['Licence files are never registered.']];
        }
        if (str_starts_with($relative, 'docker/')) {
            return ['path' => $relative, 'status' => 'skipped', 'image' => null, 'error' => null, 'warnings' => ['Docker addons are not supported.']];
        }

        $class = $this->classify($relative);
        if ($class === null) {
            return $this->failed($relative, 'The file is not a recognised addon image.');
        }
        try {
            $magic = ImageMagic::matches($real, $class['extension']);
        } catch (RuntimeException) {
            return $this->failed($relative, 'The file is not readable.');
        }
        if (!$magic) {
            return $this->failed($relative, 'The file does not match the expected ' . $class['kind'] . ' format.');
        }

        $sha256 = @hash_file('sha256', $real);
        if (!is_string($sha256)) {
            return $this->failed($relative, 'The file could not be hashed.');
        }
        $duplicate = $this->findByChecksum($sha256);
        if ($duplicate !== null) {
            return ['path' => $relative, 'status' => 'duplicate', 'image' => $duplicate, 'error' => null, 'warnings' => []];
        }

        $override = $templateOverrides[$relative] ?? null;
        if ($override !== null && !$this->templateExists($override)) {
            return $this->failed($relative, 'Template not found: ' . $override . '.');
        }
        $template = $override ?? $this->suggestTemplate($this->suggestionName($relative, $class['kind']));

        $inspected = [];
        if ($class['kind'] === 'qemu') {
            try {
                $inspected = ($this->inspector)($real);
            } catch (Throwable $exception) {
                $warnings[] = 'The agent could not inspect the image: ' . $exception->getMessage();
            }
            $backing = $inspected['backing_file'] ?? null;
            if (is_string($backing) && $backing !== '') {
                $warnings[] = 'The image has a backing file (' . $backing . '); adoption continues.';
            }
        }

        $size = @filesize($real);
        $size = is_int($size) ? $size : 0;
        $id = $class['kind'] . '-' . substr($sha256, 0, 16);
        $directory = $this->config->imagesDir . '/' . $class['kind'] . '/' . $id;
        // Ownership flags: a failure may only undo what this call created.
        // A link or directory that already existed belongs to another row
        // (an earlier or concurrent import of the same checksum).
        $createdDirectory = false;
        if (!is_dir($directory)) {
            // Match ImageStore and the installer: the store directory is
            // traversed by tools running as another user (the agent runs as
            // teralab-vm), so it must not be more restrictive than an upload.
            $createdDirectory = mkdir($directory, 0755, true);
            if (!$createdDirectory && !is_dir($directory)) {
                return $this->failed($relative, 'Cannot create the image store directory.');
            }
        }
        $storePath = $directory . '/' . ImageReference::portable($name);

        $createdLink = false;
        if (is_link($storePath)) {
            if (realpath($storePath) !== $real) {
                return $this->failed($relative, 'The image store already contains a different file for this checksum.');
            }
        } elseif (file_exists($storePath)) {
            return $this->failed($relative, 'The image store already contains a file at this path.');
        } else {
            $createdLink = @symlink($real, $storePath);
            if (!$createdLink) {
                // A failure here may mean another import won the race; the
                // surviving link is then not ours to clean up.
                return $this->failed($relative, 'Cannot link the image into the store.');
            }
        }

        $backing = $inspected['backing_file'] ?? null;
        $row = [
            'id' => $id,
            'kind' => $class['kind'],
            'name' => ImageReference::portable($name),
            'reference' => $this->uniqueReference($this->referenceCandidate($relative, $class['kind'], $real)),
            'template' => $template,
            'path' => $storePath,
            'sha256' => $sha256,
            'size' => $size,
            'virtual_size' => (int) ($inspected['virtual_size'] ?? $size),
            'format' => $class['kind'] === 'qemu' ? 'qcow2' : $class['extension'],
            'backing_file' => is_string($backing) && $backing !== '' ? $backing : null,
            'notes' => $warnings === [] ? null : implode(' ', $warnings),
            'created_by' => $userId,
            'created_at' => time(),
            'external' => 1,
        ];

        try {
            $this->insert($row);
        } catch (Throwable $exception) {
            if ($createdLink) {
                @unlink($storePath);
            }
            if ($createdDirectory) {
                $this->removeEmptyDirectory($directory);
            }
            $existing = $this->findByChecksum($sha256);
            if ($existing !== null) {
                return ['path' => $relative, 'status' => 'duplicate', 'image' => $existing, 'error' => null, 'warnings' => $warnings];
            }

            return $this->failed($relative, 'Cannot register the image: ' . $exception->getMessage());
        }

        $image = $this->find($id);
        if ($image === null) {
            return $this->failed($relative, 'The image row was not stored.');
        }

        return ['path' => $relative, 'status' => 'imported', 'image' => $image, 'error' => null, 'warnings' => $warnings];
    }

    /**
     * @return array{path: string, status: string, image: null, error: string, warnings: list<string>}
     */
    private function failed(string $path, string $error): array
    {
        return ['path' => $path, 'status' => 'failed', 'image' => null, 'error' => $error, 'warnings' => []];
    }

    /**
     * @return list<array{path: string, absolute: string, kind: ?string, reference: string, suggestion: string}>
     */
    private function candidates(string $root): array
    {
        $candidates = [];

        $qemu = $root . '/qemu';
        foreach ($this->subdirectories($qemu) as $folder) {
            $folderName = basename($folder);
            $files = $this->filesIn($folder);
            $primary = $this->primaryQcow2($files);
            foreach ($files as $file) {
                $name = basename($file);
                $relative = 'qemu/' . $folderName . '/' . $name;
                $class = $this->classify($relative);
                $candidates[] = [
                    'path' => $relative,
                    'absolute' => $file,
                    'kind' => $class['kind'] ?? null,
                    'reference' => $primary !== null && $file === $primary ? $folderName : $folderName . '-' . $name,
                    'suggestion' => $folderName,
                ];
            }
        }

        $iol = $root . '/iol/bin';
        foreach ($this->filesIn($iol) as $file) {
            $name = basename($file);
            $relative = 'iol/bin/' . $name;
            $class = $this->classify($relative);
            $candidates[] = [
                'path' => $relative,
                'absolute' => $file,
                'kind' => $class['kind'] ?? 'iol',
                'reference' => $name,
                'suggestion' => $name,
            ];
        }

        $dynamips = $root . '/dynamips';
        foreach ($this->filesIn($dynamips) as $file) {
            $name = basename($file);
            $relative = 'dynamips/' . $name;
            $class = $this->classify($relative);
            $candidates[] = [
                'path' => $relative,
                'absolute' => $file,
                'kind' => $class['kind'] ?? 'dynamips',
                'reference' => $name,
                'suggestion' => $name,
            ];
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function subdirectories(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return [];
        }
        $directories = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $directories[] = $path;
            }
        }
        sort($directories, SORT_STRING);

        return $directories;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return [];
        }
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_file($path) || is_link($path)) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The QEMU primary disk: hda.qcow2, else the first qcow2.
     *
     * @param list<string> $files
     */
    private function primaryQcow2(array $files): ?string
    {
        $disks = [];
        foreach ($files as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'qcow2') {
                $disks[] = $file;
            }
        }
        foreach ($disks as $disk) {
            if (strtolower(basename($disk)) === 'hda.qcow2') {
                return $disk;
            }
        }

        return $disks[0] ?? null;
    }

    /**
     * @return array{kind: string, extension: string}|null
     */
    private function classify(string $relative): ?array
    {
        $parts = explode('/', $relative);
        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));

        if (count($parts) === 3 && $parts[0] === 'qemu') {
            return match ($extension) {
                'qcow2' => ['kind' => 'qemu', 'extension' => 'qcow2'],
                'iso' => ['kind' => 'iso', 'extension' => 'iso'],
                default => null,
            };
        }
        if (count($parts) === 3 && $parts[0] === 'iol' && $parts[1] === 'bin') {
            return $extension === 'bin' ? ['kind' => 'iol', 'extension' => 'bin'] : null;
        }
        if (count($parts) === 2 && $parts[0] === 'dynamips') {
            return in_array($extension, ['image', 'bin'], true)
                ? ['kind' => 'dynamips', 'extension' => $extension]
                : null;
        }

        return null;
    }

    private function fallbackKind(string $relative): string
    {
        $parts = explode('/', $relative);

        return match ($parts[0]) {
            'iol' => 'iol',
            'dynamips' => 'dynamips',
            default => 'qemu',
        };
    }

    private function suggestionName(string $relative, string $kind): string
    {
        if ($kind === 'qemu' || $kind === 'iso') {
            $parts = explode('/', $relative);

            return $parts[1] ?? basename($relative);
        }

        return basename($relative);
    }

    private function referenceCandidate(string $relative, string $kind, string $absolute): string
    {
        $base = basename($relative);
        if ($kind === 'qemu' || $kind === 'iso') {
            $parts = explode('/', $relative);
            $folder = $parts[1] ?? 'image';
            if ($kind === 'qemu') {
                $primary = $this->primaryQcow2($this->filesIn(dirname($absolute)));
                if ($primary !== null && basename($primary) === $base) {
                    return $folder;
                }
            }

            return $folder . '-' . $base;
        }

        return $base;
    }

    /**
     * Longest template id that is a prefix of the folder or file name.
     */
    private function suggestTemplate(string $name): ?string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;
        foreach ($this->templateIds() as $id) {
            $lower = strtolower($id);
            if (str_starts_with($name, $lower) && strlen($lower) > $bestLength) {
                $best = $id;
                $bestLength = strlen($lower);
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private function templateIds(): array
    {
        if ($this->templateIds !== null) {
            return $this->templateIds;
        }
        $ids = [];
        foreach ($this->templates?->all() ?? [] as $template) {
            $id = $template['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $this->templateIds = $ids;
    }

    private function templateExists(string $id): bool
    {
        if ($this->templates === null) {
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $id) === 1;
        }
        try {
            return $this->templates->find($id) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function isLicence(string $name): bool
    {
        return preg_match(self::LICENCE_PATTERN, $name) === 1;
    }

    private function within(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, rtrim($root, '/') . '/');
    }

    private function resolveRoot(string $root): string
    {
        $root = trim($root);
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new HttpException(422, 'The addons root must be an absolute path.');
        }
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new HttpException(422, 'The addons root does not exist or is not a directory.');
        }
        if (!is_readable($resolved)) {
            throw new HttpException(422, 'The addons root is not readable.');
        }

        return rtrim($resolved, '/') === '' ? '/' : rtrim($resolved, '/');
    }

    /**
     * Already adopted files keyed by resolved target path.
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingTargets(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, kind, name, reference, path, template FROM images WHERE external = 1'
        );
        if ($statement === false) {
            return [];
        }
        $targets = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = $row['path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }
            $target = realpath($path);
            if ($target !== false && is_file($target)) {
                $targets[$target] = $row;
            }
        }

        return $targets;
    }

    /**
     * @return array<int, true>
     */
    private function existingSizes(): array
    {
        $statement = $this->pdo->query('SELECT DISTINCT size FROM images');
        if ($statement === false) {
            return [];
        }
        $sizes = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $size) {
            $sizes[(int) $size] = true;
        }

        return $sizes;
    }

    /**
     * @param array<string, true> $reserved
     */
    private function reserveReference(string $candidate, array &$reserved): string
    {
        $proposed = ImageReference::portable($candidate);
        $reference = ImageReference::unique($proposed, function (string $value) use (&$reserved): bool {
            return isset($reserved[$value]) || $this->referenceExists($value);
        });
        $reserved[$reference] = true;

        return $reference;
    }

    private function uniqueReference(string $candidate): string
    {
        return ImageReference::unique(
            ImageReference::portable($candidate),
            fn (string $reference): bool => $this->referenceExists($reference),
        );
    }

    private function referenceExists(string $reference): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM images WHERE reference = :reference LIMIT 1');
        $statement->execute([':reference' => $reference]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByChecksum(string $sha256): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, template, external, created_at '
            . 'FROM images WHERE sha256 = :sha256 LIMIT 1'
        );
        $statement->execute([':sha256' => $sha256]);
        $row = $statement->fetch();

        return is_array($row) ? $this->publicRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, template, external, created_at '
            . 'FROM images WHERE id = :id'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->publicRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO images '
            . '(id, kind, name, reference, path, sha256, size, virtual_size, format, backing_file, notes, template, created_by, created_at, external) '
            . 'VALUES (:id, :kind, :name, :reference, :path, :sha256, :size, :virtual_size, :format, :backing_file, :notes, :template, :created_by, :created_at, :external)'
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
            ':template' => $row['template'],
            ':created_by' => $row['created_by'],
            ':created_at' => $row['created_at'],
            ':external' => $row['external'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        $row['size'] = (int) $row['size'];
        $row['virtual_size'] = (int) $row['virtual_size'];
        $row['external'] = (int) ($row['external'] ?? 0);
        $row['created_at'] = (int) $row['created_at'];

        return $row;
    }

    private function removeEmptyDirectory(string $directory): void
    {
        if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..']) {
            @rmdir($directory);
        }
    }
}
