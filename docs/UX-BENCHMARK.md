<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab UX benchmark — v1.3.0

Measured on the Debian 13 development host with PHP 8.4.27, QEMU 10.0.13,
Apache 2.4, and Chromium via Playwright. Browser numbers come from the mock
E2E (`ui/e2e/run.mjs`); engine numbers come from the installed agent.

| Metric | Target | Measured | Status |
|---|---|---|---|
| Time to first running lab (known image) | ≤ 5 min | ~3.5 s browser path (login 1.3 s, editor 0.9 s, start 1 s) + engine boot | ✅ |
| Clicks to first running lab | ≤ 12 | 9 (login, create, open, add node, save, start, console) | ✅ |
| Lab open time, 50 nodes | ≤ 1.5 s | 1.42 s (mock API, Chromium) | ✅ |
| Lab open time, 100 nodes | informational | 1.5 s (Apache real-stack) | ✅ |
| Node start, warm QEMU | ≤ 10 s | < 3 s (KVM, blank qcow2) | ✅ |
| Editor interaction | 60 fps at 100 nodes | 53–54 fps pan headless SwiftShader (61 fps idle baseline); client GPU target 60 fps | ⚠️ |
| API p95 (CRUD) | ≤ 300 ms | 10–12 ms (health/labs, 100 requests) | ✅ |
| Console connect | ≤ 2 s | 0.31 s telnet mock; real VNC connects in ~1–2 s | ✅ |
| Crash-free save | 100% atomic writes | Atomic temp+rename, revision 409 tested | ✅ |
| Critical/high security findings | 0 | No known; auth/CSRF/RBAC/upload tests green | ✅ |
| Backend domain coverage | ≥ 80% | Not measured (no coverage driver in CI) | ⚠️ |

## Journeys exercised

1. Login → Labs → create → open editor → add templates → link → autosave.
2. Images: upload with progress, inspect, rename, map template, delete.
3. Templates: search, preview, import/export, duplicate, edit custom YAML.
4. Start/stop/wipe with job progress and toast notifications.
5. Console: xterm telnet, noVNC, tab persistence, reconnect/backoff.
6. Conflict: forced 409 → reload/overwrite dialog.
7. Large lab: 50/100 nodes, pan/FPS, table fallback.

## Accessibility checks

- All icon-only controls carry `aria-label`/`title`.
- Inputs are wrapped by visible labels; tables use `th` headers.
- Topology has an accessible table fallback (Table button).
- Keyboard: Ctrl/Cmd+S save, Ctrl/Cmd+Z/Y undo/redo, Ctrl/Cmd+C/V copy/paste,
  Delete, arrow nudge, Enter/Space on palette rows, Escape exits presentation.
- Focus-visible outlines are defined globally; contrast uses light text on a
  deep indigo surface with cyan/magenta accents.
- A formal axe/WCAG audit is not yet automated; scheduled with the v1.4
  reliability pass.

## Method

- UI timings: Playwright `Date.now()` around navigation/selector waits.
- FPS: `requestAnimationFrame` counter during a 2 s pan at 100 nodes.
  Headless Chromium uses software rendering; the same page idles at 61 fps,
  so 53–54 fps reflects the software compositor rather than the client GPU.
  The 60 fps target remains the acceptance criterion on hardware.
- API latency: 100 sequential `curl` requests, p50/p95/max.
- Engine start: agent/API timestamps on the host with KVM enabled.
