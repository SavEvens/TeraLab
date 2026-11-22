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
use TeraLab\Api\System\SettingsService;

final class HealthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly SettingsService $settings,
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
            // Public feature flags for the login page. The Turnstile secret is
            // deliberately absent: only the site key may leave the server.
            'demo' => [
                'enabled' => $this->settings->int('demo_enabled', 0) === 1,
                'landing' => $this->settings->string('demo_landing', 'login'),
            ],
            'turnstile' => [
                'enabled' => $this->settings->int('turnstile_enabled', 0) === 1,
                'site_key' => $this->settings->string('turnstile_site_key', ''),
            ],
        ]);
    }
}
