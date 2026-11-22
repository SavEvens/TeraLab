<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use TeraLab\Api\Http\RequestIp;

final class RequestIpTest extends TestCase
{
    public function testDirectPeerIsUsedWhenItIsNotTrusted(): void
    {
        self::assertSame(
            '192.0.2.10',
            RequestIp::resolve(['REMOTE_ADDR' => '192.0.2.10'], ['10.0.0.0/8'])
        );
        self::assertSame(
            '192.0.2.10',
            RequestIp::resolve([
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['10.0.0.0/8'])
        );
        self::assertSame(
            '192.0.2.10',
            RequestIp::resolve([
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], [])
        );
    }

    public function testTrustedProxyChainReturnsTheFirstUntrustedHopFromTheRight(): void
    {
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1',
            ], ['10.0.0.0/8'])
        );

        // The leftmost entry is client-controlled and must not win while the
        // rightmost untrusted address is still available.
        self::assertSame(
            '198.51.100.99',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.55, 198.51.100.99, 10.0.0.1',
            ], ['10.0.0.0/8'])
        );
    }

    public function testWhitespaceAroundForwardedEntriesIsIgnored(): void
    {
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => ' 10.0.0.2 ',
                'HTTP_X_FORWARDED_FOR' => "  198.51.100.7 ,  10.0.0.1  ",
            ], ['10.0.0.0/8'])
        );
    }

    public function testExactAddressesAndBoundariesMatch(): void
    {
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['10.0.0.2'])
        );
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['10.0.0.0/32', '10.0.0.0/8'])
        );
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['10.0.0.0/25'])
        );

        // /0 trusts every hop, so the outermost entry is the answer.
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '203.0.113.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 192.0.2.1',
            ], ['0.0.0.0/0'])
        );
    }

    public function testIpv6ChainsAreResolved(): void
    {
        self::assertSame(
            '2001:db9::5',
            RequestIp::resolve([
                'REMOTE_ADDR' => '2001:db8:ffff::1',
                'HTTP_X_FORWARDED_FOR' => '2001:db9::5, 2001:db8:ffff::2',
            ], ['2001:db8::/32'])
        );

        self::assertSame(
            '2001:db8::99',
            RequestIp::resolve([
                'REMOTE_ADDR' => '2001:db8::2',
                'HTTP_X_FORWARDED_FOR' => '2001:db8::99',
            ], ['2001:db8::/32'])
        );

        self::assertSame(
            '2001:db9::5',
            RequestIp::resolve([
                'REMOTE_ADDR' => '2001:db8::2',
                'HTTP_X_FORWARDED_FOR' => '2001:db9::5',
            ], ['2001:db8::2'])
        );

        self::assertSame(
            '2001:db8::2',
            RequestIp::resolve([
                'REMOTE_ADDR' => '2001:db8::2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['10.0.0.0/8'])
        );

        // Alternative textual forms are compared in binary form.
        self::assertSame(
            '2001:db9::5',
            RequestIp::resolve([
                'REMOTE_ADDR' => '2001:0db8:0000:0000:0000:0000:0000:0002',
                'HTTP_X_FORWARDED_FOR' => '2001:db9::5',
            ], ['2001:db8::/32'])
        );
    }

    public function testIpv4MappedIpv6IsCanonicalisedToIpv4(): void
    {
        // A mapped direct peer is the same client as its IPv4 spelling.
        self::assertSame(
            '127.0.0.1',
            RequestIp::resolve(['REMOTE_ADDR' => '::ffff:127.0.0.1'], [])
        );
        self::assertSame(
            '127.0.0.1',
            RequestIp::resolve(['REMOTE_ADDR' => '127.0.0.1'], [])
        );

        // Mapped peers and forwarded hops match IPv4 CIDRs after mapping.
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '::ffff:10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '::ffff:198.51.100.7, ::ffff:10.0.0.1',
            ], ['10.0.0.0/8'])
        );
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '::ffff:10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['10.0.0.0/8'])
        );

        // Hex and dotted mapped forms collapse to the same canonical IPv4.
        self::assertSame(
            '192.0.2.128',
            RequestIp::resolve(['REMOTE_ADDR' => '::ffff:c000:0280'], [])
        );
        self::assertSame(
            '192.0.2.128',
            RequestIp::resolve(['REMOTE_ADDR' => '::ffff:192.0.2.128'], [])
        );

        // Non-mapped IPv6 still returns canonical inet_ntop output.
        self::assertSame(
            '2001:db8::2',
            RequestIp::resolve(['REMOTE_ADDR' => '2001:0db8:0000:0000:0000:0000:0000:0002'], [])
        );
    }

    public function testMalformedInputFallsBackToTheDirectPeer(): void
    {
        self::assertSame(
            '10.0.0.2',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7, not-an-address',
            ], ['10.0.0.0/8'])
        );
        self::assertSame(
            '10.0.0.2',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '',
            ], ['10.0.0.0/8'])
        );
        self::assertSame(
            '10.0.0.2',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => ['198.51.100.7'],
            ], ['10.0.0.0/8'])
        );
    }

    public function testMissingOrInvalidRemoteAddressReturnsTheFallback(): void
    {
        self::assertSame('0.0.0.0', RequestIp::resolve([], ['10.0.0.0/8']));
        self::assertSame('0.0.0.0', RequestIp::resolve(['REMOTE_ADDR' => 'garbage'], ['10.0.0.0/8']));
        self::assertSame('0.0.0.0', RequestIp::resolve(['REMOTE_ADDR' => 42], []));
        self::assertSame('0.0.0.0', RequestIp::resolve(['REMOTE_ADDR' => ''], []));

        // An invalid direct peer is never replaced by header content, even
        // when the CIDR list would otherwise trust everything.
        self::assertSame('0.0.0.0', RequestIp::resolve([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['0.0.0.0/0']));
    }

    public function testInvalidCidrEntriesAreIgnored(): void
    {
        self::assertSame(
            '10.0.0.2',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['bogus', '10.0.0.0/33', '', '10.0.0.2/not-a-number'])
        );
        self::assertSame(
            '198.51.100.7',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], ['garbage', '10.0.0.2/8'])
        );
    }

    public function testHostileForwardingHeadersAreBounded(): void
    {
        $hops = [];
        for ($index = 0; $index < 33; $index++) {
            $hops[] = '198.51.100.' . (($index % 200) + 1);
        }
        self::assertSame(
            '10.0.0.2',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => implode(', ', $hops),
            ], ['10.0.0.0/8'])
        );

        $allowed = array_slice($hops, 0, 32);
        $allowed[31] = '198.51.100.250';
        self::assertSame(
            '198.51.100.250',
            RequestIp::resolve([
                'REMOTE_ADDR' => '10.0.0.2',
                'HTTP_X_FORWARDED_FOR' => implode(', ', $allowed),
            ], ['10.0.0.0/8'])
        );
    }
}
