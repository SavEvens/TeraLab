<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab UI

Vue 3 single-page application for TeraLab v1.5.2.

## Design

Synthwave tokens: deep indigo background, neon cyan primary, magenta accent,
monospace uppercase chrome, and a subtle perspective grid. Layout follows a
compact simulator model: a left section rail for workspace pages and a
full-bleed editor with a floating inspector, toolbar, and a persistent bottom
console dock. No external UI kit or web fonts are used.

## Features

- Right-click canvas, node, and link menus: add nodes/links/text/pictures,
  paste, fit, validate, open consoles, start/stop/wipe, delete/rename links,
  duplicate, and delete.
- “+ Add node” opens a template menu grouped into templates that have an image
  mapped and templates that still need one.
- “Add link” selects the node and interface (`e0/0`, `e0/1`, `eth1`, …) on
  each end; drag-to-connect opens the same dialog.
- Icon-first topology nodes with names underneath; link ends show interface
  tags and stop at the node body.
- First-run setup, login, session/CSRF handling.
- Lab list with running status, format badge, conditional stop, create,
  drag-and-drop/import (legacy, UNetLab, EVE-NG and TeraLab v2), export,
  duplicate, move, delete, and a 30-day trash with restore.
- Portable lab files: EVE-NG-compatible saves with named image references,
  decoded base64 configs and an import report (see `docs/LAB-FORMAT.md`).
- Template library: YAML import/export, duplicate, custom editing.
- Vue Flow topology editor: inspector (name, template, image, CPU, RAM, NIC
  driver, port/serial counts, console), links, undo/redo, copy/paste,
  duplicate, align, snap-to-grid, grid toggle, fit, minimap, table fallback.
- Autosave with revision conflict detection and reload/overwrite.
- Validation surface for duplicate names, missing images, and port limits.
- xterm.js telnet and noVNC VNC console tabs that survive route changes.
- Toast notifications and job progress.
- Node startup configuration editing/download (IOL injection).
- Keyboard shortcuts: Ctrl/Cmd+S save, Ctrl/Cmd+Z/Y undo/redo, Ctrl/Cmd+C/V
  copy/paste, Delete, arrow-key nudge.

## Development

```sh
npm ci
npm run dev        # http://127.0.0.1:5173/ui/
npm test           # Vitest unit tests
npm run build      # dist/
npm run e2e        # mock API + Chromium E2E
npm run e2e:real   # real API/agent via Vite + SSH tunnels
npm run e2e:apache # installed Apache /ui/ + /api + /ws (real stack)
```

The dev server proxies `/api` to `TERALAB_UI_API` (default
`http://127.0.0.1:8094`) and `/ws` to `TERALAB_UI_WS` (default
`ws://127.0.0.1:8091`).

## Production

`packaging/teralab/install.sh` builds the app and installs `dist/` at
`html/ui`; Apache serves it at `/ui/` with SPA fallback. The app expects the
API on the same origin and the console gateway at `/ws/*`.

## Apache E2E

`e2e/apache.mjs` drives the installed `/ui/` through Apache and checks login
over HTTP (non-Secure cookie), `/api/v2/version`, template import/export,
image upload/rename/map, drag-drop import, VNC, telnet WebSocket, and a
100-node interaction FPS budget.

Environment: `TERALAB_APACHE_URL` (default `http://127.0.0.1:18080`),
`TERALAB_TEST_IMAGE` (small qcow2), `TERALAB_ADMIN_PASSWORD`.

## Licence

AGPL-3.0-or-later. Third-party notices: `docs/SBOM.md`.
