<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;

final class HealthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException) {
            throw new HttpException(503, 'Database unavailable.');
        }

        return Json::response($response, [
            'status' => 'ok',
            'version' => $this->config->version(),
            'php' => PHP_VERSION,
            'time' => time(),
        ]);
    }
}
