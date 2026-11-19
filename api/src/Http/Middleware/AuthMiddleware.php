<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Auth\TokenService;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            $context = $this->tokens->authenticate(trim($matches[1]));
            if ($context === null) {
                throw new HttpException(401, 'Invalid or expired API token.');
            }
            $method = strtoupper($request->getMethod());
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !in_array('write', $context['scopes'], true)) {
                throw new HttpException(403, 'This API token is read-only.');
            }

            return $handler->handle(
                $request
                    ->withAttribute('user', $context['user'])
                    ->withAttribute('api_token', true)
                    ->withAttribute('token_scopes', $context['scopes'])
            );
        }

        $cookies = $request->getCookieParams();
        $sessionId = $cookies[Config::COOKIE_NAME] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            throw new HttpException(401, 'Authentication required.');
        }

        $context = $this->auth->authenticate($sessionId);

        return $handler->handle(
            $request
                ->withAttribute('user', $context['user'])
                ->withAttribute('session', $context['session'])
                ->withAttribute('session_id', $sessionId)
        );
    }
}
