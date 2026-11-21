<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Installing TeraLab v1.5.6

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
   systemd is available, the lab bridge firewall unit.

Options: `--root`, `--data`, `--source`, `--agent-binary`, `--no-systemd`.

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

## First run

1. Open `http://<host>/ui/` or call `GET /api/v2/health`.
2. Create the administrator once with `POST /api/v2/setup`.
3. Log in with the session cookie and use `/api/v2/labs` to create/start labs.

4. Open the UI at `http://<host>/ui/`.
5. Upload qcow2/ISO/IOL/Dynamips files on the Images page, then pick them in
   the node inspector. Custom YAML templates live in `/etc/teralab/templates`.

There are no default accounts, no telemetry, and no `setcap` usage.

## Engines

- QEMU: distribution package, unprivileged `teralab-vm`, cgroup limits.
- VPCS: `apt-get install vpcs`.
- IOL: supply an x86-64 `.bin` and `iourc`; set `TERALAB_IOL_IOURC` if not
  `/etc/teralab/iourc`.
- Dynamips: build and install the separate package:
  `sudo apt-get install cmake libelf-dev libpcap-dev && packaging/dynamips/build.sh`
  then `sudo dpkg -i dist/teralab-dynamips_*.deb`. A licensed IOS image is
  required and is never bundled.

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

## Host firewall (Docker hosts)

On hosts that run Docker, the `br_netfilter` module is loaded and
`net.bridge.bridge-nf-call-iptables=1` is set, so bridged IPv4 frames are
filtered by iptables. Docker's default `FORWARD` policy is `DROP` with accept
rules only for `docker0`. As a result, traffic between TeraLab lab bridges is
dropped even though ARP (0x0806) and CDP (LLC/SNAP) pass because they are not
IPv4. The symptom is confusing: interfaces stay `up/up`, LIN switches and CDP
neighbours appear, but ping and other IPv4 traffic between lab nodes fail.

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
sudo rm -rf /opt/teralab /etc/teralab /var/lib/teralab
sudo rm -f /etc/apache2/conf-available/teralab.conf \
    /etc/systemd/system/teralab-agent.service \
    /etc/systemd/system/teralab-firewall.service
sudo iptables -D FORWARD -i tl+ -o tl+ -j ACCEPT 2>/dev/null || true
sudo ip6tables -D FORWARD -i tl+ -o tl+ -j ACCEPT 2>/dev/null || true
```

Never delete `/var/lib/teralab` unless you intend to remove labs and images.
