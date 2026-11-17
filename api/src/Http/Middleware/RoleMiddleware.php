<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Http\HttpException;

final class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AccessService $access,
        private readonly string $minimumRole,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!is_array($user)) {
            throw new HttpException(401, 'Authentication required.');
        }
        $this->access->requireRole($user, $this->minimumRole);

        return $handler->handle($request);
    }
}
