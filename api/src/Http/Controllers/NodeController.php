<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;

final class NodeController
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly Config $config,
    ) {
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = Json::body($request);
        $spec = $this->spec($data);

        return Json::response($response, [
            'status' => 'accepted',
            'data' => $this->agent->start($spec),
        ], 202);
    }

    public function stop(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->id(Json::body($request));

        return Json::response($response, [
            'status' => 'success',
            'data' => $this->agent->stop($id),
        ]);
    }

    public function wipe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->id(Json::body($request));

        return Json::response($response, [
            'status' => 'success',
            'data' => $this->agent->wipe($id),
        ]);
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $request->getQueryParams()['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new HttpException(422, 'id is required.');
        }

        return Json::response($response, ['data' => $this->agent->nodeStatus($id)]);
    }

    public function console(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $id = $this->id(Json::body($request), 'node_id');
        $session = $this->agent->openConsole($id);
        $token = $session['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new HttpException(502, 'The agent did not return a console token.');
        }

        $kind = $session['kind'] ?? 'telnet';
        $kind = is_string($kind) ? $kind : 'telnet';
        $path = $kind === 'vnc' ? $this->config->consoleVncPath : $this->config->consolePath;

        $data = [
            'kind' => $kind,
            'token' => $token,
            'expires_in' => $session['expires_in'] ?? 0,
            'ws_path' => $path . '?token=' . rawurlencode($token),
        ];
        if ($kind === 'vnc') {
            $data['password'] = $session['password'] ?? '';
        }

        return Json::response($response, ['data' => $data]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function id(array $data, string $key = 'id'): string
    {
        $id = $data[$key] ?? null;
        if (!is_string($id) || $id === '') {
            throw new HttpException(422, $key . ' is required.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function spec(array $data): array
    {
        $spec = [
            'id' => $this->id($data),
            'name' => $this->string($data, 'name', required: true, maxLength: 128),
        ];

        foreach (['engine', 'lab_id', 'image', 'iso', 'kernel', 'initrd', 'append', 'nic', 'machine', 'console', 'uuid', 'platform', 'iourc'] as $key) {
            $value = $this->string($data, $key);
            if ($value !== null) {
                $spec[$key] = $value;
            }
        }

        foreach (['cpu' => 64, 'ram' => 131072, 'ethernet' => 16, 'nvram' => 4096] as $key => $maximum) {
            $value = $data[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_int($value) || $value < 0 || $value > $maximum) {
                throw new HttpException(422, $key . ' is out of range.');
            }
            $spec[$key] = $value;
        }

        $engine = $spec['engine'] ?? 'qemu';
        if (!in_array($engine, ['qemu', 'vpcs', 'dynamips', 'iol'], true)) {
            throw new HttpException(422, 'Unsupported engine.');
        }

        $slots = $data['slots'] ?? null;
        if ($slots !== null) {
            if (!is_array($slots)) {
                throw new HttpException(422, 'slots must be a list.');
            }
            foreach ($slots as $slot) {
                if (!is_string($slot)) {
                    throw new HttpException(422, 'slots must be a list of strings.');
                }
            }
            $spec['slots'] = $slots;
        }

        $networks = $data['networks'] ?? null;
        if ($networks !== null) {
            if (!is_array($networks)) {
                throw new HttpException(422, 'networks must be a list.');
            }
            foreach ($networks as $network) {
                if (!is_string($network)) {
                    throw new HttpException(422, 'networks must be a list of strings.');
                }
            }
            $spec['networks'] = $networks;
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(
        array $data,
        string $key,
        bool $required = false,
        int $maxLength = 512,
    ): ?string {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw new HttpException(422, $key . ' is required.');
            }

            return null;
        }
        if (!is_string($value) || strlen($value) > $maxLength) {
            throw new HttpException(422, 'Invalid ' . $key . '.');
        }

        return $value;
    }
}
