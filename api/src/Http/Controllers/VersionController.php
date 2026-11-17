<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Config;
use TeraLab\Api\Http\Json;

final class VersionController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::response($response, [
            'product' => 'TeraLab',
            'version' => $this->config->version(),
            'license' => 'AGPL-3.0-or-later',
            'source' => 'https://github.com/SavEvens/TeraLab',
            'upstream' => [
                'name' => 'UNetLab',
                'version' => '1.0.2-2',
                'license' => 'BSD-3-Clause',
            ],
        ]);
    }
}
