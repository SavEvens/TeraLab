<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Templates\TemplateRepository;

final class TemplateController
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
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
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
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
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $this->templates->deleteCustom($args['id'] ?? '');
        $this->audit->log((string) $user['id'], $this->address($request), 'template.delete', (string) ($args['id'] ?? ''), 'success');

        return Json::response($response, ['status' => 'success']);
    }

    private function address(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($address) ? $address : '0.0.0.0';
    }
}
