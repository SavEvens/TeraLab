<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

use TeraLab\Api\App;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    (new App())->create()->run();
} catch (Throwable $exception) {
    error_log('TeraLab API bootstrap failure: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 500,
        'status' => 'error',
        'message' => 'Internal server error.',
        'request_id' => null,
    ], JSON_THROW_ON_ERROR);
}
