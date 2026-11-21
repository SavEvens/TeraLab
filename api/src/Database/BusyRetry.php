<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Database;

use PDOException;
use Throwable;

/**
 * Retry helper for SQLite write paths that are safe to repeat.
 *
 * SQLite serialises writers; a long import or snapshot can hold the write lock
 * for longer than a request is willing to wait. WAL mode plus
 * `PRAGMA busy_timeout` handles most of it, but a writer can still surface
 * `SQLITE_BUSY`/"database is locked" after the timeout. Only idempotent writes
 * should be wrapped: applying the same INSERT/UPDATE twice must be harmless.
 *
 * Anything that is not a busy/locked error is re-thrown immediately.
 */
final class BusyRetry
{
    public const ATTEMPTS = 3;

    public const SLEEP_MS = 250;

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws Throwable the last failure when it is not busy or attempts run out
     */
    public static function retry(callable $operation, int $attempts = self::ATTEMPTS, int $sleepMs = self::SLEEP_MS): mixed
    {
        $attempts = max(1, $attempts);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                if ($attempt >= $attempts || !self::isBusy($exception)) {
                    throw $exception;
                }
                usleep(max(0, $sleepMs) * 1000);
            }
        }
    }

    public static function isBusy(Throwable $exception): bool
    {
        if ($exception instanceof PDOException) {
            $info = $exception->errorInfo;
            if (is_array($info) && isset($info[1]) && (int) $info[1] === 5) {
                return true;
            }
            if ((int) $exception->getCode() === 5) {
                return true;
            }
        }
        $message = strtolower($exception->getMessage());
        foreach (['database is locked', 'database table is locked', 'sqlite_busy', 'sqlite_locked'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
