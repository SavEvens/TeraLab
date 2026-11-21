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
| `TERALAB_IMAGES_DIR` | `/var/lib/teralab/images` | Primary image/ISO/kernel root |
| `TERALAB_IMAGE_EXTRA_ROOTS` | — | Additional allowed image roots, colon-separated absolute paths (e.g. `/opt/unetlab/addons`) |
| `TERALAB_CGROUP_ROOT` | `/sys/fs/cgroup/teralab.slice` | Per-node cgroup parent |
| `TERALAB_DISABLE_CGROUPS` | `0` | Disable cgroup limits (dev only) |
| `TERALAB_QEMU` | `/usr/bin/qemu-system-x86_64` | QEMU binary |
| `TERALAB_QEMU_IMG` | `/usr/bin/qemu-img` | qemu-img binary |
| `TERALAB_VPCS` | `/usr/bin/vpcs` | VPCS binary |
| `TERALAB_DYNAMIPS` | `/usr/bin/dynamips` | Dynamips binary (separate package) |
| `TERALAB_DISABLE_KVM` | `0` | Force TCG (nested-VM debugging only) |
| `TERALAB_NETWORK_BACKEND` | `auto` | `linux`, `ovs`, or `auto` |
| `TERALAB_BRIDGE_GROUP_FWD_MASK` | `0x5` | Per-port `group_fwd_mask` (STP + LACP); `0` disables |
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
- `POST /v1/cleanup.stale` → reconcile orphaned processes, links, cgroups,
  run directories (optional body `{"lab_ids": ["<uuid>", ...]}`)
- `POST /v1/image.inspect` → checksum, format, virtual size, backing file
- `GET /v1/docker/images` → locally pulled container images (reference, id, size, created)

The console gateway listens on loopback and bridges WebSocket binary frames to
the QEMU serial pty (`/ws`), the loopback VNC server (`/vnc`), or an engine's
loopback TCP console (`/tcp`). Tokens are short-lived and bound to a node.

Node status carries `ready` next to `state`: it is true once the engine console
can be driven (a prompt was seen on the IOL or VPCS pty, or a QEMU guest wrote
to its serial console) and false again when the node stops. The probe is
best-effort and never delays a start; a guest that never shows a prompt is left
`ready: false`.

## Engines

`node.start` takes `engine`: `qemu` (default), `vpcs`, `dynamips`, or `iol`.

- **QEMU:** qcow2/ISO/kernel boot, VNC or serial console. The node spec selects
  `arch`, `machine`, `nic`, `cpu_model`, `accel` and `disk_interface`; the agent
  falls back to `x86_64`/`pc`/`e1000`/`virtio`, and to the detected KVM
  accelerator, only when a field is empty. `disks` optionally adds extra disks
  as `[{slot, image | size_gb}]`: slots `virtiob`–`virtiod`, `image` an absolute
  agent-resolved qcow2 path, or `size_gb` 1–100 for a per-node blank disk.
  Extras attach as virtio-blk after the primary in slot order; absent means no
  extra disks. `nic` accepts `virtio`, `vmxnet3`, `e1000` and `rtl8139`; the
  legacy lab values `virtio-net-pci` and `i82559er` normalise to `virtio` and
  `e1000` so stored labs keep starting. A legacy
  `qemu_options` string is
  parsed before validation: known flags fill empty fields, engine-owned flags
  (console, boot order, memory, image and kernel paths) are dropped, and only a
  small allow-listed set of extras (`-no-acpi`, `-no-hpet`, `-no-shutdown`,
  `-smbios`, `-global`, `-icount`, `-realtime mlock=off`, `-usbdevice tablet`,
  and `-boot` when the engine does not choose an order) reaches QEMU. The
  effective command line is logged once per start.
- **VPCS:** one tap bridged to the lab network; the agent relays raw Ethernet
  frames between the tap and VPCS's loopback UDP sockets, and exposes the VPCS
  CLI on a pty. Works without `/dev/tapX` or root VPCS.
- **Dynamips:** direct-mode argv with tap slots and a loopback TCP console.
  Requires the separate `teralab-dynamips` package and a user-supplied IOS
  image; TeraLab never bundles one.
- **IOL:** user-supplied x86-64 `.bin` plus `iourc`, run as `teralab-vm` with
  a deterministic application id, `LD_LIBRARY_PATH`, and per-node `iourc` /
  `NETMAP` files. Every IOL Ethernet interface gets a tap on the lab bridge and a
  Unix-datagram netio relay; `NETMAP` pairs each IOL port with the relay, and
  frames are translated between the 8-byte IOU header and the tap.
  For the layer-2 image the agent inserts `vtp mode transparent` before the
  first `vlan` definition of the startup configuration, because IOS otherwise
  keeps VLANs in `vlan.dat` and ignores them. After boot the agent also replays
  `no shutdown` on interfaces that carry an IPv6 address and no IPv4 address,
  which IOS would otherwise leave administratively down; an interface with an
  explicit `shutdown` is left alone, and a configuration with `enable secret`
  or `enable password` skips the replay (it cannot reach configuration mode
  without credentials) with a warning. Both fixups are idempotent, bounded, and
  logged; they never fail a node start.

## Networking

- Bridges are named `tl<labtag>b<net>` and taps `tl<labtag>t<nodetag><if>`,
  deterministic and at most 15 characters.
- Backend `auto` prefers OVS when `ovs-vsctl` is installed, otherwise the
  Linux bridge.
- The Linux bridge sets the highest accepted `group_fwd_mask` (LLDP and most
  groups) and disables multicast snooping. Every bridge port also gets a
  per-port `group_fwd_mask` of `0x5` (STP + LACP); per-port masks only reject
  MAC pause frames, so LACP EtherChannels form without a patched kernel.
  `TERALAB_BRIDGE_GROUP_FWD_MASK` overrides the value and `0` disables it (use
  static EtherChannel); a kernel without the attribute logs one warning per
  bridge and the lab still starts. OVS is configured with
  `other_config:forward-bpdu=true` and forwards LLDP/LACP.
- OVS taps are attached with `ovs-vsctl add-port`; the Linux backend uses the
  bridge master.
- `cleanup.stale` reconciles after a crash: it kills orphan engine processes
  from run-directory state, removes their taps/bridges and cgroups, and deletes
  stale `tl*` links. The agent runs the same reconciliation at startup.
- The optional `lab_ids` body is the keep-set of labs that still exist: every
  directory directly under `TERALAB_RUN_DIR` that is not in the keep-set and
  holds no live node is removed, which reclaims run directories left behind by
  deleted labs. A missing body means the keep-set is unknown, so lab
  directories are left alone; an explicit empty list prunes every lab
  directory that has no live node. The startup pass never prunes lab
  directories because the agent does not know the labs yet.

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

- Image paths must resolve under `TERALAB_IMAGES_DIR` or one of
  `TERALAB_IMAGE_EXTRA_ROOTS`. Extra roots only widen acceptance to the
  directories an administrator configured; nothing is scanned or served from
  them automatically. Paths are canonicalized with `EvalSymlinks` (all symlinks
  resolved), and the resolved target must land under the primary store or an
  allowed extra root. A symlink inside the store that aims anywhere else is
  rejected; uploads continue to target the primary store only.
- QEMU runs as `teralab-vm`, not root.
- Tap names are generated from the node ID and are always `tl`-prefixed.
- QEMU is launched with an argv array; there is no shell.
