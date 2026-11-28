#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Run the local unit, static, and mock gate for TeraLab.
#
# The script never needs root and never changes the host. It only reads the
# checkout and runs the project toolchains. Caches and build output are
# redirected to a temporary directory outside the repository, and the
# temporary directory is removed on exit.
#
# CI remains an independent check. This script mirrors the CI commands so a
# merge candidate can be gated before it is pushed.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Project toolchains are installed under the owner's home on the build host.
PATH="$HOME/.local/go/bin:$HOME/.local/node/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
export PATH

MODE=full
ACTION=run

usage() {
	cat <<EOF
Usage: scripts/test-all.sh [--fast] [--list] [--help]

Run the TeraLab local unit/static/mock gate without root or host changes.

Options:
  --fast      Skip the UI production build and the mock browser E2E.
  --list      Print the gate commands without running them.
  -h, --help  Show this help.

The full gate runs the policy gates, PHP lint, the API validate/lint/PHPStan/
PHPUnit/audit suites, the Go format/vet/test/build suites, the UI unit/build/
mock-E2E suites, shellcheck, the workflow-tool tests, and the read-only
install-permission checker when the data directory is readable.

Missing prerequisites (api/vendor, ui/node_modules, Go, Node) are reported
with the install command; the script never installs dependencies silently.
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--fast)
			MODE=fast
			shift
			;;
		--list)
			ACTION=list
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			printf 'test-all: unknown option: %s\n\n' "$1" >&2
			usage >&2
			exit 2
			;;
	esac
done

list_suites() {
	cat <<'EOF'
policy/artifact          ./scripts/check-artifact-policy.sh --tracked
policy/licence           ./scripts/check-licence-headers.sh
policy/secrets           ./scripts/check-secrets.sh
php/lint                 ./scripts/php-lint.sh
api/validate             (cd api) composer validate --no-check-publish
api/lint                 (cd api) composer lint
api/stan                 (cd api) composer stan / phpstan (cache in a temp dir)
api/test                 (cd api) composer test (cache in a temp dir)
api/audit                (cd api) composer audit --locked --no-interaction
go/fmt                   (cd agent) gofmt -l .
go/vet                   (cd agent) go vet ./...
go/test                  (cd agent) go test ./...
go/build                 (cd agent) go build ./...
ui/test                  (cd ui) npm test
ui/build                 (cd ui) npm run build (output in a temp dir)
ui/e2e                   (cd ui) npm run e2e (artifacts in a temp dir)
shellcheck               shellcheck <project scripts>
tests/workflow-tools     bash tests/ci/workflow-tools.sh
install/perms            ./scripts/check-install-perms.sh (skipped when unprivileged)
EOF
	if [ "$MODE" = fast ]; then
		printf '\n--fast skips ui/build and ui/e2e.\n'
	fi
}

if [ "$ACTION" = list ]; then
	list_suites
	exit 0
fi

FAIL=0
SUITE_IDS=()
SUITE_STATUS=()
SUITE_DETAILS=()

record() {
	local status="$1" id="$2" detail="$3"
	SUITE_IDS+=("$id")
	SUITE_STATUS+=("$status")
	SUITE_DETAILS+=("$detail")
}

if [ -d /tmp/opencode ]; then
	WORK_PARENT=/tmp/opencode
else
	WORK_PARENT="${TMPDIR:-/tmp}"
fi
WORK="$(mktemp -d "$WORK_PARENT/t.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/logs" "$WORK/phpunit-cache" "$WORK/phpstan-cache"
# Keep TMPDIR short: Go tests bind Unix domain sockets under it, and sockaddr
# paths are limited to about 108 bytes. A deep temporary path breaks them.
export TMPDIR="$WORK"
export GOTMPDIR="$WORK"

missing=()
need_cmd() {
	local cmd="$1" hint="$2"
	if ! command -v "$cmd" >/dev/null 2>&1; then
		missing+=("$cmd ($hint)")
	fi
}
need_file() {
	local path="$1" hint="$2"
	if [ ! -e "$ROOT/$path" ]; then
		missing+=("$path ($hint)")
	fi
}

need_cmd php "install PHP 8.4"
need_cmd composer "install Composer"
need_file api/vendor/autoload.php "cd api && composer install"
need_cmd go "expected at ~/.local/go/bin/go"
need_cmd gofmt "ships with Go"
need_cmd node "expected at ~/.local/node/bin/node"
need_cmd npm "expected at ~/.local/node/bin/npm"
need_file ui/node_modules "cd ui && npm ci"
need_cmd git "required for the policy gates"
need_cmd python3 "required for the long-lived evidence tooling"

if [ "${#missing[@]}" -gt 0 ]; then
	printf 'test-all: missing prerequisites; nothing was run.\n' >&2
	for item in "${missing[@]}"; do
		printf '  - %s\n' "$item" >&2
	done
	printf '\nInstall the dependencies, then run this gate again.\n' >&2
	exit 2
fi

run_argv() {
	local id="$1" desc="$2" workdir="$3"
	shift 3
	local log="$WORK/logs/${id//[^A-Za-z0-9_.-]/_}.log"
	local rc=0
	printf '\n== %s: %s ==\n' "$id" "$desc"
	if [ "$workdir" = "." ]; then
		( cd "$ROOT" && "$@" ) 2>&1 | tee "$log" || rc=${PIPESTATUS[0]}
	else
		( cd "$ROOT/$workdir" && "$@" ) 2>&1 | tee "$log" || rc=${PIPESTATUS[0]}
	fi
	if [ "$rc" -eq 0 ]; then
		record PASS "$id" "$desc"
	else
		printf '\n-- %s failed (exit %d); last log lines: --\n' "$id" "$rc" >&2
		tail -n 40 "$log" >&2 || true
		record FAIL "$id" "$desc"
		FAIL=1
	fi
}

run_argv_retry() {
	local id="$1" desc="$2" workdir="$3"
	shift 3
	local log="$WORK/logs/${id//[^A-Za-z0-9_.-]/_}.log"
	local rc=0 attempt=1
	while [ "$attempt" -le 2 ]; do
		printf '\n== %s: %s (attempt %d) ==\n' "$id" "$desc" "$attempt"
		if [ "$workdir" = "." ]; then
			( cd "$ROOT" && "$@" ) 2>&1 | tee "$log" || rc=${PIPESTATUS[0]}
		else
			( cd "$ROOT/$workdir" && "$@" ) 2>&1 | tee "$log" || rc=${PIPESTATUS[0]}
		fi
		[ "$rc" -eq 0 ] && break
		if [ "$attempt" -eq 1 ]; then
			printf '\n-- %s failed (exit %d); retrying once to distinguish a flake (plan 07 risk 9.7) --\n' "$id" "$rc" >&2
			tail -n 20 "$log" >&2 || true
		fi
		attempt=$((attempt + 1))
	done
	if [ "$rc" -eq 0 ]; then
		record PASS "$id" "$desc"
	else
		printf '\n-- %s failed twice (exit %d); last log lines: --\n' "$id" "$rc" >&2
		tail -n 40 "$log" >&2 || true
		record FAIL "$id" "$desc"
		FAIL=1
	fi
}

skip_suite() {
	local id="$1" desc="$2" reason="$3"
	printf '\n== %s: SKIP: %s ==\n' "$id" "$reason"
	record SKIP "$id" "$reason"
}

# The install-permission checker is read-only, but /var/lib/teralab is
# deliberately 0750 teralab-api:teralab. An unprivileged run cannot inspect the
# database, so mark it SKIP instead of reporting a false failure. The deploy
# helper runs the same checker as root after installing.
install_perms_skippable() {
	[ "$(id -u)" -eq 0 ] && return 1
	local data="${TERALAB_DATA_DIR:-/var/lib/teralab}"
	[ -d "$data" ] && [ ! -r "$data" ]
}

# Prepare the PHPStan configuration outside the repository. PHPStan resolves
# relative paths against the config file that defines them, so the repo config
# is included rather than copied.
cat >"$WORK/phpstan.neon" <<EOF
includes:
    - $ROOT/api/phpstan.neon
parameters:
    tmpDir: $WORK/phpstan-cache
EOF

run_gofmt() {
	local log="$WORK/logs/go_fmt.log" rc=0 files=""
	printf '\n== go/fmt: Go formatting ==\n'
	files="$( cd "$ROOT/agent" && gofmt -l . )" || rc=$?
	if [ "$rc" -eq 0 ] && [ -z "$files" ]; then
		record PASS "go/fmt" "Go formatting"
		return 0
	fi
	if [ -n "$files" ]; then
		printf 'unformatted files:\n%s\n' "$files" >&2
	fi
	printf 'gofmt failed (exit %d)\n' "$rc" >&2
	record FAIL "go/fmt" "Go formatting"
	FAIL=1
}

run_argv "policy/artifact" "tracked-file artifact policy" . ./scripts/check-artifact-policy.sh --tracked
run_argv "policy/licence" "SPDX licence headers" . ./scripts/check-licence-headers.sh
run_argv "policy/secrets" "tracked-file secret scan" . ./scripts/check-secrets.sh
run_argv "php/lint" "PHP syntax for tracked files" . ./scripts/php-lint.sh
run_argv "api/validate" "Composer metadata" api composer validate --no-check-publish
run_argv "api/lint" "API PHP lint" api composer lint
run_argv "api/stan" "API PHPStan level 6" api ./vendor/bin/phpstan analyse --no-progress -c "$WORK/phpstan.neon"
run_argv "api/test" "API PHPUnit" api composer test -- --cache-directory "$WORK/phpunit-cache" --do-not-cache-result
run_argv "api/audit" "API dependency advisories" api composer audit --locked --no-interaction
run_gofmt
run_argv "go/vet" "Go vet" agent go vet ./...
run_argv "go/test" "Go unit tests" agent go test ./...
run_argv "go/build" "Go build" agent go build ./...
run_argv "ui/test" "UI unit tests" ui npm test

if [ "$MODE" = fast ]; then
	skip_suite "ui/build" "UI production build" "--fast"
	skip_suite "ui/e2e" "UI mock browser E2E" "--fast"
else
	run_argv "ui/build" "UI production build" ui npm run build -- --outDir "$WORK/ui-dist" --emptyOutDir
	run_argv_retry "ui/e2e" "UI mock browser E2E" ui env TERALAB_E2E_OUT="$WORK/ui-e2e" npm run e2e
fi

if command -v shellcheck >/dev/null 2>&1; then
	run_argv "shellcheck" "ShellCheck project scripts" . shellcheck \
		scripts/check-licence-headers.sh \
		scripts/check-artifact-policy.sh \
		scripts/php-lint.sh \
		scripts/check-secrets.sh \
		scripts/check-install-perms.sh \
		scripts/dev-vm.sh \
		scripts/setup-runtime-paths.sh \
		scripts/test-all.sh \
		scripts/live-check.sh \
		scripts/deploy-local.sh \
		scripts/live-diagnostics.sh \
		scripts/teralab-project-init.sh \
		packaging/dynamips/build.sh \
		packaging/teralab/apply-state.sh \
		packaging/teralab/build-deb.sh \
		packaging/teralab/install.sh \
		packaging/teralab/postinst.sh \
		packaging/teralab/prerm.sh \
		packaging/teralab/postrm.sh \
		packaging/teralab/prune-tree.sh \
		packaging/teralab/teralab-firewall \
		packaging/teralab/fpm-socket-acl \
		scripts/tests/prune-tree-test.sh \
		tests/ci/workflow-tools.sh
else
	skip_suite "shellcheck" "ShellCheck project scripts" "shellcheck is not installed (CI runs it)"
fi

run_argv "tests/workflow-tools" "Workflow tool unit tests" . bash tests/ci/workflow-tools.sh

if install_perms_skippable; then
	skip_suite "install/perms" "install permission matrix" \
		"unprivileged cannot read ${TERALAB_DATA_DIR:-/var/lib/teralab}; the deploy helper runs this as root"
else
	run_argv "install/perms" "install permission matrix (read-only)" . ./scripts/check-install-perms.sh
fi

printf '\n%-6s %-22s %s\n' RESULT SUITE DETAIL
printf '%-6s %-22s %s\n' ------ ---------------------- ------------------------------
passed=0
failed=0
skipped=0
for index in "${!SUITE_IDS[@]}"; do
	status="${SUITE_STATUS[$index]}"
	printf '%-6s %-22s %s\n' "$status" "${SUITE_IDS[$index]}" "${SUITE_DETAILS[$index]}"
	case "$status" in
		PASS) passed=$((passed + 1)) ;;
		FAIL) failed=$((failed + 1)) ;;
		SKIP) skipped=$((skipped + 1)) ;;
	esac
done
printf '\nSummary: %d passed, %d failed, %d skipped (%d total)\n' \
	"$passed" "$failed" "$skipped" "${#SUITE_IDS[@]}"

if [ "$FAIL" -ne 0 ]; then
	printf 'test-all: FAILED\n' >&2
	exit 1
fi
printf 'test-all: PASS\n'
