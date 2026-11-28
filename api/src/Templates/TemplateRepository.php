<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Templates;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TeraLab\Api\Config;
use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Policy\IconPolicy;

final class TemplateRepository
{
    private const KINDS = ['qemu', 'vpcs', 'iol', 'dynamips', 'docker'];

    /** Templates are small YAML documents; anything larger is not a template. */
    public const MAX_YAML_BYTES = 262144;

    public const MAX_YAML_DEPTH = 16;

    public const MAX_YAML_ALIASES = 8;

    public const MAX_NAME_BYTES = 128;

    public const MAX_OPTION_KEYS = 64;

    public const MAX_DEFAULT_KEYS = 16;

    private readonly IconPolicy $icons;

    public function __construct(private readonly Config $config, ?IconPolicy $icons = null)
    {
        $this->icons = $icons ?? new IconPolicy($config->root);
    }

    public function root(): string
    {
        return $this->config->root;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $templates = [];
        foreach ($this->files($this->builtinDir(), 'builtin') as $entry) {
            $templates[$entry['id']] = $entry;
        }
        foreach ($this->files($this->config->templatesDir, 'custom') as $entry) {
            // A custom file that predates the reserved-id rule must not shadow
            // a built-in template. It stays on disk for manual cleanup but is
            // never resolved or listed.
            if (is_file($this->builtinDir() . '/' . $entry['id'] . '.yaml')) {
                continue;
            }
            $templates[$entry['id']] = $entry;
        }
        ksort($templates);

        return array_values($templates);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $id) !== 1) {
            return null;
        }
        $builtin = $this->builtinDir() . '/' . $id . '.yaml';
        if (is_file($builtin)) {
            return $this->load($builtin, 'builtin');
        }
        $custom = $this->config->templatesDir . '/' . $id . '.yaml';
        if (is_file($custom)) {
            try {
                return $this->load($custom, 'custom');
            } catch (HttpException) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function saveCustom(string $id, string $yaml): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $id) !== 1) {
            throw new HttpException(422, 'Invalid template id.');
        }
        if (is_file($this->builtinDir() . '/' . $id . '.yaml')) {
            throw new HttpException(409, 'Built-in template IDs are reserved.');
        }
        $parsed = $this->parse($yaml);
        if (($parsed['id'] ?? null) !== $id) {
            throw new HttpException(422, 'Template id must match the route id.');
        }
        $this->validate($parsed);

        $directory = $this->config->templatesDir;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the template directory.');
        }
        $path = $directory . '/' . $id . '.yaml';
        $temporary = tempnam($directory, '.tpl-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary template.');
        }
        if (file_put_contents($temporary, $yaml) === false) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write the template.');
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot install the template.');
        }

        return $this->load($path, 'custom');
    }

    /**
     * @return array{filename: string, yaml: string}
     */
    public function export(string $id): array
    {
        $template = $this->find($id);
        if ($template === null) {
            throw new HttpException(404, 'Template not found.');
        }

        return ['filename' => $id . '.yaml', 'yaml' => (string) $template['yaml']];
    }

    /**
     * @return array<string, mixed>
     */
    public function import(string $yaml): array
    {
        $parsed = $this->parse($yaml);
        $id = $parsed['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new HttpException(422, 'Template id is required.');
        }
        if (is_file($this->builtinDir() . '/' . $id . '.yaml')) {
            throw new HttpException(409, 'A built-in template already uses this id.');
        }
        $this->validate($parsed);
        if (is_file($this->config->templatesDir . '/' . $id . '.yaml')) {
            throw new HttpException(409, 'A custom template already uses this id.');
        }

        return $this->saveCustom($id, $yaml);
    }

    /**
     * @return array<string, mixed>
     */
    public function duplicate(string $id): array
    {
        $template = $this->find($id);
        if ($template === null) {
            throw new HttpException(404, 'Template not found.');
        }
        $base = $id . '-copy';
        $newId = $base;
        $suffix = 1;
        while ($this->find($newId) !== null) {
            $suffix++;
            $newId = $base . '-' . $suffix;
        }
        $template['id'] = $newId;
        $template['name'] = (string) $template['name'] . ' copy';
        unset($template['source'], $template['yaml']);
        $yaml = Yaml::dump($template, 8, 2);

        return $this->saveCustom($newId, $yaml);
    }

    public function deleteCustom(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $id) !== 1) {
            throw new HttpException(422, 'Invalid template id.');
        }
        if (is_file($this->builtinDir() . '/' . $id . '.yaml')) {
            throw new HttpException(409, 'Built-in template IDs are reserved.');
        }
        $path = $this->config->templatesDir . '/' . $id . '.yaml';
        if (!is_file($path)) {
            throw new HttpException(404, 'Custom template not found.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Cannot delete the template.');
        }
    }

    private function builtinDir(): string
    {
        return $this->config->root . '/api/templates/builtin';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function files(string $directory, string $source): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = [];
        foreach (glob($directory . '/*.yaml') ?: [] as $file) {
            try {
                $entries[] = $this->load($file, $source);
            } catch (HttpException) {
                continue;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $path, string $source): array
    {
        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw new HttpException(422, 'Cannot read the template.');
        }
        $template = $this->parse($yaml);
        $this->validate($template);
        $template['source'] = $source;
        $template['yaml'] = $yaml;

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $yaml): array
    {
        if (strlen($yaml) > self::MAX_YAML_BYTES) {
            throw new HttpException(
                422,
                'The template is larger than ' . intdiv(self::MAX_YAML_BYTES, 1024) . ' KiB.',
            );
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $yaml) === 1) {
            throw new HttpException(422, 'The template contains control characters.');
        }
        try {
            $parsed = Yaml::parse($yaml, 0, self::MAX_YAML_DEPTH, self::MAX_YAML_ALIASES);
        } catch (ParseException $exception) {
            throw new HttpException(422, 'Invalid YAML: ' . $exception->getMessage());
        }
        if (!is_array($parsed)) {
            throw new HttpException(422, 'Template must be a YAML mapping.');
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $template
     */
    private function validate(array $template): void
    {
        if (($template['schema'] ?? null) !== 1) {
            throw new HttpException(422, 'Template schema must be 1.');
        }
        $id = $template['id'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $id) !== 1) {
            throw new HttpException(422, 'Invalid template id.');
        }
        $name = $template['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new HttpException(422, 'Template name is required.');
        }
        if (strlen($name) > self::MAX_NAME_BYTES
            || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new HttpException(422, 'Invalid template name.');
        }
        if (!in_array($template['kind'] ?? null, self::KINDS, true)) {
            throw new HttpException(422, 'Invalid template kind.');
        }
        if (array_key_exists('icon', $template)) {
            $this->icons->validate($template['icon'], 'Template icon');
        }
        $defaults = $template['defaults'] ?? [];
        if (!is_array($defaults)) {
            throw new HttpException(422, 'Template defaults must be a mapping.');
        }
        if (count($defaults) > self::MAX_DEFAULT_KEYS) {
            throw new HttpException(
                422,
                'Template defaults allow at most ' . self::MAX_DEFAULT_KEYS . ' keys.',
            );
        }
        foreach (['cpu', 'ram', 'ethernet', 'serial'] as $key) {
            if (isset($defaults[$key]) && !is_int($defaults[$key])) {
                throw new HttpException(422, 'Template default ' . $key . ' must be an integer.');
            }
        }
        $options = $template['options'] ?? [];
        if (!is_array($options)) {
            throw new HttpException(422, 'Template options must be a mapping.');
        }
        if (count($options) > self::MAX_OPTION_KEYS) {
            throw new HttpException(
                422,
                'Template options allow at most ' . self::MAX_OPTION_KEYS . ' keys.',
            );
        }
        QemuOptions::validate($options);
        QemuDisks::validate($options);
        DockerOptions::validate($options, $template['kind'] === 'docker');
    }
}
