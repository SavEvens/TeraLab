<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# UX principles and benchmark

## Product priority

Core labbing and ease of use are the primary goals for v1.2–v1.4. Clusters,
HA, AI, and advanced user management are deliberately deferred.

## Principles

1. **A lab in minutes.** A new user with a known image should create, start,
   and console into a lab in under five minutes and roughly a dozen actions.
2. **The topology is the workspace.** The canvas is the main surface; forms
   and lists are supporting tools, not the centre of the experience.
3. **State is obvious.** Node state, job progress, save state, and errors are
   visible without opening a log.
4. **Recoverable by default.** Undo/redo, autosave, conflict detection, and
   non-destructive delete/trash.
5. **Keyboard and mouse.** Every core action has a shortcut; the canvas has a
   list/table fallback.
6. **Independent identity.** TeraLab has its own visual language. Reference
   observations inform behaviour, not copied pixels, text, or assets.

## Personas

| Persona | Needs |
|---|---|
| Lab builder | Fast topology editing, templates, images, consoles, save/export |
| Instructor | Reusable labs, descriptions, import/export, reliable restart |
| Operator | Host status, images, networks, logs, clean start/stop/wipe |

## Primary journeys and targets

| Journey | Target |
|---|---|
| First login | ≤ 1 minute; no default credentials |
| Create lab + add two nodes + link | ≤ 3 minutes |
| Start lab + open console | ≤ 2 minutes |
| Return to saved lab + start | ≤ 1 minute |
| Upload image + use in a node | ≤ 3 minutes |
| Export/import lab | ≤ 1 minute |

Measure clicks, keystrokes, time, and error/recovery steps. Record results in
the milestone report.

## Information architecture

| Area | Contents |
|---|---|
| Labs | Folder tree/list, create/import/export, lab metadata, editor entry |
| Editor | Canvas, palette, inspector, links, validation, start/stop, consoles |
| Running | Active labs, node counts, owners, bulk stop/destroy |
| Images | Upload, list, checksum, template mapping, delete guard |
| Templates | Built-in/custom catalogue, YAML editor, validation |
| System | Resources, engine versions, logs, settings, backup |
| Network | Management interface, lab bridges/clouds, NAT (v1.4) |
| Users | Minimal admin/editor/user management (v1.4) |
| Version | Product version, licenses, source offer, component versions |

## Editor interaction contract

- Drag from palette to canvas; drop creates a node with template defaults.
- Drag from a port handle to another port creates a link; invalid targets are
  highlighted and rejected.
- Selection: click, shift-click, box select; move with drag or arrow keys.
- Undo/redo: ≥ 50 steps; covers add/delete/move/link/paste/align.
- View: pan, zoom, fit, minimap, grid/snap toggle.
- Inspector: template-driven fields, inline validation, image picker.
- Autosave: 1 s debounce, visible save state, conflict dialog on 409.
- Context menu: start/stop/wipe, duplicate, delete, console, edit.
- Validation: duplicate names, port overflow, missing image, dangling link.

## Consoles

- Tabs; one console per node; keep-alive across route changes.
- Telnet/serial via xterm.js; VNC via noVNC.
- Reconnect with backoff; copy/paste; font size; fit on resize.
- Clear states for stopped node, expired token, agent unavailable.

## Design system

- Vue 3 + TypeScript + PrimeVue components, themed with TeraLab tokens.
- Dark and light themes; accessible contrast; no reliance on colour alone.
- Self-hosted fonts/icons; no CDN or telemetry.
- Monospace treatment for identifiers, versions, and console-adjacent data.
- Motion is restrained; no decorative animation that blocks work.

## Accessibility

- WCAG 2.2 AA target for core flows.
- Keyboard-only path for login, lab list, editor basics, forms, consoles.
- List/table view of topology as an alternative to the canvas.
- Focus management for dialogs, tabs, and console focus.
- Reduced-motion support.

## Benchmark method

1. Capture the reference workflow with screenshots and timings (outside the
   repository).
2. Write an original behavioural spec: actions, states, errors, timings.
3. Implement the same journey in TeraLab.
4. Measure clicks/keys/time and compare; record screenshots side by side
   outside the repository.
5. Repeat before each milestone gate; fail a gate if a core journey regresses
   by more than 20%.
