<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TeraLab contributors

declare(strict_types=1);

namespace TeraLab\Api\Labs;

/**
 * Compile-time caps for authored lab topology and metadata.
 *
 * These are deliberately generous for real topologies (64 nodes, 64 networks,
 * 16 ports per node) but small enough that a malformed request is rejected
 * before it can be written, compiled into XML, or echoed back. The agent
 * enforces the same 16-port and 64 KiB config limits again.
 */
final class LabLimits
{
    public const MAX_NODES = 64;

    public const MAX_NETWORKS = 64;

    public const MAX_INTERFACES = 16;

    /** Lab, node and network labels. */
    public const MAX_NAME_BYTES = 128;

    public const MAX_DESCRIPTION_BYTES = 65536;

    public const MAX_BODY_BYTES = 65536;

    /** The agent refuses a startup config above this size. */
    public const MAX_CONFIG_BYTES = 65536;

    public const MAX_TAG_COUNT = 20;

    public const MAX_TAG_BYTES = 32;

    /** Node ids, network ids, template ids and lab ids share one grammar. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/';

    public const MAX_NODE_REFERENCE_BYTES = 128;

    public const MAX_TYPE_BYTES = 32;

    /** Image references, console modes, UUIDs and similar short metadata. */
    public const MAX_FIELD_BYTES = 255;

    /** Kernel command lines are longer but still bounded. */
    public const MAX_APPEND_BYTES = 4096;

    /**
     * C0 controls except tab, newline and carriage return, plus DEL.
     *
     * Descriptions, bodies and configs may contain normal line structure; the
     * remaining control bytes cannot be represented in the lab XML anyway.
     */
    public const FORBIDDEN_TEXT_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /** Names and ids allow no control byte at all. */
    public const FORBIDDEN_NAME_PATTERN = '/[\x00-\x1F\x7F]/';
}
