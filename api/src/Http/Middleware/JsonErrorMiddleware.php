<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use Throwable;

final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getAttribute('request_id');
        $requestId = is_string($requestId) ? $requestId : null;

        try {
            return $handler->handle($request);
        } catch (HttpException $exception) {
            return Json::error(
                new Response(),
                $exception->status,
                $exception->getMessage(),
                $exception->details,
                $requestId,
            );
        } catch (Throwable $exception) {
            $this->logger->error('Unhandled API error', [
                'exception' => $exception,
                'request_id' => $requestId,
            ]);

            return Json::error(
                new Response(),
                500,
                'Internal server error.',
                [],
                $requestId,
            );
        }
    }
}
