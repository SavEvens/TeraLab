<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Image\ImageStore;
use TeraLab\Api\Image\LegacyAddonsImporter;
use TeraLab\Api\Labs\LabRepository;

final class ImageController
{
    public function __construct(
        private readonly ImageStore $store,
        private readonly AgentClient $agent,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
        private readonly LabRepository $labs,
        private readonly LegacyAddonsImporter $importer,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, ['data' => $this->store->all()]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $image = $this->store->find($args['id'] ?? '');
        if ($image === null) {
            throw new HttpException(404, 'Image not found.');
        }

        return Json::response($response, ['data' => $image]);
    }

    /**
     * Adopt images from an existing addons tree (dry run or import).
     */
    public function importLegacy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'admin');
        $body = Json::body($request);

        $root = $body['root'] ?? LegacyAddonsImporter::DEFAULT_ROOT;
        if (!is_string($root) || trim($root) === '') {
            throw new HttpException(422, 'root must be a non-empty absolute path.');
        }
        if ($this->hasControlCharacter($root)) {
            throw new HttpException(422, 'root must not contain control characters.');
        }

        $dryRun = $body['dry_run'] ?? true;
        if (!is_bool($dryRun)) {
            throw new HttpException(422, 'dry_run must be a boolean.');
        }

        $user = $request->getAttribute('user');
        $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;

        if ($dryRun) {
            return Json::response($response, [
                'status' => 'success',
                'data' => $this->importer->scan($root),
            ]);
        }

        $files = $body['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw new HttpException(422, 'files is required when dry_run is false.');
        }
        if (!array_is_list($files)) {
            throw new HttpException(422, 'files must be a JSON list of relative paths, not an object.');
        }
        $requested = [];
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                throw new HttpException(422, 'files must be a list of relative paths.');
            }
            if ($this->hasControlCharacter($file)) {
                throw new HttpException(422, 'files must not contain control characters.');
            }
            $requested[] = $file;
        }

        $templates = $body['templates'] ?? [];
        if (!is_array($templates)) {
            throw new HttpException(422, 'templates must be an object keyed by relative path.');
        }
        $overrides = [];
        foreach ($templates as $path => $template) {
            if (!is_string($path) || !is_string($template) || $template === '') {
                throw new HttpException(422, 'templates must map relative paths to template ids.');
            }
            $overrides[$path] = $template;
        }

        $data = $this->importer->import($root, $requested, $overrides, $userId);
        $this->audit->log($userId, $this->ip($request), 'image.import', (string) $data['root'], 'success', [
            'root' => $data['root'],
            'summary' => $data['summary'],
        ]);

        return Json::response($response, ['status' => 'success', 'data' => $data]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'editor');
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            $length = (int) $request->getHeaderLine('Content-Length');
            if ($length > 0) {
                throw new HttpException(413, 'The upload was rejected before TeraLab received it. Raise PHP upload_max_filesize and post_max_size (the installer sets 4G).');
            }
            throw new HttpException(422, 'A multipart "file" field is required.');
        }

        $body = $request->getParsedBody();
        $fields = is_array($body) ? $body : [];
        $kind = $this->stringField($fields, 'kind');
        $name = $this->stringField($fields, 'name');
        if ($kind === null) {
            $kind = $this->kindFromName($file->getClientFilename() ?? '');
        }

        $user = $request->getAttribute('user');
        $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;

        $image = $this->store->store($file, $kind, $name, $userId);
        $this->audit->log($userId, $this->ip($request), 'image.upload', (string) $image['id'], 'success', ['sha256' => $image['sha256']]);

        return Json::response($response, ['status' => 'success', 'data' => $image], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'editor');
        $nodeImages = [];
        try {
            $status = $this->agent->status();
            foreach (($status['nodes'] ?? []) as $node) {
                // The agent keeps stopped nodes in its map, still carrying the
                // spec image. Only a running node holds the on-disk image, so a
                // stopped node must not pin an otherwise unused image.
                if (!is_array($node) || ($node['state'] ?? '') !== 'running') {
                    continue;
                }
                if (isset($node['image']) && is_string($node['image'])) {
                    $nodeImages[] = $node['image'];
                }
            }
        } catch (\Throwable) {
            $nodeImages = [];
        }

        // Lab files reference images by name; deleting a referenced image
        // would leave those labs unstartable.
        $image = $this->store->find($args['id'] ?? '');
        $reference = is_array($image) && is_string($image['reference'] ?? null) ? $image['reference'] : '';
        $imagePath = is_array($image) && is_string($image['path'] ?? null) ? (string) $image['path'] : '';
        foreach ($this->labs->all() as $lab) {
            foreach ((array) ($lab['node_list'] ?? []) as $node) {
                if (!is_array($node)) {
                    continue;
                }
                foreach (['image', 'iso', 'kernel', 'initrd'] as $key) {
                    $value = (string) ($node[$key] ?? '');
                    if ($value === '') {
                        continue;
                    }
                    if (($reference !== '' && $value === $reference)
                        || $this->store->resolveReference($value) === $imagePath
                    ) {
                        throw new HttpException(
                            409,
                            'The image is referenced by lab "' . (string) ($lab['name'] ?? '') . '".',
                        );
                    }
                }
            }
        }

        $user = $request->getAttribute('user');
        $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;
        $this->store->delete($args['id'] ?? '', $nodeImages);
        $this->audit->log($userId, $this->ip($request), 'image.delete', (string) ($args['id'] ?? ''), 'success');

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * @param array<string, string> $args
     */
    public function rename(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'editor');
        $body = Json::body($request);
        $name = $body['name'] ?? null;
        $reference = $body['reference'] ?? null;
        if (!is_string($name) && !is_string($reference)) {
            throw new HttpException(422, 'name or reference is required.');
        }
        if (!is_string($name)) {
            $existing = $this->store->find($args['id'] ?? '');
            $name = is_array($existing) ? (string) ($existing['name'] ?? '') : '';
        }
        $image = $this->store->rename($args['id'] ?? '', $name, is_string($reference) ? $reference : null);
        $user = $request->getAttribute('user');
        $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;
        $this->audit->log($userId, $this->ip($request), 'image.rename', (string) $image['id'], 'success', ['name' => $name]);

        return Json::response($response, ['status' => 'success', 'data' => $image]);
    }

    public function templates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, ['data' => $this->store->templates()]);
    }

    /**
     * @param array<string, string> $args
     */
    public function mapTemplate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'editor');
        $body = Json::body($request);
        $template = $body['template'] ?? null;
        if (!is_string($template) || $template === '') {
            throw new HttpException(422, 'template is required.');
        }
        $image = $this->store->mapTemplate($args['id'] ?? '', $template);
        $user = $request->getAttribute('user');
        $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;
        $this->audit->log($userId, $this->ip($request), 'image.template', (string) $image['id'], 'success', ['template' => $template]);

        return Json::response($response, ['status' => 'success', 'data' => $image]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function stringField(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hasControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1f]/', $value) === 1;
    }

    private function ip(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($address) ? $address : '0.0.0.0';
    }

    private function kindFromName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'qcow2' => 'qemu',
            'iso' => 'iso',
            'bin' => 'iol',
            default => '',
        };
    }
}
