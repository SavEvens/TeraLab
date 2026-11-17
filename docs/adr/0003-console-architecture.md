<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# ADR-0003: WebSocket console gateway

- Status: Accepted
- Date: 2026-11-17
- Deciders: TeraLab owner, engineering

## Context

UNetLab exposes a raw telnet port per node and uses external desktop clients
(PuTTY, VNC viewers) for consoles. The reference deployment provides browser
consoles. TeraLab needs telnet and VNC consoles in the browser without
exposing unauthenticated TCP ports or copying reference implementation code.

## Decision

Use a dedicated console gateway that bridges authenticated WebSocket sessions
to engine consoles:

- Telnet/serial: agent allocates a pty attached to the engine serial port;
  the gateway bridges pty ↔ WebSocket binary frames.
- VNC: QEMU listens on `127.0.0.1:<display>`; the gateway proxies WebSocket ↔
  TCP; the browser uses noVNC.
- Access: `POST /api/v2/console.open` returns a single-use, short-lived token
  bound to user, session, and node. The gateway validates the session cookie
  and token, checks the origin, and audits open/close.
- Default: no public raw telnet/VNC ports. Loopback compatibility ports are
  opt-in and disabled by default.
- Browser: xterm.js for telnet/serial, noVNC for VNC. Sessions survive route
  changes via a tab manager and client-side keep-alive.

## Consequences

Positive:
- No unauthenticated console ports on the network.
- One authorization path for all console types.
- Works through Apache/WSS and ordinary HTTPS port 443.
- Console tabs can be reconnected and audited.

Negative:
- Adds a long-lived service and WebSocket proxy to operate.
- Needs careful backpressure, resize, and idle-timeout handling.
- noVNC is MPL-2.0; notices must be retained.

## Alternatives considered

- **Guacamole/guacd:** mature, but adds a heavier Java/Guacamole stack and
  more operational surface; may be revisited for RDP.
- **websockify:** simple, but a separate Python service with its own licence
  and lifecycle; we prefer one Go gateway.
- **Raw telnet ports only:** simpler, but poor UX and unacceptable exposure.
