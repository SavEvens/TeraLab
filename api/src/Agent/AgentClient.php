<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Agent;

use Closure;
use JsonException;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;

/**
 * Client for the privileged TeraLab agent.
 *
 * The agent listens on a Unix socket and authenticates with a bearer secret.
 * This class is the only place in the web tier that talks to it.
 */
final class AgentClient
{
    private const TIMEOUT_SECONDS = 60;
    private const MAX_RESPONSE_BYTES = 1048576;

    /** @var Closure(string, string, array<string, mixed>): array{int, string}|null */
    private readonly ?Closure $transport;

    private ?string $secret = null;

    /**
     * @param (Closure(string, string, array<string, mixed>): array{int, string})|null $transport
     *        Test transport; receives method, path, and payload.
     */
    public function __construct(
        private readonly Config $config,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport;
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('GET', '/v1/health');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->request('GET', '/v1/system/status');
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public function start(array $spec): array
    {
        return $this->request('POST', '/v1/node.start', $spec);
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $id): array
    {
        return $this->request('POST', '/v1/node.stop', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function wipe(string $id): array
    {
        return $this->request('POST', '/v1/node.wipe', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function nodeStatus(string $id): array
    {
        return $this->request('GET', '/v1/node.status?id=' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function openConsole(string $id): array
    {
        return $this->request('POST', '/v1/console.open', ['node_id' => $id]);
    }

    /**
     * @param list<array{id: string, name?: string}> $networks
     * @return array<string, mixed>
     */
    public function ensureLab(string $labID, array $networks): array
    {
        return $this->request('POST', '/v1/lab.ensure', [
            'lab_id' => $labID,
            'networks' => $networks,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanupLab(string $labID): array
    {
        return $this->request('POST', '/v1/lab.cleanup', ['lab_id' => $labID]);
    }

    /**
     * Reclaim stale engine resources.
     *
     * Without an argument the agent runs node-level cleanup only and leaves
     * lab run directories alone. Pass the live lab ids to also remove the run
     * directories of labs that no longer exist: the list is the keep-set, so
     * an explicit empty list frees every lab run directory and must only be
     * used when the live listing really is empty.
     *
     * @param list<string>|null $labIDs null = node-level cleanup only
     * @return array<string, mixed>
     */
    public function reconcile(?array $labIDs = null): array
    {
        $payload = [];
        if ($labIDs !== null) {
            $payload['lab_ids'] = $labIDs;
        }

        return $this->request('POST', '/v1/cleanup.stale', $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function engines(): array
    {
        $result = $this->request('GET', '/v1/engines');

        /** @var list<array<string, mixed>> $result */
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectImage(string $path): array
    {
        return $this->request('POST', '/v1/image.inspect', ['path' => $path]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        [$status, $raw] = $this->transport !== null
            ? ($this->transport)($method, $path, $payload)
            : $this->send($method, $path, $payload);

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(502, 'The agent returned an invalid response.');
        }

        if (!is_array($decoded)) {
            throw new HttpException(502, 'The agent returned an invalid response.');
        }

        /** @var array<string, mixed> $decoded */
        if ($status >= 400) {
            $message = 'The agent rejected the request.';
            $error = $decoded['error'] ?? null;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                $message = $error['message'];
            }

            throw new HttpException($status === 401 ? 502 : $status, $message);
        }

        $result = $decoded['result'] ?? null;
        if (($decoded['ok'] ?? false) !== true || !is_array($result)) {
            throw new HttpException(502, 'The agent returned an unexpected response.');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{int, string}
     */
    private function send(string $method, string $path, array $payload): array
    {
        if (!function_exists('stream_socket_client')) {
            throw new HttpException(503, 'The agent transport is unavailable.');
        }
        if (!file_exists($this->config->agentSocket)) {
            throw new HttpException(503, 'The TeraLab agent is not running.');
        }

        $body = $payload === []
            ? ''
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            $method . ' ' . $path . ' HTTP/1.1',
            'Host: localhost',
            'Authorization: Bearer ' . $this->secret(),
            'Accept: application/json',
            'Connection: close',
        ];
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $errorCode = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            'unix://' . $this->config->agentSocket,
            $errorCode,
            $errorMessage,
            self::TIMEOUT_SECONDS,
        );
        if ($stream === false) {
            throw new HttpException(503, 'The TeraLab agent is unavailable.');
        }

        stream_set_timeout($stream, self::TIMEOUT_SECONDS);
        $request = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        if (@fwrite($stream, $request) === false) {
            fclose($stream);
            throw new HttpException(503, 'Cannot write to the TeraLab agent.');
        }

        $response = stream_get_contents($stream, self::MAX_RESPONSE_BYTES);
        fclose($stream);
        if (!is_string($response) || $response === '') {
            throw new HttpException(503, 'The TeraLab agent did not respond.');
        }

        $separator = strpos($response, "\r\n\r\n");
        if ($separator === false) {
            throw new HttpException(502, 'The agent returned a malformed response.');
        }
        $head = substr($response, 0, $separator);
        $rawBody = substr($response, $separator + 4);
        if (preg_match('#^HTTP/1\.[01] ([0-9]{3})#', $head, $matches) !== 1) {
            throw new HttpException(502, 'The agent returned a malformed status line.');
        }

        return [(int) $matches[1], $rawBody];
    }

    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        $file = $this->config->agentSecretFile;
        if (!is_file($file) || !is_readable($file)) {
            throw new HttpException(503, 'The TeraLab agent secret is not readable.');
        }

        $secret = trim((string) file_get_contents($file));
        if (strlen($secret) < 32) {
            throw new HttpException(503, 'The TeraLab agent secret is invalid.');
        }
        $this->secret = $secret;

        return $secret;
    }
}
