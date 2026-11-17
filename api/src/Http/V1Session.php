<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class V1Session
{
    public const LEGACY_COOKIE = 'unetlab_session';
    public const COOKIE = 'teralab_session';

    public static function id(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        foreach ([self::LEGACY_COOKIE, self::COOKIE] as $name) {
            $value = $cookies[$name] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function withCookies(
        ResponseInterface $response,
        string $sessionId,
        int $ttl,
        bool $secure,
    ): ResponseInterface {
        return $response
            ->withAddedHeader('Set-Cookie', self::cookie(self::LEGACY_COOKIE, $sessionId, $ttl, $secure, '/api/'))
            ->withAddedHeader('Set-Cookie', self::cookie(self::COOKIE, $sessionId, $ttl, $secure, '/'));
    }

    public static function clearCookies(ResponseInterface $response, bool $secure): ResponseInterface
    {
        return $response
            ->withAddedHeader('Set-Cookie', self::cookie(self::LEGACY_COOKIE, '', 0, $secure, '/api/'))
            ->withAddedHeader('Set-Cookie', self::cookie(self::COOKIE, '', 0, $secure, '/'));
    }

    private static function cookie(
        string $name,
        string $value,
        int $ttl,
        bool $secure,
        string $path,
    ): string {
        $cookie = $name . '=' . $value
            . '; Path=' . $path
            . '; HttpOnly; SameSite=Lax; Max-Age=' . $ttl;
        if ($ttl === 0) {
            $cookie .= '; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($secure) {
            $cookie .= '; Secure';
        }

        return $cookie;
    }
}
