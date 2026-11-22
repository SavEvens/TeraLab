-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- v1.6: ephemeral demo guests. A guest is an ordinary `users` row with
-- `guest = 1` and a short non-negative `expiration`; the index supports
-- capacity and sweep queries over guest rows.

ALTER TABLE users ADD COLUMN guest INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS users_guest ON users (guest, expiration);
