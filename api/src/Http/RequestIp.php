<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http;

/**
 * Resolve the client address of a request behind trusted reverse proxies.
 *
 * X-Forwarded-For is append-only: each proxy adds the address it received the
 * request from, so the rightmost entry is the hop closest to us. Walking from
 * the right and stopping at the first address that is not a trusted proxy is
 * the only safe way to find the client; entries further left may be spoofed by
 * the client. When the direct peer is not trusted, X-Forwarded-For is ignored
 * entirely.
 */
final class RequestIp
{
    private const FALLBACK = '0.0.0.0';

    /** Bound the work a hostile header can cause. */
    private const MAX_FORWARDED_HOPS = 32;

    /** ::ffff:0:0/96 — IPv4-mapped IPv6 addresses. */
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * @param array<string, mixed> $server
     * @param list<string> $cidrs
     */
    public static function resolve(array $server, array $cidrs): string
    {
        $remote = self::normalise($server['REMOTE_ADDR'] ?? null);
        if ($remote === null) {
            return self::FALLBACK;
        }

        if ($cidrs === [] || !self::isTrusted($remote, $cidrs)) {
            return $remote;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (!is_string($forwarded) || trim($forwarded) === '') {
            return $remote;
        }

        $parts = explode(',', $forwarded);
        if (count($parts) > self::MAX_FORWARDED_HOPS) {
            return $remote;
        }

        /** @var list<string> $chain */
        $chain = [];
        foreach ($parts as $part) {
            $address = self::normalise($part);
            if ($address === null) {
                // A malformed chain is not trustworthy end to end.
                return $remote;
            }
            $chain[] = $address;
        }

        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!self::isTrusted($chain[$index], $cidrs)) {
                return $chain[$index];
            }
        }

        // Every hop was a trusted proxy: the leftmost entry is the best
        // remaining answer (the client as seen by the outermost proxy).
        return $chain[0];
    }

    private static function normalise(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($value);
        if ($packed === false) {
            return null;
        }
        // Collapse IPv4-mapped IPv6 to its IPv4 form so a dual-stack peer
        // cannot use two spellings for one address and get two budget keys.
        if (strlen($packed) === 16 && str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            $packed = substr($packed, 12);
        }
        $canonical = @inet_ntop($packed);

        return $canonical === false ? null : $canonical;
    }

    /**
     * @param list<string> $cidrs
     */
    private static function isTrusted(string $address, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matches($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $address, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        $bits = null;
        $subnet = $cidr;
        if (str_contains($cidr, '/')) {
            [$subnet, $suffix] = explode('/', $cidr, 2);
            if (!ctype_digit($suffix)) {
                return false;
            }
            $bits = (int) $suffix;
        }

        $addressBin = @inet_pton($address);
        $subnetBin = @inet_pton($subnet);
        if ($addressBin === false || $subnetBin === false || strlen($addressBin) !== strlen($subnetBin)) {
            return false;
        }

        $maximum = strlen($addressBin) * 8;
        if ($bits === null) {
            $bits = $maximum;
        }
        if ($bits < 0 || $bits > $maximum) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && substr($addressBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}
