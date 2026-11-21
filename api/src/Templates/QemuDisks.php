<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Templates;

use TeraLab\Api\Http\HttpException;
use TeraLab\Api\Image\ImageReference;

/**
 * Extra QEMU disks declared by a template's `options.qemu_disks` list.
 *
 * The primary disk stays the node's `image` (slot virtioa); this block only
 * describes the additional virtio-blk slots. Each entry attaches either an
 * image-store reference or a blank disk generated per node:
 *
 *     options:
 *       qemu_disks:
 *         - slot: virtiob
 *           image: catmanager-20.16.1-virtiob.qcow2
 *         - slot: virtioc
 *           size_gb: 40
 *
 * Templates are editable by the `editor` role, so the shape is validated when
 * a template is loaded, saved or imported (see TemplateRepository). Image
 * references are only resolved against the image store at node start, because
 * a template may be imported before its images are.
 */
final class QemuDisks
{
    /** Template option name. */
    public const OPTION = 'qemu_disks';

    /** Extra slots, in attach order; virtioa is always the node's image. */
    public const SLOTS = ['virtiob', 'virtioc', 'virtiod'];

    /** One entry per extra slot. */
    public const MAX_ENTRIES = 3;

    public const MIN_SIZE_GB = 1;

    public const MAX_SIZE_GB = 100;

    /** Entry keys the API understands. */
    private const KEYS = ['slot', 'image', 'size_gb'];

    /**
     * Validate the `qemu_disks` block of a template `options` mapping.
     *
     * @param array<string, mixed> $options
     * @throws HttpException 422 when the block is malformed
     */
    public static function validate(array $options): void
    {
        self::entries($options);
    }

    /**
     * Validated disk entries in template order, without resolving image
     * references. An absent block yields an empty list.
     *
     * @param array<string, mixed> $options
     * @return list<array{slot: string, image: string}|array{slot: string, size_gb: int}>
     * @throws HttpException 422 when the block is malformed
     */
    public static function disks(array $options): array
    {
        return self::entries($options);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{slot: string, image: string}|array{slot: string, size_gb: int}>
     * @throws HttpException 422
     */
    private static function entries(array $options): array
    {
        if (!array_key_exists(self::OPTION, $options)) {
            return [];
        }
        $value = $options[self::OPTION];
        if (!is_array($value) || !array_is_list($value)) {
            throw new HttpException(422, 'Template option ' . self::OPTION . ' must be a list of disk entries.');
        }
        if (count($value) > self::MAX_ENTRIES) {
            throw new HttpException(
                422,
                'Template option ' . self::OPTION . ' allows at most ' . self::MAX_ENTRIES . ' entries.',
            );
        }

        $disks = [];
        $slots = [];
        foreach ($value as $index => $entry) {
            $disk = self::entry($entry, $index);
            $slot = $disk['slot'];
            if (isset($slots[$slot])) {
                throw new HttpException(
                    422,
                    'Template option ' . self::OPTION . ' entry ' . ($index + 1) . ' repeats the slot: ' . $slot . '.',
                );
            }
            $slots[$slot] = true;
            $disks[] = $disk;
        }

        return $disks;
    }

    /**
     * @return array{slot: string, image: string}|array{slot: string, size_gb: int}
     * @throws HttpException 422
     */
    private static function entry(mixed $entry, int $index): array
    {
        $label = 'Template option ' . self::OPTION . ' entry ' . ($index + 1);
        // An entry is a YAML mapping; a list (or scalar) is never valid, even
        // though a two-element list is technically an array.
        if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
            throw new HttpException(422, $label . ' must be a mapping.');
        }
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new HttpException(422, $label . ' has an unsupported key: ' . (string) $key . '.');
            }
        }

        $slot = $entry['slot'] ?? null;
        if (!is_string($slot) || !in_array($slot, self::SLOTS, true)) {
            throw new HttpException(
                422,
                $label . ' must have a slot of: ' . implode(', ', self::SLOTS) . ' (virtioa is the node image).',
            );
        }

        $hasImage = array_key_exists('image', $entry);
        $hasSize = array_key_exists('size_gb', $entry);
        if ($hasImage === $hasSize) {
            throw new HttpException(
                422,
                $label . ' (' . $slot . ') must set exactly one of image or size_gb.',
            );
        }

        if ($hasImage) {
            $image = $entry['image'];
            if (!is_string($image) || !self::isPortableImage($image)) {
                throw new HttpException(
                    422,
                    $label . ' (' . $slot . ') has an invalid image reference: ' . self::describe($image) . '.',
                );
            }

            return ['slot' => $slot, 'image' => $image];
        }

        $size = $entry['size_gb'];
        if (!is_int($size) || $size < self::MIN_SIZE_GB || $size > self::MAX_SIZE_GB) {
            throw new HttpException(
                422,
                $label . ' (' . $slot . ') size_gb must be an integer between '
                    . self::MIN_SIZE_GB . ' and ' . self::MAX_SIZE_GB . '.',
            );
        }

        return ['slot' => $slot, 'size_gb' => $size];
    }

    /**
     * A template reference must be a single portable path segment: no path
     * separators, no traversal and no control or non-UTF-8 bytes.
     */
    private static function isPortableImage(string $image): bool
    {
        if (!ImageReference::isPortable($image)) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $image) === 1) {
            return false;
        }

        return preg_match('//u', $image) === 1;
    }

    /**
     * Render a rejected value for the error message without echoing invalid
     * UTF-8 or control characters back.
     */
    private static function describe(mixed $value): string
    {
        if (is_string($value) && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
            return '"' . substr($value, 0, 80) . '"';
        }

        return get_debug_type($value);
    }
}
