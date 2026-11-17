<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Local test images

These images are supplied by the owner for compatibility testing. They are
**not** part of the TeraLab source tree, are not redistributed, and must not
be committed to git or included in packages.

## Inventory

| Path | Type | Details |
|---|---|---|
| `~/test_images/qemu/c8000v-17.16.01a/virtioa.qcow2` | QEMU qcow2 | Cisco C8000v 17.16.01a; 8 GiB virtual, ~2 GiB actual; no backing file |
| `~/test_images/iol/bin/x86_64_crb_linux-adventerprisek9-ms.bin` | IOL ELF x86-64 | L3 image; glibc/libpthread/libdl |
| `~/test_images/iol/bin/x86_64_crb_linux_l2-adventerprisek9-ms.bin` | IOL ELF x86-64 | L2 image; same dependencies |
| `~/test_images/iol/bin/iourc` | Text | IOL licence file, 38 bytes |
| `~/test_images/iol/lib/libcrypto.so.4` | ELF i386 | Legacy 32-bit crypto library; not required by the x86-64 fixtures |
| `alpine:3.20` (Docker) | Container image | Pulled locally for Docker-node testing |

## Verification commands

```sh
qemu-img info ~/test_images/qemu/c8000v-17.16.01a/virtioa.qcow2
file ~/test_images/iol/bin/*.bin
ldd ~/test_images/iol/bin/*.bin
wc -c ~/test_images/iol/bin/iourc
```

## Copy into the local test store (never move the originals)

```sh
sudo mkdir -p /var/lib/teralab/images/qemu/c8000v-17.16.01a
sudo cp ~/test_images/qemu/c8000v-17.16.01a/virtioa.qcow2 \
  /var/lib/teralab/images/qemu/c8000v-17.16.01a/

sudo mkdir -p /var/lib/teralab/images/iol/bin
sudo cp ~/test_images/iol/bin/*.bin ~/test_images/iol/bin/iourc \
  /var/lib/teralab/images/iol/bin/
sudo chmod 0644 /var/lib/teralab/images/iol/bin/*
```

## QEMU compatibility note

The C8000v image must be tested with both SeaBIOS and OVMF and with the
`pc` and `q35` machine types. Record the working combination and any required
devices before adding a built-in template. Do not assume a QEMU 2.x machine
type will exist in QEMU 10.

Result (2026-11-17, QEMU 10.0.13): `-machine q35 -cpu host -smp 4` with
`virtio-blk` and SeaBIOS reaches the GRUB menu and boots IOS-XE 17.16.01a
from `packages.conf`. A 4 GiB guest stalls during package verification; an
8 GiB host-side run reaches IOS-XE AUTONOMOUS mode at ~300 s. Use 8 GiB as
the baseline. The login prompt, OVMF, and `pc` variants remain to be tested.
Evidence: `/home/savanna/vms/c8000v-test/serial.log` (outside the repository).

## IOL boot note

Running `x86_64_crb_linux-adventerprisek9-ms.bin` with an application ID
starts the IOL process but reports `netio error: unable to open NETMAP`.
The agent must generate a NETMAP and interface plumbing in Phase 1. The
binary, `iourc`, and 64-bit dependency check are otherwise valid.

## IOL compatibility note

The local fixtures are 64-bit. TeraLab still supports 32-bit IOL images, but
they require the 32-bit runtime libraries and the bundled 32-bit
`libcrypto.so.4`. The `iourc` file is a licence file; protect it and never
commit it.

## Redistribution

Cisco and other vendor images are proprietary. Do not publish them, include
them in CI artifacts, or commit them. Test fixtures that can be redistributed
(Alpine, VPCS, generated labs) are preferred for CI and public examples.
