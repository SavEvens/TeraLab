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

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

root="$work/root"
outside="$work/outside"
mkdir -p "$root/pkg" "$root/stale/empty" "$root/stale/nonempty" "$root/mixed" "$outside"
printf 'pkg\n' > "$root/pkg/owned.txt"
printf 'stray\n' > "$root/stray.txt"
printf 'nested\n' > "$root/stale/nonempty/nested.txt"
printf 'keep\n' > "$root/mixed/owned.txt"
printf 'stray-in-package-dir\n' > "$root/mixed/stray.txt"
printf 'sentinel\n' > "$outside/keep.txt"
ln -s /etc/passwd "$root/symlink"
ln -s /etc/passwd "$root/stale/link"
ln -s "$outside" "$root/dirlink"

manifest="$work/manifest"
{
	printf '%s\n' "$root" "$root/pkg" "$root/pkg/owned.txt" "$root/mixed" "$root/mixed/owned.txt"
} > "$manifest"

removed="$("$prune" "$root" "$manifest")"

[ "$removed" -eq 6 ] || fail "expected 6 removed files, got $removed"
[ -f "$root/pkg/owned.txt" ] || fail "package file was removed"
[ -f "$root/mixed/owned.txt" ] || fail "package file in mixed dir was removed"
[ ! -e "$root/stray.txt" ] || fail "stray file survived"
[ ! -e "$root/mixed/stray.txt" ] || fail "stray file in package dir survived"
[ ! -L "$root/symlink" ] || fail "stray symlink survived"
[ ! -L "$root/dirlink" ] || fail "stray directory symlink survived"
[ -f "$outside/keep.txt" ] || fail "directory symlink target was modified"
[ ! -e "$root/stale" ] || fail "empty stale tree survived"
[ -r /etc/passwd ] || fail "symlink target was touched"

# A path outside the manifest but inside a package directory must be removed,
# and a non-empty directory that is in the manifest must stay.
mkdir -p "$root/keep"
printf 'x\n' > "$root/keep/extra.txt"
printf '%s\n' "$root/keep" > "$manifest"
"$prune" "$root" "$manifest" >/dev/null
[ ! -e "$root/keep/extra.txt" ] || fail "second pass did not remove extra.txt"
[ -d "$root/keep" ] || fail "manifest directory was pruned"

# Refusals: none of these may remove anything.
probe="$work/probe"
mkdir -p "$probe"
printf 'sentinel\n' > "$probe/keep.txt"

expect_refusal() {
	local label="$1"
	shift
	if "$@" >/dev/null 2>&1; then
		fail "$label was not refused"
	fi
	[ -f "$probe/keep.txt" ] || fail "$label removed a file"
}

expect_refusal "root /" "$prune" / "$manifest"
expect_refusal "root /opt" "$prune" /opt "$manifest"
expect_refusal "trailing slash" "$prune" "$root/" "$manifest"
expect_refusal "missing manifest" "$prune" "$probe" "$work/missing"
: > "$work/empty"
expect_refusal "empty manifest" "$prune" "$probe" "$work/empty"
printf '%s\n' "/somewhere/else" > "$work/foreign"
expect_refusal "foreign manifest" "$prune" "$probe" "$work/foreign"

echo "prune-tree tests passed"
