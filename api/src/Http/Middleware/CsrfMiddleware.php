<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TeraLab\Api\Http\HttpException;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || $request->getAttribute('api_token') === true) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute('session');
        if (!is_array($session) || !isset($session['csrf_hash'])) {
            throw new HttpException(401, 'Authentication required.');
        }

        $token = $request->getHeaderLine('X-CSRF-Token');
        if ($token === '' || !hash_equals((string) $session['csrf_hash'], hash('sha256', $token))) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }

        return $handler->handle($request);
    }
}
