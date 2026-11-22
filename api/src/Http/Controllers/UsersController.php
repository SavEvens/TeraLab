<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\UserService;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Http\RequestIp;

/**
 * Thin admin-only HTTP surface over {@see UserService}. Non-admin callers are
 * rejected by the role middleware before reaching this controller.
 */
final class UsersController
{
    public function __construct(
        private readonly UserService $users,
        private readonly AccessService $access,
        private readonly Config $config,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->user($request);

        return Json::response($response, ['data' => $this->users->list()]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = $this->access->user($request);
        $result = $this->users->create(Json::body($request), (string) $actor['id'], $this->ip($request));

        $data = ['user' => $result['user']];
        if ($result['password'] !== null) {
            $data['password'] = $result['password'];
        }

        return $this->noStore(Json::response($response, ['data' => $data], 201));
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $this->access->user($request);
        $user = $this->users->update(
            $args['id'] ?? '',
            Json::body($request),
            (string) $actor['id'],
            $this->ip($request),
        );

        return Json::response($response, ['data' => $user]);
    }

    /**
     * @param array<string, string> $args
     */
    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $this->access->user($request);
        $body = Json::body($request);
        $password = array_key_exists('password', $body) ? $body['password'] : null;
        $result = $this->users->resetPassword(
            $args['id'] ?? '',
            $password,
            (string) $actor['id'],
            $this->ip($request),
        );

        // Only a generated password is returned; an admin-typed one is a
        // secret the caller already knows and must not be echoed back. Keep
        // `data` an object even when the key is omitted.
        $data = [];
        if ($result['password'] !== null) {
            $data['password'] = $result['password'];
        }

        return $this->noStore(Json::response($response, [
            'data' => $data === [] ? new \stdClass() : $data,
        ]));
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeSessions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $this->access->user($request);
        $revoked = $this->users->revokeSessions(
            $args['id'] ?? '',
            (string) $actor['id'],
            $this->ip($request),
        );

        return Json::response($response, ['data' => ['revoked' => $revoked]]);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $this->access->user($request);
        $body = Json::body($request);
        $query = $request->getQueryParams();
        $flag = $body['delete_labs'] ?? $query['delete_labs'] ?? null;
        $result = $this->users->delete(
            $args['id'] ?? '',
            $this->boolFlag($flag),
            (string) $actor['id'],
            $this->ip($request),
        );

        return Json::response($response, ['data' => $result]);
    }

    /**
     * A response that carries a one-time password must never be retained by a
     * browser or intermediary. The global security middleware already sets
     * this, but keeping it on the response itself makes the guarantee local to
     * the secret-bearing endpoints.
     */
    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function boolFlag(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            if ($value === 0 || $value === 1) {
                return $value === 1;
            }
        }
        if (is_string($value)) {
            $normalised = strtolower(trim($value));
            if (in_array($normalised, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalised, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        throw new HttpException(422, 'delete_labs must be a boolean.');
    }

    private function ip(ServerRequestInterface $request): string
    {
        return RequestIp::resolve($request->getServerParams(), $this->config->trustedProxies);
    }
}
