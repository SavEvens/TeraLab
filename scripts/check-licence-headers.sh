#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Verify that new TeraLab files carry an SPDX licence header.
#
# Legacy UNetLab-derived files (html/, existing scripts/) are
# exempt until they are modified. New directories and new top-level scripts
# must declare AGPL-3.0-or-later.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

status=0
checked=0

check_file() {
	local file="$1"
	case "$file" in
		*.png|*.jpg|*.jpeg|*.gif|*.ico|*.svg|*.woff|*.woff2|*.ttf|*.eot)
			return 0
			;;
		*.json|*.lock|*.sum|*.xml|*.neon|*.dist|*.ini|*.unl|*.exe|*.bin|*.qcow2|*.iso|*.img|*.phar|*.yaml|*.yml)
			return 0
			;;
		packaging/dynamips/copyright)
			# GPLv2 notice for the separate Dynamips package.
			return 0
			;;
	esac
	checked=$((checked + 1))
	if ! head -n 8 "$file" | grep -q 'SPDX-License-Identifier: AGPL-3.0-or-later'; then
		printf 'missing AGPL SPDX header: %s\n' "$file" >&2
		status=1
	fi
}

for dir in api ui agent tests packaging docs .github; do
	[ -d "$dir" ] || continue
	while IFS= read -r -d '' file; do
		check_file "$file"
	done < <(find "$dir" \
		\( -name vendor -o -name node_modules -o -name .git -o -name var \
		-o -name build -o -name dist -o -name coverage -o -name .phpunit.cache -o -name .phpstan-cache \) \
		-prune -o -type f -print0)
done

for file in \
	scripts/check-licence-headers.sh \
	scripts/check-artifact-policy.sh \
	scripts/php-lint.sh \
	scripts/check-secrets.sh \
	scripts/check-install-perms.sh \
	scripts/dev-vm.sh; do
	[ -f "$file" ] || continue
	check_file "$file"
done

if [ "$status" -eq 0 ]; then
	printf 'licence headers OK (%d files checked)\n' "$checked"
fi

exit "$status"
