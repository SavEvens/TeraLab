<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Installing TeraLab v1.6-dev

TeraLab targets Debian 13 with PHP 8.4 and QEMU 10. QEMU, Dynamips, IOL, and
the kernel remain separate components with their own licences.

## Requirements

- Debian 13 (amd64)
- PHP 8.4+ with `pdo_sqlite`, `mbstring`, `dom`, `json`
- `qemu-system-x86_64`, `qemu-img`
- `composer` (only when `api/vendor` is absent)
- Go 1.27+ (only when no prebuilt agent is supplied)
- Node 20+ and npm (to build the Vue UI; prebuilt `ui/dist` is used when present)
- Apache 2.4 with `mod_proxy`, `mod_proxy_wstunnel`, and `mod_rewrite`
- Optional: `openvswitch-switch` for LACP forwarding
- Optional: Docker Engine (`docker` CLI and daemon) for `kind: docker` nodes
- Optional: KSM for memory deduplication (`--ksm`); needs kernel 6.4+ and
  systemd 254+ — see
  [Kernel same-page merging](#kernel-same-page-merging-ksm-optional)
- Optional, for Turnstile: outbound HTTPS (443) from the web tier and from the
  visitor's browser to `challenges.cloudflare.com`, plus `allow_url_fopen=On`
  and the `openssl` extension in the PHP used by Apache

PHP upload limits must exceed your largest image. The installer writes
`/etc/php/8.4/{apache2,fpm,cli}/conf.d/99-teralab-uploads.ini` with
`upload_max_filesize = 4G` and `post_max_size = 4G`. Without this, PHP silently
drops the multipart body and the API reports that no file was received.
- Optional: `teralab-dynamips` for the Dynamips engine

## Install

```sh
sudo apt-get install apache2 php8.4-cli php8.4-sqlite3 php8.4-mbstring \
    php8.4-xml qemu-system-x86 qemu-utils composer rsync
sudo packaging/teralab/install.sh
```

The installer:

1. verifies PHP/QEMU and installs Composer dependencies once;
2. creates the `teralab-vm` system user and adds it to `kvm`;
3. copies the tree to `/opt/teralab` and builds `/opt/teralab/bin/teralab-agent`;
4. creates `/etc/teralab/agent.secret` if missing (never overwrites it);
5. creates `/var/lib/teralab/{labs,images,run}` and `/run/teralab`;
6. builds the Vue UI and installs it at `html/ui`;
7. links `/opt/unetlab -> /opt/teralab` for legacy paths;
8. writes the Apache configuration and enables the agent unit and, when
   systemd is available, the lab bridge firewall unit;
9. with `--ksm`, also installs and starts the KSM unit and the two memory-merge
   drop-ins (see below). A default install does not.

Options: `--root`, `--data`, `--source`, `--agent-binary`, `--no-systemd`,
`--ksm`.

## Apache

```sh
sudo a2enmod proxy proxy_wstunnel rewrite
sudo a2enconf teralab
sudo systemctl reload apache2
```

`/ws/console`, `/ws/vnc`, and `/ws/tcp` proxy to the loopback agent gateway.
The agent is the only privileged process; the web tier never starts QEMU.

## TLS and cookies

Session cookies use the `Secure` attribute automatically when the request
arrives over HTTPS (direct TLS, `X-Forwarded-Proto: https`, or port 443). On a
plain-HTTP install the cookie is not marked Secure, so first-run login works
without extra configuration.

For a TLS-terminating reverse proxy, forward `X-Forwarded-Proto: https`, or set
`TERALAB_COOKIE_SECURE=1` (environment) / `'cookie_secure' => true` in
`/etc/teralab/config.php`. Do not expose the agent or the API without TLS in
production.

### Client address behind a proxy

TeraLab records the visitor's address in audit entries and uses it for the login
and demo rate limits and for Turnstile verification. By default it uses the
direct peer (`REMOTE_ADDR`) and ignores `X-Forwarded-For`.

If Apache or a load balancer sits in front of the web tier, list the proxies so
the real visitor is seen instead of the proxy:

```sh
# comma-separated IPv4/IPv6 addresses or CIDRs, default empty
TERALAB_TRUSTED_PROXIES=127.0.0.1/32,::1,10.20.0.0/16
```

or in `/etc/teralab/config.php`:

```php
'trusted_proxies' => ['127.0.0.1/32', '::1', '10.20.0.0/16'],
```

The header is only consulted when the direct peer matches the list, and the
address is then the first untrusted hop walking `X-Forwarded-For` from the
right. Malformed chains fall back to the direct peer. Keep the list narrow:
every listed range can choose the address that rate limiting and audit records
see, and `0.0.0.0/0` would trust client-supplied headers.

## First run

1. Open `http://<host>/ui/` or call `GET /api/v2/health`.
2. Create the administrator once with `POST /api/v2/setup`.
3. Log in with the session cookie and use `/api/v2/labs` to create/start labs.

4. Open the UI at `http://<host>/ui/`.
5. Upload qcow2/ISO/IOL/Dynamips files on **Appliances → Images**, then pick
   them in the node inspector. Custom YAML templates live in
   `/etc/teralab/templates`.

The navigation rail has three destinations: **Labs**, **Appliances**
(Templates and Images tabs) and **System** (including About). The running
version is shown in the rail footer.

Import a lab from **Labs → Import** (or drag-drop) as a `.unl`, `.yaml`, or
`.yml` file, or use **Create project → From YAML** to paste YAML, validate it,
and create the lab in an optional folder. Labs created from YAML show a
**YAML** source panel in the editor; applying an edited source requires every
node to be stopped. See [`LAB-YAML.md`](LAB-YAML.md) for the format.

### Users

The administrator manages accounts from **System → Users**: add a user, choose
a role (`admin`, `editor`, or `user`), and optionally set an email, display
name, and expiry. Leave the password empty to have TeraLab generate one; a
generated password is shown once, when the account is created or its password
is reset, and cannot be recovered later. An admin-typed password is never
displayed. `editor` accounts can build and run labs, `user` accounts have
read-only lab access, and `admin` accounts also manage users and system
settings.

Deleting a user keeps that user's labs by default; they become ownerless and
admin-only. Choose “also delete labs” to move them to the 30-day lab trash
instead; with that option, deletion is refused while any owned lab has running
nodes. Every user can change their own password from the top bar.

There are no default accounts, no telemetry, and no `setcap` usage.

### Demo entry

The demo tier is off by default. Turn it on in **System → Demo** (or with
`PUT /api/v2/system/settings` and `demo_enabled: 1`); while it is off,
`POST /api/v2/auth/guest` answers 404 and the sign-in page shows no **Enter demo**
button. There is no registration form: a visitor who enters the demo gets a
throwaway `editor` account with no email, name, or password and a slot against
`demo_max_guests`. The account expires after `demo_guest_ttl_hours`, and the
session is clamped so it never outlives the account: it lasts the smaller of
`session_ttl` and that TTL (default 1 hour). It cannot be renewed or re-opened
with credentials, so that is the practical demo length; the slot frees when the
session lapses. When the cap is reached the API answers 403 `The demo is full.`
The cap is advisory: the count and the account creation are not atomic, so a
burst of simultaneous entries can briefly exceed it by a few, but steady-state
usage stays within it.

Guest entries are limited to 20 per hour per address (429 `Too many demo
entries from this network. Try again later.`); each entry is charged before the
guest is created. Captcha attempts are limited separately, to 20 per 15 minutes
per address, and are charged before Cloudflare is contacted, including requests
that arrive without a token (429 `Too many captcha attempts. Try again
later.`).

`demo_landing` chooses what the signed-out page leads with: `login` keeps the
account form primary, `demo` highlights the demo panel. The account form is
always available in both modes.

The per-lab demo limits are enforced for guests. A guest lab is limited to
`demo_max_nodes_per_lab` nodes (default 5), every engine node must use a
template from `demo_allowed_templates` (default `vpcs`) that is installed on the
host, and the template's kind must match the node type (a `vpcs` template
cannot back a `docker` node). Each node may request at most
`demo_max_ram_per_node` MB of RAM (default 1024). On a guest docker node the
`image` must be empty or exactly the template's docker image; any other
reference returns 422
`The demo does not allow custom container images; use template "frr-bgp".`
Non-guests keep their override. Text and picture annotations are exempt from the
template and RAM checks but still count toward the node cap. Create, save, import, apply, duplicate, snapshot restore, and
start enforce the caps; starting a lab re-checks its current topology, so
lowering a cap also stops older over-cap labs from starting. Duplicating an
over-cap lab or restoring an over-cap snapshot answers 422 and leaves the new
copy unwritten or the lab unchanged. Importing a `.unl` file clamps an over-cap RAM value to the
cap and returns a `demo_limited` warning instead of failing the import;
node-count and template violations still reject the import. Administrators and
non-guest `editor` accounts are exempt.

Guests cannot change the shared catalogues. Template mutations return 403
`The demo does not allow template changes.` and image mutations return 403
`The demo does not allow image changes.`; template and image reads stay
available. This stops a guest shadowing an allowlisted builtin template or
deleting an image other labs use.

In the Add-node picker a guest sees allowed templates normally and the rest of
the catalogue in a disabled **Available on the full platform** group with an
upsell hint. An empty `demo_allowed_templates` therefore disables the whole
catalogue, matching the API, which rejects every templated node. See the API
reference's *Per-lab demo limits* for the exact messages.

The idle-wipe/sweep settings (`demo_guest_idle_hours`,
`demo_idle_wipe_minutes`, `demo_sweep_interval`) are enforced by the demo
sweeper: expired or idle guests and idle guest labs are reclaimed lazily when
the lab list or a lab or node start runs a due sweep, at most once per
`demo_sweep_interval` seconds. Admin and non-guest editor labs are never
touched. See the API reference's *Demo sweeper and capacity*. The caps bound
lab size, not behaviour: a guest can still use the host's CPU and disk within
them, so only expose the demo to visitors you would trust with a constrained
`editor` account.

**Turnstile.** `turnstile_enabled` adds a Cloudflare Turnstile check to demo
entry *and* to password sign-in, on both the v2 and the legacy v1 login routes,
so it cannot be bypassed through the older endpoint. The legacy HTML pages have
no Turnstile widget, so while it is enabled their sign-in is refused with 422;
use the modern UI at `/ui/`. Enabling Turnstile requires:

- Outbound HTTPS from the web tier to `challenges.cloudflare.com` for the
  server-side `siteverify` call; this is the product's only outbound request,
  it has a 5 s timeout, and it fails closed.
- Outbound HTTPS from the visitor's browser to the same host for the widget
  script.
- `allow_url_fopen=On` and the `openssl` extension in the PHP that serves the
  API; `siteverify` uses PHP's HTTPS stream wrapper, so no `curl` extension is
  involved.

Check egress before enabling it:

```sh
curl -fsS https://challenges.cloudflare.com/turnstile/v0/api.js >/dev/null && echo ok
```

If the host cannot reach Cloudflare, every sign-in and demo entry is refused
with 422 `Captcha verification is unavailable; try again.`; the web UI cannot
be used to undo this, so disable Turnstile from the settings table with the
`sqlite3` CLI installed:

```sh
sudo sqlite3 /var/lib/teralab/database.sdb \
  "UPDATE settings SET value='0' WHERE key='turnstile_enabled';"
```

The shipped `turnstile_site_key` (`3x00000000000000000000FF`) and
`turnstile_secret_key` (`1x0000000000000000000000000000000AA`) are Cloudflare's
public *testing* credentials. They always validate and give no bot protection.
Create your own widget keys and replace both before the demo is reachable from
the internet. Never commit real keys; the secret is never returned by the API.
Clearing either key while `turnstile_enabled` is `1` blocks both demo entry and
sign-in: without a site key the widget cannot mount, and without a secret every
token is rejected. Turn the setting off instead of clearing only the keys.

Behind a reverse proxy, also set `TERALAB_TRUSTED_PROXIES` (see
[Client address behind a proxy](#client-address-behind-a-proxy)); otherwise the
rate limits, audit records, and Turnstile `remoteip` all see the proxy address
and every visitor shares one rate-limit bucket.

## Engines

- QEMU: distribution package, unprivileged `teralab-vm`, cgroup limits.
- VPCS: `apt-get install vpcs`.
- IOL: supply an x86-64 `.bin` and `iourc`; set `TERALAB_IOL_IOURC` if not
  `/etc/teralab/iourc`.
- Dynamips: build and install the separate package:
  `sudo apt-get install cmake libelf-dev libpcap-dev && packaging/dynamips/build.sh`
  then `sudo dpkg -i dist/teralab-dynamips_*.deb`. A licensed IOS image is
  required and is never bundled.
- Docker: `docker` CLI and daemon on the agent host (`TERALAB_DOCKER`, default
  `/usr/bin/docker`); containers are unprivileged and connect only through lab
  bridges. See [Docker nodes](#docker-nodes).

### EtherChannel, VLANs and guest NICs

- **LACP works on the default Linux bridge backend.** TeraLab enables the
  reserved group per port (`/sys/class/net/<bridge>/brif/<tap>/group_fwd_mask`,
  default `0x5` = STP + LACP) because the bridge-level mask cannot allow it.
  This needs Linux ≥ 4.15. Set `TERALAB_BRIDGE_GROUP_FWD_MASK=0` to restore
  strict 802.1D filtering and use static `channel-group … mode on`, or install
  OVS.
- **QEMU parameters come from the template or the node.** Templates may set
  `qemu_nic`, `qemu_machine`, `qemu_arch`, `qemu_cpu`, `qemu_accel`,
  `qemu_disk_interface` and `qemu_options` (validated extras); a node overrides
  them. `vmxnet3` is what the Catalyst 8000V template uses (e1000 is not a
  supported C8000V vNIC). With nothing configured, `e1000` remains the fallback
  so existing guests keep their model; PAN-OS stays explicitly on `e1000`.
  Legacy machine aliases from older templates and `qemu_options`
  (`pc-1.0`, `q35-1.0`, and pre-2.0 `pc-i440fx-*`/`pc-q35-*`) are canonicalised
  to `pc`/`q35`, because current QEMU no longer ships those machine types.

### Multi-disk QEMU nodes

A QEMU template may declare up to three extra disks with `options.qemu_disks`.
The primary disk stays the node image (`virtioa`); the block only adds
`virtiob`, `virtioc` and `virtiod`:

```yaml
schema: 1
id: catmanager-2disk
name: Catalyst SD-WAN Manager (2 disks)
kind: qemu
license: AGPL-3.0-or-later
defaults: { cpu: 4, ram: 32768, ethernet: 2, console: telnet }
options:
  qemu_disks:
    - slot: virtiob
      image: catmanager-20.16.1-virtiob.qcow2   # image-store reference
    - slot: virtioc
      size_gb: 40                               # generated blank disk
```

- The list holds at most three entries. Slots must be unique and one of
  `virtiob`, `virtioc`, `virtiod`; `virtioa` cannot be declared here because it
  remains the node's image.
- Each entry sets exactly one of `image` or `size_gb`; unknown keys, duplicate
  slots, and malformed blocks are rejected with 422 when a custom template is
  saved or imported. Discovery skips a malformed template instead: it is not
  listed, and for a custom template `GET /api/v2/templates/{id}` returns 404.
  A malformed builtin still fails when loaded by id.
- `image` is an image-store reference (the file name shown on the Appliances → Images tab),
  not a path: a single portable name, and the stored file must be a qcow2. The
  reference is resolved when the node starts, so a template may be imported
  before its images. A reference that is not in the store, or whose stored file
  is missing, fails the node start with 422 naming the template, slot and
  reference (the lab start prefixes the node name). Each start gives the node
  its own overlay on top of the stored image, so the stored image is never
  modified. As with the primary disk, the overlay is recreated on every start,
  so guest writes to an image-backed extra disk do not survive stop → start.
  Use `size_gb` for data that must persist across stop → start.
- `size_gb` (an integer from 1 to 100) creates a sparse qcow2 in the node's run
  directory on first start. It is created once and reused, so its contents
  survive stop → start; `wipe` removes it with the rest of the node state. The
  file lives under `/var/lib/teralab/run`, not in the image store, so it does
  not count against the image quota.
- Disk files are create-only. Changing `size_gb`, or switching a slot between
  `image` and `size_gb`, does not migrate or resize the file already in the
  node's run directory. Wipe the node after changing a template's disk layout
  so the node starts from the new definition.
- Extra disks attach as virtio-blk after the primary disk, in slot order
  (`virtiob`, `virtioc`, `virtiod`) regardless of the order the template lists
  them. Boot order is unchanged: the primary disk remains the boot disk.
- A template without `qemu_disks` behaves exactly as before. This is a
  template-level option only: it is never stored in the lab (`.unl`) format, so
  the format, EVE-NG round-trip, and per-node image field are unchanged.

The Catalyst SD-WAN Manager image archive ships two disks
(`…-virtioa.qcow2` and `…-virtiob.qcow2`). Upload both to the image store, set
the node image to the virtioa file, and declare virtiob in the template's
`qemu_disks` as shown. TeraLab references the images you supply; it never
bundles or redistributes vendor images.

### Docker nodes

`kind: docker` nodes run containers through the agent. They start, stop, wipe
and open a console when the `docker` CLI and daemon are available on the agent
host. The `.unl` format is unchanged: an EVE-NG docker node imports with its
stored `image` (a container reference) and starts.

In the editor:

- The add-node picker lists every `kind: docker` template in **Ready to
  deploy**, because a container template needs no image-store mapping. Its tile
  reads `docker · <options.docker.image>`.
- Adding such a template sets the node image to the template's container
  reference.
- The Inspector shows Type `docker` and a free-text **Image** field for the
  container reference. Images already pulled on the agent host are offered as
  suggestions while typing (`GET /api/v2/docker/images`); an empty field keeps
  using the template default. If Docker is unavailable the field still works,
  simply without suggestions.
- ISO, NIC driver, serial ports, startup config and the VNC console option are
  hidden for docker nodes; containers use none of them and the console is
  telnet only.
- Switching a node's type to or from docker clears the image field, and
  applying a docker template fills it from `options.docker.image`, so a
  container reference never ends up in a store-image field.

Template example (`/etc/teralab/templates/frr.yaml`):

```yaml
schema: 1
id: frr
name: FRR Router
kind: docker
license: AGPL-3.0-or-later
icon: Router.png
defaults: { cpu: 1, ram: 256, ethernet: 3, console: telnet }
options:
  description: Free Range Routing appliance container.
  docker:
    image: quay.io/frrouting/frr:10.7.1  # node image may override
    console_command: /usr/bin/vtysh
    cap_add: [NET_ADMIN, SYS_ADMIN]
    daemons: [zebra, ospfd, bgpd]
    config_path: /etc/frr/frr.conf
    pull: true
```

`daemons` and `config_path` tell the agent what to write into the container at
start. Both are optional and come from the node template (the Inspector has no
per-node field for them). A template that sets neither is untouched: the
generic `docker` template stays a plain shell container.

- **`daemons`** selects routing daemons from a fixed allowlist. At every start
  the agent renders a complete `/etc/frr/daemons` file — each allowlisted
  daemon set to `yes` when selected and `no` otherwise, plus
  `vtysh_enable=yes` and each allowlisted daemon's stock `<daemon>_options=`
  line — and copies it into the container before `docker start`. The rendering
  depends only on the template, not on the image's own file or the order of
  the list. Upstream FRR always starts `watchfrr`, `mgmtd`, `zebra` and
  `staticd`, and the stock image ships every other selectable daemon
  disabled: selecting `zebra` is allowed for readability but not required, and
  an empty list injects no file at all (image defaults apply). Every
  allowlisted daemon's `_options` line is emitted, plus the stock
  `mgmtd_options` and `staticd_options` of the always-started daemons.
- **`config_path`** is the absolute container path that receives the node's
  startup configuration. When the lab or node has a config, it is copied there
  before every start; after start the agent runs a best-effort
  `chown frr:frr <config_path>` so `write memory` in vtysh can rewrite the
  file. Because both steps run on every start, a config edit applies on
  stop → start; `wipe` removes the container and its run directory, so the
  next start begins from the template image again. A startup config larger
  than 64 KiB is rejected by the node-start endpoint and by the agent.
  Consequently, changes saved in the container with `write memory` are
  overwritten on the next stop → start (the lab/template config wins); persist
  changes in the lab's node config instead.

The `daemons` file is always written to `/etc/frr/daemons`; a template cannot
redirect it.

Three built-in templates use `quay.io/frrouting/frr:10.7.1`, differing in
daemon set and RAM: `frr` (zebra, ospfd, bgpd; 256 MB), `frr-ospf` (zebra,
ospfd; 128 MB) and `frr-bgp` (zebra, bgpd; 128 MB). All three set
`config_path: /etc/frr/frr.conf` and grant `NET_ADMIN` and `SYS_ADMIN` (zebra
requires `SYS_ADMIN` in the published image). FRRouting is GPLv2 upstream
software: TeraLab references the published container image and never bundles
or links FRR.

`options.docker` is strict: unknown keys are rejected.

| Key | Type | Meaning |
|---|---|---|
| `image` | string, required | Container reference such as `quay.io/frrouting/frr:10.7.1`; digest references (`@sha256:…`) are accepted. A node's `image` attribute overrides it. It is not an image-store file. |
| `console_command` | absolute path, optional | Command for the console tab; default `/bin/sh`. |
| `cap_add` | list, optional | Only `NET_ADMIN`, `NET_RAW` or `SYS_ADMIN`, at most 8 entries. `SYS_ADMIN` is opt-in and weakens isolation inside the container. |
| `daemons` | list, optional | FRR daemons to enable: `zebra`, `bgpd`, `ospfd`, `ospf6d`, `ripd`, `ripngd`, `isisd`, `pimd`, `pim6d`, `ldpd`, `nhrpd`, `eigrpd`, `babeld`, `sharpd`, `pbrd`, `bfdd`, `fabricd`, `vrrpd`, `pathd`. At most 12 entries, no duplicates; every start rewrites `/etc/frr/daemons` as described above. |
| `config_path` | absolute path, optional | Where the node startup config is copied before each start, e.g. `/etc/frr/frr.conf`. Must match `^/[A-Za-z0-9_./-]{1,200}$` with no `..` segment. |
| `pull` | bool, optional | Default `true`. When `false`, a missing local image fails with a message telling the operator to `docker pull` it. |

Templates saved by earlier TeraLab versions with `kind: docker` and no
`options.docker.image` are ignored by the current validator; add an `image` to
such a template to keep using it. The old builtin placeholder was the only
shipped case and has been updated.

Containers are created with `--network=none`, `--cap-drop=ALL` followed by a
fixed baseline of Docker's default capabilities, plus only the extras a
template lists in `cap_add`; `SYS_ADMIN` is not in the baseline and is granted
only when a template asks for it (FRR's zebra requires it). They also run with
`--security-opt=no-new-privileges`, Docker's default seccomp and AppArmor
profiles, `--pids-limit=1024`, and `--memory`/`--cpus` from the node. They are
never privileged, have no host mounts and use no Docker-managed network. Each
connected interface is a veth pair created by the agent and attached to the
node's lab bridge with a deterministic MAC; only lab bridges provide
connectivity. The container is created with an interactive TTY (`-i -t`), so an
image whose default command is a shell stays running; an image whose default
command exits immediately fails the start with its exit code and last log
lines.

An image that expects a capability outside this baseline must have it added to
the template's `cap_add` (the allowlist is bounded); `SYS_ADMIN` weakens
isolation inside the container even though the container remains namespaced.

Template editors can author templates and call the node-start endpoint, so they
are trusted to grant the allowlisted container capabilities (`NET_ADMIN`,
`NET_RAW`, `SYS_ADMIN`). Capability grants are not admin-gated; use `SYS_ADMIN`
only for images that genuinely need it, such as FRR zebra.

A missing image is pulled during start, bounded to 45 s (the API call times
out at 60 s). The agent runs `docker` as root, but its service has no access to
a user Docker config (`ProtectHome=yes` masks `/root`), so pulls are
unauthenticated. Pre-pull private images on the host with
`sudo docker pull <reference>` before starting the node; the same command is
the reliable option for large images on slow registries or offline hosts. The
built-in `docker` template defaults to `alpine:3.20`; override the image per
node, or copy the template and pin your own reference.

`Stop` preserves the container, so filesystem state survives stop → start
(unless the template image or owning lab changed, in which case the container
is replaced). `Wipe` removes the container, its veths and its run directory.
Containers are labelled `teralab.node` and `teralab.lab`; after an agent
restart, reconcile re-registers preserved containers and removes only labelled
orphans — unlabelled containers on the host are never touched.

The systemd unit adds `CAP_SYS_ADMIN` to the agent's existing `CAP_NET_ADMIN`
so the agent can enter the container network namespace and configure the veth
peer there (`nsenter`/`setns`); moving and attaching the link itself needs
`CAP_NET_ADMIN`. The containers themselves stay unprivileged.

TeraLab prefers Docker's sandbox key for both entering the container namespace
and moving the veth peer, so hosts with `kernel.yama.ptrace_scope=1` work
normally. If Docker does not expose a sandbox key, or exposes one outside
`/var/run/docker/netns/` or `/run/docker/netns/`, the engine falls back to the
container init PID; that path needs ptrace access to `/proc/<pid>/ns/net`, so
`kernel.yama.ptrace_scope=1` blocks interface attachment unless the operator
sets it to 0 (runtime `sysctl -w kernel.yama.ptrace_scope=0`, or a persistent
sysctl drop-in) or grants the agent `CAP_SYS_PTRACE` as a local exception.
Lowering yama weakens a host hardening setting. System → Engines reports Docker
availability and version.

## Host firewall (Docker hosts)

On hosts that run Docker, the `br_netfilter` module is loaded and
`net.bridge.bridge-nf-call-iptables=1` is set, so bridged IPv4 frames are
filtered by iptables. Docker's default `FORWARD` policy is `DROP` with accept
rules only for `docker0`. As a result, traffic between TeraLab lab bridges is
dropped even though ARP (0x0806) and CDP (LLC/SNAP) pass because they are not
IPv4. The symptom is confusing: interfaces stay `up/up`, LIN switches and CDP
neighbours appear, but ping and other IPv4 traffic between lab nodes fail.
This applies to every lab bridge, including bridges that carry docker-node
interfaces.

The installer enables `teralab-firewall.service`, which inserts one rule once:

```sh
iptables -I FORWARD 1 -i tl+ -o tl+ -j ACCEPT
ip6tables -I FORWARD 1 -i tl+ -o tl+ -j ACCEPT   # when ip6tables is available
```

The script applies the IPv4 rule always and the IPv6 one when `ip6tables`
exists, best effort. `tl+` matches only TeraLab lab bridges (their names all
start with `tl`), so Docker's `docker0` rules are untouched. The rule is
idempotent and survives service restarts via the systemd unit.

On a host installed with `--no-systemd`, or to re-apply the rule after a
firewall manager reloads the `FORWARD` chain, run:

```sh
sudo /opt/teralab/packaging/teralab/teralab-firewall
sudo systemctl enable --now teralab-firewall.service   # systemd hosts
```

If you use a firewall manager (for example `ufw`, `firewalld`, or a custom
iptables restore script) that flushes or overwrites the `FORWARD` chain, make
sure it preserves this rule, or re-run the script or unit afterwards.

## Kernel same-page merging (KSM, optional)

KSM asks the kernel to merge identical anonymous memory pages across processes.
TeraLab runs many nodes from a handful of images, and QEMU nodes are the main
beneficiary: their guest RAM is anonymous and QEMU registers it as mergeable by
default (`-machine mem-merge=on`), so several nodes booted from the same image
share most of their memory once the KSM scanner runs. Only anonymous memory is
merged; file-backed image and container layers are page cache and are already
shared by the kernel.

KSM is **opt-in**: a default `install.sh` run does not touch it, and the demo
tier does not need it. Enable it when you run many similar nodes on a host that
is short on memory; QEMU nodes benefit most.

### Requirements

- kernel 6.4 or newer with `CONFIG_KSM` (`prctl(2)` `PR_SET_MEMORY_MERGE`,
  which systemd uses to mark a service's processes, arrived in 6.4)
- systemd 254 or newer (`MemoryKSM=`, documented in `systemd.exec(5)`)
- no cgroup setup is involved: systemd applies the setting per process, not
  through a cgroup file
- Docker Engine only if you run `kind: docker` nodes

Debian 13 ships kernel 6.12 and systemd 257, so it meets both.

The setting name is `MemoryKSM=` (capital `KSM`), not `MemoryKsm=`. systemd
rejects an unknown key with `Unknown key 'MemoryKsm' in section [Service],
ignoring.` and the drop-in silently does nothing, so check the key if you write
the drop-in by hand.

### Install

```sh
sudo packaging/teralab/install.sh --ksm                     # from a checkout
sudo /opt/teralab/packaging/teralab/install.sh --ksm        # from the installed tree
```

`--ksm` is additive and idempotent: it changes nothing for the other options,
re-running it rewrites the same content, and a re-run without `--ksm` does not
remove an existing KSM setup. It writes:

| Path | Content |
|---|---|
| `/etc/systemd/system/teralab-ksm.service` | oneshot, `RemainAfterExit=yes`; sets `/sys/kernel/mm/ksm/run=1`, `pages_to_scan=4000`, `sleep_millisecs=50`; enabled and started |
| `/etc/systemd/system/docker.service.d/ksm.conf` | `[Service]` with `MemoryKSM=yes` |
| `/etc/systemd/system/teralab-agent.service.d/ksm.conf` | `[Service]` with `MemoryKSM=yes` |

The two drop-ins add only `ksm.conf` to their directories (created mode 0755);
other drop-ins there are left alone. `MemoryKSM=yes` marks the processes systemd
starts for that unit as mergeable; containers whose processes are started
through Docker/containerd are not automatically covered, so judge the result by
the counters below rather than by the configuration alone.

The installer runs `systemctl daemon-reload`, enables and starts
`teralab-ksm.service`, and prints the two notes below. It deliberately does
**not** restart Docker or the agent:

- The Docker drop-in is read the next time `docker.service` starts. **Restart
  Docker while no labs are running**:

  ```sh
  sudo systemctl restart docker
  ```

  The restart stops every running container. Start the lab again afterwards:
  container filesystems survive (a stop is not a wipe), but in-memory
  configuration and process state do not.
- The agent drop-in is applied the next time `teralab-agent.service` starts.
  Restarting the agent ends open consoles and interrupts node control, so do it
  when the lab is idle.

`--ksm` with `--no-systemd` (or on a host without `systemctl`) installs nothing
and warns, because the feature is entirely systemd-driven.

### Verify

```sh
cat /sys/kernel/mm/ksm/run                     # 1 while teralab-ksm.service is active
systemctl status teralab-ksm.service
systemctl cat docker.service | grep MemoryKSM          # drop-in is loaded
systemctl cat teralab-agent.service | grep MemoryKSM
cat /sys/kernel/mm/ksm/pages_shared /sys/kernel/mm/ksm/pages_sharing
```

`pages_shared` counts the distinct pages KSM keeps as one copy; `pages_sharing`
counts the additional mappings that point at them, i.e. the number of pages
saved (the kernel documents it as “how much saved”). Merge activity is visible
as `pages_sharing` growing while `pages_shared` grows more slowly, and the
memory saved is `pages_sharing × 4096` bytes. Both numbers stay at `0` until
several similar nodes have run for a while: the unit's `sleep_millisecs=50` and
`pages_to_scan=4000` are a deliberately gentle scan rate, and KSM only merges
pages it has scanned. A high `pages_unshared` means KSM is checking pages that
never merge, which wastes some effort; `pages_volatile` counts pages that change
too fast to merge, which is normal for a busy node.

If `systemctl cat` shows no `MemoryKSM=`, or the journal reports that
`MemoryKSM` is an unknown key for `teralab-agent.service`, the systemd on the
host is older than 254 and the drop-in has no effect.

### Caveats

- **Restarting Docker is disruptive.** It stops the containers of every running
  lab, so do it during a maintenance window with no labs up. KSM itself is
  already scanning after the unit starts; only the drop-in waits for the
  restart.
- **CPU cost.** KSM scans pages continuously and merges copy-on-write. It costs
  CPU on memory-heavy hosts; the unit's settings are a conservative compromise,
  and `pages_to_scan`/`sleep_millisecs` can be tuned in the unit's `ExecStart`.
- **Page-sharing side channel.** Deduplicated pages are shared, and a process
  can observe whether a page it owns is shared. That is a known timing side
  channel between processes, so deduplication is only appropriate inside one
  operator's trust domain. Do not enable KSM on a host that serves mutually
  untrusted paying tenants: any future multi-tenant paid tier must run without
  KSM or on separate hosts.
- **Opt-in and reversible.** Nothing else in TeraLab depends on KSM; disabling
  it only changes memory use.

### Disable

```sh
sudo systemctl disable --now teralab-ksm.service
sudo rm -f /etc/systemd/system/teralab-ksm.service \
    /etc/systemd/system/docker.service.d/ksm.conf \
    /etc/systemd/system/teralab-agent.service.d/ksm.conf
sudo systemctl daemon-reload
echo 2 | sudo tee /sys/kernel/mm/ksm/run           # stop scanning and unmerge shared pages
sudo systemctl restart docker                      # when no labs are running
```

`echo 2` stops the scanner and unmerges every shared page, which needs enough
free memory to make the copies; `echo 0` would only stop the scanner and leave
the merged pages in place.

The drop-in removal only takes effect for Docker and the agent after their next
restart, as above.

## Image upload troubleshooting

IOL/Dynamips images are commonly 100–300 MB. If an upload immediately fails with
“A multipart file field is required”, check the effective PHP limits:

```sh
php -i | grep -E 'upload_max_filesize|post_max_size'          # CLI
curl -s http://127.0.0.1/api/v2/system/settings | jq .data    # app limit
```

The installer sets 4G for Apache mod_php, PHP-FPM, and CLI. Re-run
`packaging/teralab/install.sh` after upgrading, or edit the
`99-teralab-uploads.ini` files and restart Apache/PHP-FPM. The app-level limit
is configurable in System → Settings (`max_upload_bytes`, default 2 GiB).

## Smoke test

```sh
curl -fsS http://127.0.0.1/api/v2/health
curl -fsS http://127.0.0.1/api/v2/version
systemctl status teralab-agent
```

See `docs/API.md` for the lab lifecycle endpoints.

## Uninstall

Stop the unit and remove TeraLab-owned paths:

```sh
sudo systemctl disable --now teralab-agent teralab-firewall
sudo systemctl disable --now teralab-ksm 2>/dev/null || true   # only with --ksm
sudo rm -rf /opt/teralab /etc/teralab /var/lib/teralab
sudo rm -f /etc/apache2/conf-available/teralab.conf \
    /etc/systemd/system/teralab-agent.service \
    /etc/systemd/system/teralab-firewall.service \
    /etc/systemd/system/teralab-ksm.service \
    /etc/systemd/system/docker.service.d/ksm.conf \
    /etc/systemd/system/teralab-agent.service.d/ksm.conf
sudo systemctl daemon-reload
sudo iptables -D FORWARD -i tl+ -o tl+ -j ACCEPT 2>/dev/null || true
sudo ip6tables -D FORWARD -i tl+ -o tl+ -j ACCEPT 2>/dev/null || true
```

If you enabled KSM with `--ksm`, the scanner stays on until you disable it; see
[Disable](#disable) to stop it and unmerge shared pages.

Never delete `/var/lib/teralab` unless you intend to remove labs and images.
