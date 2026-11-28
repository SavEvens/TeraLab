#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Remove files under an install root that a package does not own, then prune
# directories that the package does not ship and that are now empty.
#
# Usage: prune-tree.sh ROOT MANIFEST
#   ROOT      absolute install root (for example /opt/teralab)
#   MANIFEST  newline-separated absolute paths owned by the package
#
# Prints the number of removed files. Never follows symlinks and never removes
# a non-empty directory.
set -Eeuo pipefail

ROOT="${1:-}"
MANIFEST="${2:-}"

if [ -z "$ROOT" ] || [ -z "$MANIFEST" ]; then
	printf 'usage: %s ROOT MANIFEST\n' "$0" >&2
	exit 2
fi
case "$ROOT" in
	/*) ;;
	*) printf 'ROOT must be absolute\n' >&2; exit 2 ;;
esac
[ -d "$ROOT" ] || exit 0
[ -f "$MANIFEST" ] || { printf 'missing manifest: %s\n' "$MANIFEST" >&2; exit 2; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
sort -u "$MANIFEST" > "$work/package"
find "$ROOT" -type f -o -type l | sort > "$work/present"

removed=0
while IFS= read -r stale; do
	[ -n "$stale" ] || continue
	rm -f -- "$stale" && removed=$((removed + 1))
done < <(comm -23 "$work/present" "$work/package")

# Deepest first; rmdir only succeeds on empty directories, so a stray file
# always keeps its parent.
find "$ROOT" -depth -type d | while IFS= read -r directory; do
	[ "$directory" = "$ROOT" ] && continue
	grep -qxF -- "$directory" "$work/package" || rmdir -- "$directory" 2>/dev/null || true
done

printf '%s\n' "$removed"
