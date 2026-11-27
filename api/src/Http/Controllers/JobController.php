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
        $user = $this->access->user($request);
        $jobs = $this->jobs->all();
        if (!$this->access->isAdmin($user)) {
            $userId = (string) ($user['id'] ?? '');
            $jobs = array_values(array_filter(
                $jobs,
                static fn (array $job): bool => (string) ($job['created_by'] ?? '') === $userId,
            ));
        }

        return Json::response($response, ['data' => $jobs]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $job = $this->jobs->find($args['id'] ?? '');
        if ($job === null) {
            throw new HttpException(404, 'Job not found.');
        }
        $this->assertJobAccess($user, $job);

        return Json::response($response, ['data' => $job]);
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $job = $this->jobs->find($args['id'] ?? '');
        if ($job === null) {
            throw new HttpException(404, 'Job not found.');
        }
        $this->assertJobAccess($user, $job);
        $this->jobs->cancel((string) $job['id']);

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $job
     */
    private function assertJobAccess(array $user, array $job): void
    {
        if ($this->access->isAdmin($user)) {
            return;
        }
        if ((string) ($job['created_by'] ?? '') !== (string) ($user['id'] ?? '')) {
            throw new HttpException(403, 'You do not have access to this job.');
        }
    }
}
