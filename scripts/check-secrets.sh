#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Lightweight tracked-file secret scan for CI. This complements GitHub
# secret scanning and a full gitleaks run; it is not a replacement.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

patterns=(
	'-----BEGIN (RSA|OPENSSH|EC|DSA) PRIVATE KEY-----'
	'AKIA[0-9A-Z]{16}'
	'gh[pousr]_[A-Za-z0-9]{20,}'
	'github_pat_[A-Za-z0-9_]{20,}'
	'xox[baprs]-[A-Za-z0-9-]{10,}'
	'password[[:space:]]*=[[:space:]]*["'"'][^"'"']{8,}["'"']'
)

status=0
for pattern in "${patterns[@]}"; do
	matches="$(git grep -nIE -e "$pattern" -- . ':!tests/ci/php-lint-allowlist.txt' || true)"
	if [ -n "$matches" ]; then
		printf 'potential secret (pattern: %s):\n%s\n' "$pattern" "$matches" >&2
		status=1
	fi
done

if [ "$status" -eq 0 ]; then
	printf 'secret scan OK\n'
fi

exit "$status"
