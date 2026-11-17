<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Reference feature matrix (initial)

This matrix is an original behavioural summary based on frontend observation
on 2026-11-17. It does not reproduce reference text, assets, or layouts.
Screenshots are stored outside the repository. The reference identifies
itself as `9.0.0`; that number is not adopted by TeraLab.

| Area | Observed reference capability | TeraLab v1.1 | Target |
|---|---|---|---|
| Login | Version banner, username/password, default-console selector, login button, theme | Basic login, default `admin/unl` | v1.2: first-run admin, Argon2id, CSRF, theme |
| Labs | Folder navigation; list with name/modified; create folder/lab; import/export; refresh; list/grid | Labs/folders, import/export, jQuery UI | v1.3: SPA list, folders, import/export, metadata |
| Editor | Separate `/legacy/topology` canvas; node/interface labels; resource overlay; bottom toolbar; node selection mini-toolbar | jsPlumb canvas, 53 templates | v1.3: Vue Flow editor, palette, inspector, undo |
| Running | Lab list with owner, node count/status, location (master), destroy all | Status page | v1.3: running inventory, bulk actions |
| System | CPU/memory/swap/disk bars; running-node counts by engine; platform versions; KSM/UKSM/CPU limit; disk expand | Status endpoint only | v1.4: resources, versions, settings, logs |
| Images | Installed image count; QEMU/IOL/Dynamips groups; file count and size; manage local images | Manual directories | v1.3: upload, checksum, list, mapping |
| Docker Devices | Catalogue of ready-made Docker images with install buttons and console descriptions | Docker node support (TCP 4243) | v1.4 optional: secure image catalogue |
| Import Data | Import images/labs from another server over SSH; export labs from Labs view | `.unl` import/export | v1.3: `.unl`; v1.4: optional server import with key auth |
| Clusters | Master/satellite, pre-shared key, add satellite | None | Deferred (v2.0) |
| Network | Management interface (pnet0), static/DHCP, IP/netmask/gateway/DNS/search domain, reboot warning | Cloud/bridge primitives | v1.4: management network UI, clouds, NAT |
| Users & Roles | Users/roles tabs; username/email/role/status/limits/lab schedule; add user | Basic users/roles | v1.4 minimal: admin/editor/user, no LDAP |
| Version | Product/OS/kernel/QEMU/Docker/Guacamole/PHP/hostname | Hardcoded version | v1.2: About/licences/source offer |
| Consoles | (Not captured in this pass) | Telnet/VNC ports | v1.3: xterm.js + noVNC over WSS |
| AI/MCP | Hidden navigation entry; no content captured | None | Deferred |

## Prioritisation

**v1.2 (runtime):** login/security, engine bring-up, health/status, About.
**v1.3 (core UX):** Labs, editor, running, images, consoles, import/export.
**v1.4 (comfort):** System, network, minimal users, optional Docker, snapshots.
**Later:** clusters/HA, AI, LDAP/SSO, marketplace.

## Gaps to close in the next observation pass

- Lab editor behaviour: palette, node forms, link creation, validation,
  keyboard shortcuts, undo/redo, autosave.
- Console tabs: telnet/VNC, reconnect, copy/paste, tab persistence.
- Image upload and template mapping flow.
- Lab creation/import/export flow and error handling.
- Permissions and concurrent-edit behaviour.
- Timing and click counts for the primary journeys.
