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
use TeraLab\Api\Auth\SetupToken;
use TeraLab\Api\Auth\TurnstileVerifier;
use TeraLab\Api\Database\Bootstrap;
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
use TeraLab\Api\Http\Controllers\UsersController;
use TeraLab\Api\Http\Controllers\V1Controller;
use TeraLab\Api\Http\Controllers\VersionController;
use TeraLab\Api\Http\Middleware\AuthMiddleware;
use TeraLab\Api\Http\Middleware\CsrfMiddleware;
use TeraLab\Api\Http\Middleware\JsonErrorMiddleware;
use TeraLab\Api\Http\Middleware\RequestIdMiddleware;
use TeraLab\Api\Http\Middleware\RoleMiddleware;
use TeraLab\Api\Http\Middleware\SecurityHeadersMiddleware;
use TeraLab\Api\Http\Middleware\V1AuthMiddleware;
use TeraLab\Api\Image\ImageReferences;
use TeraLab\Api\Image\ImageStore;
use TeraLab\Api\Image\LegacyAddonsImporter;
use TeraLab\Api\Jobs\JobService;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;
use TeraLab\Api\Labs\LabYamlCompiler;
use TeraLab\Api\Labs\UnlParser;
use TeraLab\Api\Labs\UnlWriter;
use TeraLab\Api\Snapshots\SnapshotRepository;
use TeraLab\Api\System\DemoGuard;
use TeraLab\Api\System\DemoPolicy;
use TeraLab\Api\System\DemoSweeper;
use TeraLab\Api\System\HostMemory;
use TeraLab\Api\System\MaintenanceService;
use TeraLab\Api\System\SettingsService;
use TeraLab\Api\Templates\TemplateRepository;

final class App
{
    public function __construct(
        private readonly ?Config $config = null,
        private readonly ?AgentClient $agent = null,
        private readonly ?TurnstileVerifier $turnstile = null,
        private readonly ?HostMemory $hostMemory = null,
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
            static function (Config $config): LoggerInterface {
                $handlers = [new StreamHandler('php://stderr', Logger::WARNING)];
                $directory = $config->dataDir . '/logs';
                if (is_dir($directory) || @mkdir($directory, 0770, true) || is_dir($directory)) {
                    $handlers[] = new StreamHandler($directory . '/api.log', Logger::WARNING);
                }

                return new Logger('teralab-api', $handlers);
            }
        );
        $container->set(UnlParser::class, static fn (): UnlParser => new UnlParser());
        $container->set(
            LabRepository::class,
            static fn (Config $config, UnlParser $parser): LabRepository => new LabRepository($config->labsDir, $parser)
        );
        $hostMemory = $this->hostMemory;
        $container->set(
            HostMemory::class,
            static fn (): HostMemory => $hostMemory ?? new HostMemory()
        );
        $container->set(
            DemoGuard::class,
            static fn (
                PDO $pdo,
                SettingsService $settings,
                TemplateRepository $templates,
                HostMemory $memory,
                LabRepository $labs,
            ): DemoGuard => new DemoGuard($pdo, $settings, new DemoPolicy(), $templates, $labs, $memory)
        );
        $container->set(
            DemoSweeper::class,
            static fn (
                PDO $pdo,
                SettingsService $settings,
                AgentClient $agent,
                LabService $labs,
                AuditLogger $audit,
                HostMemory $memory,
                LabRepository $repository,
                MaintenanceService $maintenance,
            ): DemoSweeper => new DemoSweeper(
                $pdo,
                $settings,
                new DemoPolicy(),
                $agent,
                $labs,
                $audit,
                $memory,
                $repository,
                null,
                $maintenance,
            )
        );
        $container->set(
            MaintenanceService::class,
            static fn (
                PDO $pdo,
                SettingsService $settings,
                Config $config,
                LabService $labs,
            ): MaintenanceService => new MaintenanceService($pdo, $settings, $config, $labs)
        );
        $container->set(
            LabService::class,
            static fn (
                Config $config,
                UnlParser $parser,
                UnlWriter $writer,
                AgentClient $agent,
                LabLocks $locks,
                ImageReferences $images,
                SnapshotRepository $snapshots,
                TemplateRepository $templates,
                DemoGuard $demoGuard,
                SettingsService $settings,
            ): LabService => new LabService(
                $config,
                $parser,
                $writer,
                $agent,
                $locks,
                $images,
                $snapshots,
                $templates,
                new LabYamlCompiler($templates),
                $demoGuard,
                null,
                null,
                $settings,
            )
        );
        $container->set(PasswordPolicy::class, static fn (): PasswordPolicy => new PasswordPolicy());
        $container->set(RateLimiter::class, static fn (PDO $pdo): RateLimiter => new RateLimiter($pdo));
        $container->set(AuditLogger::class, static fn (PDO $pdo): AuditLogger => new AuditLogger($pdo));
        $container->set(
            SetupToken::class,
            static fn (Config $config): SetupToken => new SetupToken($config->setupTokenFile)
        );
        $container->set(
            SettingsService::class,
            static fn (PDO $pdo, Config $config): SettingsService => new SettingsService($pdo, $config->environment)
        );
        $turnstile = $this->turnstile;
        $container->set(
            TurnstileVerifier::class,
            static fn (): TurnstileVerifier => $turnstile ?? new TurnstileVerifier()
        );
        $container->set(
            AuthService::class,
            static fn (
                PDO $pdo,
                PasswordPolicy $policy,
                RateLimiter $rateLimiter,
                AuditLogger $audit,
                Config $config,
                \TeraLab\Api\System\SettingsService $settings,
            ): AuthService => new AuthService($pdo, $policy, $rateLimiter, $audit, $config, $settings)
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
                $container->get(\TeraLab\Api\System\SettingsService::class),
            )
        );
        $container->set(
            LegacyAddonsImporter::class,
            static fn (Config $config, PDO $pdo, TemplateRepository $templates): LegacyAddonsImporter => new LegacyAddonsImporter(
                $config,
                $pdo,
                static fn (string $path): array => $container->get(AgentClient::class)->inspectImage($path),
                $templates,
            )
        );

        // Migrations are a bootstrap step, not request work: the marker records
        // the code version and migration list so a normal request only reads a
        // small file. Job reconciliation is interval-bounded for the same
        // reason (one settings row, then a write at most every few minutes).
        $bootstrap = new Bootstrap($config, $container->get(Migrator::class));
        $bootstrap->ensureMigrated();
        $container->get(JobService::class)->reconcileIfDue();
        $settings = $container->get(\TeraLab\Api\System\SettingsService::class);
        $legacyV1 = $settings->int('legacy_v1_enabled', 0) === 1;
        $timezone = $settings->string('timezone', 'UTC');
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }

        $app = AppFactory::createFromContainer($container);
        $app->addRoutingMiddleware();

        $app->get('/api/v2/health', $container->get(HealthController::class));
        $app->get('/api/v2/version', $container->get(VersionController::class));
        $app->post('/api/v2/setup', fn ($request, $response) => $container->get(AuthController::class)->setup($request, $response));
        $app->post('/api/v2/auth/login', fn ($request, $response) => $container->get(AuthController::class)->login($request, $response));
        $app->post('/api/v2/auth/guest', fn ($request, $response) => $container->get(AuthController::class)->guest($request, $response));
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
        $app->post('/api/v2/labs/validate', fn ($request, $response) => $labs->validate($request, $response))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/trash', fn ($request, $response) => $labs->trash($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/labs/trash/{id}', fn ($request, $response, array $args) => $labs->purge($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}', fn ($request, $response, array $args) => $labs->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/restore', fn ($request, $response, array $args) => $labs->restore($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/labs/{id}', fn ($request, $response, array $args) => $labs->save($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/apply', fn ($request, $response, array $args) => $labs->apply($request, $response, $args))
            ->add($editorRole())
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
        $app->post('/api/v2/labs/{id}/move', fn ($request, $response, array $args) => $labs->move($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/duplicate', fn ($request, $response, array $args) => $labs->duplicate($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}/snapshots', fn ($request, $response, array $args) => $labs->snapshots($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/snapshots', fn ($request, $response, array $args) => $labs->createSnapshot($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/snapshots/{snapshot}/restore', fn ($request, $response, array $args) => $labs->restoreSnapshot($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/labs/{id}/snapshots/{snapshot}', fn ($request, $response, array $args) => $labs->deleteSnapshot($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/nodes/{node}/start', fn ($request, $response, array $args) => $labs->nodeStart($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/nodes/{node}/stop', fn ($request, $response, array $args) => $labs->nodeStop($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/nodes/{node}/wipe', fn ($request, $response, array $args) => $labs->nodeWipe($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}/permissions', fn ($request, $response, array $args) => $labs->permissions($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/labs/{id}/permissions', fn ($request, $response, array $args) => $labs->grant($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/labs/{id}/permissions/{username}', fn ($request, $response, array $args) => $labs->revoke($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        // Node lifecycle is delegated to the privileged Go agent. The public
        // contract is lab-scoped: the agent node id is always derived from an
        // authorized lab+node pair, never accepted from the client.
        $nodes = $container->get(NodeController::class);
        $app->post('/api/v2/labs/{id}/nodes/{node}/console', fn ($request, $response, array $args) => $nodes->console($request, $response, $args))
            ->add($editorRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/labs/{id}/nodes/{node}/status', fn ($request, $response, array $args) => $labs->nodeStatus($request, $response, $args))
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
        // Catalogue mutations are admin-only: images are shared by every lab
        // and a guest/editor must not change what other users can start.
        $app->post('/api/v2/images/import', fn ($request, $response) => $images->importLegacy($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/images/{id}', fn ($request, $response, array $args) => $images->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/images', fn ($request, $response) => $images->upload($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/images/{id}', fn ($request, $response, array $args) => $images->delete($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->patch('/api/v2/images/{id}', fn ($request, $response, array $args) => $images->rename($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $templates = $container->get(TemplateController::class);
        $app->get('/api/v2/templates', fn ($request, $response) => $templates->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/templates/{id}', fn ($request, $response, array $args) => $templates->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/templates/{id}/export', fn ($request, $response, array $args) => $templates->export($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/templates/import', fn ($request, $response) => $templates->import($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/templates/{id}/duplicate', fn ($request, $response, array $args) => $templates->duplicate($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/templates/{id}', fn ($request, $response, array $args) => $templates->save($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/templates/{id}', fn ($request, $response, array $args) => $templates->delete($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/images/{id}/template', fn ($request, $response, array $args) => $images->mapTemplate($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        $engines = $container->get(EngineController::class);
        $app->get('/api/v2/engines', fn ($request, $response) => $engines->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/docker/images', fn ($request, $response) => $engines->dockerImages($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/host', fn ($request, $response) => $container->get(HostController::class)->status($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $system = $container->get(SystemController::class);
        $app->get('/api/v2/system/logs', fn ($request, $response) => $system->logs($request, $response))
            ->add($adminRole())
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/settings', fn ($request, $response) => $system->settings($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->put('/api/v2/system/settings', fn ($request, $response) => $system->updateSettings($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/system/demo/sweep', fn ($request, $response) => $system->demoSweep($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/demo/status', fn ($request, $response) => $system->demoStatus($request, $response))
            ->add($adminRole())
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/system/maintenance', fn ($request, $response) => $system->maintenance($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/backup', fn ($request, $response) => $system->backup($request, $response))
            ->add($adminRole())
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/system/restore', fn ($request, $response) => $system->restore($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/system/tokens', fn ($request, $response) => $system->tokens($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/system/tokens', fn ($request, $response) => $system->createToken($request, $response))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/system/tokens/{id}', fn ($request, $response, array $args) => $system->revokeToken($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $users = $container->get(UsersController::class);
        $app->get('/api/v2/users', fn ($request, $response) => $users->index($request, $response))
            ->add($adminRole())
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/users', fn ($request, $response) => $users->create($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->patch('/api/v2/users/{id}', fn ($request, $response, array $args) => $users->update($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/users/{id}/password', fn ($request, $response, array $args) => $users->resetPassword($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/users/{id}/sessions/revoke', fn ($request, $response, array $args) => $users->revokeSessions($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        // Legacy import lifecycle: count only, then clear every imported hash
        // and its sessions so the account requires an administrator reset.
        $app->get('/api/v2/users/legacy-hashes', fn ($request, $response) => $users->legacyHashCount($request, $response))
            ->add($adminRole())
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/users/legacy-hashes/clear', fn ($request, $response) => $users->clearLegacyHashes($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->delete('/api/v2/users/{id}', fn ($request, $response, array $args) => $users->delete($request, $response, $args))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $jobs = $container->get(JobController::class);
        $app->get('/api/v2/jobs', fn ($request, $response) => $jobs->index($request, $response))
            ->add($container->get(AuthMiddleware::class));
        $app->get('/api/v2/jobs/{id}', fn ($request, $response, array $args) => $jobs->show($request, $response, $args))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/jobs/{id}/cancel', fn ($request, $response, array $args) => $jobs->cancel($request, $response, $args))
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));
        $app->post('/api/v2/maintenance/reconcile', fn ($request, $response) => $engines->reconcile($request, $response))
            ->add($adminRole())
            ->add($container->get(CsrfMiddleware::class))
            ->add($container->get(AuthMiddleware::class));

        // Legacy v1 compatibility adapter. Disabled by default; when enabled it
        // is read-only, access-filtered, and every state-changing request
        // answers 405. Authenticated work belongs on the v2 lab-scoped routes.
        if ($legacyV1) {
            $v1 = $container->get(V1Controller::class);
            $v1Auth = $container->get(V1AuthMiddleware::class);
            $app->post('/api/auth/login', fn ($request, $response) => $v1->login($request, $response));
            // GET logout is a documented CSRF exception: it only removes the
            // caller's own session and changes no lab state.
            $app->get('/api/auth/logout', fn ($request, $response) => $v1->logout($request, $response));
            $app->get('/api/auth', fn ($request, $response) => $v1->auth($request, $response));
            $app->get('/api/status', fn ($request, $response) => $v1->status($request, $response))
                ->add($v1Auth);
            $app->delete('/api/status', fn ($request, $response) => $v1->methodNotAllowed($request, $response))
                ->add($v1Auth);
            $app->get('/api/folders', fn ($request, $response) => $v1->folders($request, $response, ['path' => '']))
                ->add($v1Auth);
            $app->get('/api/folders/{path:.*}', fn ($request, $response, array $args) => $v1->folders($request, $response, $args))
                ->add($v1Auth);
            $app->get('/api/labs', fn ($request, $response) => $v1->labsIndex($request, $response))
                ->add($v1Auth);
            foreach (['start', 'stop', 'wipe'] as $action) {
                $app->get('/api/labs/{path:.*}/nodes/{node}/' . $action, fn ($request, $response) => $v1->methodNotAllowed($request, $response))
                    ->add($v1Auth);
                $app->post('/api/labs/{path:.*}/nodes/{node}/' . $action, fn ($request, $response) => $v1->methodNotAllowed($request, $response))
                    ->add($v1Auth);
            }
            $app->get('/api/labs/{path:.*}', fn ($request, $response, array $args) => $v1->labShow($request, $response, $args))
                ->add($v1Auth);
            $app->post('/api/labs', fn ($request, $response) => $v1->methodNotAllowed($request, $response))
                ->add($v1Auth);
            $app->put('/api/labs/{path:.*}', fn ($request, $response) => $v1->methodNotAllowed($request, $response))
                ->add($v1Auth);
            $app->delete('/api/labs/{path:.*}', fn ($request, $response) => $v1->methodNotAllowed($request, $response))
                ->add($v1Auth);
        }

        $app->add($container->get(SecurityHeadersMiddleware::class));
        $app->add($container->get(JsonErrorMiddleware::class));
        $app->add($container->get(RequestIdMiddleware::class));

        return $app;
    }
}
