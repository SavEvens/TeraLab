#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Manage the TeraLab development VM without host sudo.
#
# The VM is a Debian 13 genericcloud image with a qcow2 overlay, cloud-init
# seed, key-only SSH, and user-mode networking. It never changes host bridges,
# firewalls, or routes.
set -Eeuo pipefail

: "${HOME:?HOME is not set}"

VM_DIR="${TERALAB_VM_DIR:-$HOME/vms/teralab-dev}"
BASE_IMAGE="$VM_DIR/debian-13-genericcloud-amd64.qcow2"
DISK="$VM_DIR/disk.qcow2"
SEED="$VM_DIR/seed.iso"
PIDFILE="$VM_DIR/qemu.pid"
SERIAL="$VM_DIR/serial.log"
KNOWN_HOSTS="$VM_DIR/known_hosts"
SSH_KEY="${TERALAB_VM_SSH_KEY:-$HOME/.ssh/teralab_dev}"
PUB_KEY="$SSH_KEY.pub"
SSH_PORT="${TERALAB_VM_SSH_PORT:-2222}"
WEB_PORT="${TERALAB_VM_WEB_PORT:-8080}"
VCPUS="${TERALAB_VM_VCPUS:-6}"
MEMORY="${TERALAB_VM_MEMORY:-8192}"
DISK_SIZE="${TERALAB_VM_DISK_SIZE:-60G}"
IMAGE_URL="https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2"

usage() {
	cat <<'EOF'
Usage: scripts/dev-vm.sh <command> [options]

Commands:
  create       Download the base image, create the overlay, SSH key, and seed
  start        Create if needed and boot the VM
  stop         Stop the VM
  status       Show PID, SSH, and web endpoints
  ssh          Open an interactive SSH session
  console      Follow the serial console log
  provision    Install the development packages inside the VM
  destroy      Remove VM state (requires --force; keeps the base image)

Environment overrides:
  TERALAB_VM_DIR, TERALAB_VM_SSH_KEY, TERALAB_VM_SSH_PORT,
  TERALAB_VM_WEB_PORT, TERALAB_VM_VCPUS, TERALAB_VM_MEMORY,
  TERALAB_VM_DISK_SIZE

The VM uses user-mode networking and hostfwd, so no host bridge, firewall, or
route changes are required.
EOF
}

require() {
	command -v "$1" >/dev/null 2>&1 || {
		printf 'missing required command: %s\n' "$1" >&2
		exit 1
	}
}

vm_running() {
	[ -f "$PIDFILE" ] || return 1
	local pid
	pid="$(cat "$PIDFILE" 2>/dev/null || true)"
	[ -n "$pid" ] || return 1
	kill -0 "$pid" 2>/dev/null
}

write_cloud_init() {
	local key
	key="$(cat "$PUB_KEY")"
	cat >"$VM_DIR/user-data" <<EOF
#cloud-config
hostname: teralab-dev
manage_etc_hosts: true
users:
  - name: savanna
    sudo: ALL=(ALL) NOPASSWD:ALL
    shell: /bin/bash
    groups: sudo, kvm
    ssh_authorized_keys:
      - "$key"
ssh_pwauth: false
package_update: true
packages:
  - qemu-guest-agent
  - curl
  - ca-certificates
runcmd:
  - systemctl enable --now qemu-guest-agent || true
EOF
	cat >"$VM_DIR/meta-data" <<EOF
instance-id: teralab-dev-01
local-hostname: teralab-dev
EOF
}

download_base() {
	require curl
	mkdir -p "$VM_DIR"
	printf 'downloading %s\n' "$IMAGE_URL"
	curl -L --fail --retry 3 --retry-delay 5 \
		-o "$BASE_IMAGE.part" "$IMAGE_URL"
	mv "$BASE_IMAGE.part" "$BASE_IMAGE"
}

create_vm() {
	require qemu-system-x86_64
	require qemu-img
	require cloud-localds
	require ssh-keygen
	require curl

	mkdir -p "$VM_DIR"
	if [ ! -s "$BASE_IMAGE" ]; then
		download_base
	fi
	if [ ! -f "$DISK" ]; then
		qemu-img create -f qcow2 -F qcow2 -b "$BASE_IMAGE" "$DISK" "$DISK_SIZE"
	fi
	if [ ! -f "$SSH_KEY" ]; then
		install -d -m 700 "$(dirname "$SSH_KEY")"
		ssh-keygen -t ed25519 -f "$SSH_KEY" -N '' -C 'teralab-dev'
	fi
	chmod 600 "$SSH_KEY"
	chmod 644 "$PUB_KEY"
	write_cloud_init
	cloud-localds "$SEED" "$VM_DIR/user-data" "$VM_DIR/meta-data"
	printf 'VM artifacts ready in %s\n' "$VM_DIR"
}

ssh_cmd() {
	ssh -i "$SSH_KEY" -p "$SSH_PORT" \
		-o "UserKnownHostsFile=$KNOWN_HOSTS" \
		-o StrictHostKeyChecking=accept-new \
		-o ConnectTimeout=3 \
		-o BatchMode=yes \
		"savanna@127.0.0.1" "$@"
}

start_vm() {
	if vm_running; then
		printf 'VM already running (pid %s)\n' "$(cat "$PIDFILE")"
		status_vm
		return 0
	fi
	create_vm
	rm -f "$SERIAL" "$PIDFILE"
	qemu-system-x86_64 \
		-accel kvm -machine q35 -cpu host \
		-smp "$VCPUS" -m "$MEMORY" \
		-drive "file=$DISK,if=virtio,cache=none" \
		-drive "file=$SEED,media=cdrom,readonly=on" \
		-netdev "user,id=n0,hostfwd=tcp:127.0.0.1:$SSH_PORT-:22,hostfwd=tcp:127.0.0.1:$WEB_PORT-:80" \
		-device virtio-net-pci,netdev=n0 \
		-display none -serial "file:$SERIAL" \
		-daemonize -pidfile "$PIDFILE"
	printf 'VM started (pid %s); waiting for SSH...\n' "$(cat "$PIDFILE")"
	local _
	for _ in $(seq 1 60); do
		if ssh_cmd true >/dev/null 2>&1; then
			printf 'SSH ready on 127.0.0.1:%s\n' "$SSH_PORT"
			return 0
		fi
		sleep 2
	done
	printf 'SSH did not become ready; see %s\n' "$SERIAL" >&2
	return 1
}

stop_vm() {
	if ! vm_running; then
		printf 'VM is not running\n'
		rm -f "$PIDFILE"
		return 0
	fi
	local pid _
	pid="$(cat "$PIDFILE")"
	kill -TERM "$pid" 2>/dev/null || true
	for _ in $(seq 1 30); do
		if ! kill -0 "$pid" 2>/dev/null; then
			break
		fi
		sleep 1
	done
	kill -KILL "$pid" 2>/dev/null || true
	rm -f "$PIDFILE"
	printf 'VM stopped\n'
}

status_vm() {
	if vm_running; then
		printf 'state: running (pid %s)\n' "$(cat "$PIDFILE")"
	else
		printf 'state: stopped\n'
	fi
	printf 'ssh:   ssh -i %s -p %s savanna@127.0.0.1\n' "$SSH_KEY" "$SSH_PORT"
	printf 'web:   http://127.0.0.1:%s/\n' "$WEB_PORT"
	printf 'dir:   %s\n' "$VM_DIR"
}

provision_vm() {
	# The remote script is intentionally single-quoted so $HOME/$b expand on
	# the guest, not on the host.
	# shellcheck disable=SC2016
	ssh_cmd '
		set -e
		sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq
		sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
			apache2 php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-curl \
			php8.4-mbstring php8.4-xml php8.4-gd php8.4-zip php8.4-intl \
			php8.4-apcu qemu-system-x86 qemu-utils iproute2 nftables \
			bridge-utils vpcs git rsync curl ca-certificates unzip zip jq \
			build-essential pkg-config libpcap-dev libssl-dev \
			libglib2.0-dev libpixman-1-dev python3-pexpect \
			python3-requests composer ovmf tcpdump
		sudo usermod -aG kvm savanna
		sudo a2enmod proxy proxy_fcgi rewrite headers ssl >/dev/null
		sudo a2enconf php8.4-fpm >/dev/null
		sudo systemctl enable --now php8.4-fpm apache2 >/dev/null
		if [ -x "$HOME/.local/node/bin/node" ]; then
			for b in node npm npx; do
				sudo ln -sfn "$HOME/.local/node/bin/$b" "/usr/local/bin/$b"
			done
		fi
		if [ -x "$HOME/.local/go/bin/go" ]; then
			for b in go gofmt; do
				sudo ln -sfn "$HOME/.local/go/bin/$b" "/usr/local/bin/$b"
			done
		fi
		printf "provisioned: "
		php -v | head -1
	'
}

destroy_vm() {
	if [ "${1:-}" != "--force" ]; then
		printf 'refusing to remove VM state without --force\n' >&2
		exit 2
	fi
	stop_vm
	rm -f "$DISK" "$SEED" "$VM_DIR/user-data" "$VM_DIR/meta-data" \
		"$SERIAL" "$KNOWN_HOSTS" "$PIDFILE"
	printf 'removed VM state; base image retained at %s\n' "$BASE_IMAGE"
}

command="${1:-}"
if [ "$#" -gt 0 ]; then
	shift
fi
case "$command" in
	create) create_vm ;;
	start) start_vm ;;
	stop) stop_vm ;;
	status) status_vm ;;
	ssh)
		exec ssh -t -i "$SSH_KEY" -p "$SSH_PORT" \
			-o "UserKnownHostsFile=$KNOWN_HOSTS" \
			-o StrictHostKeyChecking=accept-new \
			"savanna@127.0.0.1" "$@"
		;;
	console)
		touch "$SERIAL"
		exec tail -n 50 -f "$SERIAL"
		;;
	provision) provision_vm ;;
	destroy) destroy_vm "${1:-}" ;;
	-h|--help|help|"") usage ;;
	*)
		printf 'unknown command: %s\n' "$command" >&2
		usage >&2
		exit 2
		;;
esac
