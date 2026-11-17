<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Config;

final class ConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['TERALAB_CONFIG', 'TERALAB_ROOT', 'TERALAB_DATA_DIR', 'TERALAB_DB', 'TERALAB_LABS', 'TERALAB_COOKIE_SECURE', 'TERALAB_SESSION_TTL', 'TERALAB_ENV'] as $key) {
            $this->original[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    public function testLoadsConfigFileAndEnvironmentOverrides(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'teralab-config-');
        self::assertIsString($file);
        file_put_contents($file, <<<'PHP'
<?php
return [
    'root' => '/tmp/teralab-config-root',
    'data_dir' => '/tmp/teralab-config-data',
    'labs_dir' => '/tmp/teralab-config-labs',
    'cookie_secure' => false,
    'session_ttl' => 120,
    'environment' => 'test-config',
];
PHP);

        try {
            putenv('TERALAB_CONFIG=' . $file);
            $config = Config::fromEnvironment();

            self::assertSame('/tmp/teralab-config-root', $config->root);
            self::assertSame('/tmp/teralab-config-data', $config->dataDir);
            self::assertSame('/tmp/teralab-config-data/database.sdb', $config->dbPath);
            self::assertSame('/tmp/teralab-config-labs', $config->labsDir);
            self::assertFalse($config->cookieSecure);
            self::assertSame(120, $config->sessionTtl);
            self::assertSame('test-config', $config->environment);

            putenv('TERALAB_ENV=env-override');
            self::assertSame('env-override', Config::fromEnvironment()->environment);
        } finally {
            unlink($file);
        }
    }
}
