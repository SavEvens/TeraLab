<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Labs\LabService;

final class NodeController
{
    public function __construct(
        private readonly AccessService $access,
        private readonly LabService $labs,
        private readonly Config $config,
    ) {
    }

    /**
     * Open a console through a lab-scoped node pair.
     *
     * The client never supplies an agent node id: the authorized lab and node
     * resolve it on the server.
     *
     * @param array<string, string> $args
     */
    public function console(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccessWithRole($user, $args['id'] ?? '', 'editor');
        $userId = isset($user['id']) && is_string($user['id']) ? $user['id'] : null;
        $session = $this->labs->openConsole((string) $lab['id'], $args['node'] ?? '', $userId);
        $token = $session['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new HttpException(502, 'The agent did not return a console token.');
        }

        $kind = $session['kind'] ?? 'telnet';
        $kind = is_string($kind) ? $kind : 'telnet';
        $port = $session['port'] ?? null;
        $path = $this->config->consolePath;
        if ($kind === 'vnc') {
            $path = $this->config->consoleVncPath;
        } elseif (is_int($port) && $port > 0) {
            $path = $this->config->consoleTcpPath;
        }

        // The token is a capability. It must never travel in a URL or query
        // string: access logs and browser history would retain it. The client
        // offers it as the WebSocket subprotocol instead.
        $data = [
            'kind' => $kind,
            'token' => $token,
            'expires_in' => $session['expires_in'] ?? 0,
            'ws_path' => $path,
            'subprotocol' => 'teralab.console.' . $token,
        ];
        if ($kind === 'vnc') {
            $data['password'] = $session['password'] ?? '';
        }
        if (is_int($port) && $port > 0) {
            $data['port'] = $port;
        }

        return Json::response($response, ['data' => $data]);
    }
}
