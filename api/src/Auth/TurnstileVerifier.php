<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use JsonException;
use RuntimeException;
use TeraLab\Api\Http\HttpException;

/**
 * Cloudflare Turnstile server-side verification.
 *
 * This is the product's only outbound request. It is operator-configured and
 * user-initiated, and it fails closed: if the siteverify endpoint cannot be
 * reached (or answers with something that is not a JSON object), entry is
 * refused rather than allowed. Verification tokens are single-use and are
 * never logged, audited, or included in error messages.
 */
final class TurnstileVerifier
{
    public const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const INVALID_MESSAGE = 'Captcha verification failed; try again.';

    public const UNAVAILABLE_MESSAGE = 'Captcha verification is unavailable; try again.';

    /** @var callable(string, array<string, string>): array<string, mixed> */
    private $transport;

    /**
     * @param callable(string, array<string, string>): array<string, mixed>|null $transport
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? static fn (string $url, array $form): array => self::post($url, $form);
    }

    /**
     * Verify a Turnstile token. Throws HttpException(422) on every failure so
     * callers never have to decide whether a rejection is safe.
     */
    public function verify(string $secret, string $token, string $ip): void
    {
        if ($secret === '' || $token === '') {
            throw new HttpException(422, self::INVALID_MESSAGE);
        }

        try {
            $result = ($this->transport)(self::ENDPOINT, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (\Throwable) {
            throw new HttpException(422, self::UNAVAILABLE_MESSAGE);
        }

        if (($result['success'] ?? null) !== true) {
            throw new HttpException(422, self::INVALID_MESSAGE);
        }
    }

    /**
     * Decode a siteverify response body.
     *
     * @return array<string, mixed>
     *
     * @internal Exposed for tests and for the default stream transport.
     */
    public static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed siteverify response.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Malformed siteverify response.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private static function post(string $url, array $form): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                    . "Accept: application/json\r\n",
                'content' => http_build_query($form, '', '&', PHP_QUERY_RFC1738),
                'timeout' => 5.0,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Turnstile siteverify request failed.');
        }

        return self::decode($body);
    }
}
