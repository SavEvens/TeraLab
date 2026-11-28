<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;

final class NetworkController
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AccessService $access,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function ensure(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccessWithRole($user, $args['id'] ?? '', 'editor');
        $declared = $this->declaredNetworks($lab);
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
            if (array_key_exists('bridge', $network)) {
                throw new HttpException(422, 'Bridge names are derived by the agent and are not accepted.');
            }
            if (!isset($declared[$network['id']])) {
                throw new HttpException(422, 'Network id is not declared in this lab.');
            }
            $entry = ['id' => $network['id']];
            if (isset($network['name']) && is_string($network['name'])) {
                $entry['name'] = $network['name'];
            }
            $payload[] = $entry;
        }

        $labID = (string) $lab['id'];
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
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccessWithRole($user, $args['id'] ?? '', 'editor');
        $labID = (string) $lab['id'];

        return Json::response($response, [
            'status' => 'success',
            'data' => $this->agent->cleanupLab($labID),
        ]);
    }

    /**
     * @param array<string, mixed> $lab
     * @return array<string, true>
     */
    private function declaredNetworks(array $lab): array
    {
        $declared = [];
        foreach (($lab['network_list'] ?? []) as $network) {
            if (is_array($network) && isset($network['id']) && is_string($network['id']) && $network['id'] !== '') {
                $declared[$network['id']] = true;
            }
        }

        return $declared;
    }
}
