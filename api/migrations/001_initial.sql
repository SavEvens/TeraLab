-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- Initial API v2 schema.

CREATE TABLE users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    email TEXT,
    name TEXT,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'editor', 'user')),
    expiration INTEGER NOT NULL DEFAULT -1,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE sessions (
    id_hash TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    csrf_hash TEXT NOT NULL,
    ip TEXT,
    user_agent TEXT,
    created_at INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE INDEX sessions_user_id ON sessions (user_id);
CREATE INDEX sessions_expires_at ON sessions (expires_at);

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempted_at INTEGER NOT NULL,
    success INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX login_attempts_lookup ON login_attempts (username, ip, attempted_at);

CREATE TABLE audit_log (
    id TEXT PRIMARY KEY,
    ts INTEGER NOT NULL,
    user_id TEXT,
    ip TEXT,
    action TEXT NOT NULL,
    target TEXT,
    result TEXT NOT NULL,
    details_json TEXT
);

CREATE INDEX audit_log_ts ON audit_log (ts);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
