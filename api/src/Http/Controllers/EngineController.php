<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Http\Json;

final class EngineController
{
    public function __construct(private readonly AgentClient $agent)
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, ['data' => $this->agent->engines()]);
    }

    public function reconcile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, [
            'status' => 'success',
            'data' => $this->agent->reconcile(),
        ]);
    }
}
