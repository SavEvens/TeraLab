#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Unit test for packaging/teralab/prune-tree.sh. Runs unprivileged in a
# temporary tree; no package manager or root required.
set -Eeuo pipefail

repo="$(cd "$(dirname "$0")/../.." && pwd)"
prune="$repo/packaging/teralab/prune-tree.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

root="$work/root"
mkdir -p "$root/pkg" "$root/stale/empty" "$root/stale/nonempty" "$root/mixed"
printf 'pkg\n' > "$root/pkg/owned.txt"
printf 'stray\n' > "$root/stray.txt"
printf 'nested\n' > "$root/stale/nonempty/nested.txt"
printf 'keep\n' > "$root/mixed/owned.txt"
printf 'stray-in-package-dir\n' > "$root/mixed/stray.txt"
ln -s /etc/passwd "$root/symlink"
ln -s /etc/passwd "$root/stale/link"

manifest="$work/manifest"
{
	printf '%s\n' "$root" "$root/pkg" "$root/pkg/owned.txt" "$root/mixed" "$root/mixed/owned.txt"
} > "$manifest"

removed="$("$prune" "$root" "$manifest")"

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

[ "$removed" -eq 5 ] || fail "expected 5 removed files, got $removed"
[ -f "$root/pkg/owned.txt" ] || fail "package file was removed"
[ -f "$root/mixed/owned.txt" ] || fail "package file in mixed dir was removed"
[ ! -e "$root/stray.txt" ] || fail "stray file survived"
[ ! -e "$root/mixed/stray.txt" ] || fail "stray file in package dir survived"
[ ! -L "$root/symlink" ] || fail "stray symlink survived"
[ ! -e "$root/stale" ] || fail "empty stale tree survived"
[ -r /etc/passwd ] || fail "symlink target was touched"
[ ! -e "$root/keep" ] || fail "unexpected path"

# A path outside the manifest but inside a package directory must be removed,
# and a non-empty directory that is in the manifest must stay.
mkdir -p "$root/keep"
printf 'x\n' > "$root/keep/extra.txt"
printf '%s\n' "$root/keep" > "$manifest"
"$prune" "$root" "$manifest" >/dev/null
[ ! -e "$root/keep/extra.txt" ] || fail "second pass did not remove extra.txt"
[ -d "$root/keep" ] || fail "manifest directory was pruned"

echo "prune-tree tests passed"
