<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Auth\TokenService;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Http\RequestIp;
use TeraLab\Api\Http\RouteId;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\System\BackupService;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\System\DemoSweeper;
use TeraLab\Api\System\MaintenanceService;
use TeraLab\Api\System\SettingsService;

final class SystemController
{
    /** Guests are ephemeral and should need at most one API token. */
    public const GUEST_MAX_TOKENS = 1;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly LabRepository $labs,
        private readonly AccessService $access,
        private readonly SettingsService $settings,
        private readonly BackupService $backup,
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly DemoSweeper $sweeper,
        private readonly RateLimiter $rateLimiter,
        private readonly MaintenanceService $maintenance,
    ) {
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->user($request);
        $users = $this->pdo->query('SELECT COUNT(*) FROM users');

        return Json::response($response, [
            'status' => 'ok',
            'version' => $this->config->version(),
            'environment' => $this->config->environment,
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'database' => 'ok',
            'users' => $users === false ? 0 : (int) $users->fetchColumn(),
            'labs' => $this->labs->count(),
        ]);
    }

    public function settings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $values = $this->settings->all();
        if (!$this->access->isAdmin($user)) {
            // The Turnstile secret is write-only for non-admins: they may see
            // every other setting, but never the credential itself.
            unset($values['turnstile_secret_key']);
        }

        return Json::response($response, ['data' => $values]);
    }

    public function updateSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'admin');
        $values = $this->settings->update(Json::body($request));
        $this->audit->log((string) $user['id'], $this->address($request), 'system.settings', null, 'success');

        return Json::response($response, ['status' => 'success', 'data' => $values]);
    }

    public function demoSweep(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'admin');
        $summary = $this->sweeper->sweepNow();
        $this->audit->log(
            (string) $user['id'],
            $this->address($request),
            'demo.sweep',
            null,
            'success',
            ['manual' => true, 'summary' => $summary],
        );

        return Json::response($response, ['status' => 'success', 'data' => $summary]);
    }

    public function demoStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'admin');

        return Json::response($response, ['data' => $this->sweeper->status()]);
    }

    public function logs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->user($request);
        $query = $request->getQueryParams();
        $source = is_string($query['source'] ?? null) ? $query['source'] : 'api';
        if (!in_array($source, ['api', 'agent'], true)) {
            throw new HttpException(422, 'Unknown log source.');
        }
        $path = $this->config->dataDir . '/logs/' . $source . '.log';
        if (!is_file($path) || !is_readable($path)) {
            return Json::response($response, ['data' => [
                'source' => $source,
                'lines' => [],
                'size' => 0,
                'modified' => null,
            ]]);
        }

        return Json::response($response, ['data' => [
            'source' => $source,
            'lines' => $this->tail($path, 300),
            'size' => filesize($path) ?: 0,
            'modified' => filemtime($path) ?: null,
        ]]);
    }

    public function backup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->access->user($request);
        $backup = $this->backup->create();
        $contents = file_get_contents($backup['path']);
        if ($contents === false) {
            @unlink($backup['path']);
            throw new HttpException(500, 'Cannot read the backup archive.');
        }
        @unlink($backup['path']);
        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $backup['filename'] . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'admin');
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            throw new HttpException(422, 'A multipart "file" field is required.');
        }
        $result = $this->backup->restore($file);
        $this->audit->log((string) $user['id'], $this->address($request), 'system.restore', null, 'success', $result);

        return Json::response($response, ['status' => 'success', 'data' => $result])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function maintenance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'admin');
        $result = $this->maintenance->runNow();
        $this->audit->log(
            (string) $user['id'],
            $this->address($request),
            'system.maintenance',
            null,
            'success',
            $result,
        );

        return Json::response($response, ['status' => 'success', 'data' => $result])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function tokens(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);

        return Json::response($response, ['data' => $this->tokens->all((string) $user['id'])]);
    }

    public function createToken(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $userId = (string) $user['id'];
        $body = Json::body($request);
        $name = $body['name'] ?? null;
        $scopes = $body['scopes'] ?? ['read', 'write'];
        $expiresIn = $body['expires_in'] ?? null;
        if (!is_string($name)) {
            throw new HttpException(422, 'name is required.');
        }
        if (!is_array($scopes)) {
            throw new HttpException(422, 'scopes must be an array.');
        }

        $guest = DemoPolicy::isGuest($user);
        $cap = $guest
            ? self::GUEST_MAX_TOKENS
            : $this->settings->int('demo_max_tokens_per_user', 5);
        if ($this->tokens->count($userId) >= $cap) {
            throw new HttpException(
                422,
                $guest
                    ? 'The demo allows one API token per guest; revoke it before creating another.'
                    : 'At most ' . $cap . ' API tokens are kept per user; revoke one first.',
            );
        }
        $address = $this->address($request);
        $this->rateLimiter->assertCountBelow(
            'token:' . $userId,
            $address,
            RateLimiter::TOKEN_MAX_PER_WINDOW,
            RateLimiter::TOKEN_WINDOW_SECONDS,
            RateLimiter::TOKEN_LIMIT_MESSAGE,
        );
        $this->rateLimiter->consume('token:' . $userId, $address);

        $token = $this->tokens->create(
            $userId,
            $name,
            array_values(array_filter($scopes, 'is_string')),
            is_int($expiresIn) ? $expiresIn : null,
        );
        $this->audit->log($userId, $address, 'auth.token.create', (string) $token['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $token], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeToken(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->tokens->revoke((string) $user['id'], RouteId::token($args['id'] ?? ''));
        $this->audit->log((string) $user['id'], $this->address($request), 'auth.token.revoke', (string) ($args['id'] ?? ''), 'success');

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * @return list<string>
     */
    private function tail(string $path, int $lines): array
    {
        $size = filesize($path) ?: 0;
        if ($size === 0) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $read = min($size, 524288);
        fseek($handle, -$read, SEEK_END);
        $data = (string) fread($handle, $read);
        fclose($handle);
        $all = explode("\n", rtrim($data, "\n"));
        if ($size > $read) {
            array_shift($all);
        }

        return array_slice($all, -$lines);
    }

    private function address(ServerRequestInterface $request): string
    {
        return RequestIp::resolve($request->getServerParams(), $this->config->trustedProxies);
    }
}
