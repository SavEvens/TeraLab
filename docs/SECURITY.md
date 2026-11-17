<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Security policy and design notes

## Reporting

Do not open a public issue for a suspected vulnerability. Contact the TeraLab
project owner privately and include the affected version, a reproduction, and
the impact. Do not access data, systems, or labs you do not own.

## Supported versions

| Version | Status |
|---|---|
| v1.1.0 | Baseline only; not hardened for untrusted networks |
| v1.2.0+ | Supported after release; see release notes |

## Known legacy debt (v1.1.0)

The baseline must not be exposed to untrusted users or networks:

- Default administrator password `unl`, stored as an unsalted SHA-256 hash.
- Google Analytics phone-home when a `privacy` cookie is present.
- `www-data` can run `/opt/unetlab/wrappers/unl_wrapper` as root through
  `sudo`; arguments are built into shell command strings.
- The installer applies `setcap cap_net_admin+ep` to system binaries such as
  `/bin/ip`, which lets any local user reconfigure networking.
- Docker is contacted over unauthenticated TCP `127.0.0.1:4243`.
- `/opt/unetlab/data/Logs` and `Exports` are served with directory indexes.
- No CSRF protection, login rate limiting, or audit log.
- Five node templates are syntactically broken.

These are removed or replaced in v1.2.0; do not deploy v1.1.0 as a service.

## Threat model

| Threat | Vector | Control |
|---|---|---|
| Credential theft | Weak/default password, XSS | Argon2id, no defaults, CSP, HttpOnly cookies |
| CSRF | Cross-site state changes | CSRF token, SameSite, origin checks |
| Command injection | Lab/image/template fields | Agent allowlist, argv arrays, no shell |
| Path traversal | Lab/image/template paths | Canonicalization, root confinement, symlink rejection |
| Privilege escalation | Agent socket, sudo, setcap | Peer creds, secret, capabilities, systemd sandbox |
| Malicious upload | Crafted qcow2/ISO | Magic/size checks, `qemu-img` isolation, no execution |
| Console hijack | Guessable tokens/ports | One-time tokens, session binding, no public ports |
| Tenant crossover | Shared bridges/ports | Unique names, per-lab resources, RBAC |
| Supply chain | Malicious dependency | Lockfiles, audits, SBOM, allowlist |
| Data exfiltration | Telemetry/CDN | No external calls; self-hosted assets |

## Required controls for v1.2+

- First-run administrator creation; no default credentials.
- Argon2id password hashing; password policy; rehash on login.
- Server-side sessions with rotation, idle/absolute timeouts, revoke on
  password change.
- CSRF protection and same-origin policy; strict security headers and CSP.
- Login and API rate limiting; audit log for auth and admin actions.
- RBAC (`admin`, `editor`, `user`) enforced server-side; per-lab permissions.
- Agent over Unix socket with peer credentials and a bearer secret; only
  allowlisted verbs; no shell; canonical paths; timeouts.
- Engines run as `teralab-vm` with cgroup limits and minimal devices.
- Upload validation, quotas, content-addressed storage, no execution.
- No telemetry, no CDNs, no third-party fonts at runtime.
- Dependency lockfiles, `composer audit`, `npm audit`, `govulncheck`, SBOM,
  secret scanning, and licence header checks in CI.
- Release checklist includes `scripts/teralab-project-init.sh --check` and
  `scripts/check-licence-headers.sh`.

## Docker group warning

The `docker` group is root-equivalent. During development it may be used for
isolated builds and tests, but:
- the web tier must never be in the group;
- no `--privileged` containers without explicit approval;
- no read-write mount of the host root;
- no host network/firewall changes from a container;
- remove the developer from the group when it is no longer needed.

## Clean-room and reference access

The external reference deployment is observed through its frontend only. Do
not use SSH, shell, database, filesystem, API tokens, or source access. Do not
copy code, CSS, images, templates, texts, assets, lab files, or metadata.
Screenshots and notes stay outside this repository.

## Dependency policy

- Pin exact versions and commit lockfiles.
- Allowed: MIT, BSD-2/3, Apache-2.0, ISC, MPL-2.0.
- GPLv2 engines remain separate executables/packages; do not link them into
  the AGPLv3 combined work.
- Every new dependency is recorded in `NOTICE`/SBOM and audited for licence
  compatibility.
