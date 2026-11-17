#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Lint every tracked PHP file, excluding the documented baseline allowlist.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

allowlist="tests/ci/php-lint-allowlist.txt"
[ -f "$allowlist" ] || {
	printf 'missing allowlist: %s\n' "$allowlist" >&2
	exit 1
}

command -v php >/dev/null 2>&1 || {
	printf 'php is not installed\n' >&2
	exit 1
}

checked=0
failed=0

while IFS= read -r file; do
	[ -n "$file" ] || continue
	if grep -qxF "$file" "$allowlist" 2>/dev/null; then
		continue
	fi
	checked=$((checked + 1))
	if ! output="$(php -l "$file" 2>&1)"; then
		printf '%s\n' "$output" >&2
		failed=1
	fi
done < <(git ls-files '*.php')

if [ "$failed" -ne 0 ]; then
	printf 'PHP lint failed\n' >&2
	exit 1
fi

printf 'PHP lint OK (%d files checked, allowlisted baseline failures excluded)\n' "$checked"
