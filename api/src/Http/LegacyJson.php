<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http;

use Psr\Http\Message\ResponseInterface;

final class LegacyJson
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function success(
        ResponseInterface $response,
        array $data = [],
        string $message = 'Success',
    ): ResponseInterface {
        return Json::response($response, [
            'code' => 200,
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function error(
        ResponseInterface $response,
        int $code,
        string $status,
        string $message,
        array $data = [],
    ): ResponseInterface {
        return Json::response($response, [
            'code' => $code,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}
