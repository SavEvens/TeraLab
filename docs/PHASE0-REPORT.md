<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Phase 0 report — discovery and guardrails

- Date: 2026-11-17
- Branch: `feat/phase-0-foundation`
- Commits: `d1a3eb4` (foundation), `f358eaa` (CI pinning)
- Status: **Complete**, with the C8000v boot-matrix follow-up noted below.

## Objectives

1. Create and verify the nested development VM.
2. Capture the reference frontend behaviours.
3. Build the initial feature matrix and UX benchmark inputs.
4. Record architecture decisions.
5. Add CI, licence, secret, and repository guardrails.
6. Inventory and smoke-test the local vendor images.
7. Produce the Phase 0 documentation.

## Environment

| Item | Result |
|---|---|
| Host | Debian 13, 16 vCPU, 15 GiB RAM, KVM available |
| VM | Debian 13 genericcloud, 6 vCPU / 8 GiB / 60 GiB |
| VM access | SSH `127.0.0.1:2222`, key-only, user `savanna` |
| VM web | `http://127.0.0.1:8080/` |
| Guest PHP | 8.4.27; FPM active and Apache config enabled |
| Guest QEMU | 10.0.13 |
| Guest Node.js | 24.22.3 LTS in `~/.local/node`; npm 11.19.1 |
| Guest Go | 1.27.4 in `~/.local/go` |
| Guest KVM | `/dev/kvm` usable; `savanna` added to `kvm` group; nested KVM test passes |
| Docker | 26.1.5 on host; `alpine:3.20` pulled and runs (`docker-ok`) |
| Reference | Login verified; screenshots captured |
| CI | GitHub Actions run `44110024902`: all jobs green (licence, PHP lint, ShellCheck, Python syntax, secret scan) |

## Deliverables

| Deliverable | Location |
|---|---|
| Dev VM manager | `scripts/dev-vm.sh` |
| Licence header check | `scripts/check-licence-headers.sh` |
| PHP lint wrapper | `scripts/php-lint.sh` |
| Secret scan | `scripts/check-secrets.sh` |
| PHP lint allowlist | `tests/ci/php-lint-allowlist.txt` |
| CI workflow | `.github/workflows/ci.yml` |
| Documentation index | `docs/README.md` |
| Architecture | `docs/ARCHITECTURE.md` |
| Dev VM runbook | `docs/DEV-VM.md` |
| Security policy | `docs/SECURITY.md` |
| Testing guide | `docs/TESTING.md` |
| UX principles | `docs/UX.md` |
| Feature matrix | `docs/REFERENCE-FEATURE-MATRIX.md` |
| Observation log | `docs/REFERENCE-OBSERVATIONS.md` |
| Test image inventory | `docs/TEST-IMAGES.md` |
| IOL NETMAP smoke test | `tests/manual/iol-netmap-smoke.sh` |
| C8000v QEMU smoke test | `tests/manual/qemu-c8000v-smoke.sh` |
| ADRs | `docs/adr/0001`–`0005` |
| Reference screenshots | `/home/savanna/ref-observations/screenshots/` (outside repo) |

## Reference capture

Captured routes:

`#/labs`, `#/running`, `#/system`, `#/images`, `#/devices`, `#/data`,
`#/clusters`, `#/network`, `#/users`, `#/version`, and the login page.

Method: system Chromium 156 driven by Playwright, authenticated through the
public login flow. Screenshots and the capture manifest remain outside the
repository. No reference source, CSS, images, text, templates, or lab files
were copied. The login page uses third-party artwork; it is explicitly not
reused.

## Local image smoke tests

| Image | Result |
|---|---|
| Docker `alpine:3.20` | Pulled; `docker run --rm alpine:3.20 echo docker-ok` → `docker-ok` |
| IOL x86-64 L3/L2 | Binary executes and prints IOL usage. A generated NETMAP (`1:d 513:d` for 64 ports) is parsed and IOL reports the port mappings. `tests/manual/iol-netmap-smoke.sh` passes. Full console/interface wiring remains agent work. |
| QEMU C8000v 17.16.01a | Image metadata verified: qcow2, 8 GiB virtual, ~2 GiB actual, no backing file. With `q35` + SeaBIOS + virtio-blk, QEMU 10 boots IOS-XE 17.16.01a from `packages.conf`, enters AUTONOMOUS mode, and reaches `Press RETURN to get started!` in under four minutes with 8 GiB. A 4 GiB guest stalls during package verification; use 8 GiB as the baseline. The `pc` variant is being tested; OVMF is not required for the working configuration. The guest attempts Cisco PnP to `devicehelper.cisco.com:443`; block egress or disable PnP for lab use. Evidence: `/home/savanna/vms/c8000v-test/serial-login.log` (outside the repository). |

## Guardrails verified

```sh
./scripts/check-licence-headers.sh   # OK
./scripts/php-lint.sh                # OK, 100 files; no allowlist entries
./scripts/check-secrets.sh           # OK
shellcheck scripts/check-licence-headers.sh scripts/php-lint.sh \
  scripts/check-secrets.sh scripts/dev-vm.sh \
  tests/manual/iol-netmap-smoke.sh \
  tests/manual/qemu-c8000v-smoke.sh            # OK
```

The five baseline templates with unterminated comments were repaired during
the Phase 0 follow-up, including QEMU 10-compatible `pc` machine types for
the affected templates. The PHP lint allowlist is now empty. No new entries
are permitted without a tracked reason.

## Known issues and follow-ups

1. **C8000v boot matrix** — 8 GiB is required (4 GiB stalls); `q35` +
   SeaBIOS reaches the `Press RETURN` prompt in under four minutes. The `pc`
   variant is being tested; OVMF is not required for the working
   configuration. Block the guest PnP egress for lab use.
2. **IOL interface wiring** — NETMAP generation and parsing are validated;
   the agent must provide the wrapper/netio side and console plumbing.
3. **Deeper reference capture** — node edit form, palette, consoles, lab
   creation/import, permissions, and click/time benchmarks.
4. **Node 24 LTS and Go toolchain** are installed in the VM (24.22.3 and
   1.27.4).
5. The PHP lint allowlist is empty; keep it that way unless a tracked
   exception is unavoidable.

## Exit criteria

| Criterion | Status |
|---|---|
| VM reachable; nested KVM verified | Met |
| Reference screenshots captured | Met |
| Feature matrix reviewed | Met (initial version) |
| CI runs on a trivial PR | Met locally; remote run on first push |
| ADRs approved | Met |
| Local images inventoried; smoke results recorded | Met for Docker/IOL; C8000v boot matrix pending |

## Follow-up closeout

- The five broken PHP templates are repaired, QEMU 10-compatible machine
  flags are applied where needed, and the PHP lint allowlist is empty.
- Node.js 24.22.3 LTS and Go 1.27.4 are installed in the VM with verified
  official tarballs.
- IOL NETMAP generation is validated and reproducible with
  `tests/manual/iol-netmap-smoke.sh`.
- The reference Local Image Manager modal was captured and documented.
- C8000v: 8 GiB on `q35` + SeaBIOS reaches the `Press RETURN` prompt in
  under four minutes; the `pc` variant is being tested. Vendor PnP egress
  should be blocked for lab use.

## Next milestone

v1.2.0 runtime foundation, Sprint 1:

- path/config refactor and single version source;
- convert the repaired PHP templates to YAML;
- Slim 4 migration for auth/status/labs;
- Argon2id, first-run admin, CSRF, rate limiting;
- remove Analytics and default credentials;
- verify with V-AUTH and V-BUILD checks.
