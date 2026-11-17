<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# teralab-agent

The privileged TeraLab agent owns QEMU processes, tap devices, run
directories, and the WebSocket console gateway. The web tier talks to it over
a Unix socket; it never runs shell command strings.

## Build

```sh
go build ./cmd/teralab-agent
go vet ./...
go test ./...
```

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `TERALAB_AGENT_SOCKET` | `/run/teralab/agent.sock` | Unix socket |
| `TERALAB_AGENT_CONSOLE` | `127.0.0.1:8091` | Console WebSocket listener |
| `TERALAB_AGENT_SECRET_FILE` | `/etc/teralab/agent.secret` | Bearer secret file |
| `TERALAB_AGENT_SECRET` | — | Secret override (tests) |
| `TERALAB_AGENT_ALLOWED_UIDS` | `33` | Allowed peer UIDs, comma-separated |
| `TERALAB_AGENT_SOCKET_GROUP` | `www-data` | Socket group |
| `TERALAB_RUN_DIR` | `/var/lib/teralab/run` | Per-node run directories |
| `TERALAB_IMAGES_DIR` | `/var/lib/teralab/images` | Allowed image/ISO/kernel root |
| `TERALAB_CGROUP_ROOT` | `/sys/fs/cgroup/teralab.slice` | Per-node cgroup parent |
| `TERALAB_DISABLE_CGROUPS` | `0` | Disable cgroup limits (dev only) |
| `TERALAB_QEMU` | `/usr/bin/qemu-system-x86_64` | QEMU binary |
| `TERALAB_QEMU_IMG` | `/usr/bin/qemu-img` | qemu-img binary |
| `TERALAB_VM_USER` | `teralab-vm` | Unprivileged QEMU user |
| `TERALAB_ALLOW_ROOT_VM` | `0` | Explicitly allow root QEMU (dev only) |

## API

All endpoints require the bearer secret and an allowed peer UID.

- `GET /v1/health`
- `GET /v1/system/status`
- `POST /v1/node.start`
- `POST /v1/node.stop`
- `POST /v1/node.wipe`
- `GET /v1/node.status?id=...`
- `POST /v1/console.open` → WebSocket token/URL

The console gateway listens on loopback and bridges WebSocket binary frames to
the QEMU serial pty (`/ws`) or to the loopback VNC server (`/vnc`). Tokens are
short-lived and bound to a node.

`node.start` accepts a qcow2 `image`, an `iso`, or direct kernel boot via
`kernel` / `initrd` / `append`. `console` is `telnet` (default) or `vnc`.
Host memory and CPU are enforced through a per-node cgroup under
`TERALAB_CGROUP_ROOT`.

For VNC nodes the agent:

- reserves a free display in the QEMU range and binds only to
  `127.0.0.1:5900+display`;
- enables the QEMU VNC password and sets a random per-node password through
  the private monitor socket;
- returns the port and password only from the authenticated, token-gated
  `console.open` call.

VNC access is therefore loopback-only and gated behind the agent token; the
web tier exposes `/ws/vnc` as a same-origin WebSocket proxy.

## Safety

- Image paths must resolve under `TERALAB_IMAGES_DIR`.
- QEMU runs as `teralab-vm`, not root.
- Tap names are generated from the node ID and are always `tl`-prefixed.
- QEMU is launched with an argv array; there is no shell.
