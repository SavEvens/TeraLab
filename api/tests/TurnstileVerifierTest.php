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
