<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use TeraLab\Api\Http\HttpException;

/**
 * First-run operator token.
 *
 * The installer generates `/etc/teralab/setup.token` once (root-owned, not
 * world-readable) and prints it. The API only reads and compares it; it never
 * generates, stores in the database, logs, or returns the value. Comparison is
 * constant-time, and the file is removed on a best-effort basis after the
 * first administrator exists.
 */
final class SetupToken
{
    /** Anything shorter is a misconfiguration, not a usable secret. */
    private const MIN_LENGTH = 16;

    private const MAX_LENGTH = 256;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @throws HttpException 401 when no token was supplied, 403 when the file
     *                       is missing or the presented token is wrong
     */
    public function assertValid(string $presented): void
    {
        $expected = $this->stored();
        if ($expected === null) {
            throw new HttpException(403, 'Setup is not available.');
        }
        if ($presented === '') {
            throw new HttpException(401, 'The setup token is required.');
        }
        if (!hash_equals($expected, $presented)) {
            throw new HttpException(403, 'Invalid setup token.');
        }
    }

    /**
     * Remove the one-time token after a successful setup. The API identity may
     * not own `/etc/teralab`, so a failure is expected there and harmless:
     * the route is disabled by the user count regardless.
     */
    public function remove(): void
    {
        @unlink($this->file);
    }

    private function stored(): ?string
    {
        if (!is_file($this->file) || !is_readable($this->file)) {
            return null;
        }
        $size = filesize($this->file);
        if ($size === false || $size > 4096) {
            return null;
        }
        $contents = file_get_contents($this->file);
        if ($contents === false) {
            return null;
        }
        $token = trim($contents);
        if (strlen($token) < self::MIN_LENGTH || strlen($token) > self::MAX_LENGTH) {
            return null;
        }

        return $token;
    }
}
