<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http;

use TeraLab\Api\Labs\LabLimits;

/**
 * Route-parameter shape checks.
 *
 * A route id is client-controlled and may be arbitrarily long. Resolving it
 * through a directory scan, a database query or a log line is only safe when
 * it first matches the grammar the resource actually uses. Invalid shapes are
 * answered with 422 instead of being echoed or treated as a lookup miss.
 */
final class RouteId
{
    /** Lab, template, image and node ids share this grammar. */
    public const RESOURCE_PATTERN = LabLimits::ID_PATTERN;

    /** Snapshot, token, user and job ids are random 16-byte hex strings. */
    public const HEX32_PATTERN = '/^[a-f0-9]{32}\z/';

    public static function lab(string $id): string
    {
        if (preg_match(self::RESOURCE_PATTERN, $id) !== 1) {
            throw new HttpException(422, 'Invalid lab id.');
        }

        return $id;
    }

    public static function template(string $id): string
    {
        if (preg_match(self::RESOURCE_PATTERN, $id) !== 1) {
            throw new HttpException(422, 'Invalid template id.');
        }

        return $id;
    }

    public static function image(string $id): string
    {
        if (preg_match(self::RESOURCE_PATTERN, $id) !== 1) {
            throw new HttpException(422, 'Invalid image id.');
        }

        return $id;
    }

    public static function snapshot(string $id): string
    {
        if (preg_match(self::HEX32_PATTERN, $id) !== 1) {
            throw new HttpException(422, 'Invalid snapshot id.');
        }

        return $id;
    }

    public static function token(string $id): string
    {
        if (preg_match(self::HEX32_PATTERN, $id) !== 1) {
            throw new HttpException(422, 'Invalid token id.');
        }

        return $id;
    }

    /**
     * A node may be addressed by its bounded id or its display name.
     */
    public static function node(string $reference): string
    {
        if ($reference === ''
            || strlen($reference) > LabLimits::MAX_NODE_REFERENCE_BYTES
            || preg_match('//u', $reference) !== 1
            || preg_match(LabLimits::FORBIDDEN_NAME_PATTERN, $reference) === 1
        ) {
            throw new HttpException(422, 'Invalid node reference.');
        }

        return $reference;
    }
}
