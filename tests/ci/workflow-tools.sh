#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Unit and negative tests for the pre-merge workflow tooling.
#
# No root, network, Docker, or live TeraLab host is required. Every test runs
# inside a temporary directory that is removed on exit. CI also checks the Node
# E2E syntax separately; this file covers the shell tools only.
set -Eeuo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/teralab-workflow-tools.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

pass() {
	printf 'ok: %s\n' "$*"
}

log_file="$WORK/output.log"

expect_ok() {
	local label="$1"
	shift
	if ! "$@" >"$log_file" 2>&1; then
		cat "$log_file" >&2 || true
		fail "$label: expected success"
	fi
	pass "$label"
}

expect_failure() {
	local label="$1" pattern="$2"
	shift 2
	if "$@" >"$log_file" 2>&1; then
		cat "$log_file" >&2 || true
		fail "$label: expected a refusal"
	fi
	if ! grep -Eqi -- "$pattern" "$log_file"; then
		cat "$log_file" >&2 || true
		fail "$label: refusal did not mention '$pattern'"
	fi
	pass "$label"
}

# 1. --help for every tool.
for tool in test-all live-check deploy-local live-diagnostics; do
	out="$(bash "$REPO/scripts/$tool.sh" --help)" \
		|| fail "$tool --help exited nonzero"
	case "$out" in
		*Usage:*) ;;
		*) fail "$tool --help did not print usage" ;;
	esac
	pass "$tool --help"
done

# 2. test-all --list names the API, Go, UI, shellcheck, and policy commands.
list_output="$(bash "$REPO/scripts/test-all.sh" --list)" \
	|| fail "test-all --list exited nonzero"
for needle in \
	"composer validate --no-check-publish" \
	"composer lint" \
	"phpstan" \
	"composer test" \
	"composer audit" \
	"go vet" \
	"go test" \
	"go build" \
	"npm test" \
	"npm run build" \
	"npm run e2e" \
	"shellcheck" \
	"check-artifact-policy.sh --tracked" \
	"check-install-perms.sh"; do
	if ! grep -Fq -- "$needle" <<<"$list_output"; then
		fail "test-all --list is missing: $needle"
	fi
done
pass "test-all --list entries"

# 3. live-check --verify fails closed without evidence or a stamp.
EVIDENCE="$WORK/evidence"
mkdir -p "$EVIDENCE"
STAMP="$WORK/stamp.json"
HEAD="$(git -C "$REPO" rev-parse HEAD)"
BRANCH="$(git -C "$REPO" rev-parse --abbrev-ref HEAD)"
VERSION="$(tr -d '[:space:]' <"$REPO/TERALAB_VERSION")"
NOW="$(python3 -c 'import datetime; print(datetime.datetime.now().astimezone().isoformat(timespec="seconds"))')"

expect_failure "live-check missing evidence" "evidence not found" \
	env TERALAB_DEPLOY_STAMP="$STAMP" bash "$REPO/scripts/live-check.sh" \
	--verify --evidence-dir "$EVIDENCE"

cat >"$EVIDENCE/$HEAD.json" <<EOF
{
  "tool": "teralab-live-check",
  "pass": true,
  "commit": "$HEAD",
  "branch": "$BRANCH",
  "version": "$VERSION",
  "url": "http://127.0.0.1",
  "startedAt": "$NOW",
  "durationMs": 1,
  "suites": {"smoke": {"pass": true, "checks": []}},
  "pageErrors": [],
  "deployStamp": "deploy-$HEAD.json"
}
EOF

expect_failure "live-check missing stamp" "deploy stamp" \
	env TERALAB_DEPLOY_STAMP="$STAMP" bash "$REPO/scripts/live-check.sh" \
	--verify --evidence-dir "$EVIDENCE"

cat >"$STAMP" <<EOF
{
  "tool": "teralab-deploy-local",
  "pass": true,
  "commit": "$HEAD",
  "branch": "$BRANCH",
  "version": "$VERSION",
  "deployedAt": "$NOW",
  "tests": {"ran": true, "skipped": false},
  "checks": {"services": true, "health": true, "ui": true, "setupClosed": true, "installPerms": true}
}
EOF

# Evidence with a different commit must be refused.
WRONG="0000000000000000000000000000000000000000"
python3 - "$EVIDENCE/$HEAD.json" "$WRONG" <<'PY'
import json
import sys

path, wrong = sys.argv[1:3]
with open(path, encoding="utf-8") as handle:
    document = json.load(handle)
document["commit"] = wrong
with open(path, "w", encoding="utf-8") as handle:
    json.dump(document, handle, indent=2)
    handle.write("\n")
PY
expect_failure "live-check commit mismatch" "commit" \
	env TERALAB_DEPLOY_STAMP="$STAMP" bash "$REPO/scripts/live-check.sh" \
	--verify --evidence-dir "$EVIDENCE"

# 4. deploy-local --dry-run in a temporary repository, plus refusals.
TMP_REPO="$WORK/repo"
mkdir -p "$TMP_REPO"
git -C "$TMP_REPO" init -q
git -C "$TMP_REPO" config user.email "test@example.invalid"
git -C "$TMP_REPO" config user.name "TeraLab workflow test"
printf '1.6-dev\n' >"$TMP_REPO/TERALAB_VERSION"
printf 'tracked\n' >"$TMP_REPO/tracked.txt"
mkdir -p "$TMP_REPO/scripts"
cp "$REPO/scripts/deploy-local.sh" "$TMP_REPO/scripts/deploy-local.sh"
git -C "$TMP_REPO" add TERALAB_VERSION tracked.txt scripts/deploy-local.sh
git -C "$TMP_REPO" commit -qm "test fixture"

INSTALLED="$WORK/installed-deploy-local"
cp "$REPO/scripts/deploy-local.sh" "$INSTALLED"

deploy_args=(
	--dry-run
	--source "$TMP_REPO"
	--root "$WORK/root"
	--data "$WORK/data"
	--stamp "$WORK/state/live-deploy.json"
	--backup-dir "$WORK/backups"
	--evidence-dir "$WORK/evidence"
	--installed "$INSTALLED"
	--expected-commit "$(git -C "$TMP_REPO" rev-parse HEAD)"
)

expect_ok "deploy-local dry run" bash "$REPO/scripts/deploy-local.sh" "${deploy_args[@]}"

printf 'dirty\n' >>"$TMP_REPO/tracked.txt"
expect_failure "deploy-local refuses a dirty tree" "dirty" \
	bash "$REPO/scripts/deploy-local.sh" "${deploy_args[@]}"
git -C "$TMP_REPO" checkout -- tracked.txt

expect_failure "deploy-local refuses the wrong commit" "expected commit" \
	bash "$REPO/scripts/deploy-local.sh" "${deploy_args[@]}" \
	--expected-commit 0000000000000000000000000000000000000000

printf '#!/bin/sh\nexit 0\n' >"$WORK/stale-deploy-local"
chmod 0755 "$WORK/stale-deploy-local"
expect_failure "deploy-local refuses a stale installed copy" "stale" \
	bash "$REPO/scripts/deploy-local.sh" "${deploy_args[@]}" \
	--installed "$WORK/stale-deploy-local"

git -C "$TMP_REPO" rm -q TERALAB_VERSION
git -C "$TMP_REPO" commit -qm "remove version"
expect_failure "deploy-local refuses a missing version" "TERALAB_VERSION" \
	bash "$REPO/scripts/deploy-local.sh" "${deploy_args[@]}" \
	--expected-commit "$(git -C "$TMP_REPO" rev-parse HEAD)"

printf 'workflow tool tests: PASS\n'
