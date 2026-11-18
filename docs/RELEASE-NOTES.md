<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab v1.2.0 release notes

- Release date: 2026-11-17
- Previous release: v1.1.0 (UNetLab 1.0.2-2 baseline)
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause
- Source: https://github.com/SavEvens/TeraLab

## Highlights

- **Debian 13 modernization**: PHP 8.4, QEMU 10, current packages, no legacy
  runtime workarounds.
- **Vue 3 UI** at `/ui/`: lab list, topology editor (palette, inspector, links,
  undo/redo, copy/paste, snap/grid, minimap, validation), autosave with
  revision-conflict handling, and an accessible table fallback.
- **Consoles**: xterm.js telnet and noVNC VNC tabs, persisted across routes,
  with short-lived node-bound tokens.
- **Lab lifecycle**: create/save/duplicate/move/delete, import/export `.unl`,
  start/stop/wipe with rollback, running-lab locks, per-lab permissions,
  audit events, trash with 30-day restore.
- **Images**: content-addressed store, validation/checksums, upload/list/
  rename/template mapping/delete UI, and image/ISO pickers in the editor.
- **Templates**: 52 built-in YAML templates plus custom templates in
  `/etc/teralab/templates`, with import/export/duplicate and schema validation.
- **Jobs and notifications**: persisted job records, status polling,
  safe cancellation of queued jobs, and UI progress toasts.
- **Networking**: deterministic lab bridges/taps, Linux bridge and OVS
  backends, LLDP/LACP validation, cleanup and crash reconciliation.
- **Engines**: QEMU, VPCS, IOL, and Dynamips as separate processes/packages;
  IOL startup-config injection is supported.
- **Installer and upgrade**: idempotent installer, systemd agent unit, Apache
  `/api`, `/ui`, and `/ws/*` wiring, v1.1 user/lab import with Argon2id
  rehash-on-login, and INSTALL/UPGRADE/API/SBOM documentation.

## Compatibility

- `.unl` labs, folders, and the v1 API core surface are preserved.
- `html/api.php` serves the v2 API and legacy v1 routes used by the old UI.
- The legacy jQuery UI remains at `/`; the new SPA is at `/ui/`.
- Upstream `VERSION` stays `1.0.2-2` for provenance; product version is
  `TERALAB_VERSION` (`1.2.0`).

## Validation

- PHP: 53 tests / 409 assertions; PHPStan level 6.
- UI: 8 Vitest tests; production build; mock browser E2E (`UI_E2E_OK`).
- Go: `go vet` and `go test ./...`.
- Real-stack browser E2E through installed Apache `/ui/`:
  version 1.2.0, HTTP login without a Secure cookie, template import/export,
  image upload/rename/map, drag-drop `.unl` import, noVNC, telnet `VPCS`,
  100-node open in 2.36 s at 60 fps (`APACHE_UI_E2E_OK`).
- IOL startup-config: `Configured from unix:startup-config`, prompt `TESTCFG>`.
- Release gate: `scripts/teralab-project-init.sh --check` passes.
- CI: all jobs green on `main`, including API/agent tests, UI build/E2E,
  licence, artifact, secret, ShellCheck, Dynamips package, and release gate.

## Known limits

- Live Dynamips IOS boot was not validated because no licensed IOS image is
  available; the package, adapter, and CI build are validated.
- Cloud/physical interface mapping, NAT, image URL import, API tokens, and
  logs/backup/settings are scheduled for v1.4 by the plan.
- Lab start/stop/wipe run synchronously; job records and polling exist, but
  there is no background worker yet for very large labs.
- QEMU, Dynamips, IOL/IOUtools, VPCS, Open vSwitch, and the kernel remain
  separate programs with their own licences; no vendor images are bundled.

## Upgrade

See `docs/UPGRADE.md`. Back up `/etc/teralab` and `/var/lib/teralab`, check out
`v1.2.0`, and re-run `packaging/teralab/install.sh`. v1.1 users and labs can be
imported with `packaging/teralab/import-legacy.php`; passwords are rehashed
with Argon2id on first login.
