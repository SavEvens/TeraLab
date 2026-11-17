<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;

final class LabController
{
    public function __construct(
        private readonly LabRepository $labs,
        private readonly LabService $service,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, ['data' => $this->labs->all()]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $lab = $this->labs->find($args['id'] ?? '');
        if ($lab === null) {
            throw new HttpException(404, 'Lab not found.');
        }

        return Json::response($response, ['data' => $lab]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $lab = $this->service->create(Json::body($request), $this->author($request));

        return Json::response($response, ['status' => 'success', 'data' => $lab], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = Json::body($request);
        $revision = $body['revision'] ?? null;
        $lab = $this->service->save(
            $args['id'] ?? '',
            $body,
            is_int($revision) ? $revision : null,
        );

        return Json::response($response, ['status' => 'success', 'data' => $lab]);
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            throw new HttpException(422, 'A multipart "file" field is required.');
        }
        $lab = $this->service->import($file, $this->author($request));

        return Json::response($response, ['status' => 'success', 'data' => $lab], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $export = $this->service->export($args['id'] ?? '');
        $response->getBody()->write($export['xml']);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->service->delete($args['id'] ?? '');

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * @param array<string, string> $args
     */
    public function start(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::response($response, [
            'status' => 'success',
            'data' => $this->service->start($args['id'] ?? ''),
        ], 202);
    }

    /**
     * @param array<string, string> $args
     */
    public function stop(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::response($response, [
            'status' => 'success',
            'data' => $this->service->stop($args['id'] ?? ''),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function wipe(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::response($response, [
            'status' => 'success',
            'data' => $this->service->wipe($args['id'] ?? ''),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function nodes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::response($response, [
            'data' => $this->service->nodes($args['id'] ?? ''),
        ]);
    }

    private function author(ServerRequestInterface $request): ?string
    {
        $user = $request->getAttribute('user');
        if (is_array($user) && isset($user['username']) && is_string($user['username'])) {
            return $user['username'];
        }

        return null;
    }
}
