#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Enforce repository artifact separation. Research findings, reference
# observations, screenshots, test records, phase reports, test-image
# inventories, working plans, and vendor images must not enter git.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

mode="${1:---tracked}"
case "$mode" in
	--staged)
		mapfile -t files < <(git diff --cached --name-only --diff-filter=ACMR)
		;;
	--tracked)
		mapfile -t files < <(git ls-files)
		;;
	-h|--help)
		printf 'usage: %s [--staged|--tracked]\n' "$0"
		exit 0
		;;
	*)
		printf 'usage: %s [--staged|--tracked]\n' "$0" >&2
		exit 2
		;;
esac

status=0

for file in "${files[@]}"; do
	[ -n "$file" ] || continue

	# Research, reference, and test-record paths.
	case "$file" in
		docs/REFERENCE-*|docs/REFERENCE/*|docs/PHASE*|docs/TEST-IMAGES*|docs/adr/*|docs/research/*)
			printf 'forbidden research artifact: %s\n' "$file" >&2
			status=1
			continue
			;;
		reference-observations/*|phase-0/*|research/*|work/*|teralab-work/*)
			printf 'forbidden work artifact: %s\n' "$file" >&2
			status=1
			continue
			;;
		tests/manual/*|tests/evidence/*|tests/test-images/*)
			printf 'forbidden test artifact: %s\n' "$file" >&2
			status=1
			continue
			;;
	esac

	# Screenshots, captures, logs, vendor images, and credentials.
	case "$file" in
		*screenshot*|*Screenshot*|*capture*.json|*capture*.py)
			printf 'forbidden capture artifact: %s\n' "$file" >&2
			status=1
			continue
			;;
		*.qcow2|*.qcow2.part|*.iso|*.img|*.bin|*.pcap|*.vmdk|*.ova|*.ovf|*.log|*.pem|*.key|*.p12|*.pfx)
			printf 'forbidden binary/test artifact: %s\n' "$file" >&2
			status=1
			continue
			;;
	esac

	# docs/ is reserved for approved public documentation.
	case "$file" in
		docs/*)
			case "$file" in
				docs/README.md|docs/INSTALL.md|docs/UPGRADE.md|docs/API.md|docs/AGENT.md|docs/SECURITY.md|docs/UX.md|docs/RELEASE-NOTES.md|docs/CHANGELOG.md|docs/SBOM.md|docs/LAB-FORMAT.md|docs/LAB-YAML.md|docs/openapi.yaml|docs/examples/*)
					;;
				*)
					printf 'unapproved documentation path: %s\n' "$file" >&2
					status=1
					continue
					;;
			esac
			;;
	esac

	# Everything must live under a known product/tooling directory or be a
	# known root file.
	case "$file" in
		html/*|scripts/*|etc/*|LICENSES/*|api/*|ui/*|agent/*|packaging/*|tests/*|docs/*|.github/*)
			;;
		LICENSE|NOTICE|PROVENANCE.md|README|VERSION|TERALAB_VERSION|.gitignore)
			;;
		*)
			printf 'unexpected repository path: %s\n' "$file" >&2
			status=1
			;;
	esac
done

if [ "$status" -eq 0 ]; then
	printf 'artifact policy OK (%d files checked)\n' "${#files[@]}"
fi

exit "$status"
