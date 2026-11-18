<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab UI

Vue 3 single-page application for TeraLab v1.2.0.

## Features

- First-run setup, login, session/CSRF handling.
- Lab list with create, import, export, duplicate, move, delete.
- Template library: YAML import/export, duplicate, custom editing.
- Vue Flow topology editor: palette, inspector, links, undo/redo, copy/paste,
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
```

The dev server proxies `/api` to `TERALAB_UI_API` (default
`http://127.0.0.1:8094`) and `/ws` to `TERALAB_UI_WS` (default
`ws://127.0.0.1:8091`).

## Production

`packaging/teralab/install.sh` builds the app and installs `dist/` at
`html/ui`; Apache serves it at `/ui/` with SPA fallback. The app expects the
API on the same origin and the console gateway at `/ws/*`.

## Licence

AGPL-3.0-or-later. Third-party notices: `docs/SBOM.md`.
