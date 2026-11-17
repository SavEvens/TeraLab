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
| `TERALAB_VPCS` | `/usr/bin/vpcs` | VPCS binary |
| `TERALAB_DYNAMIPS` | `/usr/bin/dynamips` | Dynamips binary (separate package) |
| `TERALAB_DISABLE_KVM` | `0` | Force TCG (nested-VM debugging only) |
| `TERALAB_IOL_IOURC` | `/etc/teralab/iourc` | Default IOL licence file |
| `TERALAB_VM_USER` | `teralab-vm` | Unprivileged QEMU user |
| `TERALAB_ALLOW_ROOT_VM` | `0` | Explicitly allow root QEMU (dev only) |

## API

All endpoints require the bearer secret and an allowed peer UID.

- `GET /v1/health`
- `GET /v1/system/status`
- `GET /v1/engines`
- `POST /v1/node.start`
- `POST /v1/node.stop`
- `POST /v1/node.wipe`
- `GET /v1/node.status?id=...`
- `POST /v1/console.open` → WebSocket token/URL
- `POST /v1/lab.ensure` → create lab bridges
- `POST /v1/lab.cleanup` → remove lab bridges
- `POST /v1/cleanup.stale` → reconcile orphaned processes, links, cgroups
- `POST /v1/image.inspect` → checksum, format, virtual size, backing file

The console gateway listens on loopback and bridges WebSocket binary frames to
the QEMU serial pty (`/ws`), the loopback VNC server (`/vnc`), or an engine's
loopback TCP console (`/tcp`). Tokens are short-lived and bound to a node.

## Engines

`node.start` takes `engine`: `qemu` (default), `vpcs`, `dynamips`, or `iol`.

- **QEMU:** qcow2/ISO/kernel boot, VNC or serial console.
- **VPCS:** one tap bridged to the lab network; the agent relays raw Ethernet
  frames between the tap and VPCS's loopback UDP sockets, and exposes the VPCS
  CLI on a pty. Works without `/dev/tapX` or root VPCS.
- **Dynamips:** direct-mode argv with tap slots and a loopback TCP console.
  Requires the separate `teralab-dynamips` package and a user-supplied IOS
  image; TeraLab never bundles one.
- **IOL:** user-supplied x86-64 `.bin` plus `iourc`, run as `teralab-vm` with
  a deterministic application id, `LD_LIBRARY_PATH`, and per-node `iourc` /
  `NETMAP` files in the run directory. Ethernet bridging for IOL is not
  implemented; nodes must use `ethernet: 0` and the engine reports that
  limitation.

## Networking

- Bridges are named `tl<labtag>b<net>` and taps `tl<labtag>t<nodetag><if>`,
  deterministic and at most 15 characters.
- Bridges set `group_fwd_mask=0xffff` and `multicast_snooping=0`, so LLDP/LACP
  frames are forwarded.
- `cleanup.stale` reconciles after a crash: it kills orphan engine processes
  from run-directory state, removes their taps/bridges and cgroups, and deletes
  stale `tl*` links. The agent runs the same reconciliation at startup.

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
