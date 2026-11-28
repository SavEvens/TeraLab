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
WORK="$(mktemp -d "${TMPDIR:-/tmp}/t.XXXXXX")"
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

# 3. Build a temporary repository on a real branch. The verifier tests run the
#    copied script from here, so commit/branch/version come from this fixture
#    and never from the PR's possibly-detached checkout.
TMP_REPO="$WORK/repo"
mkdir -p "$TMP_REPO/scripts"
git -C "$TMP_REPO" init -q
git -C "$TMP_REPO" config user.email "test@example.invalid"
git -C "$TMP_REPO" config user.name "TeraLab workflow test"
git -C "$TMP_REPO" checkout -q -b test-branch
printf '1.6-dev\n' >"$TMP_REPO/TERALAB_VERSION"
printf 'fixture\n' >"$TMP_REPO/tracked.txt"
cp "$REPO/scripts/live-check.sh" "$TMP_REPO/scripts/live-check.sh"
cp "$REPO/scripts/deploy-local.sh" "$TMP_REPO/scripts/deploy-local.sh"
git -C "$TMP_REPO" add TERALAB_VERSION tracked.txt scripts/live-check.sh scripts/deploy-local.sh
git -C "$TMP_REPO" commit -qm "test fixture"

TMP_HEAD="$(git -C "$TMP_REPO" rev-parse HEAD)"
TMP_BRANCH="$(git -C "$TMP_REPO" rev-parse --abbrev-ref HEAD)"
TMP_VERSION="$(tr -d '[:space:]' <"$TMP_REPO/TERALAB_VERSION")"
LIVE="$TMP_REPO/scripts/live-check.sh"
DEPLOY="$TMP_REPO/scripts/deploy-local.sh"

fixture_dir="$WORK/fixtures"
mkdir -p "$fixture_dir"
now="$(python3 -c 'import datetime; print(datetime.datetime.now().astimezone().isoformat(timespec="seconds"))')"
deployed="$(python3 -c 'import datetime; print((datetime.datetime.now().astimezone()-datetime.timedelta(seconds=60)).isoformat(timespec="seconds"))')"

make_evidence() {
	local path="$1" commit="$2" branch="$3" version="$4" started="$5" duration="$6" suite_pass="$7"
	python3 - "$path" "$commit" "$branch" "$version" "$started" "$duration" "$suite_pass" <<'PY'
import json
import sys

path, commit, branch, version, started, duration, suite_pass = sys.argv[1:8]
document = {
    "tool": "teralab-live-check",
    "pass": True,
    "commit": commit,
    "branch": branch,
    "version": version,
    "url": "http://127.0.0.1",
    "startedAt": started,
    "durationMs": int(duration),
    "suites": {"smoke": {"pass": suite_pass == "1", "checks": []}},
    "pageErrors": [],
    "deployStamp": "live-deploy.json",
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(document, handle, indent=2)
    handle.write("\n")
PY
}

make_stamp() {
	local path="$1" commit="$2" branch="$3" version="$4" deployed_at="$5" ran="$6" skipped="$7" services="$8" security_headers="$9"
	python3 - "$path" "$commit" "$branch" "$version" "$deployed_at" "$ran" "$skipped" "$services" "$security_headers" <<'PY'
import json
import sys

(path, commit, branch, version, deployed_at, ran, skipped, services, headers) = sys.argv[1:10]
document = {
    "tool": "teralab-deploy-local",
    "pass": True,
    "commit": commit,
    "branch": branch,
    "version": version,
    "installedVersion": "1.6~dev-1",
    "deployedAt": deployed_at,
    "artifact": "/tmp/teralab.deb",
    "artifactSha256": "0" * 64,
    "backup": "/tmp/data.tgz",
    "codeBackup": "/tmp/code.tgz",
    "tests": {"ran": ran == "1", "skipped": skipped == "1"},
    "checks": {
        "services": services == "1",
        "health": True,
        "ui": True,
        "setupClosed": True,
        "securityHeaders": headers == "1",
        "installPerms": True,
    },
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(document, handle, indent=2)
    handle.write("\n")
PY
}

base_evidence="$fixture_dir/evidence.json"
base_stamp="$fixture_dir/stamp.json"
make_evidence "$base_evidence" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$now" 5000 1
make_stamp "$base_stamp" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$deployed" 1 0 1 1

validate() {
	local extra="${3:-}"
	if [ -n "$extra" ]; then
		bash "$LIVE" --validate-only --evidence "$1" --stamp "$2" "$extra"
	else
		bash "$LIVE" --validate-only --evidence "$1" --stamp "$2"
	fi
}

EVIDENCE_DIR="$WORK/evidence"
mkdir -p "$EVIDENCE_DIR"

# --stamp is the testing/diagnostic override. The merge gate uses the fixed
# canonical path; these tests use a temp stamp so they also work unprivileged.
verify() {
	local evidence="$1" stamp="$2"
	shift 2
	bash "$LIVE" --validate-only --evidence "$evidence" --stamp "$stamp" "$@"
}

expect_ok "live-check baseline static validation" validate "$base_evidence" "$base_stamp"

# 4. Verify negatives, with a temp --stamp and the copied temp-repo script.
cp "$base_evidence" "$fixture_dir/skip-evidence.json"
make_stamp "$fixture_dir/skip-stamp.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$deployed" 0 1 1 1
expect_failure "deploy stamp with tests.skipped" "tests.skipped" \
	verify "$fixture_dir/skip-evidence.json" "$fixture_dir/skip-stamp.json"

WRONG_COMMIT="0000000000000000000000000000000000000000"
make_evidence "$fixture_dir/commit-evidence.json" "$WRONG_COMMIT" "$TMP_BRANCH" "$TMP_VERSION" "$now" 5000 1
expect_failure "evidence commit mismatch" "evidence commit" \
	verify "$fixture_dir/commit-evidence.json" "$base_stamp"

make_evidence "$fixture_dir/stale-evidence.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" \
	"$(python3 -c 'import datetime; print((datetime.datetime.now().astimezone()-datetime.timedelta(days=8)).isoformat(timespec="seconds"))')" 5000 1
make_stamp "$fixture_dir/stale-stamp.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$deployed" 1 0 1 1
expect_failure "stale evidence age" "old; limit" \
	verify "$fixture_dir/stale-evidence.json" "$fixture_dir/stale-stamp.json"

make_evidence "$fixture_dir/older-evidence.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" \
	"$(python3 -c 'import datetime; print((datetime.datetime.now().astimezone()-datetime.timedelta(seconds=120)).isoformat(timespec="seconds"))')" 5000 1
expect_failure "evidence older than deploy" "older than the deploy stamp" \
	verify "$fixture_dir/older-evidence.json" "$base_stamp"

make_evidence "$fixture_dir/future-evidence.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" \
	"$(python3 -c 'import datetime; print((datetime.datetime.now().astimezone()+datetime.timedelta(hours=1)).isoformat(timespec="seconds"))')" 5000 1
expect_failure "future evidence timestamp" "future" \
	verify "$fixture_dir/future-evidence.json" "$base_stamp"

make_evidence "$fixture_dir/branch-evidence.json" "$TMP_HEAD" "other-branch" "$TMP_VERSION" "$now" 5000 1
expect_failure "branch mismatch" "evidence branch" \
	verify "$fixture_dir/branch-evidence.json" "$base_stamp"

make_evidence "$fixture_dir/version-evidence.json" "$TMP_HEAD" "$TMP_BRANCH" "0.0.0" "$now" 5000 1
expect_failure "version mismatch" "evidence version" \
	verify "$fixture_dir/version-evidence.json" "$base_stamp"

make_evidence "$fixture_dir/failing-evidence.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$now" 5000 0
expect_failure "failing suite" "smoke suite" \
	verify "$fixture_dir/failing-evidence.json" "$base_stamp"

make_evidence "$fixture_dir/short-evidence.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$now" 1 1
expect_failure "implausibly short evidence" "implausibly short" \
	validate "$fixture_dir/short-evidence.json" "$base_stamp"
expect_ok "short evidence accepted only with --allow-short" \
	validate "$fixture_dir/short-evidence.json" "$base_stamp" --allow-short

make_stamp "$fixture_dir/header-stamp.json" "$TMP_HEAD" "$TMP_BRANCH" "$TMP_VERSION" "$deployed" 1 0 1 0
expect_failure "stamp with failing security headers" "securityHeaders" \
	validate "$base_evidence" "$fixture_dir/header-stamp.json"

# 5. The default verify path reads ONLY the canonical root-owned stamp. In CI
#    the real path is absent, so it must fail closed; a user-owned mirror in
#    the evidence dir must not rescue it.
cp "$base_evidence" "$EVIDENCE_DIR/$TMP_HEAD.json"
cp "$base_stamp" "$EVIDENCE_DIR/deploy-$TMP_HEAD.json"
expect_failure "verify ignores user-owned mirror and fails without canonical stamp" "deploy stamp" \
	bash "$LIVE" --verify --evidence-dir "$EVIDENCE_DIR"

# 5b. The merge gate must refuse the fixture override flags outright.
expect_failure "verify refuses the stamp override" "not valid for the merge gate" \
	bash "$LIVE" --verify --stamp "$base_stamp" --evidence-dir "$EVIDENCE_DIR"
expect_failure "verify refuses allow-short" "not valid for the merge gate" \
	bash "$LIVE" --verify --allow-short --evidence-dir "$EVIDENCE_DIR"

# 6. deploy-local --dry-run in a temporary repository, plus refusals.
INSTALLED="$WORK/installed-deploy-local"
cp "$DEPLOY" "$INSTALLED"

deploy_args=(
	--dry-run
	--source "$TMP_REPO"
	--root "$WORK/root"
	--data "$WORK/data"
	--stamp "$WORK/state/live-deploy.json"
	--backup-dir "$WORK/backups"
	--evidence-dir "$WORK/evidence"
	--installed "$INSTALLED"
	--expected-commit "$TMP_HEAD"
)

expect_ok "deploy-local dry run" bash "$DEPLOY" "${deploy_args[@]}"

printf 'dirty\n' >>"$TMP_REPO/tracked.txt"
expect_failure "deploy-local refuses a dirty tree" "dirty" \
	bash "$DEPLOY" "${deploy_args[@]}"
git -C "$TMP_REPO" checkout -- tracked.txt

mkdir -p "$TMP_REPO/packaging"
printf 'untracked\n' >"$TMP_REPO/packaging/untracked.txt"
expect_failure "deploy-local refuses untracked build files" "untracked build-relevant" \
	bash "$DEPLOY" "${deploy_args[@]}"
rm -f "$TMP_REPO/packaging/untracked.txt"
rmdir "$TMP_REPO/packaging"

expect_failure "deploy-local refuses the wrong commit" "expected commit" \
	bash "$DEPLOY" "${deploy_args[@]}" \
	--expected-commit 0000000000000000000000000000000000000000

printf '#!/bin/sh\nexit 0\n' >"$WORK/stale-deploy-local"
chmod 0755 "$WORK/stale-deploy-local"
expect_failure "deploy-local refuses a stale installed copy" "stale" \
	bash "$DEPLOY" "${deploy_args[@]}" \
	--installed "$WORK/stale-deploy-local"

unwritable="$WORK/unwritable-backups"
mkdir -p "$unwritable"
chmod 0500 "$unwritable"
expect_failure "deploy-local refuses an unwritable backup dir" "not writable" \
	bash "$DEPLOY" "${deploy_args[@]}" \
	--backup-dir "$unwritable"
chmod 0700 "$unwritable"

# 7. Root argument surface. Simulate euid 0 with a test-only id shim first on
#    PATH (the script prepends HOME/.local/go/bin), then pass an override.
fake_home="$WORK/fake-home"
mkdir -p "$fake_home/.local/go/bin"
cat >"$fake_home/.local/go/bin/id" <<'SH'
#!/bin/sh
if [ "$#" -eq 1 ] && [ "$1" = "-u" ]; then
	echo 0
	exit 0
fi
exec /usr/bin/id "$@"
SH
chmod 0755 "$fake_home/.local/go/bin/id"

expect_failure "deploy refuses path overrides as root" "fixed paths" \
	env HOME="$fake_home" bash "$DEPLOY" \
	--source "$TMP_REPO" \
	--root "$WORK/root" \
	--data "$WORK/data" \
	--stamp "$WORK/state/live-deploy.json" \
	--backup-dir "$WORK/backups" \
	--evidence-dir "$WORK/evidence" \
	--installed "$INSTALLED"

expect_failure "rollback refuses overrides as root" "rollback accepts only the default paths" \
	env HOME="$fake_home" bash "$DEPLOY" \
	--rollback "$WORK/fake-code.tgz" \
	--root "$WORK/root"

# 8. Running-lab guard: a fake qemu process with cwd under the data directory.
mkdir -p "$WORK/data"
cp /bin/sleep "$WORK/qemu-fake"
(
	cd "$WORK/data" || exit 1
	exec "$WORK/qemu-fake" 60
) &
lab_pid=$!
# shellcheck disable=SC2064 # expand the PID at trap definition time.
trap 'kill "$lab_pid" 2>/dev/null || true; rm -rf "$WORK"' EXIT
sleep 0.2
expect_failure "deploy-local refuses a running lab cwd" "lab engines are running" \
	bash "$DEPLOY" "${deploy_args[@]}"
kill "$lab_pid" 2>/dev/null || true
wait "$lab_pid" 2>/dev/null || true
trap 'rm -rf "$WORK"' EXIT

# 9. Missing version file.
git -C "$TMP_REPO" rm -q TERALAB_VERSION
git -C "$TMP_REPO" commit -qm "remove version"
expect_failure "deploy-local refuses a missing version" "TERALAB_VERSION" \
	bash "$DEPLOY" "${deploy_args[@]}" \
	--expected-commit "$(git -C "$TMP_REPO" rev-parse HEAD)"

printf 'workflow tool tests: PASS\n'
