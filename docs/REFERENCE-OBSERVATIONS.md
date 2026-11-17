<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Reference observation log

## Scope and method

- Date: 2026-11-17.
- Target: `https://192.168.1.21/`, frontend only.
- Authentication: local credentials supplied by the owner; used through the
  public login flow.
- Tooling: system Chromium 156 driven by Playwright; authenticated session
  created with the public login endpoint.
- Output: screenshots and a capture manifest in
  `/home/savanna/ref-observations/`, outside this repository.
- Clean-room: no SSH, shell, database, filesystem, source, or API token was
  used. No reference code, CSS, images, templates, texts, assets, lab files,
  or metadata were copied into this repository.

## Captured routes

`#/labs`, `#/running`, `#/system`, `#/images`, `#/devices`, `#/data`,
`#/clusters`, `#/network`, `#/users`, `#/version`, and the login page.

## Observed behaviours

### Login

- A login card with version label, username, password, a default-console
  selector, and a login button.
- A large third-party illustration is used as the page background. It is
  credited to its artists in the page. **Do not reuse this artwork.**
- The page exposes a version banner and short help text.

### Labs

- Breadcrumb navigation within a workspace; a table of labs with name and
  modified time.
- Actions: new folder, new lab, import, export, refresh, list/grid toggle.
- Folders and labs share the list; rows show a lab icon.
- No editor was opened in this pass.

### Running Labs

- Table of labs with owner, node state/count, and location (`MASTER`).
- Refresh and list/grid controls; a prominent “destroy all” action.
- Empty or stopped labs still appear in the list.

### System

- Resource bars for CPU, memory, swap, and disk with percentage and absolute
  values.
- Running-node counters by engine (QEMU, IOL, Dynamips, Docker, VPCS).
- Platform panel: engine version, QEMU version, CPU cores, KSM toggle, UKSM
  state, CPU limit toggle.
- Disk-expand panel with detection and apply actions.

### Images

- Summary count and grouping by engine (QEMU, IOL, Dynamips).
- Rows show engine badge, image name, file count, and size.
- A “manage local images” action is present.
- The reference lists many vendor images; TeraLab does not redistribute them.

### Docker Devices

- A catalogue of ready-made Docker images with description, image reference,
  install button, and install state.
- The catalogue is reference-specific; TeraLab will define its own curated
  list (if Docker support ships in v1.4).

### Import Data

- “Import from another server” form: host/IP, SSH user, SSH password, port,
  and discover action, described as pulling QEMU images, IOL binaries, and
  labs from another server.
- Export guidance points back to the Labs view.

### Clusters

- Master version, pre-shared key generation, add-satellite action, and a
  satellite list. No satellites were joined.

### Network

- Management-network warning: a wrong address or gateway can make the
  appliance unreachable; previous configuration is backed up.
- Management interface (`pnet0`), addressing mode (static/manual), IP,
  netmask, gateway, DNS servers, search domain, save action, and reboot
  warning.

### Users & Roles

- Users/Roles tabs; users table with username, email, role, status, limits,
  and lab schedule; add-user action.
- Advanced scheduling/limits are out of scope for the first milestones.

### Version

- Product name and version, OS release, kernel, QEMU, Docker, Guacamole,
  PHP, and hostname.
- Observed values are not TeraLab targets; they only describe the reference
  environment.

## Technology observations (not targets)

The reference reports an Ubuntu 26.04 environment, QEMU 10.2.1, Docker
29.10.0, Guacamole 1.6.0, and PHP 8.5.4. TeraLab targets Debian 13, QEMU 10,
PHP 8.4, and its own console stack (xterm.js/noVNC). Guacamole is noted as an
option, not a dependency.

## Gaps and next pass

- Editor: palette, node forms, link creation, validation, undo/redo, autosave.
- Consoles: telnet/VNC tabs, reconnect, copy/paste, persistence.
- Image upload and template mapping.
- Lab creation/import/export and error handling.
- Permissions and concurrent edits.
- Click/time benchmark for the primary journeys.

## Clean-room statement

This log records behaviour and timing only. It is not a source of code,
assets, text, layout, or version numbers. Screenshots remain outside the
repository and must not be committed.
