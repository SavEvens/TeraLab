<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests\Support;

use RuntimeException;
use TeraLab\Api\Config;

/**
 * Install the operator setup token where the test configuration expects it and
 * expose the value for request bodies.
 *
 * The path mirrors {@see Config::forTests()}: `<data_dir>/setup.token`. Tests
 * use the same file the production API reads, so setup coverage never relies
 * on a test-only authentication shortcut.
 */
trait SetupToken
{
    private const TEST_SETUP_TOKEN = 'test-setup-token-0123456789abcdef';

    private function installSetupToken(Config $config): void
    {
        $directory = dirname($config->dataDir . '/setup.token');
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the setup token directory.');
        }
        if (file_put_contents($config->dataDir . '/setup.token', self::TEST_SETUP_TOKEN) === false) {
            throw new RuntimeException('Cannot write the setup token.');
        }
    }

    private function setupToken(): string
    {
        return self::TEST_SETUP_TOKEN;
    }
}
