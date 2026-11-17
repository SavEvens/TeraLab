<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Auth;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(
        ?string $userId,
        string $ip,
        string $action,
        ?string $target,
        string $result,
        array $details = [],
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (id, ts, user_id, ip, action, target, result, details_json) '
            . 'VALUES (:id, :ts, :user_id, :ip, :action, :target, :result, :details_json)'
        );
        $statement->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':ts' => time(),
            ':user_id' => $userId,
            ':ip' => $ip,
            ':action' => $action,
            ':target' => $target,
            ':result' => $result,
            ':details_json' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
