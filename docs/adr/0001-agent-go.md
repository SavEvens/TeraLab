<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# ADR-0001: Go for the privileged agent

- Status: Accepted
- Date: 2026-11-17
- Deciders: TeraLab owner, engineering

## Context

UNetLab invokes a root PHP CLI wrapper through `sudo`. The wrapper builds
shell command strings from lab and node parameters, which is both a command
injection risk and hard to test. The replacement must own QEMU/Dynamips/IOL/
VPCS/Docker lifecycle, bridges/taps, cgroups, cleanup, and console plumbing.

The agent is the most security-sensitive component in the system. It needs a
small, auditable surface, a static binary for packaging, safe JSON handling,
and straightforward concurrency for jobs and console sessions.

## Decision

Implement `teralab-agent` in Go (1.24+), built as a static binary and run as a
systemd service with a narrowed capability set and sandboxing.

The agent exposes an HTTP/1.1 JSON API over a Unix socket with:
- a bearer secret stored in `/etc/teralab/agent.secret`;
- `SO_PEERCRED` checks against the allowed uid/gid;
- an allowlisted verb catalogue;
- `execve` with argv arrays, never a shell;
- canonicalized paths confined to the TeraLab roots.

## Consequences

Positive:
- One static artifact; straightforward Debian packaging.
- Standard library JSON/HTTP, goroutines for jobs and console streams.
- Easy to fuzz and to test with table-driven unit tests.
- Safer default than continuing to extend the C wrappers.

Negative:
- Adds the Go toolchain to the build environment.
- Requires disciplined capability/sandbox configuration.
- The legacy C wrappers remain temporarily during v1.2 migration.

## Alternatives considered

- **Rust:** stronger memory guarantees, but slower iteration and a smaller
  contributor pool for this project.
- **Python 3:** fastest to write, but heavier runtime and weaker isolation
  for a root daemon.
- **C wrappers:** reuses existing code but keeps the highest-risk code in the
  least-safe language and slows hardening.
