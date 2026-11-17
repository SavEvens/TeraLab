<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Image\ImageStore;

final class ImageController
{
    public function __construct(
        private readonly ImageStore $store,
        private readonly AgentClient $agent,
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

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
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

        return Json::response($response, ['status' => 'success', 'data' => $image], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $nodeImages = [];
        try {
            $status = $this->agent->status();
            foreach (($status['nodes'] ?? []) as $node) {
                if (is_array($node) && isset($node['image']) && is_string($node['image'])) {
                    $nodeImages[] = $node['image'];
                }
            }
        } catch (\Throwable) {
            $nodeImages = [];
        }

        $this->store->delete($args['id'] ?? '', $nodeImages);

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function stringField(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
