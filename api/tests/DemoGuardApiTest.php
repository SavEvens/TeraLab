<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TeraLab\Api\App;
use TeraLab\Api\Config;
use TeraLab\Api\Database\Database;
use TeraLab\Api\System\SettingsService;
use TeraLab\Api\Tests\Support\StaticAssets;

final class DemoGuardApiTest extends TestCase
{
    use StaticAssets;

    private const PASSWORD = 'Teralab-Admin-2026!';

    private const GUEST_PASSWORD = 'Teralab-Guest-2026!';

    private string $root;
    /** @var SlimApp<\DI\Container> */
    private SlimApp $app;
    private PDO $pdo;
    private SettingsService $settings;
    private string $adminCookie = '';
    private string $adminCsrf = '';
    private string $guestCookie = '';
    private string $guestCsrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/teralab-demo-guard-api-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/labs', 0770, true);
        mkdir($this->root . '/data', 0770, true);
        $this->installStaticAssets($this->root);
        $config = Config::forTests($this->root, $this->root . '/data');
        $this->pdo = (new Database($config))->pdo();
        $this->app = (new App($config))->create();
        $this->settings = new SettingsService($this->pdo);
        $this->writeTemplate($config, 'vpcs');

        $setup = $this->request('POST', '/api/v2/setup', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $setup->getStatusCode(), (string) $setup->getBody());
        $this->adminCookie = $this->cookie($setup);
        $this->adminCsrf = (string) $this->json($setup)['csrf_token'];

        $this->insertGuest('guest-api', self::GUEST_PASSWORD);
        [$this->guestCookie, $this->guestCsrf] = $this->login('guest-api', self::GUEST_PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testGuestCreateIsNodeCappedAndAdminsAreExempt(): void
    {
        $rejected = $this->request(
            'POST',
            '/api/v2/labs',
            ['name' => 'Guest Six', 'nodes' => $this->nodes(6)],
            [],
            $this->guestCookie,
            null,
            $this->guestCsrf,
        );
        self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(
            'The demo allows at most 5 nodes per lab; this lab has 6.',
            (string) $this->json($rejected)['message'],
        );
        self::assertSame([], $this->json($this->request('GET', '/api/v2/labs', [], [], $this->guestCookie))['data']);

        $accepted = $this->request(
            'POST',
            '/api/v2/labs',
            ['name' => 'Admin Six', 'nodes' => $this->nodes(6)],
        );
        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame(6, $this->json($accepted)['data']['nodes']);
    }

    public function testGuestImportRejectsANonAllowlistedTemplateAndAdminsAreExempt(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><lab name="Guest IOL" id="guest-iol" version="1">'
            . '<topology><nodes><node id="1" name="R1" type="iol" template="iol" ethernet="0"/>'
            . '</nodes><networks/></topology></lab>';

        $rejected = $this->request(
            'POST',
            '/api/v2/labs/import',
            [],
            [],
            $this->guestCookie,
            $this->upload($xml, 'guest-iol.unl'),
            $this->guestCsrf,
        );
        self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(
            'Template "iol" is not available on the demo.',
            (string) $this->json($rejected)['message'],
        );

        $accepted = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($xml, 'admin-iol.unl'));
        self::assertSame(201, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('iol', $this->json($accepted)['data']['node_list'][0]['template']);
    }

    public function testGuestCannotOverrideDockerTemplateImageAndAdminsCan(): void
    {
        $config = Config::forTests($this->root, $this->root . '/data');
        $templateImage = 'quay.io/example/frr:1';
        $this->writeDockerTemplate($config, 'frr-bgp', $templateImage);
        $this->settings->update(['demo_allowed_templates' => 'frr-bgp']);

        $node = [
            'id' => '1',
            'name' => 'R1',
            'type' => 'docker',
            'template' => 'frr-bgp',
            'image' => 'alpine',
            'ram' => 256,
            'interfaces' => [],
        ];

        $rejected = $this->request(
            'POST',
            '/api/v2/labs',
            ['name' => 'Guest Docker', 'nodes' => [$node]],
            [],
            $this->guestCookie,
            null,
            $this->guestCsrf,
        );
        self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame(
            'The demo does not allow custom container images; use template "frr-bgp".',
            (string) $this->json($rejected)['message'],
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?><lab name="Guest Docker Import" id="guest-docker" version="1">'
            . '<topology><nodes><node id="1" name="R1" type="docker" template="frr-bgp"'
            . ' image="alpine" ram="256" ethernet="0"/></nodes><networks/></topology></lab>';
        $imported = $this->request(
            'POST',
            '/api/v2/labs/import',
            [],
            [],
            $this->guestCookie,
            $this->upload($xml, 'guest-docker.unl'),
            $this->guestCsrf,
        );
        self::assertSame(422, $imported->getStatusCode(), (string) $imported->getBody());
        self::assertSame(
            'The demo does not allow custom container images; use template "frr-bgp".',
            (string) $this->json($imported)['message'],
        );

        // Matching and empty node images are allowed for guests.
        $node['image'] = $templateImage;
        self::assertSame(
            201,
            $this->request(
                'POST',
                '/api/v2/labs',
                ['name' => 'Guest Docker Match', 'nodes' => [$node]],
                [],
                $this->guestCookie,
                null,
                $this->guestCsrf,
            )->getStatusCode(),
        );
        $node['image'] = '';
        self::assertSame(
            201,
            $this->request(
                'POST',
                '/api/v2/labs',
                ['name' => 'Guest Docker Empty', 'nodes' => [$node]],
                [],
                $this->guestCookie,
                null,
                $this->guestCsrf,
            )->getStatusCode(),
        );

        // Admins keep the per-node override.
        $node['image'] = 'alpine';
        $admin = $this->request('POST', '/api/v2/labs', ['name' => 'Admin Docker', 'nodes' => [$node]]);
        self::assertSame(201, $admin->getStatusCode(), (string) $admin->getBody());
        self::assertSame('alpine', $this->json($admin)['data']['node_list'][0]['image']);
    }

    public function testGuestImportClampsRamWithADemoLimitedWarningAndAdminsAreExempt(): void
    {
        $xml = $this->labXml(2048);

        $clamped = $this->request(
            'POST',
            '/api/v2/labs/import',
            [],
            [],
            $this->guestCookie,
            $this->upload($xml, 'guest-ram.unl'),
            $this->guestCsrf,
        );
        self::assertSame(201, $clamped->getStatusCode(), (string) $clamped->getBody());
        $body = $this->json($clamped);
        self::assertSame(1024, (int) $body['data']['node_list'][0]['ram']);
        $warnings = $body['meta']['warnings'];
        $limited = array_values(array_filter(
            $warnings,
            static fn (array $warning): bool => ($warning['type'] ?? '') === 'demo_limited',
        ));
        self::assertCount(1, $limited);
        self::assertSame('PC1', $limited[0]['node']);
        self::assertSame('RAM clamped from 2048 MB to 1024 MB for the demo.', $limited[0]['message']);

        $admin = $this->request('POST', '/api/v2/labs/import', [], [], null, $this->upload($xml, 'admin-ram.unl'));
        self::assertSame(201, $admin->getStatusCode(), (string) $admin->getBody());
        $adminBody = $this->json($admin);
        self::assertSame(2048, (int) $adminBody['data']['node_list'][0]['ram']);
        self::assertNotContains('demo_limited', array_column($adminBody['meta']['warnings'], 'type'));
    }

    public function testHealthPublishesTheTemplateAllowlistWithoutTheTurnstileSecret(): void
    {
        $this->settings->update(['demo_allowed_templates' => ' vpcs , iol , vpcs ']);

        $response = $this->request('GET', '/api/v2/health', [], [], '');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = $this->json($response);
        self::assertSame(['vpcs', 'iol'], $payload['demo']['templates']);
        self::assertStringNotContainsString(
            (string) SettingsService::DEFAULTS['turnstile_secret_key'],
            (string) $response->getBody(),
        );
        self::assertArrayNotHasKey('turnstile_secret_key', $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodes(int $count): array
    {
        $nodes = [];
        for ($index = 1; $index <= $count; $index++) {
            $nodes[] = [
                'id' => (string) $index,
                'name' => 'PC' . $index,
                'type' => 'vpcs',
                'template' => 'vpcs',
                'ram' => 256,
                'interfaces' => [],
            ];
        }

        return $nodes;
    }

    private function labXml(int $ram): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><lab name="Guest RAM" id="guest-ram" version="1">'
            . '<topology><nodes><node id="1" name="PC1" type="vpcs" template="vpcs" ram="' . $ram . '"'
            . ' ethernet="0"/></nodes><networks/></topology></lab>';
    }

    private function insertGuest(string $id, string $password): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, role, expiration, guest, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :role, :expiration, :guest, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':username' => $id,
            ':password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            ':role' => 'editor',
            ':expiration' => time() + 3600,
            ':guest' => 1,
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function login(string $username, string $password): array
    {
        $response = $this->request('POST', '/api/v2/auth/login', [
            'username' => $username,
            'password' => $password,
        ], [], '');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return [$this->cookie($response), (string) $this->json($response)['csrf_token']];
    }

    private function writeTemplate(Config $config, string $id): void
    {
        $directory = $config->templatesDir;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $path = dirname(__DIR__) . '/templates/builtin/' . $id . '.yaml';
        self::assertFileExists($path);
        file_put_contents($directory . '/' . $id . '.yaml', (string) file_get_contents($path));
    }

    private function writeDockerTemplate(Config $config, string $id, string $image): void
    {
        $directory = $config->templatesDir;
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        file_put_contents(
            $directory . '/' . $id . '.yaml',
            "schema: 1\nid: " . $id . "\nname: " . $id . "\nkind: docker\noptions:\n"
                . "  docker:\n    image: " . $image . "\n",
        );
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
        ?UploadedFile $file = null,
        ?string $csrf = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        $effectiveCookie = $cookie ?? $this->adminCookie;
        $effectiveCsrf = $csrf ?? $this->adminCsrf;
        if ($effectiveCookie !== '') {
            $request = $request->withCookieParams([Config::COOKIE_NAME => $effectiveCookie]);
        }
        if ($effectiveCsrf !== '') {
            $request = $request->withHeader('X-CSRF-Token', $effectiveCsrf);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        if ($file !== null) {
            $request = $request
                ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
                ->withUploadedFiles(['file' => $file]);
        }

        return $this->app->handle($request);
    }

    private function upload(string $content, string $name): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            'application/xml',
            strlen($content),
            UPLOAD_ERR_OK,
        );
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
