<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Installing TeraLab v1.2.0

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
8. writes the Apache configuration and enables the agent unit.

Options: `--root`, `--data`, `--source`, `--agent-binary`, `--no-systemd`.

## Apache

```sh
sudo a2enmod proxy proxy_wstunnel rewrite
sudo a2enconf teralab
sudo systemctl reload apache2
```

`/ws/console`, `/ws/vnc`, and `/ws/tcp` proxy to the loopback agent gateway.
The agent is the only privileged process; the web tier never starts QEMU.

## First run

1. Open `http://<host>/` or call `GET /api/v2/health`.
2. Create the administrator once with `POST /api/v2/setup`.
3. Log in with the session cookie and use `/api/v2/labs` to create/start labs.
4. Open the new UI at `http://<host>/ui/` (the legacy jQuery UI remains at `/`).

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
sudo systemctl disable --now teralab-agent
sudo rm -rf /opt/teralab /etc/teralab /var/lib/teralab
sudo rm -f /etc/apache2/conf-available/teralab.conf /etc/systemd/system/teralab-agent.service
```

Never delete `/var/lib/teralab` unless you intend to remove labs and images.
