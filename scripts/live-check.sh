#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Run the live TeraLab regression suite and act as the pre-merge gate.
#
# A smoke run executes ui/e2e/live.mjs against the installed host. A full run
# adds ui/e2e/apache.mjs. Both write evidence to $EVIDENCE_DIR/<commit>.json.
# `--verify` runs no tests: it fails closed unless the single root-owned canonical
# stamp at /var/lib/teralab-live/live-deploy.json and the evidence describe the
# current commit, branch, and version, the evidence is fresh, the required
# suites passed, and the host is healthy. It is read-only and needs no root.
#
# The user-owned deploy-<commit>.json mirror is a convenience copy only. Verify
# never reads it and never trusts it. `--stamp FILE` is an explicit
# testing/diagnostic override and is never valid for a merge gate.
#
# `--validate-only` is an internal, unprivileged test mode used by the workflow
# tool tests. It validates explicit evidence/stamp files but is not a gate.
#
# Secrets are passed to the Node suite through the environment and are never
# printed or written to the evidence file.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

PATH="$HOME/.local/go/bin:$HOME/.local/node/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
export PATH

MODE="smoke"
VERIFY_COMMIT=""
EVIDENCE_DIR="${TERALAB_EVIDENCE_DIR:-/home/savanna/teralab-work/live-validation}"
OUT=""
URL="${TERALAB_LIVE_URL:-http://127.0.0.1}"
PASSWORD_FILE="${TERALAB_ADMIN_PASSWORD_FILE:-}"
ADMIN_USER="${TERALAB_ADMIN_USER:-admin}"
TIMEOUT=""
REQUIRE_FULL=0
ALLOW_SHORT=0
VALIDATE_EVIDENCE=""
STAMP_OVERRIDE=""
CANONICAL_STAMP="/var/lib/teralab-live/live-deploy.json"
CANONICAL_STAMP_DIR="/var/lib/teralab-live"

usage() {
	cat <<EOF
Usage: scripts/live-check.sh [--smoke|--full] [options]
       scripts/live-check.sh --verify [commit] [options]

Run the live regression suite, or verify existing evidence for the merge gate.

Modes:
  --smoke            Live smoke suite (default).
  --full             Smoke plus the browser-free Apache suite.
  --verify [commit]  Run no tests; validate the stamp and evidence for the
                     commit (default: HEAD).

Options:
  --url URL              Base URL for health and suites (default: $URL)
  --password-file FILE   File containing the admin password
                         (default: \$TERALAB_ADMIN_PASSWORD_FILE)
  --evidence-dir DIR     Evidence directory
                         (default: $EVIDENCE_DIR)
  --out FILE             Override the evidence JSON path
  --timeout SECONDS      Per-suite timeout (default: 600 smoke, 1800 full)
  --require-full         Require (or run) the full suite
  --allow-short          Internal test flag: accept durationMs < 1000
  --stamp FILE           Testing/diagnostic override of the canonical deploy
                         stamp; never valid for a merge gate
                         (default: $CANONICAL_STAMP)
  -h, --help             Show this help

Internal test options (never a merge gate):
  --validate-only        Validate explicit --evidence/--stamp files and exit.
  --evidence FILE        Evidence file for --validate-only.

Environment:
  TERALAB_ADMIN_PASSWORD       Admin password, when no file is used
  TERALAB_TEST_IMAGE           qcow2 fixture for --full
  TERALAB_TEST_ISO             ISO fixture for --full
  TERALAB_EVIDENCE_MAX_AGE     Maximum evidence age for --verify (default 7d)

The smoke run requires a clean tracked tree, active teralab-agent/apache2/
php8.4-fpm services, a password source, and a health version equal to
TERALAB_VERSION. --verify needs no password and no root. Unless --stamp is
given for testing, it reads only the root-owned canonical stamp at
$CANONICAL_STAMP.
EOF
}

die() {
	printf 'live-check: %s\n' "$*" >&2
	exit 1
}

need_value() {
	if [ "$#" -lt 2 ] || [ -z "$2" ]; then
		printf 'live-check: %s requires a value\n\n' "$1" >&2
		usage >&2
		exit 2
	fi
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--smoke)
			MODE="smoke"
			shift
			;;
		--full)
			MODE="full"
			shift
			;;
		--verify)
			MODE="verify"
			if [ "$#" -ge 2 ] && [ "${2#-}" = "$2" ]; then
				VERIFY_COMMIT="$2"
				shift 2
			else
				shift
			fi
			;;
		--url)
			need_value "$@"
			URL="$2"
			shift 2
			;;
		--password-file)
			need_value "$@"
			PASSWORD_FILE="$2"
			shift 2
			;;
		--evidence-dir)
			need_value "$@"
			EVIDENCE_DIR="$2"
			shift 2
			;;
		--out)
			need_value "$@"
			OUT="$2"
			shift 2
			;;
		--timeout)
			need_value "$@"
			TIMEOUT="$2"
			shift 2
			;;
		--require-full)
			REQUIRE_FULL=1
			shift
			;;
		--allow-short)
			ALLOW_SHORT=1
			shift
			;;
		--validate-only)
			MODE="validate-only"
			shift
			;;
		--evidence)
			need_value "$@"
			VALIDATE_EVIDENCE="$2"
			shift 2
			;;
		--stamp)
			need_value "$@"
			STAMP_OVERRIDE="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			printf 'live-check: unknown option: %s\n\n' "$1" >&2
			usage >&2
			exit 2
			;;
	esac
done

if [ "$MODE" != "validate-only" ] && [ -n "$VALIDATE_EVIDENCE" ]; then
	die "--evidence is only valid with --validate-only"
fi
if [ "$MODE" = "smoke" ] || [ "$MODE" = "full" ]; then
	[ -z "$STAMP_OVERRIDE" ] || die "--stamp is a validate-only testing override"
fi
if [ "$MODE" = "verify" ] && [ -n "$STAMP_OVERRIDE" ]; then
	die "--stamp is not valid for the merge gate; use --validate-only for fixtures"
fi
if [ "$MODE" = "verify" ] && [ "$ALLOW_SHORT" -eq 1 ]; then
	die "--allow-short is not valid for the merge gate"
fi
if [ "$MODE" = "validate-only" ] && { [ -z "$VALIDATE_EVIDENCE" ] || [ -z "$STAMP_OVERRIDE" ]; }; then
	die "--validate-only requires --evidence and --stamp"
fi
if [ "$ALLOW_SHORT" -eq 1 ] && { [ "$MODE" = "smoke" ] || [ "$MODE" = "full" ]; }; then
	die "--allow-short is a verify/validate-only test flag"
fi

case "$URL" in
	http://*|https://*) ;;
	*) die "URL must start with http:// or https://: $URL" ;;
esac
URL="${URL%/}"

case "$EVIDENCE_DIR" in
	/*) ;;
	*) die "--evidence-dir must be absolute: $EVIDENCE_DIR" ;;
esac
case "$EVIDENCE_DIR" in
	"$ROOT"|"$ROOT"/*) die "--evidence-dir must be outside the repository" ;;
esac
if [ -n "$OUT" ]; then
	case "$OUT" in
		/*) ;;
		*) die "--out must be absolute: $OUT" ;;
	esac
	case "$OUT" in
		"$ROOT"|"$ROOT"/*) die "--out must be outside the repository" ;;
	esac
fi
if [ -n "$TIMEOUT" ]; then
	case "$TIMEOUT" in
		''|*[!0-9]*) die "--timeout must be a positive integer: $TIMEOUT" ;;
	esac
fi

git_ro() {
	git -c "safe.directory=$ROOT" -C "$ROOT" "$@"
}

CURRENT_COMMIT="$(git_ro rev-parse HEAD)"
CURRENT_BRANCH="$(git_ro rev-parse --abbrev-ref HEAD)"
VERSION_FILE="$ROOT/TERALAB_VERSION"
[ -f "$VERSION_FILE" ] || die "TERALAB_VERSION is missing from $ROOT"
VERSION="$(tr -d '[:space:]' <"$VERSION_FILE")"
[ -n "$VERSION" ] || die "TERALAB_VERSION is empty"

MAX_AGE_RAW="${TERALAB_EVIDENCE_MAX_AGE:-7d}"
case "$MAX_AGE_RAW" in
	*d) MAX_AGE_NUMBER="${MAX_AGE_RAW%d}" MAX_AGE_UNIT=86400 ;;
	*h) MAX_AGE_NUMBER="${MAX_AGE_RAW%h}" MAX_AGE_UNIT=3600 ;;
	*m) MAX_AGE_NUMBER="${MAX_AGE_RAW%m}" MAX_AGE_UNIT=60 ;;
	*s) MAX_AGE_NUMBER="${MAX_AGE_RAW%s}" MAX_AGE_UNIT=1 ;;
	*) MAX_AGE_NUMBER="$MAX_AGE_RAW" MAX_AGE_UNIT=1 ;;
esac
case "$MAX_AGE_NUMBER" in
	''|*[!0-9]*) die "invalid TERALAB_EVIDENCE_MAX_AGE: $MAX_AGE_RAW" ;;
esac
MAX_AGE=$(( MAX_AGE_NUMBER * MAX_AGE_UNIT ))
[ "$MAX_AGE" -gt 0 ] || die "TERALAB_EVIDENCE_MAX_AGE must be positive"

# validate_static is shared by the real --verify gate and the internal
# --validate-only test mode. It never contacts the host.
validate_static() {
	local evidence_path="$1" stamp_path="$2" commit="$3" branch="$4"
	local version="$5" max_age="$6" require_full="$7" allow_short="$8"
	python3 - "$evidence_path" "$stamp_path" "$commit" "$branch" "$version" \
		"$max_age" "$require_full" "$allow_short" <<'PY'
import json
import sys
from datetime import datetime, timedelta, timezone

(
    evidence_path,
    stamp_path,
    commit,
    branch,
    version,
    max_age_raw,
    require_full_raw,
    allow_short_raw,
) = sys.argv[1:9]
max_age = int(max_age_raw)
require_full = require_full_raw == "1"
allow_short = allow_short_raw == "1"
errors = []


def fail(message):
    errors.append(message)


def load(path, label):
    try:
        with open(path, encoding="utf-8") as handle:
            value = json.load(handle)
    except (OSError, json.JSONDecodeError) as exc:
        fail(f"{label} is not readable valid JSON: {exc}")
        return None
    if not isinstance(value, dict):
        fail(f"{label} is not a JSON object")
        return None
    return value


def parse_time(value, label):
    if not isinstance(value, str) or not value:
        fail(f"{label} is missing")
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        fail(f"{label} is not an ISO-8601 timestamp: {value}")
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


evidence = load(evidence_path, "evidence")
stamp = load(stamp_path, "deploy stamp")

if evidence is not None:
    if evidence.get("tool") != "teralab-live-check":
        fail("evidence tool is not teralab-live-check")
    if evidence.get("pass") is not True:
        fail("evidence does not report pass: true")
    if evidence.get("commit") != commit:
        fail(f"evidence commit {evidence.get('commit')!r} does not match {commit}")
    if evidence.get("branch") != branch:
        fail(f"evidence branch {evidence.get('branch')!r} does not match {branch}")
    if evidence.get("version") != version:
        fail(f"evidence version {evidence.get('version')!r} does not match {version}")
    if evidence.get("deployStamp") != "live-deploy.json":
        fail("evidence deployStamp does not name the canonical live-deploy.json")
    if not isinstance(evidence.get("url"), str) or not evidence["url"]:
        fail("evidence url is missing")
    duration = evidence.get("durationMs")
    if isinstance(duration, bool) or not isinstance(duration, int) or duration < 0:
        fail("evidence durationMs is missing or invalid")
    elif duration < 1000 and not allow_short:
        fail(f"evidence durationMs {duration} is implausibly short (minimum 1000)")
    page_errors = evidence.get("pageErrors")
    if not isinstance(page_errors, list):
        fail("evidence pageErrors is not a list")
    evidence_started = parse_time(evidence.get("startedAt"), "evidence startedAt")

    suites = evidence.get("suites")
    if not isinstance(suites, dict):
        fail("evidence suites is missing")
        suites = {}
    smoke = suites.get("smoke")
    if not isinstance(smoke, dict) or smoke.get("pass") is not True:
        fail("the required smoke suite is missing or did not pass")
    if require_full:
        full = suites.get("full")
        if not isinstance(full, dict) or full.get("pass") is not True:
            fail("the required full suite is missing or did not pass")
    for name, suite in suites.items():
        if not isinstance(suite, dict) or suite.get("pass") is not True:
            fail(f"suite {name!r} did not pass")

if stamp is not None:
    if stamp.get("tool") != "teralab-deploy-local":
        fail("deploy stamp tool is not teralab-deploy-local")
    if stamp.get("pass") is not True:
        fail("deploy stamp does not report pass: true")
    if stamp.get("commit") != commit:
        fail(f"deploy stamp commit {stamp.get('commit')!r} does not match {commit}")
    if stamp.get("branch") != branch:
        fail(f"deploy stamp branch {stamp.get('branch')!r} does not match {branch}")
    if stamp.get("version") != version:
        fail(f"deploy stamp version {stamp.get('version')!r} does not match {version}")
    tests = stamp.get("tests")
    if not isinstance(tests, dict):
        fail("deploy stamp tests is missing")
    else:
        if tests.get("skipped") is not False:
            fail("deploy stamp reports tests.skipped (the gate requires a tested deploy)")
        if tests.get("ran") is not True:
            fail("deploy stamp does not report tests.ran: true")
    checks = stamp.get("checks")
    if not isinstance(checks, dict):
        fail("deploy stamp checks is missing")
    else:
        for key in ("services", "health", "ui", "setupClosed", "installPerms"):
            if checks.get(key) is not True:
                fail(f"deploy stamp check {key!r} is not true")
        if "securityHeaders" in checks and checks["securityHeaders"] is not True:
            fail("deploy stamp check 'securityHeaders' is not true")
    deployed_at = parse_time(stamp.get("deployedAt"), "deploy stamp deployedAt")
    if evidence is not None and evidence_started is not None and deployed_at is not None:
        if evidence_started < deployed_at:
            fail("evidence is older than the deploy stamp")
    if evidence is not None and evidence_started is not None:
        now = datetime.now(timezone.utc)
        age = now - evidence_started
        if age < timedelta(0):
            fail("evidence startedAt is in the future")
        elif age > timedelta(seconds=max_age):
            fail(f"evidence is {age} old; limit is {max_age} seconds")

if errors:
    for message in errors:
        print(f"verify: FAIL: {message}", file=sys.stderr)
    sys.exit(1)
print("verify: stamp and evidence fields OK")
PY
}

if [ "$MODE" = "validate-only" ]; then
	if [ ! -f "$VALIDATE_EVIDENCE" ] || [ ! -r "$VALIDATE_EVIDENCE" ]; then
		die "validate-only evidence is not readable: $VALIDATE_EVIDENCE"
	fi
	if [ ! -f "$STAMP_OVERRIDE" ] || [ ! -r "$STAMP_OVERRIDE" ]; then
		die "validate-only stamp is not readable: $STAMP_OVERRIDE"
	fi
	if ! validate_static "$VALIDATE_EVIDENCE" "$STAMP_OVERRIDE" \
		"$CURRENT_COMMIT" "$CURRENT_BRANCH" "$VERSION" "$MAX_AGE" \
		"$REQUIRE_FULL" "$ALLOW_SHORT"; then
		die "validate-only rejected the evidence/stamp pair"
	fi
	printf 'live-check: validate-only OK (not a merge gate)\n'
	exit 0
fi

if [ "$MODE" = "verify" ]; then
	[ -n "$CURRENT_BRANCH" ] || die "cannot determine the current branch"
	[ "$CURRENT_BRANCH" != "HEAD" ] || die "detached HEAD; check out the candidate branch"

	TARGET_COMMIT="${VERIFY_COMMIT:-$CURRENT_COMMIT}"
	case "$TARGET_COMMIT" in
		*[!0-9a-f]*) die "not a commit id: $TARGET_COMMIT" ;;
	esac
	[ "${#TARGET_COMMIT}" -eq 40 ] || die "expected a full 40-character commit id: $TARGET_COMMIT"
	[ "$TARGET_COMMIT" = "$CURRENT_COMMIT" ] \
		|| die "candidate $TARGET_COMMIT is not the checked-out HEAD $CURRENT_COMMIT"

	[ -d "$EVIDENCE_DIR" ] || die "evidence directory does not exist: $EVIDENCE_DIR"
	EVIDENCE="${OUT:-$EVIDENCE_DIR/$TARGET_COMMIT.json}"
	[ -f "$EVIDENCE" ] || die "evidence not found for $TARGET_COMMIT: $EVIDENCE"
	[ -r "$EVIDENCE" ] || die "evidence is not readable: $EVIDENCE"

	STAMP="$CANONICAL_STAMP"
	if [ -n "$STAMP_OVERRIDE" ]; then
		STAMP="$STAMP_OVERRIDE"
		if [ -L "$STAMP" ] || [ ! -f "$STAMP" ] || [ ! -r "$STAMP" ]; then
			die "deploy stamp override is not a readable regular file: $STAMP"
		fi
		printf 'live-check: WARNING: using a non-canonical deploy stamp override; not valid for a merge gate\n' >&2
	else
		if [ ! -d "$CANONICAL_STAMP_DIR" ] || [ -L "$CANONICAL_STAMP_DIR" ]; then
			die "canonical deploy stamp directory is missing or unsafe: $CANONICAL_STAMP_DIR; run /usr/local/libexec/teralab/deploy-local to publish it"
		fi
		canonical_dir_uid="$(stat -c %u -- "$CANONICAL_STAMP_DIR")" \
			|| die "cannot stat the canonical stamp directory"
		[ "$canonical_dir_uid" = 0 ] \
			|| die "canonical stamp directory is not owned by root: $CANONICAL_STAMP_DIR"
		canonical_dir_mode="$(stat -c %a -- "$CANONICAL_STAMP_DIR")" \
			|| die "cannot read the canonical stamp directory mode"
		if (( 8#$canonical_dir_mode & 0022 )); then
			die "canonical stamp directory is group or world writable: $CANONICAL_STAMP_DIR"
		fi
		if (( (8#$canonical_dir_mode & 0005) != 0005 )); then
			die "canonical stamp directory is not world-readable and traversable: $CANONICAL_STAMP_DIR"
		fi

		if [ -L "$STAMP" ] || [ ! -f "$STAMP" ] || [ ! -r "$STAMP" ]; then
			die "canonical deploy stamp is missing or unreadable: $STAMP"
		fi
		canonical_uid="$(stat -c %u -- "$STAMP")" || die "cannot stat the canonical deploy stamp"
		[ "$canonical_uid" = 0 ] || die "canonical deploy stamp is not owned by root: $STAMP"
		canonical_mode="$(stat -c %a -- "$STAMP")" || die "cannot read the canonical deploy stamp mode"
		if (( 8#$canonical_mode & 0022 )); then
			die "canonical deploy stamp is group or world writable: $STAMP"
		fi
		if (( (8#$canonical_mode & 0004) != 0004 )); then
			die "canonical deploy stamp is not world-readable: $STAMP"
		fi
	fi

	printf 'live-check: verifying %s\n' "$TARGET_COMMIT"
	printf '  evidence: %s\n' "$EVIDENCE"
	STAMP_LABEL="canonical"
	if [ -n "$STAMP_OVERRIDE" ]; then
		STAMP_LABEL="testing override"
	fi
	printf '  stamp:    %s (%s)\n' "$STAMP" "$STAMP_LABEL"

	REQUIRE_FULL_FLAG=0
	[ "$REQUIRE_FULL" -eq 1 ] && REQUIRE_FULL_FLAG=1

	if ! validate_static "$EVIDENCE" "$STAMP" "$TARGET_COMMIT" "$CURRENT_BRANCH" \
		"$VERSION" "$MAX_AGE" "$REQUIRE_FULL_FLAG" "$ALLOW_SHORT"; then
		die "evidence/stamp validation failed for $TARGET_COMMIT"
	fi

	HEALTH_JSON="$(curl -fsS --max-time 15 "$URL/api/v2/health" 2>/dev/null)" \
		|| die "health endpoint is not reachable at $URL/api/v2/health"
	read -r HEALTH_STATUS HEALTH_VERSION <<<"$(
		printf '%s' "$HEALTH_JSON" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("status",""), d.get("version",""))'
	)"
	[ "$HEALTH_STATUS" = "ok" ] || die "health status is '$HEALTH_STATUS', expected ok"
	[ "$HEALTH_VERSION" = "$VERSION" ] \
		|| die "running version '$HEALTH_VERSION' does not match TERALAB_VERSION '$VERSION'"

	for unit in teralab-agent apache2 php8.4-fpm; do
		if ! systemctl is-active --quiet "$unit"; then
			die "required service is not active: $unit"
		fi
	done

	printf 'live-check: PASS: %s is deployed and validated\n' "$TARGET_COMMIT"
	exit 0
fi

# ---------------------------------------------------------------------------
# Run mode
# ---------------------------------------------------------------------------

if [ "$MODE" = "smoke" ] && [ "$REQUIRE_FULL" -eq 1 ]; then
	MODE="full"
fi
[ -n "$TIMEOUT" ] || {
	if [ "$MODE" = "full" ]; then TIMEOUT=1800; else TIMEOUT=600; fi
}

DIRTY="$(git_ro status --porcelain --untracked-files=no)"
[ -z "$DIRTY" ] || die "tracked tree is dirty; commit or stash before a live run"

[ "$CURRENT_BRANCH" != "HEAD" ] || die "detached HEAD; check out the candidate branch"

for unit in teralab-agent apache2 php8.4-fpm; do
	systemctl is-active --quiet "$unit" || die "required service is not active: $unit"
done

if [ -n "$PASSWORD_FILE" ]; then
	if [ ! -f "$PASSWORD_FILE" ] || [ ! -r "$PASSWORD_FILE" ]; then
		die "password file is not readable: $PASSWORD_FILE"
	fi
	PASSWORD="$(head -n 1 "$PASSWORD_FILE" | tr -d '\r')"
else
	PASSWORD="${TERALAB_ADMIN_PASSWORD:-}"
	[ -n "$PASSWORD" ] || die "no password source; use --password-file or TERALAB_ADMIN_PASSWORD"
fi
[ -n "$PASSWORD" ] || die "the password source is empty"

HEALTH_JSON="$(curl -fsS --max-time 15 "$URL/api/v2/health" 2>/dev/null)" \
	|| die "health endpoint is not reachable at $URL/api/v2/health"
read -r HEALTH_STATUS HEALTH_VERSION <<<"$(
	printf '%s' "$HEALTH_JSON" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("status",""), d.get("version",""))'
)"
[ "$HEALTH_STATUS" = "ok" ] || die "health status is '$HEALTH_STATUS', expected ok"
[ "$HEALTH_VERSION" = "$VERSION" ] \
	|| die "running version '$HEALTH_VERSION' does not match TERALAB_VERSION '$VERSION'"

[ -d "$ROOT/ui/node_modules" ] || die "ui/node_modules is missing; run: cd ui && npm ci"
command -v npm >/dev/null 2>&1 || die "npm is not on PATH (expected ~/.local/node/bin/npm)"

mkdir -p "$EVIDENCE_DIR"
[ -w "$EVIDENCE_DIR" ] || die "evidence directory is not writable: $EVIDENCE_DIR"
OUT="${OUT:-$EVIDENCE_DIR/$CURRENT_COMMIT.json}"
TIMEOUT_CMD=()
if command -v timeout >/dev/null 2>&1; then
	TIMEOUT_CMD=(timeout --signal=TERM --kill-after=30 "$TIMEOUT")
fi

ARTIFACT_DIR="$EVIDENCE_DIR/artifacts-$CURRENT_COMMIT"
mkdir -p "$ARTIFACT_DIR"

STARTED_AT="$(python3 -c 'import datetime; print(datetime.datetime.now().astimezone().isoformat(timespec="seconds"))')"
START_NS="$(date +%s%N)"
SMOKE_STATUS="notrun"
FULL_STATUS="notrun"
SMOKE_DETAILS="$EVIDENCE_DIR/$CURRENT_COMMIT-smoke-details.json"
FULL_DETAILS="$EVIDENCE_DIR/$CURRENT_COMMIT-full-details.json"
SMOKE_LOG="$EVIDENCE_DIR/$CURRENT_COMMIT-smoke.log"
FULL_LOG="$EVIDENCE_DIR/$CURRENT_COMMIT-full.log"
SMOKE_OUT="$ARTIFACT_DIR/smoke"
FULL_OUT="$ARTIFACT_DIR/full"
mkdir -p "$SMOKE_OUT" "$FULL_OUT"

SUITE_RESULT=""
run_suite() {
	local name="$1" log="$2" details="$3" out="$4"
	shift 4
	local rc=0
	printf '\n== live suite: %s ==\n' "$name"
	(
		cd "$ROOT/ui" || exit 1
		env \
			TERALAB_LIVE_URL="$URL" \
			TERALAB_APACHE_URL="$URL" \
			TERALAB_ADMIN_USER="$ADMIN_USER" \
			TERALAB_ADMIN_PASSWORD="$PASSWORD" \
			TERALAB_EXPECT_VERSION="$VERSION" \
			TERALAB_E2E_OUT="$out" \
			TERALAB_EVIDENCE="$details" \
			"${TIMEOUT_CMD[@]}" "$@"
	) 2>&1 | tee "$log" || rc=${PIPESTATUS[0]}
	if [ "$rc" -eq 0 ]; then
		printf 'live suite %s: PASS\n' "$name"
		SUITE_RESULT="pass"
	else
		printf 'live suite %s: FAIL (exit %d)\n' "$name" "$rc"
		SUITE_RESULT="fail"
	fi
}

if [ "$MODE" = "full" ]; then
	[ -n "${TERALAB_TEST_IMAGE:-}" ] || die "--full requires TERALAB_TEST_IMAGE"
	[ -n "${TERALAB_TEST_ISO:-}" ] || die "--full requires TERALAB_TEST_ISO"
	if [ ! -f "$TERALAB_TEST_IMAGE" ] || [ ! -r "$TERALAB_TEST_IMAGE" ]; then
		die "TERALAB_TEST_IMAGE is not a readable file: $TERALAB_TEST_IMAGE"
	fi
	if [ ! -f "$TERALAB_TEST_ISO" ] || [ ! -r "$TERALAB_TEST_ISO" ]; then
		die "TERALAB_TEST_ISO is not a readable file: $TERALAB_TEST_ISO"
	fi
fi

run_suite smoke "$SMOKE_LOG" "$SMOKE_DETAILS" "$SMOKE_OUT" npm run e2e:live
SMOKE_STATUS="$SUITE_RESULT"

if [ "$MODE" = "full" ]; then
	run_suite full "$FULL_LOG" "$FULL_DETAILS" "$FULL_OUT" \
		env TERALAB_TEST_IMAGE="$TERALAB_TEST_IMAGE" TERALAB_TEST_ISO="$TERALAB_TEST_ISO" npm run e2e:apache
	FULL_STATUS="$SUITE_RESULT"
fi

END_NS="$(date +%s%N)"
DURATION_MS=$(( (END_NS - START_NS) / 1000000 ))
OVERALL="fail"
if [ "$SMOKE_STATUS" = "pass" ] && { [ "$FULL_STATUS" = "notrun" ] || [ "$FULL_STATUS" = "pass" ]; }; then
	OVERALL="pass"
fi

if ! LIVE_EVIDENCE="$OUT" \
	LIVE_COMMIT="$CURRENT_COMMIT" \
	LIVE_BRANCH="$CURRENT_BRANCH" \
	LIVE_VERSION="$VERSION" \
	LIVE_URL="$URL" \
	LIVE_STARTED_AT="$STARTED_AT" \
	LIVE_DURATION_MS="$DURATION_MS" \
	LIVE_OVERALL="$OVERALL" \
	LIVE_SMOKE_STATUS="$SMOKE_STATUS" \
	LIVE_SMOKE_DETAILS="$SMOKE_DETAILS" \
	LIVE_FULL_STATUS="$FULL_STATUS" \
	LIVE_FULL_DETAILS="$FULL_DETAILS" \
	python3 <<'PY'
import json
import os
import re
import tempfile

SECRET = re.compile(
    r"(?i)\b(token|password|secret|csrf|authorization|cookie)(\s*[=:]\s*)([^\s,;&\"']+)"
)


def redact(value):
    if isinstance(value, str):
        return SECRET.sub(r"\1\2<redacted>", value)
    if isinstance(value, list):
        return [redact(item) for item in value]
    if isinstance(value, dict):
        return {key: redact(item) for key, item in value.items()}
    return value


def read_details(path):
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, json.JSONDecodeError):
        return {}
    return data if isinstance(data, dict) else {}


suites = {}
page_errors = []
for name, status, details_path in (
    ("smoke", os.environ["LIVE_SMOKE_STATUS"], os.environ["LIVE_SMOKE_DETAILS"]),
    ("full", os.environ["LIVE_FULL_STATUS"], os.environ["LIVE_FULL_DETAILS"]),
):
    if status == "notrun":
        continue
    details = read_details(details_path)
    checks = details.get("checks")
    if not isinstance(checks, list):
        checks = []
    suites[name] = {"pass": status == "pass", "checks": redact(checks)}
    errors = details.get("pageErrors")
    if isinstance(errors, list):
        page_errors.extend(errors)

document = {
    "tool": "teralab-live-check",
    "pass": os.environ["LIVE_OVERALL"] == "pass",
    "commit": os.environ["LIVE_COMMIT"],
    "branch": os.environ["LIVE_BRANCH"],
    "version": os.environ["LIVE_VERSION"],
    "url": os.environ["LIVE_URL"],
    "startedAt": os.environ["LIVE_STARTED_AT"],
    "durationMs": int(os.environ["LIVE_DURATION_MS"]),
    "suites": suites,
    "pageErrors": redact(page_errors),
    "deployStamp": "live-deploy.json",
}

path = os.environ["LIVE_EVIDENCE"]
directory = os.path.dirname(path) or "."
handle, temporary = tempfile.mkstemp(prefix=".live-check-", dir=directory)
try:
    with os.fdopen(handle, "w", encoding="utf-8") as stream:
        json.dump(document, stream, indent=2, sort_keys=True)
        stream.write("\n")
    os.chmod(temporary, 0o644)
    os.replace(temporary, path)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
PY
then
	die "could not write evidence: $OUT"
fi

printf '\nEvidence: %s\n' "$OUT"
if [ "$OVERALL" != "pass" ]; then
	printf 'live-check: FAILED (smoke=%s full=%s)\n' "$SMOKE_STATUS" "$FULL_STATUS" >&2
	exit 1
fi
printf 'live-check: PASS (smoke=%s full=%s)\n' "$SMOKE_STATUS" "$FULL_STATUS"
