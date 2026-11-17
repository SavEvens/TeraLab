-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- Allow a one-time upgrade from the unsalted v1.1 SHA-256 password hash.
-- The value is cleared and replaced with Argon2id after the first login.

ALTER TABLE users ADD COLUMN legacy_password_hash TEXT;
