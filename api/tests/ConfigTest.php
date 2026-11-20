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

    public function testVersionMatchesTheVersionFile(): void
    {
        $root = dirname(__DIR__, 2);
        $file = $root . '/TERALAB_VERSION';
        self::assertFileExists($file);
        $expected = trim((string) file_get_contents($file));
        self::assertSame('1.4.6', $expected);

        putenv('TERALAB_ROOT=' . $root);
        putenv('TERALAB_CONFIG=/nonexistent/teralab-config.php');
        self::assertSame($expected, Config::fromEnvironment()->version());
    }

    public function testHttpsDetectionCoversDirectAndProxiedRequests(): void
    {
        self::assertFalse(Config::requestIsHttps([]));
        self::assertFalse(Config::requestIsHttps(['HTTPS' => 'off', 'SERVER_PORT' => '80']));
        self::assertTrue(Config::requestIsHttps(['HTTPS' => 'on']));
        self::assertTrue(Config::requestIsHttps(['SERVER_PORT' => '443']));
        self::assertTrue(Config::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
        self::assertTrue(Config::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https, http']));
        self::assertFalse(Config::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'http']));
    }

    public function testPlainHttpDefaultsToNonSecureCookies(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'teralab-config-');
        self::assertIsString($file);
        file_put_contents($file, "<?php\nreturn [];\n");

        try {
            putenv('TERALAB_CONFIG=' . $file);
            self::assertFalse(Config::fromEnvironment()->cookieSecure);
        } finally {
            unlink($file);
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
