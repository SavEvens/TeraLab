<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Target architecture

## Summary

TeraLab is a single-host network lab platform with a clear split between an
unprivileged web control plane and a privileged engine agent.

```
Browser (Vue 3 + TypeScript SPA)
   │ HTTPS / WSS
Apache 2.4 ── PHP-FPM 8.4 (API v2, Slim 4)
   │                 ├── SQLite WAL (repository abstraction)
   │                 ├── labs, templates, image metadata
   │                 └── Unix socket ──► teralab-agent (Go, root)
   │                                        ├── QEMU / Dynamips / IOL / VPCS / Docker
   │                                        ├── bridges, taps, veth, nftables
   │                                        └── console broker (pty / VNC proxy)
   └── Console gateway (WSS) ── xterm.js / noVNC
```

## Components

| Component | Responsibility |
|---|---|
| SPA (`ui/`) | Labs, topology editor, node/template/image forms, consoles, system views |
| API (`api/`) | Auth/RBAC, validation, lab and image metadata, job orchestration, v1 adapter |
| Agent (`agent/`) | All privileged operations: engines, networking, consoles, cleanup, resource limits |
| Console gateway | Authenticated WebSocket-to-pty/TCP bridge |
| Engines | QEMU, Dynamips, IOL, VPCS, Docker as separate processes/packages |
| Database | SQLite WAL for single-host; repository interface for later MariaDB/PostgreSQL |
| Image store | Immutable base images plus per-run qcow2 overlays |

## Privilege model

- PHP-FPM runs as `www-data`; it never runs shell commands and never has
  `sudo` or `docker` group access.
- `teralab-agent` runs as root under systemd with a narrowed capability set
  and sandboxing. It accepts only authenticated, allowlisted verbs over
  `/run/teralab/agent.sock`.
- Engines run as `teralab-vm` (no login shell) with cgroup limits and only
  the devices they need (`/dev/kvm`, `/dev/net/tun`).
- Host network changes are limited to TeraLab-owned bridges, taps, veths,
  and nftables tables. No global firewall flush, no `setcap` on system
  binaries, no default `sudo` rule for the web tier.

## Runtime layout

```
/opt/teralab/              read-only code (web/, api/, bin/, share/)
/etc/teralab/              config.php, secrets, custom templates
/var/lib/teralab/          db/, labs/, images/, run/, exports/, trash/
/var/log/teralab/          structured logs
/run/teralab/              sockets and pid files
```

`/opt/unetlab` is a compatibility symlink during v1.2 and is removed once
migration is complete.

## API and agent boundaries

- API v2 is versioned in the path (`/api/v2`); the v1 adapter maps legacy
  routes without bypassing v2 authorization.
- Long operations return `202 Accepted` with a job ID.
- The agent protocol is HTTP/1.1 over a Unix socket, JSON only, with a
  bearer secret and peer-credential checks.
- Console access uses one-time tokens bound to user, session, and node.

## Data formats

- Canonical lab format: `lab.json` schema v2 (see ADR-0005).
- Interchange format: `.unl` XML, imported/exported losslessly for known
  fields; unknown XML is preserved in `extra`.
- Templates: YAML (see ADR-0004).
- Images: content-addressed store with `meta.json` and SHA-256.

## Non-goals for v1.2–v1.4

Clusters/HA, AI/MCP, LDAP/AD/OIDC/MFA, marketplace, native desktop/mobile
clients, and live migration.

## Decision records

- [ADR-0001](adr/0001-agent-go.md) — Go for the privileged agent
- [ADR-0002](adr/0002-sqlite-wal.md) — SQLite WAL with repository abstraction
- [ADR-0003](adr/0003-console-architecture.md) — WebSocket console gateway
- [ADR-0004](adr/0004-template-yaml.md) — YAML node templates
- [ADR-0005](adr/0005-lab-json-v2.md) — Lab JSON v2 with `.unl` compatibility
