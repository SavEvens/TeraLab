-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- v1.4: lab snapshots, runtime settings, and API tokens.

CREATE TABLE lab_snapshots (
    id TEXT PRIMARY KEY,
    lab_id TEXT NOT NULL,
    name TEXT NOT NULL,
    revision INTEGER NOT NULL,
    xml TEXT NOT NULL,
    created_by TEXT REFERENCES users(id) ON DELETE SET NULL,
    created_at INTEGER NOT NULL
);

CREATE INDEX lab_snapshots_lab ON lab_snapshots (lab_id, created_at);

CREATE TABLE api_tokens (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    scopes TEXT NOT NULL DEFAULT 'read,write',
    created_at INTEGER NOT NULL,
    last_used_at INTEGER,
    expires_at INTEGER
);

CREATE INDEX api_tokens_user ON api_tokens (user_id);
