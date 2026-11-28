<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Auth\SetupToken;
use TeraLab\Api\Auth\TurnstileVerifier;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Http\RequestIp;
use TeraLab\Api\System\SettingsService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Config $config,
        private readonly SettingsService $settings,
        private readonly RateLimiter $rateLimiter,
        private readonly TurnstileVerifier $turnstile,
        private readonly SetupToken $setupToken,
    ) {
    }

    public function setup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = Json::body($request);
        if ($this->auth->hasUsers()) {
            throw new HttpException(409, 'Setup has already been completed.');
        }

        $ip = $this->ip($request);
        // The explicit header wins; the JSON field is the fallback for clients
        // that cannot set custom headers.
        $token = $request->getHeaderLine('X-TeraLab-Setup-Token');
        if ($token === '') {
            $bodyToken = $data['setup_token'] ?? null;
            $token = is_string($bodyToken) ? $bodyToken : '';
        }

        // Charge the attempt before comparing the token so an unauthenticated
        // caller cannot brute-force the operator secret.
        $this->rateLimiter->assertCountBelow(
            'setup:' . $ip,
            $ip,
            RateLimiter::SETUP_MAX_PER_IP,
            RateLimiter::SETUP_WINDOW_SECONDS,
            RateLimiter::SETUP_LIMIT_MESSAGE,
        );
        $this->rateLimiter->assertCountBelow(
            RateLimiter::SETUP_GLOBAL_KEY,
            RateLimiter::SETUP_GLOBAL_IP,
            RateLimiter::SETUP_MAX_GLOBAL,
            RateLimiter::SETUP_WINDOW_SECONDS,
            RateLimiter::SETUP_LIMIT_MESSAGE,
        );
        $this->rateLimiter->consume('setup:' . $ip, $ip);
        $this->rateLimiter->consume(RateLimiter::SETUP_GLOBAL_KEY, RateLimiter::SETUP_GLOBAL_IP);

        $this->setupToken->assertValid($token);

        $username = $this->stringField($data, 'username');
        $password = $this->stringField($data, 'password');
        $email = $this->optionalStringField($data, 'email');
        $userAgent = $request->getHeaderLine('User-Agent');

        $this->auth->setup($username, $password, $email, $ip);
        $this->setupToken->remove();
        // A prior failed login attempt for this not-yet-existing username must
        // not make the setup response fail after the administrator was created.
        $this->rateLimiter->clear($username, $ip);
        $session = $this->auth->login($username, $password, $ip, $userAgent);

        return $this->withSessionCookie(
            Json::response($response, [
                'status' => 'success',
                'user' => $session['user'],
                'csrf_token' => $session['csrf_token'],
                'expires_in' => $session['expires_in'],
            ], 201),
            $session['session_id'],
            $session['expires_in'],
        );
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = Json::body($request);
        $ip = $this->ip($request);
        $this->requireCaptcha($request, $data, $ip, 'login');
        $username = $this->stringField($data, 'username');
        $password = $this->stringField($data, 'password');
        $session = $this->auth->login(
            $username,
            $password,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        return $this->withSessionCookie(
            Json::response($response, [
                'status' => 'success',
                'user' => $session['user'],
                'csrf_token' => $session['csrf_token'],
                'expires_in' => $session['expires_in'],
            ]),
            $session['session_id'],
            $session['expires_in'],
        );
    }

    /**
     * Public demo entry: mint a guest identity and its session.
     *
     * Gate order matters. A disabled demo hides the surface behind a plain
     * 404; the concurrency cap is cheap; Turnstile runs before any state is
     * written; the rate limiter is the last gate before provisioning.
     */
    public function guest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->settings->int('demo_enabled', 0) !== 1) {
            throw new HttpException(404, 'Not found.');
        }

        $ip = $this->ip($request);
        $maximum = $this->settings->int('demo_max_guests', 50);
        // Advisory capacity cap: the count and the insert are separate
        // statements, so a simultaneous burst can briefly exceed the limit.
        // This is a soft demo guard, not an authorization boundary; the
        // captcha and guest budgets below bound abuse.
        if ($this->auth->activeGuestCount() >= $maximum) {
            throw new HttpException(403, 'The demo is full.');
        }

        $data = Json::body($request);
        $this->requireCaptcha($request, $data, $ip, 'guest');

        // Consume the guest budget before minting: provisioning failures must
        // not refund a slot, and the budget itself bounds burst creation.
        $guestKey = 'guest:' . $ip;
        $this->rateLimiter->assertCountBelow(
            $guestKey,
            $ip,
            RateLimiter::GUEST_MAX_ENTRIES,
            RateLimiter::GUEST_WINDOW_SECONDS,
            RateLimiter::GUEST_LIMIT_MESSAGE,
        );
        $this->rateLimiter->consume($guestKey, $ip);

        $session = $this->auth->createGuest($ip, $request->getHeaderLine('User-Agent'));

        return $this->withSessionCookie(
            Json::response($response, [
                'status' => 'success',
                'user' => $session['user'],
                'csrf_token' => $session['csrf_token'],
                'expires_in' => $session['expires_in'],
            ]),
            $session['session_id'],
            $session['expires_in'],
        );
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = $request->getAttribute('session_id');
        $user = $request->getAttribute('user');
        $userId = is_array($user) && isset($user['id']) && is_string($user['id']) ? $user['id'] : null;

        if (is_string($sessionId) && $sessionId !== '') {
            $this->auth->logout($sessionId, $userId, $this->ip($request));
        }

        return $this->clearSessionCookie(Json::response($response, ['status' => 'success']));
    }

    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $sessionId = $request->getAttribute('session_id');
        if (!is_array($user) || !isset($user['id']) || !is_string($user['id']) || !is_string($sessionId)) {
            throw new HttpException(401, 'Authentication required.');
        }
        $body = Json::body($request);
        $current = $body['current_password'] ?? null;
        $new = $body['new_password'] ?? null;
        if (!is_string($current) || !is_string($new)) {
            throw new HttpException(422, 'current_password and new_password are required.');
        }
        $this->auth->changePassword($user['id'], $sessionId, $current, $new, $this->ip($request));

        return Json::response($response, ['status' => 'success']);
    }

    public function revokeSessions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $sessionId = $request->getAttribute('session_id');
        if (!is_array($user) || !isset($user['id']) || !is_string($user['id']) || !is_string($sessionId)) {
            throw new HttpException(401, 'Authentication required.');
        }
        $revoked = $this->auth->revokeOtherSessions($user['id'], $sessionId, $this->ip($request));

        return Json::response($response, ['status' => 'success', 'revoked' => $revoked]);
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = $request->getAttribute('session_id');
        $user = $request->getAttribute('user');
        if (!is_string($sessionId) || !is_array($user)) {
            throw new HttpException(401, 'Authentication required.');
        }

        return Json::response($response, [
            'user' => $user,
            'csrf_token' => $this->auth->rotateCsrf($sessionId),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new HttpException(422, $key . ' is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalStringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, $key . ' must be a string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireCaptcha(
        ServerRequestInterface $request,
        array $data,
        string $ip,
        string $action,
    ): void {
        if ($this->settings->int('turnstile_enabled', 0) !== 1) {
            return;
        }

        // Bound captcha egress per address. Charge the attempt before the
        // 5-second outbound call so timeouts and failures cannot be used to
        // drive unbounded verification requests.
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
            $action,
            $this->settings->string('turnstile_hostname', ''),
        );
    }

    private function ip(ServerRequestInterface $request): string
    {
        return RequestIp::resolve($request->getServerParams(), $this->config->trustedProxies);
    }

    private function withSessionCookie(ResponseInterface $response, string $sessionId, int $ttl): ResponseInterface
    {
        $cookie = Config::COOKIE_NAME . '=' . $sessionId
            . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=' . $ttl;
        if ($this->config->cookieSecure) {
            $cookie .= '; Secure';
        }

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    private function clearSessionCookie(ResponseInterface $response): ResponseInterface
    {
        $cookie = Config::COOKIE_NAME
            . '=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        if ($this->config->cookieSecure) {
            $cookie .= '; Secure';
        }

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
