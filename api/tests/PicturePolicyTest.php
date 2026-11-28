<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Policy\PicturePolicy;
use TeraLab\Api\Tests\Support\StaticAssets;

final class PicturePolicyTest extends TestCase
{
    use StaticAssets;

    private PicturePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PicturePolicy(dirname(__DIR__, 2));
    }

    public function testEmptyDataUriAndStaticImagesAreAccepted(): void
    {
        self::assertSame('', $this->policy->validate(''));
        self::assertSame(
            'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n"),
            $this->policy->validate('data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n")),
        );
        self::assertSame(
            'data:image/svg+xml;base64,' . base64_encode('<svg/>'),
            $this->policy->validate('data:image/svg+xml;base64,' . base64_encode('<svg/>')),
        );
        self::assertSame('/images/lan-segment.png', $this->policy->validate('/images/lan-segment.png'));
        self::assertSame('/images/icons/Router.png', $this->policy->validate('/images/icons/Router.png'));
    }

    public function testDataUriCapIsEnforcedExactlyAtTheBoundary(): void
    {
        $prefix = 'data:image/png;base64,';
        $payload = str_repeat('A', PicturePolicy::MAX_ENCODED_BYTES);
        $atCap = $prefix . $payload;
        self::assertSame(PicturePolicy::MAX_ENCODED_BYTES, strlen($payload));
        self::assertSame($atCap, $this->policy->validate($atCap));

        $overCap = $prefix . $payload . 'A';
        self::assertSame(PicturePolicy::MAX_ENCODED_BYTES + 1, strlen(substr($overCap, strlen($prefix))));
        try {
            $this->policy->validate($overCap);
            self::fail('a data URI over the encoded cap must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    public function testExecutableScriptPathsAreRejected(): void
    {
        $root = sys_get_temp_dir() . '/teralab-picture-' . bin2hex(random_bytes(6));
        mkdir($root . '/html/images', 0770, true);
        $this->installStaticAssets($root);
        file_put_contents($root . '/html/images/evil.php', '<?php');
        file_put_contents($root . '/html/images/evil.phtml', '<?php');
        file_put_contents($root . '/html/images/EVIL.PHAR', 'x');
        $policy = new PicturePolicy($root);

        foreach (['/images/evil.php', '/images/evil.phtml', '/images/EVIL.PHAR'] as $value) {
            try {
                $policy->validate($value);
                self::fail('an executable script path must be rejected: ' . $value);
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }

        self::assertSame('/images/lan-segment.png', $policy->validate('/images/lan-segment.png'));
        $this->removeTree($root);
    }

    private function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    public function testUnsafeAndExternalValuesAreRejected(): void
    {
        foreach ([
            'javascript:alert(1)',
            '//evil.example/topology.png',
            '/api/v2/labs',
            'http://example.test/topology.png',
            'https://example.test/topology.png',
            'file:///etc/passwd',
            '\\images\\lan-segment.png',
            '/images/../about.php',
            '/images//lan-segment.png',
            '/images/lan-segment.png?x=1',
            '/images/lan-segment.png#fragment',
            '/images/missing.png',
            'data:text/html;base64,PHNjcmlwdD4=',
            'data:image/png;base64,',
            'data:image/png;base64,***',
        ] as $value) {
            try {
                $this->policy->validate($value);
                self::fail('expected rejection: ' . var_export($value, true));
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status, var_export($value, true));
            }
        }
    }

    public function testPhpFilesUnderTheStaticTreeAreRejected(): void
    {
        $root = sys_get_temp_dir() . '/teralab-picture-policy-' . bin2hex(random_bytes(6));
        mkdir($root . '/html/images', 0770, true);
        mkdir($root . '/html/images/nested', 0770, true);
        file_put_contents($root . '/html/images/real.png', "png");
        file_put_contents($root . '/html/images/evil.php', "<?php echo 1;");
        try {
            $policy = new PicturePolicy($root);
            self::assertSame('/images/real.png', $policy->validate('/images/real.png'));
            foreach (['/images/evil.php', '/images/nested/../evil.php'] as $value) {
                try {
                    $policy->validate($value);
                    self::fail('expected rejection: ' . $value);
                } catch (HttpException $exception) {
                    self::assertSame(422, $exception->status);
                }
            }
        } finally {
            @unlink($root . '/html/images/real.png');
            @unlink($root . '/html/images/evil.php');
            @rmdir($root . '/html/images/nested');
            @rmdir($root . '/html/images');
            @rmdir($root . '/html');
            @rmdir($root);
        }
    }
}
