<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Config;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Labs\LabRepository;

final class SystemController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly LabRepository $labs,
    ) {
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $users = $this->pdo->query('SELECT COUNT(*) FROM users');

        return Json::response($response, [
            'status' => 'ok',
            'version' => $this->config->version(),
            'environment' => $this->config->environment,
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'database' => 'ok',
            'users' => $users === false ? 0 : (int) $users->fetchColumn(),
            'labs' => $this->labs->count(),
        ]);
    }
}
