<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab release notes

# TeraLab v1.5.1 release notes

- Release date: 2026-11-20
- Previous release: v1.5.0
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Portable lab format and EVE-NG import

TeraLab now saves labs in a portable format that is a superset of the modern
EVE-NG `.unl` file:

- **Import** accepts legacy TeraLab/UNetLab files, modern EVE-NG labs and the
  new TeraLab format. EVE-NG base64 startup configs are decoded, IOL interface
  ids are reconstructed, cloud/NAT/OVS network types are preserved, and
  EVE-NG text objects become text nodes.
- **Save** always writes the new format (`format="2"`): EVE-NG's lab
  structure, interface numbering, network types and base64 configs, plus a
  TeraLab-only `<extensions>` block for future features. EVE-NG ignores the
  extension block and the TeraLab attributes, so an export can be opened in
  EVE-NG.
- **No absolute paths**: images, ISOs, kernels and initrds are stored as
  portable references (a single name). A writer guard makes path leakage
  impossible. Legacy labs are normalized on load and rewritten on save.
- **Import report**: warnings for unmapped images, unsupported node types,
  cloud/NAT/OVS networks, reconstructed interfaces and the 16-port limit.
  The lab list shows the file format (EVE-NG, legacy, v2).
- Unknown EVE-NG node/interface/network attributes and object blocks are
  preserved when the editor saves a lab.

### Images

- Every image has an editable **reference** (Images page) used by lab files.
  New uploads use the file name as the reference; collisions get `-2`, `-3`.
- Deleting an image referenced by any lab is refused.
- Unmapped references are flagged in the Inspector and in lab validation.

### IOL port numbering

IOL numbers four ports per adapter; TeraLab assumed 16, so interfaces after
the fourth were wired to non-existent ports (`Ethernet0/4`, …). Interface
encoding, NETMAP lines, `-e` (now the adapter count) and link keepalives were
corrected. A six-port node now comes up with `Ethernet0/0–0/3` and
`Ethernet1/0–1/1` all `up/up` and six CDP neighbours.

### Compatibility notes

- Legacy labs are rewritten as format v2 on their first save, including the
  editor's autosave. Older TeraLab releases cannot read v2 image references;
  back up `/var/lib/teralab` before upgrading if you need to roll back.
- Cloud/NAT/OVS networks are preserved but run as isolated bridges; TeraLab
  does not modify host networking.
- Docker and EVE-NG Ethernet-switch nodes import but do not start.
- Nodes are limited to 16 interfaces; larger imported nodes are truncated
  with a warning.

### Repository cleanup

- Removed the retired legacy PHP UI (`html/includes`), the C engine wrappers
  (`wrappers/`), the per-node configuration scripts, and the old OVF, Plymouth,
  Windows, patch, baseline and `IOUtools` assets. The Go agent owns engine
  processes; `html/api.php`, templates, icons and the About page remain.
- The `labs/Featured` sample lab was removed; the legacy round-trip test now
  uses inline XML.

## Validation

- PHP 79 tests / 855 assertions; Go suites; UI 17 unit tests; mock UI E2E
  including the import report and format badge; release gate and CI green.
- Live on the installed stack: EVE-NG fixture import (configs decoded, cloud
  preserved, warnings), export with no absolute paths, re-import identical;
  legacy Test lab duplicated and rewritten as v2; six-port IOL lab with all
  six interfaces `up/up` and six CDP neighbours.

# TeraLab v1.5.0 release notes

- Release date: 2026-11-20
- Previous release: v1.4.6
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## IOL interface link state

IOL interfaces now follow the topology. After `no shutdown`:

- an interface with a link drawn reports `up/up`
- an interface without a link reports `down/down` (line protocol down)

This replaces the limitation documented in v1.4.4, where every IOL interface
reported `up/up` because the IOL binary's layer-1 keepalive messages were never
answered. The agent now impersonates the NETMAP peer on IOL's separate
layer-1 keepalive channel:

- IOL exposes `/tmp/netl1<uid>/L1<instance>` datagram sockets for keepalives,
  independent of the netio data channel.
- The agent answers keepalives only for interfaces that have a bridge attached
  and sends one keepalive per second per linked interface. Unlinked interfaces
  receive nothing, so IOS takes the line protocol down.
- The mechanism is enabled only when the image advertises `-l` in its usage
  output (probed at node start, as the engine user). Images without `-l` keep
  the previous behaviour.
- Stale endpoints are removed before start, so a crashed run does not leave a
  socket that would break the next start.

Interface numbering follows IOL's own encoding (unit/port in the high nibble,
bay in the low nibble), so `Ethernet0/0` is `0x00`, `Ethernet0/1` `0x10`, and
so on.

### Behaviour change

Labs that relied on unlinked IOL ports staying `up/up` will now see them go
`down/down` after `no shutdown`, which is the intended modelling. Loopback
interfaces are unaffected. An IOS `reload` inside a running node can leave the
node's layer-1 endpoint stale (the IOL process does not rebind it); restart
the node to restore link state.

Protocol reference: GNS3 issue #14 and PR #2826 (GPL-3.0 project). Only the
wire format and socket layout were used; the implementation is independent Go
code. See `agent/internal/engine/iol_l1.go`.

## Validation

- Go tests cover keepalive encoding, echo filtering (linked vs unlinked),
  rejected messages, periodic sends, stale socket removal and cleanup.
- Live on IOL 17.16.1a L3: two linked nodes, `Ethernet0/0` left `up/up`
  while `Ethernet0/1` (unlinked, `no shutdown`) reported `down/down` after
  more than a minute; CDP over the link still worked; stop removed both the
  responder and engine endpoints.
- PHP 64 tests / 740 assertions; UI 15 tests; release gate and CI green.

# TeraLab v1.4.6 release notes

- Release date: 2026-11-20
- Previous release: v1.4.5
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Fix

- The agent now sets `Content-Length` on JSON responses. Go switches large
  responses (such as the system status with running nodes) to chunked
  transfer encoding, which the Unix-socket PHP client cannot decode; the API
  then reported `The agent returned an invalid response.`
- This restores the v1.4.5 labs **Status** column and the **■ Stop** action on
  real deployments, and the System page's host status.

## Validation

- Go test covers a >2 KiB payload: `Content-Length` is present and the body is
  valid JSON.
- Verified live: `GET /api/v2/labs` reports the running lab, the System host
  page returns node data, and the Stop action is shown only while running.

# TeraLab v1.4.5 release notes

- Release date: 2026-11-20
- Previous release: v1.4.4
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Editor

- **Add link dialog**: links are created by choosing the node *and interface*
  on each end (`e0/0`, `e0/1`, `eth1`, …). Ends can be swapped before
  creating. Both drag-to-connect and the node/canvas context menus open the
  same dialog.
- **Add node menu**: the toggleable device palette is gone. A single
  “+ Add node” button opens a template menu split into **Ready to deploy**
  (an image is mapped to the template) and **Needs an image** (no mapping
  yet). Search, text, and picture objects live in the same menu.
- **Link rendering**: links now stop at the node body instead of the outer
  node box, and each end carries a compact interface tag. Shared segments are
  chained in canvas order so links no longer cut across intermediate nodes.
  Interface tags keep a constant screen size while zooming.
- New IOL ports are named `Ethernet0/0`, `Ethernet0/1`, … and shown as
  `e0/0`, `e0/1` in the link dialog and on the link ends.
- The zoom indicator now reports the real value, and per-lab viewport
  persistence works again.

## Labs

- The labs table has a **Status** column derived from the agent's running
  nodes (`GET /api/v2/labs` now returns `running` and `running_nodes`).
- A **■ Stop** action appears only while a lab is running; the list refreshes
  every 15 seconds so status stays current.

## Validation

- PHP 64 tests / 740 assertions; Go suites; UI 15 unit tests; UI E2E; release
  gate and CI green.
- E2E covers: grouped Add node menu, explicit interface selection, interface
  tags on link ends, running status and the conditional stop button.

# TeraLab v1.4.4 release notes

- Release date: 2026-11-19
- Previous release: v1.4.3
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Fixes

- **Node icons**: Apache now serves the legacy `/images` and `/themes` trees.
  IOL/QEMU/VPCS template icons load instead of showing broken-image boxes.
  The installer writes both aliases.
- **IOL iourc**: the IOL process now points at the per-node copy of `iourc`
  inside its run directory, so `iouc`/licence lookup works for the
  unprivileged `teralab-vm` user.

## IOL link-state limitation

IOL layer-1 link state is a firmware limitation, not a topology bug. The
binary exposes `-l` (layer-1 keepalives), but enabling it makes every port time
out and drop — including connected ports — because the IOU fabric peer does
not implement the keepalive handshake. GNS3 has the same open issue.

TeraLab therefore keeps `-l` disabled: unconnected IOL ports still isolate
traffic (no bridge/tap path), which lets IP SLA/reachability failover work, but
Cisco IOS may report `up/up` after `no shutdown` even when no link is drawn.
For true interface-state failover labs, use QEMU/VPCS nodes or shut the
interfaces explicitly. This is documented rather than hidden.

## Validation

- Icons: `/images/icons/Router.png` and `/themes/...` return 200 on the
  installed Apache.
- IOL starts with the per-node `iourc`; no `Could not open iourc` warning.
- PHP 63 tests / 732 assertions; Go suites; UI 9 tests; release gate and CI green.

# TeraLab v1.4.3 release notes

- Release date: 2026-11-19
- Previous release: v1.4.2
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Editor

- **Right-click context menus**: canvas (add node/text/picture, paste, fit,
  validate, presentation), node (console, start/stop/wipe, add link to another
  node, configure, duplicate, delete), and link (delete link, rename network).
- **Icon-first nodes**: each node is a device icon with its name underneath,
  status LED, and hover/selection glow instead of a rectangular card.
- **No per-port link dots**: nodes expose one source and one target connection
  handle; links automatically use the first free interface. Port counts remain
  editable in the inspector.
- Straight links, a line grid, and hidden edge-update handles for a cleaner
  GNS3/EVE-NG-like canvas.
- **Console fix**: xterm/noVNC now size below a compact action row, so the
  bottom terminal line (the command being typed) is no longer clipped.
- **About page**: `/ui/about` shows product, version, licence, source link,
  components, and the no-warranty notice. `/about.php` is also aliased.

## Validation

- Browser E2E covers canvas/node/link context menus, deleting a link and
  re-adding it through the node menu, the About page, and terminal bounds.
- PHP 63 tests / 732 assertions; UI 9 tests; release gate and CI green.

# TeraLab v1.4.2 release notes

- Release date: 2026-11-19
- Previous release: v1.4.1
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Fixes

- **IOL executable permissions**: uploaded IOL/Dynamips binaries are stored
  mode 0755 and their directories mode 0755, so the unprivileged `teralab-vm`
  user can traverse and execute them. QEMU/ISO images are mode 0644 so engines
  can read them.
- **Existing uploads are repaired**: the agent adds the exec bit when an IOL
  image lacks it, and the installer normalizes image directory/file modes on
  upgrade.
- The previous 293 MB IOL image started successfully; both IOL nodes reached
  running state and the console showed the `Router>` prompt.

## Validation

- Go agent tests include executable-mode repair.
- PHP image-store tests assert IOL exec and traversal bits.
- PHP 63 tests / 732 assertions; release gate and CI green.

# TeraLab v1.4.1 release notes

- Release date: 2026-11-19
- Previous release: v1.4.0
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Fixes

- **Large image uploads**: the installer now writes
  `upload_max_filesize = 4G`, `post_max_size = 4G`, and related limits for
  Apache mod_php, PHP-FPM, and CLI. Previously PHP discarded uploads above the
  2 MB/8 MB defaults before TeraLab saw them.
- **App upload default**: `max_upload_bytes` now defaults to 2 GiB instead of
  32 MiB, configurable in System → Settings.
- **Actionable errors**: when a multipart body is dropped by PHP, image,
  template, and lab uploads return 413 explaining which PHP limits to raise
  instead of “A multipart file field is required”.

## Validation

- A real 244 MB IOL image uploaded through `/api/v2/images` with HTTP 201 in
  6.4 s, was listed with its SHA-256, and was deleted cleanly.
- PHP: 62 tests / 728 assertions; PHPStan level 6.
- Release gate and CI green.

# TeraLab v1.4.0 release notes

- Release date: 2026-11-19
- Previous release: v1.3.0
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause
- Source: https://github.com/SavEvens/TeraLab

## Highlights

- **Lab snapshots**: create, list, restore, and delete topology/config
  checkpoints (runtime memory is excluded).
- **System workspace**: host load/disk/engines/physical interfaces, API and
  agent log tails, runtime settings, backup/restore, and API tokens.
- **Settings** apply immediately to new sessions and uploads: timezone,
  session TTL, maximum upload size, and default console.
- **Backup** archives the SQLite database, labs, custom templates, settings,
  and image metadata. Restore covers labs/templates/settings/metadata and
  stages the database for the next API bootstrap, keeping a pre-restore copy.
- **API tokens**: scoped read/read-write bearer tokens, hashed at rest,
  revocable, with CSRF bypass only for token-authenticated requests.
- **Bulk node actions**: start/stop/wipe a selection directly from the editor.
- **Reliability**: the agent now has `CAP_KILL` plus fail-fast stop timeouts;
  startup reconciliation reaps orphan engines, taps, cgroups, and run dirs.

## Validation

- PHP: 61 tests / 723 assertions; PHPStan level 6.
- Go: agent tests including capabilities, reconciliation, cgroup usage, and
  host interface enumeration.
- UI: 8 Vitest tests; build; browser E2E with snapshots and the System page.
- OpenAPI 3.1 draft updated and validated.
- Release gate and CI green.

## Known limits

- NAT/cloud uplinks alter host networking and remain gated on explicit owner
  approval; physical interfaces are visible in System but not attached to labs.
- Database restore is applied at the next API request; stop lab workloads first.
- Image URL import and loopback telnet remain optional/May items.
- Headless editor panning remains below 60 fps under software rendering; the
  60 fps target is a client-GPU acceptance criterion.

# TeraLab v1.3.0 release notes

- Release date: 2026-11-19
- Previous release: v1.2.0
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause
- Source: https://github.com/SavEvens/TeraLab

## Highlights

- **New synthwave interface**: deep indigo surfaces, cyan/magenta accents,
  compact monospace chrome, neon topology grid, and a minimal GNS3-inspired
  layout with floating device palette, inspector, toolbar, and console dock.
- **Full-bleed editor**: presentation mode, status bar (nodes/links/zoom/save),
  per-user/lab viewport persistence, box selection, and keyboard palette access.
- **Images**: upload progress, metadata inspection, rename, template mapping,
  and editor image/ISO pickers.
- **Templates**: YAML preview panel, built-in/custom library, import/export,
  duplicate, and schema validation.
- **Consoles**: xterm telnet and noVNC tabs with reconnect/backoff, clipboard,
  fullscreen, and route-persistent tabs.
- **Errors**: API request IDs are surfaced in user-facing messages.
- **API contract**: OpenAPI 3.1 draft in `docs/openapi.yaml`, validated in CI.
- **UX benchmark**: journey timings and targets were measured for this release.

## Validation

- PHP: 56 tests / 603 assertions; PHPStan level 6.
- UI: 8 Vitest tests; production build; mock browser E2E (`UI_E2E_OK`).
- Measured UX: login 1.3 s, editor open 0.9 s, 50 nodes 1.42 s, console 0.31 s;
  real-stack 100 nodes open in 1.5 s. Headless panning measured 53–54 fps
  (61 fps idle baseline under software rendering); 60 fps remains the
  client-GPU acceptance target.
- Accessibility: labelled controls, focus-visible outlines, table fallback,
  keyboard shortcuts, and palette Enter/Space activation.

## Compatibility

- `.unl`, v1 API routes, v1.2 data layout, and the installer are unchanged.
- The legacy jQuery UI remains at `/`; the SPA is at `/ui/`.


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
