<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class UnlParser
{
    private const MAX_BYTES = 10485760;

    /**
     * @return array{id: string, name: string, author: string, description: string, body: string, version: string, filename: string, nodes: int, networks: int}
     */
    public function parse(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('Lab file is too large.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            throw new RuntimeException('Invalid lab XML.');
        }

        $root = $document->documentElement;
        if ($root->tagName !== 'lab') {
            throw new RuntimeException('Unexpected lab root element.');
        }

        $name = trim($root->getAttribute('name'));
        if ($name === '') {
            $name = basename($path, '.unl');
        }

        $description = '';
        $body = '';
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'description') {
                $description = trim($child->textContent);
            }
            if ($child instanceof DOMElement && $child->tagName === 'body') {
                $body = trim($child->textContent);
            }
        }

        return [
            'id' => trim($root->getAttribute('id')) !== ''
                ? trim($root->getAttribute('id'))
                : basename($path, '.unl'),
            'name' => $name,
            'author' => trim($root->getAttribute('author')),
            'description' => $description,
            'body' => $body,
            'version' => trim($root->getAttribute('version')),
            'filename' => basename($path),
            'nodes' => $root->getElementsByTagName('node')->length,
            'networks' => $root->getElementsByTagName('network')->length,
        ];
    }
}
