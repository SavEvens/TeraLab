#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Manual IOL NETMAP smoke test. Requires a user-supplied IOL binary and
# iourc file; they are not part of TeraLab and are never committed.
#
# Usage: tests/manual/iol-netmap-smoke.sh /path/to/iol.bin
set -Eeuo pipefail

BIN="${1:-}"
if [ -z "$BIN" ] || [ ! -x "$BIN" ]; then
	printf 'usage: %s /path/to/iol.bin\n' "$0" >&2
	exit 2
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -f "$(dirname "$BIN")/iourc" ]; then
	cp -a "$(dirname "$BIN")/iourc" "$TMP/iourc"
fi

# UNetLab's wrapper maps IOL device N to wrapper netio N+512 for 64 ports.
for d in $(seq 0 63); do
	printf '1:%d 513:%d\n' "$d" "$d"
done >"$TMP/NETMAP"

cd "$TMP"
printf 'running IOL with a generated NETMAP...\n'
OUTPUT="$(timeout 10 script -q -c "$(printf '%q' "$BIN") -e 2 -s 0 1" /dev/null 2>&1 || true)"
printf '%s\n' "$OUTPUT" | sed -n '1,20p'

if ! grep -q 'Port 0/0 is connected to' <<<"$OUTPUT"; then
	printf 'FAIL: IOL did not parse the generated NETMAP\n' >&2
	exit 1
fi

printf 'IOL NETMAP smoke OK\n'
