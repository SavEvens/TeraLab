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
`#/clusters`, `#/network`, `#/users`, `#/version`, the login page, and one
lab editor view (`/legacy/topology`).

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

### Lab editor

- Opening a lab navigates from the SPA shell to `/legacy/topology`, a
  separate full-window editor view.
- The canvas uses a dark grid background with node icons, node names, and
  interface labels (for example `Gi4`, `eth0`, `ge0/0`). Links are drawn as
  straight/curved lines with interface labels near the endpoints.
- Clouds and network objects are represented as distinct node shapes.
- A small resource overlay in the top-right shows CPU, RAM, and disk
  percentages.
- A bottom toolbar provides start, stop/wipe, console/terminal, download/
  export, power, node palette, theme, pan, and fullscreen controls.
- Selecting a node highlights it and shows a small floating toolbar with
  start and edit actions. No side inspector was visible in this view.
- Double-clicking a node selects it but did not open an edit form in this
  pass; the edit form and device palette remain to be captured.
- No lab content was modified; only a node was selected for observation.

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
- A “manage local images” action opens a modal with Manage/Upload tabs,
  Refresh, and New QEMU Folder.
- The Manage tab shows an expandable folder tree; each folder lists its files
  with sizes and per-file download/edit/delete actions.
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

- Editor: node edit form, palette behaviour, link creation, validation,
  undo/redo, autosave, multi-select, and keyboard shortcuts.
- Consoles: telnet/VNC tabs, reconnect, copy/paste, persistence.
- Image upload and template mapping.
- Lab creation/import/export and error handling.
- Permissions and concurrent edits.
- Click/time benchmark for the primary journeys.

## Clean-room statement

This log records behaviour and timing only. It is not a source of code,
assets, text, layout, or version numbers. Screenshots remain outside the
repository and must not be committed.
