<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Http\RequestIp;
use TeraLab\Api\System\DemoGuard;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\Templates\TemplateRepository;

final class TemplateController
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly Config $config,
        private readonly DemoGuard $demoGuard,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->user($request);
        $templates = array_map(static function (array $template): array {
            unset($template['yaml']);

            return $template;
        }, $this->templates->all());

        return Json::response($response, ['data' => $templates]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->user($request);
        $template = $this->templates->find($args['id'] ?? '');
        if ($template === null) {
            throw new HttpException(404, 'Template not found.');
        }

        return Json::response($response, ['data' => $template]);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->mutableUser($request);
        $body = Json::body($request);
        $yaml = $body['yaml'] ?? null;
        if (!is_string($yaml) || trim($yaml) === '') {
            throw new HttpException(422, 'yaml is required.');
        }
        $template = $this->templates->saveCustom($args['id'] ?? '', $yaml);
        $this->audit->log((string) $user['id'], $this->address($request), 'template.save', (string) $template['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $template]);
    }

    /**
     * @param array<string, string> $args
     */
    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->user($request);
        $export = $this->templates->export($args['id'] ?? '');
        $response->getBody()->write($export['yaml']);

        return $response
            ->withHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->mutableUser($request);
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            $length = (int) $request->getHeaderLine('Content-Length');
            if ($length > 0) {
                throw new HttpException(413, 'The upload was rejected before TeraLab received it. Raise PHP upload_max_filesize and post_max_size.');
            }
            throw new HttpException(422, 'A multipart "file" field is required.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'The template upload did not complete.');
        }
        $stream = $file->getStream();
        $stream->rewind();
        $template = $this->templates->import($stream->getContents());
        $this->audit->log((string) $user['id'], $this->address($request), 'template.import', (string) $template['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $template], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function duplicate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->mutableUser($request);
        $template = $this->templates->duplicate($args['id'] ?? '');
        $this->audit->log((string) $user['id'], $this->address($request), 'template.duplicate', (string) $template['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $template]);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->mutableUser($request);
        $this->templates->deleteCustom($args['id'] ?? '');
        $this->audit->log((string) $user['id'], $this->address($request), 'template.delete', (string) ($args['id'] ?? ''), 'success');

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * Template mutations are editor work on the shared catalogue; guests are
     * read-only so they cannot shadow an allowlisted builtin template.
     *
     * @return array<string, mixed>
     */
    private function mutableUser(ServerRequestInterface $request): array
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $actor = $this->demoGuard->actor((string) ($user['id'] ?? ''));
        if ($actor !== null && DemoPolicy::isGuest($actor)) {
            throw new HttpException(403, 'The demo does not allow template changes.');
        }

        return $user;
    }

    private function address(ServerRequestInterface $request): string
    {
        return RequestIp::resolve($request->getServerParams(), $this->config->trustedProxies);
    }
}
