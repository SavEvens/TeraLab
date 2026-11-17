<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\LegacyJson;
use TeraLab\Api\Http\V1Session;

final class V1AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionId = V1Session::id($request);
        if ($sessionId === null) {
            return LegacyJson::error(
                new Response(),
                412,
                'unauthorized',
                'User is not authenticated or session timed out.',
            );
        }

        try {
            $context = $this->auth->authenticate($sessionId);
        } catch (HttpException $exception) {
            return LegacyJson::error(
                new Response(),
                412,
                'unauthorized',
                $exception->getMessage(),
            );
        }

        return $handler->handle(
            $request
                ->withAttribute('user', $context['user'])
                ->withAttribute('session', $context['session'])
                ->withAttribute('session_id', $sessionId)
        );
    }
}
