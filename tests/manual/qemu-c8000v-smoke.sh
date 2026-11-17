#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Manual C8000v QEMU compatibility test. Requires a user-supplied image and
# KVM; the image is never committed or redistributed.
#
# Usage: tests/manual/qemu-c8000v-smoke.sh /path/to/virtioa.qcow2 [seconds]
set -Eeuo pipefail

IMAGE="${1:-}"
SECONDS_TO_RUN="${2:-600}"
if [ -z "$IMAGE" ] || [ ! -f "$IMAGE" ]; then
	printf 'usage: %s /path/to/virtioa.qcow2 [seconds]\n' "$0" >&2
	exit 2
fi
if [ ! -e /dev/kvm ]; then
	printf 'KVM is not available\n' >&2
	exit 2
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

qemu-img create -f qcow2 -F qcow2 -b "$(realpath "$IMAGE")" \
	"$TMP/overlay.qcow2" 8G >/dev/null

printf 'booting C8000v for up to %s seconds...\n' "$SECONDS_TO_RUN"
timeout "$SECONDS_TO_RUN" qemu-system-x86_64 \
	-accel kvm -machine q35 -cpu host -smp 4 -m 8192 \
	-drive "file=$TMP/overlay.qcow2,if=virtio,cache=none" \
	-netdev user,id=n0 -device virtio-net-pci,netdev=n0 \
	-display none -serial "file:$TMP/serial.log" -monitor none -no-reboot || true

grep -aE 'IOSXE version|AUTONOMOUS|Press RETURN|login:' "$TMP/serial.log" | tail -10 || true

if grep -aq 'IOSXE version' "$TMP/serial.log"; then
	printf 'C8000v boot smoke OK (image started IOS-XE)\n'
else
	printf 'FAIL: the image did not start IOS-XE\n' >&2
	exit 1
fi
