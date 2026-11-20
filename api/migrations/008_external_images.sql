-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Copyright (C) 2026 TeraLab contributors
--
-- Linked ("adopted") images point at files that already exist on the host,
-- such as a legacy /opt/unetlab/addons tree. Their store entry is a symlink
-- and they consume no store quota.

ALTER TABLE images ADD COLUMN external INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS images_external ON images (external);
