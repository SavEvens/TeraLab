#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Release gate for TeraLab. Run from the repository root.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

MODE="${1:---check}"
case "$MODE" in
	--check|--full) ;;
	-h|--help)
		printf 'usage: %s [--check|--full]\n' "$0"
		exit 0
		;;
	*)
		printf 'usage: %s [--check|--full]\n' "$0" >&2
		exit 2
		;;
esac

fail=0
run() {
	printf '\n== %s ==\n' "$*"
	if ! "$@"; then
		fail=1
	fi
}

run ./scripts/check-artifact-policy.sh --tracked
run ./scripts/check-licence-headers.sh
run ./scripts/php-lint.sh
run ./scripts/check-secrets.sh
if command -v shellcheck >/dev/null 2>&1; then
	run shellcheck \
		scripts/check-licence-headers.sh \
		scripts/check-artifact-policy.sh \
		scripts/php-lint.sh \
		scripts/check-secrets.sh \
		scripts/dev-vm.sh \
		scripts/setup-runtime-paths.sh \
		packaging/dynamips/build.sh \
		packaging/teralab/install.sh
fi

if [ "$MODE" = "--full" ]; then
	if command -v go >/dev/null 2>&1; then
		( cd agent && go vet ./... ) || fail=1
		( cd agent && go test ./... ) || fail=1
	fi
	if command -v composer >/dev/null 2>&1 && [ -f api/vendor/autoload.php ]; then
		( cd api && composer stan ) || fail=1
		( cd api && composer test ) || fail=1
	fi
fi

if [ "$fail" -ne 0 ]; then
	printf '\nrelease checks FAILED\n' >&2
	exit 1
fi
printf '\nrelease checks OK\n'
