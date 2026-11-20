<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab release notes

# TeraLab v1.5.5 release notes

- Release date: 2026-11-20
- Previous release: v1.5.4
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Editor chrome

The Align buttons, the Validate button and the Save button are removed from
the editor. The pane context-menu Validate entry and the validation panel went
with them.

Saving is unchanged: autosave (1 s debounce), Ctrl+S, the save-state
indicator, the conflict modal, and the silent saves before start, stop and wipe
all remain. Problems are still reported where they block work — import warnings
and node-start errors are unchanged. There is no standalone topology
validation until passive validation on save lands as a follow-up.

## Add link in the top bar

"+ Add link" now sits next to "+ Add node" in the top bar and is always
enabled. The link dialog validates the selection itself ("Choose two nodes and
interfaces", "Pick two different nodes"), so there is nothing to disable.

## Add several copies of a template at once

The template picker has a **Count** field: 1 to 20 copies of a template can be
added in one action. The copies cascade in a grid from the click point (or the
viewport centre), and one undo step removes the whole batch. Add text and Add
picture keep adding a single element.

## Zoom controls and minimap

The Vue Flow zoom controls and the minimap are no longer covered by the bottom
toolbar or the status bar, and they stay clear of the console dock, including
after the dock is resized. With the console open they sit above it.

## Resizable console dock

The console dock has a drag handle on its top edge. Drag it, press Up/Down
while it is focused, or double-click it to return to the default. The height is
clamped to 140 px – 75 vh and stored per user in the browser (`localStorage`,
key `teralab.console-h.<user>`). It is applied through the `--console-h` CSS
variable, which the editor chrome and the Vue Flow panels now follow; the
default is the previous 332 px, so nothing moves until someone drags. The
height is a browser preference only — nothing is stored on the server.

## No resize frames in the device console

The telnet console no longer sends `{"type":"resize",…}` frames — neither on
open nor on fit-driven terminal resizes.

The agent still understands the frame: the serial console bridge consumes a
strictly shaped resize control frame — a JSON object with exactly `type`
(`"resize"`), `cols` (1–1024) and `rows` (1–256), all integers — applies it to
the node's PTY with `pty.Setsize`, and never forwards it. Every other frame,
including binary traffic, plain text, malformed JSON, extra keys and
out-of-range values, passes through byte-for-byte. The VNC and TCP bridges are
unchanged (raw passthrough). No client can write control JSON into a device
console again.

## System page: operational status only

The System → Host tab no longer shows the raw host-JSON card or the duplicated
version badge. Engines that are not installed and interfaces that are down use
a neutral grey dot; red is reserved for real errors. A new **CPU usage** card
shows a measured percentage.

## CPU usage in the host status

The agent's host status gains `cpu_usage_percent`: two `/proc/stat` samples
about 250 ms apart, bounded by the request context, reported to one decimal
place. It is `0` when sampling is unavailable (for example off Linux, or when
`/proc/stat` cannot be read), and the two-sample wait adds roughly 250 ms to a
host-status call. The Load 1/5/15 card keeps bare unitless load averages.

## About page: licence and provenance only

About is now a pure licence/provenance display: components are listed by name
and licence with no status colours. Product, Licence, the source link, the
no-warranty notices and the version badge all remain. The System page no longer
duplicates the version; About and the header badge are where it shows.

## Compatibility notes

- No API shape change: the v1 and v2 endpoints keep their request and response
  shapes. The only addition is `cpu_usage_percent` in the agent host-status
  payload consumed by the System page; `docs/API.md` is unchanged in this
  release.
- The reported product version is now `1.5.5`.
- No database migration and no lab-format change: labs, image references and
  the `/var/lib/teralab` layout are unchanged. There is nothing to migrate and
  no additional backup is required beyond the normal upgrade backup described
  in `docs/UPGRADE.md`.
- Removing the Validate button is intentional. Import warnings and node-start
  errors still surface problems; passive validation on save is a follow-up.
- The console height is a per-user browser preference, not part of a lab or an
  account. Clearing site data restores the 332 px default.
- The System page no longer shows the version; About does, next to the
  unchanged header badge.
- The mock e2e threshold (10 s for 100 nodes) is pre-existing, and the suite
  can flake on a concurrently loaded host.

## Licensing and provenance

No new dependency and no upstream-derived code. The About cleanup keeps every
AGPL-required notice (product, licence, source link, no-warranty text); it only
removes status colouring. `LICENSE`, `NOTICE`, `PROVENANCE.md` and `LICENSES/`
change only in their version strings.

## Validation

Verified on the running stack:

- The host status reported `cpu_usage_percent` (for example `2`), and the
  System CPU usage card rendered it with a `%`.
- A resize control frame sent to a real IOL console was consumed: the device
  output showed only the following `show clock` command, with no JSON.
- The Test lab starts normally.
- PHP suite: 105 tests, 1116 assertions, PHPStan clean.
- Go: all packages, including `-race` for the console, system and engine
  packages.
- UI: 24 tests and a successful build.
- Mock e2e passes when run alone (`UI_E2E_OK`, about 8.9–9.8 s). The 10 s
  threshold for 100 nodes is pre-existing, and a concurrently loaded host can
  make the suite flake.

# TeraLab v1.5.4 release notes

- Release date: 2026-11-20
- Previous release: v1.5.3
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Adopt an existing `/opt/unetlab/addons` tree in place

A host migrating from UNetLab, EVE-NG or PNetLab usually already has its images
under `/opt/unetlab/addons`, often tens or hundreds of GiB. TeraLab can now
register those images **without copying or re-uploading a single byte**.

Recognised layouts:

- `qemu/<folder>/` — one appliance per folder; the primary disk is
  `hda.qcow2`, else the first `*.qcow2`. Further disks and ISO files become
  separate entries.
- `iol/bin/*.bin` — IOL images.
- `dynamips/*.image`, `*.bin` — Dynamips IOS images.
- `docker/` — skipped: there is no Docker engine integration.

For each selected file TeraLab creates a symlink inside the official store,
`/var/lib/teralab/images/<kind>/<id>/<basename>`, pointing at the existing
addon file. The database row points at that store path and is marked
`external = 1`. The addons tree is never modified.

Reference rules follow the public UNetLab/EVE-NG conventions: a QEMU node's
`image` attribute resolves to the addon **folder name** (so a lab with
`image="csr1000vng"` matches), and IOL/Dynamips references use the filename.
Secondary files use `<folder>-<basename>`. Each image gets a template
suggestion (exact id match after lowercasing, else the longest template id
that is a prefix of the folder or file name), which the administrator confirms
or overrides in the dry run.

Ways to run it:

- **UI:** Images → Adopt images… — admin only: dry run, per-file status,
  template selection, confirm, per-file import summary, then the list shows a
  "linked" badge and an "Unlink" action.
- **API:** `POST /api/v2/images/import` with `root`, `dry_run` (default
  `true`), `files`, and optional `templates`; admin role plus CSRF and
  authentication; audited as `image.import`.
- **CLI:** `sudo php /opt/teralab/packaging/teralab/import-legacy.php
  --legacy-addons=/opt/unetlab/addons`. The path defaults to
  `/opt/unetlab/addons`, `--legacy-db` is optional when `--legacy-addons` is
  given, and the CLI runs as root, so files unreadable by `www-data` still
  import.

### Security model

- The agent accepts a resolved path only under the primary image store or an
  approved extra root. The new colon-separated `TERALAB_IMAGE_EXTRA_ROOTS`
  variable lists those roots (systemd default: `/opt/unetlab/addons`); relative
  paths are rejected at startup, extra roots are never scanned automatically,
  and a non-existent root is inert. A store symlink pointing outside all
  approved roots is still rejected.
- Every selected file is re-validated to resolve under the scanned root before
  its store link is created.
- `iourc` and any licence-looking file are never registered.
- The import endpoint is administrator-only.
- Uploads keep the existing single-root rules; nothing changes for them.

### Removing a linked image

Unlinking (delete) removes the database row and the store-side symlink only.
The legacy addon file is never deleted. The running-node and lab-reference
guards apply to linked images as before.

### Quota and backup

Linked images consume no store space and are excluded from the store quota
sum (`external = 1`). Backup export/restore round-trips the `external` flag;
on restore, linked rows whose target is missing on the new host are skipped and
counted.

### Fixes

- Deleting or unlinking an image that is only used by stopped nodes no longer
  fails with "in use by a running node"; the guard now considers running nodes
  only. The lab-file reference guard is unchanged.

### Limitations

- Adoption computes a SHA-256 checksum; large images take time. The UI imports
  per selection, the CLI has no runtime limit.
- Files that `www-data` cannot read are reported as `unreadable`; fix
  permissions or use the root CLI path.
- Docker addons are skipped.
- QEMU images with a backing file are adopted with a warning (uploads still
  reject backing files).
- If the addons tree is moved or removed, linked nodes fail to start with a
  clear file-not-found error; unlink and re-adopt. Re-adopting a dangling row
  currently reports `duplicate` until the stale row is unlinked.
- **Compatibility note:** on a TeraLab host `/opt/unetlab` is normally a
  symlink to `/opt/teralab`. A tree created at `/opt/unetlab/addons` then lives
  inside the install tree, and the installer's `rsync --delete` replaces it on
  upgrade. The intended migration case is a host with a real `/opt/unetlab`
  directory; if the addons tree is elsewhere, add its path to
  `TERALAB_IMAGE_EXTRA_ROOTS`.

### API and upgrade notes

- New endpoint `POST /api/v2/images/import` (administrator only).
- The image representation gains `external` (`0` or `1`).
- Migration `008_external_images.sql` adds the `external` column and an index.
  This is the only automatic database change; there is no lab-format change.
- `import-legacy.php` behaviour change: `--legacy-db` is now optional when
  `--legacy-addons` is supplied.

### Licensing and provenance

No new dependency and no upstream-derived code: `NOTICE`, `PROVENANCE.md`,
`LICENSES/` and the device templates are unchanged apart from version strings.

## Validation

Verified on the running stack:

- API dry run reported `new 4, unreadable 1, skipped 2`; template suggestions
  were `csr1000vng → csr1000v`, `vios-adventerprisek9-m → vios`, and
  `c7200.image → c7200`; `iol/bin/iourc` was skipped as a licence file; a
  mode-000 qcow2 was reported `unreadable`.
- Import: 4/4 `imported` with `external = 1`; store links resolved to
  `/opt/unetlab/addons/...`; a byte-identical pair was reported `duplicate`.
- Unlink (`DELETE /api/v2/images/{id}`) removed the row and the store link;
  the target files stayed intact.
- CLI `import-legacy.php --legacy-addons=/opt/unetlab/addons` reported
  `existing 4` and imported the root-only-readable file (`imported 1`).
- An EVE-NG-format lab with `image="csr1000vng"` imported with zero warnings;
  a QEMU node using it stayed running with its process alive (store
  directories are created `0755`, like uploads, so the `teralab-vm` QEMU
  process can open the link).
- PHP suite: 102 tests, 1095 assertions, PHPStan clean. Go: all packages,
  including `-race`. UI: 23 tests and a successful build. Release gate green.

# TeraLab v1.5.3 release notes

- Release date: 2026-11-20
- Previous release: v1.5.2
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## Twelve new built-in device templates

The built-in catalogue grows from 52 to 64 entries. All twelve new templates
are metadata only (`schema: 1` YAML), TeraLab-authored under
AGPL-3.0-or-later, and reuse existing icons. No vendor template, icon, image,
firmware or licence material is bundled.

Cisco Catalyst 8000V and Firepower:

- `c8000v` — Catalyst 8000V edge router: 4 vCPU, 8 GiB RAM, 4 NICs, telnet.
- `ftd7` — Firepower Threat Defense 7 (FTDv): 4 vCPU, 8 GiB RAM, 6 NICs, VNC.
- `fmc7` — Firepower Management Center 7 (FMCv): 4 vCPU, 28 GiB RAM, 1 NIC,
  VNC.

Legacy Viptela ids:

- `vtmgmt` — vManage: 4 vCPU, 32 GiB RAM, 2 NICs, telnet.
- `vtsmart` — vSmart: 4 vCPU, 8 GiB RAM, 2 NICs, telnet.
- `vtbond` — vBond: 2 vCPU, 4 GiB RAM, 2 NICs, telnet.
- `vtedge` — vEdge: 1 vCPU, 1 GiB RAM, 5 NICs, telnet.

Catalyst SD-WAN ids (best-effort naming compatibility with current Cisco
product names):

- `catmanager` — Catalyst SD-WAN Manager (formerly vManage): 4 vCPU, 32 GiB.
- `catcontrol` — Catalyst SD-WAN Controller (formerly vSmart): 4 vCPU, 8 GiB.
- `catvalid` — Catalyst SD-WAN Validator (formerly vBond): 2 vCPU, 4 GiB.
- `catvedge` — Catalyst SD-WAN Edge (formerly vEdge): 1 vCPU, 1 GiB, 5 NICs.
- `catc8k` — Catalyst 8000V SD-WAN Edge (cEdge): 4 vCPU, 8 GiB, 4 NICs.

`catc1k` was in the approved plan but is not shipped: Cisco has no Catalyst
1000V virtual platform, and the plan explicitly allows dropping it.

## Known limitation: templates are metadata only

The engine does not consume the template `options` block yet. NIC model and
QEMU arguments beyond the generic QEMU path are therefore not applied in this
release. Starting FTD and FMC, which need VNC and a vendor-specific disk
layout, and multi-disk SD-WAN Manager nodes, is **not yet guaranteed**. The
metadata is correct and forward-compatible; the engine work is deferred.

High RAM defaults are deliberate vendor floors, not typos: `fmc7` (28 GiB)
and `vtmgmt`/`catmanager` (32 GiB). A host must have enough memory to start
those nodes.

No UI badge and no port-naming options are added in this release.

## Licensing and provenance

- Every new template is TeraLab-authored `AGPL-3.0-or-later` with an SPDX
  header; none is derived from UNetLab, EVE-NG or a vendor template.
- SD-WAN ids are best-effort naming compatibility only; no vendor template,
  icon, image or licence material was copied.
- `fortinet.yaml` and the existing BSD-3-Clause templates are untouched.

## Compatibility notes

- No API schema, lab format or database migration changed.
- `GET /api/v2/templates` now returns the 64 built-in templates (plus any
  custom templates installed).
- The OpenAPI document only has its version bumped.

## Validation

- `64 templates checked, 0 problems` from the template schema check.
- PHP suite: 84 tests, 874 assertions; PHPStan clean.
- Release gate green.
- On the deployed pre-release API, `GET /api/v2/templates` returned 64
  entries with all 12 new ids present, and `catc1k` returned `404`.

# TeraLab v1.5.2 release notes

- Release date: 2026-11-20
- Previous release: v1.5.1
- Licence: AGPL-3.0-or-later; UNetLab-derived files remain BSD-3-Clause

## A failed node start no longer destroys a running lab's network

v1.5.1 called `lab.cleanup` on any per-node start failure. That included the
harmless "node is already running" error, so starting a node that was up deleted
every bridge and tap of the lab while its engines kept running. The result was
confusing: interfaces still reported `up/up` and still answered L1 keepalives,
but ARP, CDP and ping were dead because the links underneath were gone.

- **Fixed:** a failed per-node start, including "node is already running", no
  longer cleans up the lab. Only the node that failed is affected.
- **Fixed:** the full-lab start rollback removes lab links only when no node of
  the lab is still running. A failed rollback stop no longer costs a live node
  its links.
- **Fixed:** the agent's `lab.cleanup` and the reconcile sweep keep the taps
  **and bridges** of running nodes; links that are actually removed are logged
  at WARN.
- **Fixed:** failed node actions (`lab.node.start|stop|wipe`) are written to the
  audit log with result `error` and the agent's message, so a destructive
  failure is visible after the fact.
- **Host firewall:** the installer now enables `teralab-firewall.service`, which
  allows forwarded IP traffic between TeraLab lab bridges (IPv4, plus IPv6
  when `ip6tables` is available). On Docker hosts `br_netfilter` and a
  `FORWARD` policy of `DROP` otherwise drop lab traffic while
  ARP and CDP still pass, so labs looked connected but could not ping. The
  single rule is scoped to `tl*` and leaves `docker0` untouched.

### UI

- The editor offers only the controls that are valid for the current node
  state: a running node offers Stop, a stopped node offers Start/Wipe.
- Node state is refreshed after every node action and after lab start/stop, so
  the controls follow reality instead of a stale snapshot.
- Bulk actions skip nodes that are not eligible for the action.

### Compatibility notes

- No database migration and no lab-format change. `docs/LAB-FORMAT.md` is
  unchanged and v1.5.1 labs need no conversion.
- No API shape change; the OpenAPI document only has its version bumped. The
  only API-visible difference is the additional audit-log entries for failed
  node actions.
- A lab that lost connectivity under v1.5.1 needs only a stop and a start to
  rebuild its bridge and taps. Engine nvram, including configured IP addresses,
  lives in the run directory and survives the restart. See `docs/UPGRADE.md`.
- Known limitation carried over from v1.5.1: legacy labs are rewritten as format
  v2 on their first save, including autosave, and that rewrite is one-way.
  Back up `/var/lib/teralab` before upgrading if you may need to roll back.
- Cloud/NAT/OVS networks are still preserved but run as isolated bridges, and
  Docker/EVE-NG switch nodes still import without starting (unchanged from
  v1.5.1).

## Validation

- Covered by PHP API and service tests, Go agent tests (including the new
  cleanup regression test), UI unit tests, and the mock UI E2E run; release gate
  run at packaging time.
- Verified live on the release host:
  - starting an already-running node returns `400` and leaves all 5 lab links
    in place;
  - the maintenance reconcile keeps the links of a running lab;
  - failed node actions appear in the audit log with result `error`;
  - a lab broken by v1.5.1 recovers with a stop and start;
  - IOL↔IOL ping succeeds 5/5 once the lab bridge firewall rule is present.

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
