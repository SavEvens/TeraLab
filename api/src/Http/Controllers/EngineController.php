<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Labs\LabRepository;

final class EngineController
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AccessService $access,
        private readonly LabRepository $labs,
        private readonly Config $config,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, ['data' => $this->agent->engines()]);
    }

    public function dockerImages(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'admin');

        try {
            $images = $this->agent->dockerImages();
        } catch (HttpException $exception) {
            if ($exception->status < 500) {
                throw $exception;
            }

            throw new HttpException(
                502,
                'The Docker image list is unavailable: Docker is not available on the agent host.',
            );
        }

        // The inspector only needs the container reference. Never expose agent
        // host paths (or any other Docker-internal metadata) to the browser.
        $references = [];
        foreach ($images as $image) {
            $reference = $image['reference'] ?? null;
            if (is_string($reference) && $reference !== '') {
                $references[] = ['reference' => $reference];
            }
        }

        return Json::response($response, ['data' => $references]);
    }

    public function reconcile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->requireRole($this->access->user($request), 'admin');

        return Json::response($response, [
            'status' => 'success',
            'data' => $this->agent->reconcile($this->liveLabIDs()),
        ]);
    }

    /**
     * The keep-set for run-directory reconciliation: every lab that still
     * exists, excluding trash (soft-deleted labs). Never return an empty list
     * for a non-empty or unreadable lab store: an empty keep-set tells the
     * agent to free every lab run directory.
     *
     * @return list<string>
     */
    private function liveLabIDs(): array
    {
        $root = realpath($this->config->labsDir);
        if ($root === false || !is_dir($root)) {
            throw new HttpException(
                500,
                'The lab directory is not readable; refusing to reconcile without the live lab list.',
            );
        }

        $labs = $this->labs->all();
        $ids = [];
        foreach ($labs as $lab) {
            $id = $lab['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        if ($labs !== [] && $ids === []) {
            throw new HttpException(
                500,
                'No live lab id could be read; refusing to reconcile without the live lab list.',
            );
        }

        return array_values(array_unique($ids));
    }
}
