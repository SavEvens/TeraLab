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

final class NetworkController
{
    public function __construct(private readonly AgentClient $agent)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function ensure(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $labID = $this->labID($args);
        $body = Json::body($request);
        $networks = $body['networks'] ?? null;
        if (!is_array($networks)) {
            throw new HttpException(422, 'networks must be a list.');
        }

        $payload = [];
        foreach ($networks as $network) {
            if (!is_array($network) || !isset($network['id']) || !is_string($network['id']) || $network['id'] === '') {
                throw new HttpException(422, 'Each network needs a string id.');
            }
            $entry = ['id' => $network['id']];
            if (isset($network['name']) && is_string($network['name'])) {
                $entry['name'] = $network['name'];
            }
            $payload[] = $entry;
        }

        $result = $this->agent->ensureLab($labID, $payload);
        $bridges = $result['bridges'] ?? [];
        $list = [];
        if (is_array($bridges)) {
            foreach ($bridges as $id => $name) {
                if (is_string($name)) {
                    $list[] = ['id' => (string) $id, 'bridge' => $name];
                }
            }
        }

        return Json::response($response, [
            'status' => 'success',
            'data' => ['lab_id' => $labID, 'bridges' => $list],
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function cleanup(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $labID = $this->labID($args);

        return Json::response($response, [
            'status' => 'success',
            'data' => $this->agent->cleanupLab($labID),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    private function labID(array $args): string
    {
        $labID = $args['id'] ?? '';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $labID) !== 1) {
            throw new HttpException(422, 'Invalid lab id.');
        }

        return $labID;
    }
}
