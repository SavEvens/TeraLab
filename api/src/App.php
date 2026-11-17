<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api;

use DI\Container;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Auth\PasswordPolicy;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Http\Controllers\AuthController;
use TeraLab\Api\Http\Controllers\HealthController;
use TeraLab\Api\Http\Controllers\LabController;
use TeraLab\Api\Http\Controllers\SystemController;
use TeraLab\Api\Http\Controllers\VersionController;
use TeraLab\Api\Http\Middleware\AuthMiddleware;
use TeraLab\Api\Http\Middleware\CsrfMiddleware;
use TeraLab\Api\Http\Middleware\JsonErrorMiddleware;
use TeraLab\Api\Http\Middleware\RequestIdMiddleware;
use TeraLab\Api\Http\Middleware\SecurityHeadersMiddleware;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\UnlParser;

final class App
{
    public function __construct(private readonly ?Config $config = null)
    {
    }

    /** @return SlimApp<Container> */
    public function create(): SlimApp
    {
        $config = $this->config ?? Config::fromEnvironment();

        $container = new Container();
        $container->set(Config::class, $config);
        $container->set(PDO::class, static fn (Config $config): PDO => (new Database($config))->pdo());
        $container->set(
            LoggerInterface::class,
            static fn (): LoggerInterface => new Logger('teralab-api', [
                new StreamHandler('php://stderr', Logger::WARNING),
            ])
        );
        $container->set(UnlParser::class, static fn (): UnlParser => new UnlParser());
        $container->set(
            LabRepository::class,
            static fn (Config $config, UnlParser $parser): LabRepository => new LabRepository($config->labsDir, $parser)
        );
        $container->set(PasswordPolicy::class, static fn (): PasswordPolicy => new PasswordPolicy());
        $container->set(RateLimiter::class, static fn (PDO $pdo): RateLimiter => new RateLimiter($pdo));
        $container->set(AuditLogger::class, static fn (PDO $pdo): AuditLogger => new AuditLogger($pdo));
        $container->set(
            AuthService::class,
            static fn (
                PDO $pdo,
                PasswordPolicy $policy,
                RateLimiter $rateLimiter,
                AuditLogger $audit,
                Config $config,
            ): AuthService => new AuthService($pdo, $policy, $rateLimiter, $audit, $config)
        );
        $container->set(
            Migrator::class,
            static fn (PDO $pdo, Config $config): Migrator => new Migrator($pdo, $config->migrationsDir())
        );

        $container->get(Migrator::class)->migrate();

        $app = AppFactory::createFromContainer($container);
        $app->addRoutingMiddleware();

        $app->get('/api/v2/health', $container->get(HealthController::class));
        $app->get('/api/v2/version', $container->get(VersionController::class));
        $app->post('/api/v2/setup', fn ($request, $response) => $container->get(AuthController::class)->setup($request, $response));
        $app->post('/api/v2/auth/login', fn ($request, $response) => $container->get(AuthController::class)->login($request, $response));
        $app->get('/api/v2/auth/me', fn ($request, $response) => $container->get(AuthController::class)->me($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/auth/logout', fn ($request, $response) => $container->get(AuthController::class)->logout($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/status', fn ($request, $response) => $container->get(SystemController::class)->status($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs', fn ($request, $response) => $container->get(LabController::class)->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}', fn ($request, $response, array $args) => $container->get(LabController::class)->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));

        $app->add($container->get(SecurityHeadersMiddleware::class));
        $app->add($container->get(JsonErrorMiddleware::class));
        $app->add($container->get(RequestIdMiddleware::class));

        return $app;
    }
}
