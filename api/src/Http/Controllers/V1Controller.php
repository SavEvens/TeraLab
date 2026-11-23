<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Auth\TurnstileVerifier;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Http\LegacyJson;
use TeraLab\Api\Http\RequestIp;
use TeraLab\Api\Http\V1Session;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\System\DemoSweeper;
use TeraLab\Api\System\SettingsService;

final class V1Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Config $config,
        private readonly LabRepository $labs,
        private readonly LabService $labService,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly TurnstileVerifier $turnstile,
        private readonly RateLimiter $rateLimiter,
        private readonly DemoSweeper $sweeper,
    ) {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $data = Json::body($request);
        } catch (HttpException $exception) {
            return LegacyJson::error($response, $exception->status, 'fail', $exception->getMessage());
        }
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            return LegacyJson::error($response, 400, 'fail', 'Username and password are required.');
        }

        try {
            $ip = $this->ip($request);
            $this->requireCaptcha($request, $data, $ip);
            $session = $this->auth->login(
                $username,
                $password,
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        } catch (HttpException $exception) {
            return LegacyJson::error(
                $response,
                $exception->status,
                $exception->status === 401 ? 'unauthorized' : 'fail',
                $exception->getMessage(),
            );
        }

        return V1Session::withCookies(
            LegacyJson::success($response, [], 'User authenticated.'),
            $session['session_id'],
            $session['expires_in'],
            $this->config->cookieSecure,
        );
    }

    public function auth(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = V1Session::id($request);
        if ($sessionId === null) {
            return LegacyJson::error($response, 401, 'unauthorized', 'User is not authenticated or session timed out.');
        }

        try {
            $context = $this->auth->authenticate($sessionId);
        } catch (HttpException) {
            return LegacyJson::error($response, 401, 'unauthorized', 'User is not authenticated or session timed out.');
        }

        return LegacyJson::success($response, $this->legacyUser($context['user']), 'User is logged in.');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = V1Session::id($request);
        if ($sessionId !== null) {
            $user = $request->getAttribute('user');
            $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;
            $this->auth->logout($sessionId, $userId, $this->ip($request));
        }

        return V1Session::clearCookies(
            LegacyJson::success($response, [], 'User logged out.'),
            $this->config->cookieSecure,
        );
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return LegacyJson::success($response, [
            'version' => $this->config->version(),
            'qemu_version' => '',
            'cpu' => 0,
            'disk' => 0,
            'cached' => 0,
            'mem' => 0,
            'swap' => 0,
            'iol' => 0,
            'dynamips' => 0,
            'qemu' => 0,
            'docker' => 0,
            'vpcs' => 0,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function folders(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $path = '/' . ltrim($args['path'] ?? '', '/');
        if ($path === '//') {
            $path = '/';
        }

        try {
            $listing = $this->listFolder($path);
        } catch (HttpException $exception) {
            return LegacyJson::error($response, $exception->status, 'fail', $exception->getMessage());
        }

        return LegacyJson::success($response, $listing, 'Folder content.');
    }

    public function labsIndex(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return LegacyJson::success($response, $this->labs->all(), 'Labs.');
    }

    /**
     * @param array<string, string> $args
     */
    public function labShow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $path = '/' . ltrim($args['path'] ?? '', '/');
        if (preg_match('#^(.*?\.unl)(/.*)?$#', $path, $matches) !== 1) {
            return LegacyJson::error($response, 404, 'fail', 'Lab not found.');
        }
        if (($matches[2] ?? '') !== '' && $matches[2] !== '/') {
            return LegacyJson::error(
                $response,
                501,
                'fail',
                'Lab sub-resources are not available in the v1 compatibility adapter yet.',
            );
        }

        $lab = $this->labs->findByPath($matches[1]);
        if ($lab === null) {
            return LegacyJson::error($response, 404, 'fail', 'Lab not found.');
        }

        return LegacyJson::success($response, [
            'author' => (string) ($lab['author'] ?? ''),
            'description' => (string) ($lab['description'] ?? ''),
            'body' => (string) ($lab['body'] ?? ''),
            'filename' => (string) ($lab['filename'] ?? basename((string) $lab['path'])),
            'id' => (string) ($lab['id'] ?? ''),
            'name' => (string) ($lab['name'] ?? ''),
            'version' => (string) ($lab['version'] ?? ''),
            'scripttimeout' => 0,
        ], 'Lab.');
    }

    /**
     * @param array<string, string> $args
     */
    public function nodeStart(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->nodeAction($request, $response, $args, 'start');
    }

    /**
     * @param array<string, string> $args
     */
    public function nodeStop(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->nodeAction($request, $response, $args, 'stop');
    }

    /**
     * @param array<string, string> $args
     */
    public function nodeWipe(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->nodeAction($request, $response, $args, 'wipe');
    }

    /**
     * @param array<string, string> $args
     */
    private function nodeAction(ServerRequestInterface $request, ResponseInterface $response, array $args, string $action): ResponseInterface
    {
        $lab = $this->labs->findByPath((string) ($args['path'] ?? ''));
        if ($lab === null) {
            $lab = $this->labs->find((string) ($args['path'] ?? ''));
        }
        if ($lab === null) {
            return LegacyJson::error($response, 404, 'fail', 'Lab not found.');
        }
        try {
            $user = $this->access->user($request);
            $this->access->requireRole($user, 'editor');
            $this->access->assertLabAccess($user, (string) $lab['id']);
        } catch (HttpException $exception) {
            return LegacyJson::error($response, $exception->status, 'fail', $exception->getMessage());
        }
        $nodeID = (string) ($args['node'] ?? '');
        $isAdmin = $this->access->isAdmin($user);

        if ($action === 'start') {
            $this->sweeper->sweepIfDue();
        }
        try {
            $data = match ($action) {
                'start' => $this->labService->startNode((string) $lab['id'], $nodeID, (string) $user['id'], $isAdmin),
                'stop' => $this->labService->stopNode((string) $lab['id'], $nodeID, (string) $user['id'], $isAdmin),
                'wipe' => $this->labService->wipeNode((string) $lab['id'], $nodeID, (string) $user['id'], $isAdmin),
                default => [],
            };
        } catch (HttpException $exception) {
            return LegacyJson::error($response, $exception->status, 'fail', $exception->getMessage());
        }
        $this->audit->log((string) $user['id'], $this->ip($request), 'v1.node.' . $action, (string) $lab['id'], 'success', ['node' => $nodeID]);

        return LegacyJson::success($response, $data, 'Node ' . $action . ' accepted.');
    }

    public function unsupported(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return LegacyJson::error(
            $response,
            501,
            'fail',
            'This legacy operation is not available in the v1 compatibility adapter yet.',
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function legacyUser(array $user): array
    {
        return [
            'username' => (string) ($user['username'] ?? ''),
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => (string) ($user['role'] ?? 'user'),
            'tenant' => 0,
            'pod' => 0,
            'folder' => '/',
        ];
    }

    /**
     * @return array{folders: list<array{name: string, path: string}>, labs: list<array{file: string, path: string}>}
     */
    private function listFolder(string $path): array
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new HttpException(400, 'Invalid folder path.');
            }
        }

        $root = realpath($this->config->labsDir);
        if ($root === false || !is_dir($root)) {
            return ['folders' => [], 'labs' => []];
        }

        $target = realpath($root . ($path === '/' ? '' : $path));
        if (
            $target === false
            || !is_dir($target)
            || ($target !== $root && !str_starts_with($target, $root . DIRECTORY_SEPARATOR))
        ) {
            throw new HttpException(404, 'Folder does not exist.');
        }

        $entries = scandir($target);
        if ($entries === false) {
            throw new HttpException(500, 'Cannot read folder.');
        }

        $folders = [];
        $labs = [];
        if ($path !== '/') {
            $folders[] = ['name' => '..', 'path' => dirname($path)];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $childPath = $path === '/' ? '/' . $entry : $path . '/' . $entry;
            if (is_dir($target . '/' . $entry)) {
                $folders[] = ['name' => $entry, 'path' => $childPath];
            } elseif (is_file($target . '/' . $entry) && str_ends_with(strtolower($entry), '.unl')) {
                $labs[] = ['file' => $entry, 'path' => $childPath];
            }
        }

        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        usort($labs, static fn (array $a, array $b): int => strnatcasecmp($a['file'], $b['file']));

        return ['folders' => $folders, 'labs' => $labs];
    }

    /**
     * The legacy login shares the v2 captcha setting so it cannot be used to
     * bypass Turnstile while the modern surface is gated.
     *
     * @param array<string, mixed> $data
     */
    private function requireCaptcha(ServerRequestInterface $request, array $data, string $ip): void
    {
        if ($this->settings->int('turnstile_enabled', 0) !== 1) {
            return;
        }

        // Share the address budget with the v2 surface so the legacy adapter
        // cannot add unbounded captcha egress.
        $captchaKey = 'captcha:' . $ip;
        $this->rateLimiter->assertCountBelow(
            $captchaKey,
            $ip,
            RateLimiter::CAPTCHA_MAX_ATTEMPTS,
            RateLimiter::CAPTCHA_WINDOW_SECONDS,
            RateLimiter::CAPTCHA_LIMIT_MESSAGE,
        );
        $this->rateLimiter->consume($captchaKey, $ip);

        $token = $data['cf-turnstile-response'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = $request->getHeaderLine('CF-Turnstile-Response');
        }
        if ($token === '') {
            throw new HttpException(422, TurnstileVerifier::INVALID_MESSAGE);
        }

        $this->turnstile->verify(
            $this->settings->string('turnstile_secret_key', ''),
            $token,
            $ip,
        );
    }

    private function ip(ServerRequestInterface $request): string
    {
        return RequestIp::resolve($request->getServerParams(), $this->config->trustedProxies);
    }
}
