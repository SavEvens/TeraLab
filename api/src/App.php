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
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Auth\AuthService;
use TeraLab\Api\Auth\PasswordPolicy;
use TeraLab\Api\Auth\RateLimiter;
use TeraLab\Api\Database\Database;
use TeraLab\Api\Database\Migrator;
use TeraLab\Api\Http\Controllers\AuthController;
use TeraLab\Api\Http\Controllers\EngineController;
use TeraLab\Api\Http\Controllers\ImageController;
use TeraLab\Api\Http\Controllers\JobController;
use TeraLab\Api\Http\Controllers\HealthController;
use TeraLab\Api\Http\Controllers\HostController;
use TeraLab\Api\Http\Controllers\LabController;
use TeraLab\Api\Http\Controllers\NetworkController;
use TeraLab\Api\Http\Controllers\NodeController;
use TeraLab\Api\Http\Controllers\SystemController;
use TeraLab\Api\Http\Controllers\TemplateController;
use TeraLab\Api\Http\Controllers\V1Controller;
use TeraLab\Api\Http\Controllers\VersionController;
use TeraLab\Api\Http\Middleware\AuthMiddleware;
use TeraLab\Api\Http\Middleware\CsrfMiddleware;
use TeraLab\Api\Http\Middleware\JsonErrorMiddleware;
use TeraLab\Api\Http\Middleware\RequestIdMiddleware;
use TeraLab\Api\Http\Middleware\RoleMiddleware;
use TeraLab\Api\Http\Middleware\SecurityHeadersMiddleware;
use TeraLab\Api\Http\Middleware\V1AuthMiddleware;
use TeraLab\Api\Image\ImageStore;
use TeraLab\Api\Jobs\JobService;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Templates\TemplateRepository;

final class App
{
    public function __construct(
        private readonly ?Config $config = null,
        private readonly ?AgentClient $agent = null,
    ) {
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
        $editorRole = static fn (): RoleMiddleware => new RoleMiddleware($container->get(\TeraLab\Api\Auth\AccessService::class), 'editor');
        $adminRole = static fn (): RoleMiddleware => new RoleMiddleware($container->get(\TeraLab\Api\Auth\AccessService::class), 'admin');
        $agent = $this->agent;
        $container->set(
            AgentClient::class,
            static fn (Config $config): AgentClient => $agent ?? new AgentClient($config)
        );
        $container->set(
            ImageStore::class,
            static fn (Config $config, PDO $pdo, TemplateRepository $templates): ImageStore => new ImageStore(
                $config,
                $pdo,
                static fn (string $path): array => $container->get(AgentClient::class)->inspectImage($path),
                $templates,
            )
        );

        $container->get(Migrator::class)->migrate();
        $container->get(JobService::class)->reconcile();

        $app = AppFactory::createFromContainer($container);
        $app->addRoutingMiddleware();

        $app->get('/api/v2/health', $container->get(HealthController::class));
        $app->get('/api/v2/version', $container->get(VersionController::class));
        $app->post('/api/v2/setup', fn ($request, $response) => $container->get(AuthController::class)->setup($request, $response));
        $app->post('/api/v2/auth/login', fn ($request, $response) => $container->get(AuthController::class)->login($request, $response));
        $app->get('/api/v2/auth/me', fn ($request, $response) => $container->get(AuthController::class)->me($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/auth/password', fn ($request, $response) => $container->get(AuthController::class)->changePassword($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/auth/sessions/revoke', fn ($request, $response) => $container->get(AuthController::class)->revokeSessions($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/auth/logout', fn ($request, $response) => $container->get(AuthController::class)->logout($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/status', fn ($request, $response) => $container->get(SystemController::class)->status($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs', fn ($request, $response) => $container->get(LabController::class)->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $labs = $container->get(LabController::class);
        $app->post('/api/v2/labs', fn ($request, $response) => $labs->create($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/import', fn ($request, $response) => $labs->import($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}', fn ($request, $response, array $args) => $labs->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/labs/{id}', fn ($request, $response, array $args) => $labs->save($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/labs/{id}', fn ($request, $response, array $args) => $labs->delete($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}/export', fn ($request, $response, array $args) => $labs->export($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}/nodes', fn ($request, $response, array $args) => $labs->nodes($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/start', fn ($request, $response, array $args) => $labs->start($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/stop', fn ($request, $response, array $args) => $labs->stop($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/wipe', fn ($request, $response, array $args) => $labs->wipe($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/duplicate', fn ($request, $response, array $args) => $labs->duplicate($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}/permissions', fn ($request, $response, array $args) => $labs->permissions($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/permissions', fn ($request, $response, array $args) => $labs->grant($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/labs/{id}/permissions/{user}', fn ($request, $response, array $args) => $labs->revoke($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        // Node lifecycle is delegated to the privileged Go agent.
        $nodes = $container->get(NodeController::class);
        $app->post('/api/v2/nodes/start', fn ($request, $response) => $nodes->start($request, $response))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/nodes/stop', fn ($request, $response) => $nodes->stop($request, $response))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/nodes/wipe', fn ($request, $response) => $nodes->wipe($request, $response))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/nodes/status', fn ($request, $response) => $nodes->status($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/nodes/console', fn ($request, $response) => $nodes->console($request, $response))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        $networks = $container->get(NetworkController::class);
        $app->post('/api/v2/labs/{id}/networks', fn ($request, $response, array $args) => $networks->ensure($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/networks/cleanup', fn ($request, $response, array $args) => $networks->cleanup($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        $images = $container->get(ImageController::class);
        $app->get('/api/v2/images', fn ($request, $response) => $images->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/images/{id}', fn ($request, $response, array $args) => $images->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/images', fn ($request, $response) => $images->upload($request, $response))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/images/{id}', fn ($request, $response, array $args) => $images->delete($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->patch('/api/v2/images/{id}', fn ($request, $response, array $args) => $images->rename($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $templates = $container->get(TemplateController::class);
        $app->get('/api/v2/templates', fn ($request, $response) => $templates->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/templates/{id}', fn ($request, $response, array $args) => $templates->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/templates/{id}', fn ($request, $response, array $args) => $templates->save($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/templates/{id}', fn ($request, $response, array $args) => $templates->delete($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/images/{id}/template', fn ($request, $response, array $args) => $images->mapTemplate($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        $engines = $container->get(EngineController::class);
        $app->get('/api/v2/engines', fn ($request, $response) => $engines->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/host', fn ($request, $response) => $container->get(HostController::class)->status($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $jobs = $container->get(JobController::class);
        $app->get('/api/v2/jobs', fn ($request, $response) => $jobs->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/jobs/{id}', fn ($request, $response, array $args) => $jobs->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/jobs/{id}/cancel', fn ($request, $response, array $args) => $jobs->cancel($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/maintenance/reconcile', fn ($request, $response) => $engines->reconcile($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        // Legacy v1 compatibility adapter. These routes keep the old UI and
        // clients working while the v2 API becomes the supported surface.
        $v1 = $container->get(V1Controller::class);
        $v1Auth = $container->get(V1AuthMiddleware::class);
        $app->post('/api/auth/login', fn ($request, $response) => $v1->login($request, $response));
        $app->get('/api/auth/logout', fn ($request, $response) => $v1->logout($request, $response));
        $app->get('/api/auth', fn ($request, $response) => $v1->auth($request, $response));
        $app->get('/api/status', fn ($request, $response) => $v1->status($request, $response))
            ->add($v1Auth);
        $app->delete('/api/status', fn ($request, $response) => $v1->unsupported($request, $response))
            ->add($v1Auth);
        $app->get('/api/folders', fn ($request, $response) => $v1->folders($request, $response, ['path' => '']))
            ->add($v1Auth);
        $app->get('/api/folders/{path:.*}', fn ($request, $response, array $args) => $v1->folders($request, $response, $args))
            ->add($v1Auth);
        $app->get('/api/labs', fn ($request, $response) => $v1->labsIndex($request, $response))
            ->add($v1Auth);
        $app->get('/api/labs/{path:.*}/nodes/{node}/start', fn ($request, $response, array $args) => $v1->nodeStart($request, $response, $args))
            ->add($v1Auth);
        $app->get('/api/labs/{path:.*}/nodes/{node}/stop', fn ($request, $response, array $args) => $v1->nodeStop($request, $response, $args))
            ->add($v1Auth);
        $app->get('/api/labs/{path:.*}/nodes/{node}/wipe', fn ($request, $response, array $args) => $v1->nodeWipe($request, $response, $args))
            ->add($v1Auth);
        $app->get('/api/labs/{path:.*}', fn ($request, $response, array $args) => $v1->labShow($request, $response, $args))
            ->add($v1Auth);
        $app->post('/api/labs', fn ($request, $response) => $v1->unsupported($request, $response))
            ->add($v1Auth);
        $app->put('/api/labs/{path:.*}', fn ($request, $response) => $v1->unsupported($request, $response))
            ->add($v1Auth);
        $app->delete('/api/labs/{path:.*}', fn ($request, $response) => $v1->unsupported($request, $response))
            ->add($v1Auth);

        $app->add($container->get(SecurityHeadersMiddleware::class));
        $app->add($container->get(JsonErrorMiddleware::class));
        $app->add($container->get(RequestIdMiddleware::class));

        return $app;
    }
}
