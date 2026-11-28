<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TeraLab\Api\Auth\TurnstileVerifier;
use TeraLab\Api\Http\HttpException;

final class TurnstileVerifierTest extends TestCase
{
    private const SECRET = '1x0000000000000000000000000000000AA';

    private const TOKEN = 'single-use-token';

    public function testSuccessfulVerificationPostsTheExpectedForm(): void
    {
        $captured = [];
        $verifier = new TurnstileVerifier(
            static function (string $url, array $form) use (&$captured): array {
                $captured = ['url' => $url, 'form' => $form];

                return ['success' => true, 'challenge_ts' => '2026-11-22T00:00:00Z'];
            }
        );

        $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7');

        self::assertSame(TurnstileVerifier::ENDPOINT, $captured['url']);
        self::assertSame([
            'secret' => self::SECRET,
            'response' => self::TOKEN,
            'remoteip' => '198.51.100.7',
        ], $captured['form']);
    }

    public function testInvalidTokenIsRejectedWithADistinctMessage(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => [
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]
        );

        try {
            $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7');
            self::fail('An invalid token was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
    }

    public function testNonBooleanSuccessIsInvalid(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => ['success' => 'true']
        );

        $this->assertRejected($verifier, TurnstileVerifier::INVALID_MESSAGE);
    }

    public function testMissingSuccessIsInvalid(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => []
        );

        $this->assertRejected($verifier, TurnstileVerifier::INVALID_MESSAGE);
    }

    public function testTransportFailureFailsClosedAsUnavailable(): void
    {
        $verifier = new TurnstileVerifier(
            static function (string $url, array $form): array {
                throw new RuntimeException('timed out');
            }
        );

        $this->assertRejected($verifier, TurnstileVerifier::UNAVAILABLE_MESSAGE);
    }

    public function testMalformedJsonFailsClosedAsUnavailable(): void
    {
        $verifier = new TurnstileVerifier(
            static function (string $url, array $form): array {
                return TurnstileVerifier::decode('{"success": tru');
            }
        );

        $this->assertRejected($verifier, TurnstileVerifier::UNAVAILABLE_MESSAGE);
    }

    public function testDecodeAcceptsObjectsAndRejectsEverythingElse(): void
    {
        self::assertSame(
            ['success' => true, 'hostname' => 'example.test'],
            TurnstileVerifier::decode('{"success":true,"hostname":"example.test"}')
        );
        self::assertSame([], TurnstileVerifier::decode('[]'));

        foreach (['', 'not json', 'null', '"text"', '42'] as $body) {
            try {
                TurnstileVerifier::decode($body);
                self::fail('Decoded malformed siteverify body: ' . $body);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Malformed', $exception->getMessage());
            }
        }
    }

    public function testEmptySecretOrTokenNeverReachesTheTransport(): void
    {
        $called = false;
        $verifier = new TurnstileVerifier(
            static function (string $url, array $form) use (&$called): array {
                $called = true;

                return ['success' => true];
            }
        );

        $this->assertRejected($verifier, TurnstileVerifier::INVALID_MESSAGE, '');
        $this->assertRejected($verifier, TurnstileVerifier::INVALID_MESSAGE, self::TOKEN, '');
        self::assertFalse($called);
    }

    public function testShippedTestCredentialsAreRecognised(): void
    {
        self::assertTrue(TurnstileVerifier::isTestSecretKey('1x0000000000000000000000000000000AA'));
        self::assertTrue(TurnstileVerifier::isTestSecretKey('2x0000000000000000000000000000000AA'));
        self::assertTrue(TurnstileVerifier::isTestSiteKey('3x00000000000000000000FF'));
        self::assertFalse(TurnstileVerifier::isTestSecretKey('real-secret'));
        self::assertFalse(TurnstileVerifier::isTestSiteKey('real-site-key'));
        self::assertFalse(TurnstileVerifier::isTestSecretKey(''));
    }

    public function testMatchingHostnameAndActionAreAccepted(): void
    {
        $called = false;
        $verifier = new TurnstileVerifier(
            static function (string $url, array $form) use (&$called): array {
                $called = true;

                return [
                    'success' => true,
                    'hostname' => 'Demo.Example.Test',
                    'action' => 'login',
                ];
            }
        );

        $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7', 'login', 'demo.example.test');
        self::assertTrue($called);
    }

    public function testActionMismatchIsRejected(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => [
                'success' => true,
                'hostname' => 'demo.example.test',
                'action' => 'guest',
            ]
        );

        try {
            $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7', 'login', '');
            self::fail('A token minted for another action was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $exception->getMessage());
        }
    }

    public function testMissingActionIsRejectedWhenAnActionIsExpected(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => ['success' => true]
        );

        try {
            $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7', 'login');
            self::fail('A token without an action was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    public function testHostnameMismatchIsRejected(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => [
                'success' => true,
                'hostname' => 'evil.example.test',
                'action' => 'login',
            ]
        );

        try {
            $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7', 'login', 'demo.example.test');
            self::fail('A token minted for another hostname was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame(TurnstileVerifier::INVALID_MESSAGE, $exception->getMessage());
        }
    }

    public function testMissingHostnameIsRejectedWhenOneIsConfigured(): void
    {
        $verifier = new TurnstileVerifier(
            static fn (string $url, array $form): array => ['success' => true, 'action' => 'login']
        );

        try {
            $verifier->verify(self::SECRET, self::TOKEN, '198.51.100.7', 'login', 'demo.example.test');
            self::fail('A token without a hostname was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    private function assertRejected(
        TurnstileVerifier $verifier,
        string $message,
        string $token = self::TOKEN,
        string $secret = self::SECRET,
    ): void {
        try {
            $verifier->verify($secret, $token, '198.51.100.7');
            self::fail('Verification did not fail.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame($message, $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
    }
}
