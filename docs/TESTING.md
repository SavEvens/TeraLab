<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Testing and validation

## Principles

- Test the security boundary (agent, auth, uploads) hardest.
- Prefer real engines in the VM over mocks for integration; use mocks for CI
  where KVM is unavailable.
- Every milestone has explicit validation IDs and pass criteria.
- Test fixtures must be freely redistributable or local-only; no vendor images
  are committed.

## Levels

| Level | Scope | Tools |
|---|---|---|
| Unit | Validators, parsers, services | PHPUnit, Vitest, Go test |
| Integration | API + SQLite + agent stub; agent + QEMU/VPCS in VM | PHPUnit, Go test, bats |
| End-to-end | Browser through API/agent to real engines | Playwright |
| Security | Auth/CSRF/RBAC, agent fuzzing, uploads, headers | Semgrep, ZAP, Go fuzz, custom tests |
| Performance | Lab open/start, editor FPS, API latency | Playwright traces, k6/vegeta |
| Compatibility | `.unl` corpus, v1 API clients, browsers | Fixtures, Playwright |

## Environments

- **Host:** lint, unit tests, reference capture (Chromium).
- **Dev VM:** Debian 13, PHP-FPM, Apache, agent, QEMU, VPCS, IOL/Dynamips when
  images are present; nested KVM.
- **CI:** GitHub-hosted runners for lint/unit with a mocked agent; a
  self-hosted KVM runner (or TCG) for engine integration in Phase 1.

## Local fixtures

| Fixture | Location | Use |
|---|---|---|
| Alpine cloud image | downloaded locally | QEMU guest smoke test |
| VPCS | Debian package | console/network test |
| Cisco C8000v 17.16.01a | `/home/savanna/test_images/qemu/c8000v-17.16.01a/virtioa.qcow2` | QEMU compatibility |
| IOL x86-64 L3/L2 | `/home/savanna/test_images/iol/bin/*.bin` + `iourc` | IOL compatibility |
| Docker Alpine | pulled locally | Docker-node smoke test (v1.4 optional) |

See [TEST-IMAGES.md](TEST-IMAGES.md) for details and handling rules.

## Validation matrix (condensed)

| ID | Check | Pass criterion |
|---|---|---|
| V-ENV-01 | Host prerequisites | Debian 13, PHP 8.4, QEMU 10, KVM |
| V-ENV-02 | Dev VM | SSH works; nested KVM present or TCG documented |
| V-BUILD-01 | PHP | `php -l`, PHPStan, PHPUnit pass |
| V-BUILD-02 | Frontend | `vue-tsc`, ESLint, Vitest pass |
| V-BUILD-03 | Go | `gofmt`, `go vet`, `go test`, `staticcheck` pass |
| V-BUILD-04 | Shell/C | shellcheck; ASan/UBSan build pass |
| V-AUTH-01 | First-run setup | Admin created once; setup disabled |
| V-AUTH-02 | No default credentials | No `admin/unl`, no hardcoded password |
| V-AUTH-03/04 | Sessions/CSRF | Rotation, expiry, CSRF enforced |
| V-AUTH-05/06 | Rate limit/RBAC | Lockout after failures; server-side 403 |
| V-LAB-01..05 | Lab lifecycle | Create/save/open/duplicate/import/export; conflict and traversal rejected |
| V-TOPO-01..04 | Editor | Add/move/link/delete/undo; limits; keyboard; 60 fps at 100 nodes |
| V-NODE-01..08 | Engines | QEMU/Alpine, VPCS, Dynamips/IOL, C8000v, Docker; cleanup and limits |
| V-CON-01..04 | Consoles | Telnet/VNC connect, resize, reconnect; token rejection; tab persistence |
| V-IMG-01..04 | Images | Upload, checksum, invalid rejection, delete guard, overlay isolation |
| V-TPL-01..03 | Templates | Built-ins parse; custom validation; option allowlist |
| V-NET-01..05 | Networking | Bridge/tap lifecycle, connectivity, LLDP, no host changes, orphan cleanup |
| V-SEC-01..08 | Security | Agent auth/verbs/paths, upload attacks, CSP, no telemetry, secrets, audits |
| V-PERF-01..03 | Performance | API p95 ≤ 300 ms; lab start target; editor 60 fps |
| V-COMPAT-01..03 | Compatibility | `.unl` v1.1, v1 API, browsers |
| V-REL-01..05 | Release | Clean install, upgrade, licence check, source offer, SBOM |

The full plan contains the detailed method and evidence requirements for each
ID.

## Commands

```sh
# Licence headers and secrets
./scripts/check-licence-headers.sh
./scripts/check-secrets.sh

# PHP
./scripts/php-lint.sh
composer validate --strict        # when api/composer.json exists
vendor/bin/phpstan analyse        # when configured
vendor/bin/phpunit                # when configured

# Shell / C
shellcheck scripts/check-licence-headers.sh scripts/php-lint.sh \
  scripts/check-secrets.sh scripts/dev-vm.sh \
  tests/manual/iol-netmap-smoke.sh
clang -fsanitize=address,undefined ...

# Frontend (Phase 1+)
npm ci && npm run lint && npm run typecheck && npm run test

# Go (Phase 1+)
gofmt -l . && go vet ./... && go test ./... && staticcheck ./...

# Dev VM
scripts/dev-vm.sh start
scripts/dev-vm.sh ssh

# Manual IOL NETMAP smoke test (local vendor binary, not CI)
tests/manual/iol-netmap-smoke.sh ~/test_images/iol/bin/x86_64_crb_linux-adventerprisek9-ms.bin
```

## CI

`.github/workflows/ci.yml` currently runs:

- SPDX licence header checks;
- PHP lint on all tracked PHP (the allowlist is currently empty);
- shellcheck on TeraLab scripts;
- Python syntax parsing without writing bytecode;
- tracked-file secret scanning.

Composer/PHPUnit/PHPStan, npm/Vitest, Go, and KVM integration jobs are added
when `api/`, `ui/`, and `agent/` exist. The five baseline templates were
repaired in the Phase 0 follow-up, so `tests/ci/php-lint-allowlist.txt` is
empty; keep it that way unless a tracked exception is unavoidable.

Legacy UNetLab scripts are not yet ShellCheck-clean. CI currently checks the
four new TeraLab scripts only; expanding coverage to the legacy tree is a
v1.2 cleanup task.

## Manual acceptance checklist

- [ ] Clean Debian 13 install; first-run admin setup.
- [ ] Login/logout; no default credentials; CSRF enforced.
- [ ] Create a lab; add QEMU and VPCS nodes; link; start.
- [ ] Open telnet console; run a command; reconnect.
- [ ] Stop/wipe; processes, taps, and bridges cleaned.
- [ ] Export `.unl`; import into a new lab; topology matches.
- [ ] Upload a test qcow2; map to a template; start a node.
- [ ] Confirm no telemetry and no external asset calls.
- [ ] Upgrade from v1.1 in a test VM; verify labs and v1 API.
