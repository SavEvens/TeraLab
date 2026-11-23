<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use TeraLab\Api\Agent\AgentClient;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;

/**
 * The builtin FRR templates through the public template API: they must load,
 * validate and expose their daemon options to the editor.
 */
final class TemplateApiTest extends TestCase
{
    private const PASSWORD = 'Teralab-Admin-2026!';

    private string $root;
    private Config $config;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private string $cookie = '';
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-tplapi-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        // The repository root is the real one so the actual builtin templates
        // under api/templates/builtin are exercised.
        $this->config = Config::forTests(dirname(__DIR__, 2), $this->root . '/data');
        $agent = new AgentClient($this->config, static fn (string $method, string $path, array $payload): array => [
            200,
            '{"ok":true,"result":{}}',
        ]);
        $this->app = (new App($this->config, $agent))->create();

        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $this->cookie = $this->cookie($setup);
        $this->csrf = (string) $this->json($setup)['csrf_token'];
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testFrrTemplatesAreListedAsDockerTemplates(): void
    {
        $list = $this->request('GET', '/api/v2/templates', [], [], $this->cookie);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());

        $byId = [];
        foreach ($this->json($list)['data'] as $template) {
            $byId[(string) $template['id']] = $template;
        }

        foreach (['frr', 'frr-ospf', 'frr-bgp'] as $id) {
            self::assertArrayHasKey($id, $byId, $id . ' must be listed');
            self::assertSame('docker', $byId[$id]['kind']);
            self::assertSame('AGPL-3.0-or-later', $byId[$id]['license']);
            self::assertSame('builtin', $byId[$id]['source']);
            self::assertArrayNotHasKey('yaml', $byId[$id], 'the list must not ship the raw yaml');
        }
        self::assertSame('FRR Router', $byId['frr']['name']);
        self::assertSame('FRR (OSPF only)', $byId['frr-ospf']['name']);
        self::assertSame('FRR (BGP only)', $byId['frr-bgp']['name']);
    }

    public function testShowFrrReturnsTheDaemonOptions(): void
    {
        $response = $this->request('GET', '/api/v2/templates/frr', [], [], $this->cookie);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $template = $this->json($response)['data'];
        self::assertSame('docker', $template['kind']);
        self::assertSame('quay.io/frrouting/frr:10.7.1', $template['options']['docker']['image']);
        self::assertSame('/usr/bin/vtysh', $template['options']['docker']['console_command']);
        self::assertSame(['NET_ADMIN', 'SYS_ADMIN'], $template['options']['docker']['cap_add']);
        self::assertSame(['zebra', 'ospfd', 'bgpd'], $template['options']['docker']['daemons']);
        self::assertSame('/etc/frr/frr.conf', $template['options']['docker']['config_path']);
        self::assertTrue($template['options']['docker']['pull']);
    }

    public function testInvalidDaemonAndConfigPathAreRejectedByTheApi(): void
    {
        foreach ([
            "daemons: [zebra, made-upd]",
            "daemons: [zebra, zebra]",
            "config_path: etc/frr/frr.conf",
            "config_path: /etc/../frr.conf",
        ] as $fragment) {
            $yaml = "schema: 1\nid: bad-frr\nname: Bad FRR\nkind: docker\noptions:\n  docker:\n"
                . "    image: quay.io/frrouting/frr:10.7.1\n    " . $fragment . "\n";
            $response = $this->request('PUT', '/api/v2/templates/bad-frr', ['yaml' => $yaml], ['X-CSRF-Token' => $this->csrf], $this->cookie);
            self::assertSame(422, $response->getStatusCode(), $fragment);
        }
    }

    public function testGuestsCannotMutateTemplatesButAdminsCan(): void
    {
        $adminCookie = $this->cookie;
        $adminCsrf = $this->csrf;
        [$guestCookie, $guestCsrf] = $this->guestSession();
        $this->cookie = $guestCookie;
        $this->csrf = $guestCsrf;

        $yaml = "schema: 1\nid: demo-shadow\nname: Demo Shadow\nkind: vpcs\n";
        foreach ([
            ['PUT', '/api/v2/templates/demo-shadow', ['yaml' => $yaml]],
            ['POST', '/api/v2/templates/import', []],
            ['POST', '/api/v2/templates/frr/duplicate', []],
            ['DELETE', '/api/v2/templates/frr', []],
        ] as [$method, $path, $body]) {
            $response = $this->request(
                $method,
                $path,
                $body,
                ['X-CSRF-Token' => $guestCsrf],
                $guestCookie,
            );
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame(
                'The demo does not allow template changes.',
                (string) $this->json($response)['message'],
            );
        }
        self::assertFileDoesNotExist($this->config->templatesDir . '/demo-shadow.yaml');
        self::assertFileDoesNotExist($this->config->templatesDir . '/frr-copy.yaml');

        $this->cookie = $adminCookie;
        $this->csrf = $adminCsrf;
        $adminYaml = "schema: 1\nid: admin-new\nname: Admin New\nkind: vpcs\n";
        $admin = $this->request(
            'PUT',
            '/api/v2/templates/admin-new',
            ['yaml' => $adminYaml],
            ['X-CSRF-Token' => $adminCsrf],
            $adminCookie,
        );
        self::assertSame(200, $admin->getStatusCode(), (string) $admin->getBody());
        self::assertFileExists($this->config->templatesDir . '/admin-new.yaml');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $cookie = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookie !== null && $cookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $cookie]);
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function cookie(ResponseInterface $response): string
    {
        if (preg_match('/' . preg_quote(Config::COOKIE_NAME, '/') . '=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function guestSession(): array
    {
        $password = 'Teralab-Guest-2026!';
        $pdo = (new Database($this->config))->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :expiration, :guest, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => 'guest-template',
            ':username' => 'guest-template',
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':expiration' => time() + 600,
            ':guest' => 1,
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);

        $savedCookie = $this->cookie;
        $savedCsrf = $this->csrf;
        $this->cookie = '';
        $this->csrf = '';
        $response = $this->request('POST', '/api/v2/auth/login', [
            'username' => 'guest-template',
            'password' => $password,
        ]);
        $this->cookie = $savedCookie;
        $this->csrf = $savedCsrf;
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return [$this->cookie($response), (string) $this->json($response)['csrf_token']];
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
