<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if (preg_match('/^[A-Za-z0-9._-]{8,64}\z/', $requestId) !== 1) {
            $requestId = bin2hex(random_bytes(8));
        }

        $response = $handler->handle($request->withAttribute('request_id', $requestId));

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
