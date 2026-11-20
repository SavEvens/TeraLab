<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TeraLab\Api\Auth\AccessService;
use TeraLab\Api\Auth\AuditLogger;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Http\Json;
use TeraLab\Api\Jobs\JobService;
use TeraLab\Api\Labs\LabLocks;
use TeraLab\Api\Labs\LabRepository;
use TeraLab\Api\Labs\LabService;

final class LabController
{
    public function __construct(
        private readonly LabRepository $labs,
        private readonly LabService $service,
        private readonly AccessService $access,
        private readonly LabLocks $locks,
        private readonly AuditLogger $audit,
        private readonly JobService $jobs,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);

        return Json::response($response, ['data' => $this->access->filterLabs($user, $this->labs->all())]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $lab['lock'] = $this->locks->current((string) $lab['id']);

        return Json::response($response, ['data' => $lab]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->service->create(Json::body($request), $this->author($request), (string) $user['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.create', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $lab], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $labID = $args['id'] ?? '';
        $this->access->assertLabAccess($user, $labID);
        $body = Json::body($request);
        $revision = $body['revision'] ?? null;
        $lab = $this->service->save(
            $labID,
            $body,
            is_int($revision) ? $revision : null,
            (string) $user['id'],
            $this->access->isAdmin($user),
        );
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.save', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $lab]);
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            $length = (int) $request->getHeaderLine('Content-Length');
            if ($length > 0) {
                throw new HttpException(413, 'The upload was rejected before TeraLab received it. Raise PHP upload_max_filesize and post_max_size.');
            }
            throw new HttpException(422, 'A multipart "file" field is required.');
        }
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->service->import($file, $this->author($request), (string) $user['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.import', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $lab], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $export = $this->service->export((string) $lab['id']);
        $response->getBody()->write($export['xml']);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $this->service->delete((string) $lab['id'], (string) $user['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.delete', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success']);
    }

    public function trash(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $entries = $this->service->trash();
        if (!$this->access->isAdmin($user)) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => (string) ($entry['deleted_by'] ?? '') === (string) $user['id'],
            ));
        }

        return Json::response($response, ['data' => $entries]);
    }

    /**
     * @param array<string, string> $args
     */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $this->assertTrashEntry($user, $args['id'] ?? '');
        $lab = $this->service->restore($args['id'] ?? '');
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.restore', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $lab]);
    }

    /**
     * @param array<string, string> $args
     */
    public function purge(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $this->assertTrashEntry($user, $args['id'] ?? '');
        $this->service->purge($args['id'] ?? '');
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.purge', (string) ($args['id'] ?? ''), 'success');

        return Json::response($response, ['status' => 'success']);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertTrashEntry(array $user, string $id): void
    {
        foreach ($this->service->trash() as $entry) {
            if ((string) $entry['id'] !== $id && (string) $entry['name'] !== $id) {
                continue;
            }
            if (!$this->access->isAdmin($user) && (string) ($entry['deleted_by'] ?? '') !== (string) $user['id']) {
                throw new HttpException(403, 'You cannot restore a lab deleted by another user.');
            }

            return;
        }

        throw new HttpException(404, 'Lab not found in trash.');
    }

    /**
     * @param array<string, string> $args
     */
    public function start(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $jobID = $this->jobs->start('lab.start', (string) $user['id'], ['lab_id' => $lab['id']]);
        try {
            $data = $this->service->start((string) $lab['id'], (string) $user['id'], $this->access->isAdmin($user));
            $this->jobs->succeed($jobID, $data);
        } catch (\Throwable $exception) {
            $this->jobs->fail($jobID, $exception->getMessage());
            throw $exception;
        }
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.start', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'job_id' => $jobID, 'data' => $data], 202);
    }

    /**
     * @param array<string, string> $args
     */
    public function stop(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $jobID = $this->jobs->start('lab.stop', (string) $user['id'], ['lab_id' => $lab['id']]);
        try {
            $data = $this->service->stop((string) $lab['id'], (string) $user['id'], $this->access->isAdmin($user));
            $this->jobs->succeed($jobID, $data);
        } catch (\Throwable $exception) {
            $this->jobs->fail($jobID, $exception->getMessage());
            throw $exception;
        }
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.stop', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'job_id' => $jobID, 'data' => $data]);
    }

    /**
     * @param array<string, string> $args
     */
    public function wipe(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $jobID = $this->jobs->start('lab.wipe', (string) $user['id'], ['lab_id' => $lab['id']]);
        try {
            $data = $this->service->wipe((string) $lab['id'], (string) $user['id'], $this->access->isAdmin($user));
            $this->jobs->succeed($jobID, $data);
        } catch (\Throwable $exception) {
            $this->jobs->fail($jobID, $exception->getMessage());
            throw $exception;
        }
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.wipe', (string) $lab['id'], 'success');

        return Json::response($response, ['status' => 'success', 'job_id' => $jobID, 'data' => $data]);
    }

    /**
     * @param array<string, string> $args
     */
    public function nodes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');

        return Json::response($response, [
            'data' => $this->service->nodes((string) $lab['id']),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function duplicate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $copy = $this->service->duplicate((string) $lab['id'], (string) $user['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.duplicate', (string) $copy['id'], 'success');

        return Json::response($response, ['status' => 'success', 'data' => $copy], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function move(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $body = Json::body($request);
        $folder = $body['folder'] ?? '';
        if (!is_string($folder)) {
            throw new HttpException(422, 'folder must be a string.');
        }
        $moved = $this->service->move((string) $lab['id'], $folder, (string) $user['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.move', (string) $lab['id'], 'success', ['folder' => $folder]);

        return Json::response($response, ['status' => 'success', 'data' => $moved]);
    }

    /**
     * @param array<string, string> $args
     */
    public function snapshots(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');

        return Json::response($response, ['data' => $this->service->snapshots((string) $lab['id'])]);
    }

    /**
     * @param array<string, string> $args
     */
    public function createSnapshot(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $body = Json::body($request);
        $name = $body['name'] ?? ('Snapshot ' . gmdate('Y-m-d H:i'));
        if (!is_string($name)) {
            throw new HttpException(422, 'name must be a string.');
        }
        $snapshot = $this->service->createSnapshot((string) $lab['id'], $name, (string) $user['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.snapshot.create', (string) $lab['id'], 'success', ['snapshot' => $snapshot['id']]);

        return Json::response($response, ['status' => 'success', 'data' => $snapshot], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function restoreSnapshot(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $restored = $this->service->restoreSnapshot(
            (string) $lab['id'],
            $args['snapshot'] ?? '',
            (string) $user['id'],
            $this->access->isAdmin($user),
        );
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.snapshot.restore', (string) $lab['id'], 'success', ['snapshot' => $args['snapshot'] ?? '']);

        return Json::response($response, ['status' => 'success', 'data' => $restored]);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteSnapshot(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $this->service->deleteSnapshot((string) $lab['id'], $args['snapshot'] ?? '');
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.snapshot.delete', (string) $lab['id'], 'success', ['snapshot' => $args['snapshot'] ?? '']);

        return Json::response($response, ['status' => 'success']);
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
        $user = $this->access->user($request);
        $this->access->requireRole($user, 'editor');
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $nodeID = $args['node'] ?? '';
        $isAdmin = $this->access->isAdmin($user);
        $data = match ($action) {
            'start' => $this->service->startNode((string) $lab['id'], $nodeID, (string) $user['id'], $isAdmin),
            'stop' => $this->service->stopNode((string) $lab['id'], $nodeID, (string) $user['id'], $isAdmin),
            'wipe' => $this->service->wipeNode((string) $lab['id'], $nodeID, (string) $user['id'], $isAdmin),
            default => [],
        };
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.node.' . $action, (string) $lab['id'], 'success', ['node' => $nodeID]);

        return Json::response($response, ['status' => 'success', 'data' => $data]);
    }

    /**
     * @param array<string, string> $args
     */
    public function permissions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        if (!$this->access->isAdmin($user)) {
            throw new HttpException(403, 'Administrator role required.');
        }
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');

        return Json::response($response, ['data' => $this->access->permissions((string) $lab['id'])]);
    }

    /**
     * @param array<string, string> $args
     */
    public function grant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        if (!$this->access->isAdmin($user)) {
            throw new HttpException(403, 'Administrator role required.');
        }
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $body = Json::body($request);
        $username = $body['username'] ?? null;
        $role = $body['role'] ?? 'editor';
        if (!is_string($username) || !is_string($role)) {
            throw new HttpException(422, 'username and role are required.');
        }
        $target = $this->access->findUserByUsername($username);
        if ($target === null) {
            throw new HttpException(404, 'User not found.');
        }
        $this->access->grant((string) $lab['id'], (string) $target['id'], $role);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.permission.grant', (string) $lab['id'], 'success', ['username' => $username, 'role' => $role]);

        return Json::response($response, ['status' => 'success', 'data' => $this->access->permissions((string) $lab['id'])]);
    }

    /**
     * @param array<string, string> $args
     */
    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->access->user($request);
        if (!$this->access->isAdmin($user)) {
            throw new HttpException(403, 'Administrator role required.');
        }
        $lab = $this->access->assertLabAccess($user, $args['id'] ?? '');
        $target = $this->access->findUserByUsername((string) ($args['user'] ?? ''));
        if ($target === null) {
            throw new HttpException(404, 'User not found.');
        }
        $this->access->revoke((string) $lab['id'], (string) $target['id']);
        $this->audit->log((string) $user['id'], $this->ip($request), 'lab.permission.revoke', (string) $lab['id'], 'success', ['username' => $target['username']]);

        return Json::response($response, ['status' => 'success', 'data' => $this->access->permissions((string) $lab['id'])]);
    }

    private function author(ServerRequestInterface $request): ?string
    {
        $user = $request->getAttribute('user');
        if (is_array($user) && isset($user['username']) && is_string($user['username'])) {
            return $user['username'];
        }

        return null;
    }

    private function ip(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($address) ? $address : '0.0.0.0';
    }
}
