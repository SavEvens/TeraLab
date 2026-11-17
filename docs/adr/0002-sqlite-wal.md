<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# ADR-0002: SQLite WAL with a repository abstraction

- Status: Accepted
- Date: 2026-11-17
- Deciders: TeraLab owner, engineering

## Context

TeraLab v1.1 uses SQLite at `/opt/unetlab/data/database.sdb` with poor
concurrency settings and ad-hoc migrations. The product is single-host for
v1.2–v1.4; clusters are deferred. MariaDB is installed on the host but is not
required by the application.

## Decision

Keep SQLite for the single-host milestones and enable WAL mode:

```sql
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
PRAGMA busy_timeout = 5000;
PRAGMA synchronous = NORMAL;
```

Access the database through repository interfaces (users, labs, templates,
images, jobs, audit). Migrations are numbered SQL files applied
transactionally and recorded in `schema_migrations`.

## Consequences

Positive:
- No extra service, credentials, or backup tooling for the default install.
- WAL gives readers concurrent with a writer, adequate for tens of users and
  a single web/agent writer.
- Easy to back up with `sqlite3 .backup` or the online backup API.
- Repository boundaries make a later MariaDB/PostgreSQL adapter feasible.

Negative:
- Not suitable for multi-host clusters without a central database.
- Requires care with long-running write transactions and file permissions.
- Some queries may need optimisation at higher concurrency.

## Alternatives considered

- **MariaDB from day one:** operationally heavier for a single host and not
  needed for the current scale.
- **PostgreSQL:** strong choice for clusters, but premature for v1.2–v1.4.
- **Files only:** loses permissions, jobs, audit, and concurrency control.
