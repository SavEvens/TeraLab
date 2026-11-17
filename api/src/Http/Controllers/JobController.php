<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Jobs\JobService;

final class JobController
{
    public function __construct(
        private readonly JobService $jobs,
        private readonly AccessService $access,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->user($request);

        return Json::response($response, ['data' => $this->jobs->all()]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->access->user($request);
        $job = $this->jobs->find($args['id'] ?? '');
        if ($job === null) {
            throw new HttpException(404, 'Job not found.');
        }

        return Json::response($response, ['data' => $job]);
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $this->jobs->cancel($args['id'] ?? '');

        return Json::response($response, ['status' => 'success']);
    }
}
