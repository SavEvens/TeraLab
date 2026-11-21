<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab v1.6-dev software bill of materials

This inventory covers distributed TeraLab code and its direct dependencies.
QEMU, Dynamips, IOL/IOUtools, VPCS, Open vSwitch, and the kernel are separate
programs; none is linked into the AGPLv3 TeraLab code.

## First-party code

| Component | Licence | Notes |
|---|---|---|
| TeraLab (api, agent, ui, scripts, docs) | AGPL-3.0-or-later | New code |
| UNetLab-derived PHP/HTML files | BSD-3-Clause | Notices retained; modifications marked |
| Legacy templates and assets | BSD-3-Clause | Repaired/templated where noted |

## PHP runtime dependencies (`api/composer.lock`)

| Package | Licence | Use |
|---|---|---|
| slim/slim, slim/psr7 | MIT | HTTP app and PSR-7 |
| php-di/php-di | MIT | Container |
| monolog/monolog | MIT | Logging |
| symfony/yaml | MIT | Built-in/custom template parsing |
| psr/http-message, psr/http-factory, psr/http-server-handler, psr/http-server-middleware, psr/container, psr/log | MIT | PSR interfaces |
| nikic/fast-route | BSD-3-Clause | Routing |
| fig/http-message-util | MIT | HTTP utilities |
| ralouphie/getallheaders | MIT | Header polyfill |
| phpstan/phpstan (dev) | MIT | Static analysis |
| phpunit/phpunit and dependencies (dev) | BSD-3-Clause / MIT | Tests |

Exact versions and hashes: `api/composer.lock`.

## Go agent dependencies (`agent/go.mod`)

| Module | Licence | Use |
|---|---|---|
| github.com/creack/pty | MIT | PTY consoles |
| github.com/gorilla/websocket | BSD-2-Clause | Console gateway |
| golang.org/x/sys | BSD-3-Clause | TUN/TAP ioctls |

## UI dependencies (`ui/package-lock.json`)

| Package | Licence | Use |
|---|---|---|
| vue, vue-router | MIT | SPA shell and routing |
| @vue-flow/core, background, controls, minimap | MIT | Topology canvas |
| @xterm/xterm, @xterm/addon-fit | MIT | Telnet console |
| @novnc/novnc | MPL-2.0 | VNC console client (unmodified, source available upstream) |
| vite, @vitejs/plugin-vue, vitest, jsdom, @vue/test-utils | MIT | Build/test tooling |
| playwright-core | Apache-2.0 | Browser E2E (dev/test only) |
| ws | MIT | Mock WebSocket in E2E (dev/test only) |

## System components (separate processes/packages)

| Component | Licence | Form |
|---|---|---|
| QEMU 10 | GPL-2.0 | Distribution package |
| Dynamips 0.2.23 | GPL-2.0 | Separate `teralab-dynamips` package built from pinned source |
| IOL/IOUtools | User-supplied | Not redistributed; user supplies `.bin` and `iourc` |
| VPCS 0.5b2 | BSD-3-Clause | Distribution package |
| Open vSwitch 3.5 (optional) | Apache-2.0 | Distribution package |
| Linux kernel/OVMF | GPL-2.0 / BSD | Distribution packages |

## Notices and source offer

- `NOTICE`, `PROVENANCE.md`, `LICENSES/` in the repository.
- GPLv2 text: `LICENSES/GPL-2.0.txt`.
- Dynamips corresponding source: pinned URL and checksum recorded in
  `packaging/dynamips/copyright` and `packaging/dynamips/build.sh`.
- TeraLab source: https://github.com/SavEvens/TeraLab
- About page: `/about.php`; version API: `/api/v2/version`.

No vendor IOS/firmware images, test records, or research artifacts are part of
this distribution.
