#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Create the production runtime layout and the /opt/unetlab compatibility
# symlink. Run as root after the TeraLab tree has been installed at --root.
set -Eeuo pipefail

ROOT="/opt/teralab"
DATA="/var/lib/teralab"
OWNER="${TERALAB_RUNTIME_OWNER:-teralab-api}"

usage() {
	cat <<'EOF'
Usage: scripts/setup-runtime-paths.sh [options]

Options:
  --root PATH     Installed TeraLab root (default: /opt/teralab)
  --data PATH     Runtime state directory (default: /var/lib/teralab)
  --owner USER    Runtime owner (default: teralab-api)
  -h, --help      Show this help
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--root)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			ROOT="$2"
			shift 2
			;;
		--data)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			DATA="$2"
			shift 2
			;;
		--owner)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			OWNER="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			usage >&2
			exit 2
			;;
	esac
done

if [ "$(id -u)" -ne 0 ]; then
	printf 'run as root\n' >&2
	exit 1
fi
if [ ! -d "$ROOT" ]; then
	printf 'TeraLab root not found: %s\n' "$ROOT" >&2
	exit 1
fi
if [ -e /opt/unetlab ] && [ ! -L /opt/unetlab ]; then
	printf '/opt/unetlab exists and is not a symlink; refusing to replace it\n' >&2
	exit 1
fi

ln -sfn "$ROOT" /opt/unetlab

if id -u "$OWNER" >/dev/null 2>&1; then
	GROUP="$(id -gn "$OWNER")"
	install -d -m 0750 -o "$OWNER" -g "$GROUP" "$DATA" "$DATA/labs"
else
	printf 'owner %s not found; creating runtime directories as root\n' "$OWNER" >&2
	install -d -m 0750 "$DATA" "$DATA/labs"
fi

printf 'compatibility path: /opt/unetlab -> %s\n' "$ROOT"
printf 'runtime data: %s\n' "$DATA"
