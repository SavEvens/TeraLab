-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- Per-lab permissions, running-lab locks, and image/template mapping.

CREATE TABLE permissions (
    lab_id TEXT NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('admin', 'editor', 'user')),
    created_at INTEGER NOT NULL,
    PRIMARY KEY (lab_id, user_id)
);

CREATE TABLE lab_locks (
    lab_id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

ALTER TABLE images ADD COLUMN template TEXT;
