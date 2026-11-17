<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Development VM

## Purpose

The development VM keeps TeraLab work off the host and provides a disposable
Debian 13 environment with KVM, PHP-FPM, Apache, QEMU, and the engine stack.
It is created and managed **without host sudo**: user-mode networking, a
cloud-init seed, and a qcow2 overlay are sufficient.

## Verified environment

| Item | Value |
|---|---|
| VM directory | `/home/savanna/vms/teralab-dev` |
| Base image | Debian 13 genericcloud amd64 (`debian-13-genericcloud-amd64.qcow2`) |
| Disk | `disk.qcow2`, 60 GiB overlay |
| Resources | 6 vCPU, 8 GiB RAM |
| SSH | `127.0.0.1:2222`, key `~/.ssh/teralab_dev`, user `savanna` |
| Web | `127.0.0.1:8080` → VM port 80 |
| Guest | Debian GNU/Linux 13, PHP 8.4.27, QEMU 10.0.13 |
| Nested KVM | `/dev/kvm` present and usable inside the guest |
| Console | `serial.log` in the VM directory |

The VM uses QEMU user-mode networking (`-netdev user`) with `hostfwd`, so it
does not create bridges, alter firewall rules, or change host routes.

## Commands

```sh
# Create artifacts (downloads the base image on first run)
scripts/dev-vm.sh create

# Start and wait for SSH
scripts/dev-vm.sh start

# Status / SSH / serial console
scripts/dev-vm.sh status
scripts/dev-vm.sh ssh
scripts/dev-vm.sh console

# Install/refresh development packages
scripts/dev-vm.sh provision

# Stop
scripts/dev-vm.sh stop

# Remove VM state (keeps the downloaded base image)
scripts/dev-vm.sh destroy --force
```

Overrides are environment variables: `TERALAB_VM_DIR`,
`TERALAB_VM_SSH_KEY`, `TERALAB_VM_SSH_PORT`, `TERALAB_VM_WEB_PORT`,
`TERALAB_VM_VCPUS`, `TERALAB_VM_MEMORY`, `TERALAB_VM_DISK_SIZE`.

## Provisioned packages

Apache 2.4, PHP 8.4 FPM/CLI (sqlite3, curl, mbstring, xml, gd, zip, intl,
apcu), QEMU 10, `qemu-utils`, iproute2, nftables, bridge-utils, VPCS, git,
rsync, build tools, libpcap/libssl/libglib/libpixman headers, Python
`pexpect`/`requests`, Composer, OVMF, and tcpdump.

Apache modules `proxy`, `proxy_fcgi`, `rewrite`, `headers`, and `ssl` are
enabled, and the `php8.4-fpm` configuration is enabled. The web vhost and the
TeraLab application are configured in later milestones.

## Nested KVM check

```sh
scripts/dev-vm.sh ssh
ls -l /dev/kvm
qemu-system-x86_64 -accel kvm -cpu host -m 128 -machine pc \
  -display none -S -monitor none
```

If the guest cannot use KVM, use `-accel tcg` for lab nodes and record the
performance impact. The host has nested virtualization enabled; the check
above is the authoritative test for the guest.

## Sync workflow

```sh
rsync -az --delete \
  --exclude .git --exclude node_modules --exclude vendor \
  -e 'ssh -i ~/.ssh/teralab_dev -p 2222' \
  ./ savanna@127.0.0.1:/home/savanna/teralab/
```

Run unit tests on the host where possible; run integration and end-to-end
tests in the VM. The host Chromium is used for reference capture; the VM is
used for lab engine tests.

## Reference observation

The capture script lives outside the repository at
`/home/savanna/ref-observations/capture.py`. It reads credentials from
`/home/savanna/teralab-old-creds` at runtime, never logs them, and writes
screenshots to `/home/savanna/ref-observations/screenshots/`.

```sh
/home/savanna/.venvs/teralab-tools/bin/python \
  /home/savanna/ref-observations/capture.py
```

Screenshots and captured notes are **not** committed. See
[REFERENCE-OBSERVATIONS.md](REFERENCE-OBSERVATIONS.md).

## Docker policy

The host `savanna` user is in the `docker` group, which is root-equivalent.
The current shell may need `sg docker -c '<command>'` until re-login.

Allowed:
- clean package builds in disposable containers;
- isolated test containers;
- ephemeral network-namespace fixtures.

Not allowed without explicit approval:
- `--privileged` on the host;
- mounting `/` read-write;
- changing host bridges, firewall, or routes;
- running untrusted images.

The TeraLab web tier must never have Docker group access.

## Troubleshooting

| Symptom | Check |
|---|---|
| SSH not ready | `scripts/dev-vm.sh console`; cloud-init status in the guest |
| Port already in use | `ss -ltn` on 2222/8080; change the override variables |
| No nested KVM | `/dev/kvm` in the guest; host nested setting; use TCG |
| Apache 403/404 | vhost document root, `php8.4-fpm` config, file permissions |
| PHP-FPM errors | `journalctl -u php8.4-fpm`, `/var/log/apache2/error.log` |
| Disk full | `qemu-img info disk.qcow2`; resize the overlay and filesystem |
