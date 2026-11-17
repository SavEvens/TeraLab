-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- Content-addressed image store metadata.

CREATE TABLE images (
    id TEXT PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('qemu', 'iso', 'iol', 'dynamips')),
    name TEXT NOT NULL,
    path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    size INTEGER NOT NULL,
    virtual_size INTEGER NOT NULL DEFAULT 0,
    format TEXT NOT NULL,
    backing_file TEXT,
    notes TEXT,
    created_by TEXT REFERENCES users(id) ON DELETE SET NULL,
    created_at INTEGER NOT NULL
);

CREATE UNIQUE INDEX images_sha256 ON images (sha256);
CREATE INDEX images_kind ON images (kind);
