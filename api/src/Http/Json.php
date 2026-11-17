<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Json
{
    private const MAX_BODY_BYTES = 1048576;

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
            throw new HttpException(415, 'Expected an application/json body.');
        }

        $raw = (string) $request->getBody();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body is too large.');
        }
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed JSON body.');
        }

        if (!is_array($decoded)) {
            throw new HttpException(400, 'JSON body must be an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function response(
        ResponseInterface $response,
        array $data,
        int $status = 200,
    ): ResponseInterface {
        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response;
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        ResponseInterface $response,
        int $status,
        string $message,
        array $details = [],
        ?string $requestId = null,
    ): ResponseInterface {
        return self::response(
            $response,
            [
                'code' => $status,
                'status' => 'error',
                'message' => $message,
                'details' => $details,
                'request_id' => $requestId,
            ],
            $status,
        );
    }
}
