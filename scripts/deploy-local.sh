#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Reviewed local deploy helper for the TeraLab host.
#
# Production invocation (fixed defaults, root, no arguments):
#   sudo -n /usr/local/libexec/teralab/deploy-local
#
# The helper refuses to run with a dirty tracked tree, a commit that is not the
# expected candidate, an installed copy that differs from this source, active
# lab engines or TeraLab containers, or insufficient backup space. It builds as
# the source owner, backs up configuration and runtime data plus the installed
# code tree, installs the freshly built package with --force-confold, verifies
# the host, and only then writes the pass stamp.
#
# It never restores a database automatically and never writes history. A failed
# deploy prints the exact code-rollback command; `--rollback <code-backup>`
# restores only /opt/teralab.
set -Eeuo pipefail
umask 077

PATH="$HOME/.local/go/bin:$HOME/.local/node/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
export PATH

SOURCE="/home/savanna/teralab"
ROOT="/opt/teralab"
DATA="/var/lib/teralab"
STAMP="/var/lib/teralab/live-deploy.json"
BACKUP_DIR="/var/backups"
EVIDENCE_DIR="/home/savanna/teralab-work/live-validation"
INSTALLED="/usr/local/libexec/teralab/deploy-local"
EXPECTED_COMMIT=""
DRY_RUN=0
SKIP_TESTS=0
ROLLBACK=""
LIVE_URL="${TERALAB_LIVE_URL:-http://127.0.0.1}"
SERVICES=(teralab-agent apache2 php8.4-fpm)

FAIL_STAGE="startup"
SERVICES_STOPPED=0
CODE_TAR=""
DATA_TAR=""
ARTIFACT=""
ARTIFACT_SHA=""
COMMIT="unknown"
BRANCH="unknown"
VERSION="unknown"
SOURCE_OWNER="unknown"

usage() {
	cat <<EOF
Usage: scripts/deploy-local.sh [options]
       scripts/deploy-local.sh --rollback <code-backup>

Build, back up, install, and verify TeraLab on the local host.

Options:
  --dry-run                 Run the preflight only; no build, backup, or install.
  --source PATH             Source checkout        (default: $SOURCE)
  --root PATH               Installed tree         (default: $ROOT)
  --data PATH               Runtime state          (default: $DATA)
  --stamp PATH              Canonical stamp        (default: $STAMP)
  --backup-dir PATH         Backup directory       (default: $BACKUP_DIR)
  --evidence-dir PATH       Evidence mirror dir    (default: $EVIDENCE_DIR)
  --expected-commit COMMIT  Require this HEAD      (default: current HEAD)
  --skip-tests              Skip scripts/test-all.sh (records tests.skipped)
  --rollback FILE           Restore /opt/teralab from a -code.tgz backup and
                            restart the services. The database is never touched.
  --installed PATH          Installed helper used for the source-hash check
                            (default: $INSTALLED; tests use a temporary copy)
  -h, --help                Show this help

The real run requires root. --dry-run and --help do not. Build and tests run as
the owner of the source checkout so the tree stays owned and the Go/Node
toolchains under that home resolve. Only the reviewed installed helper may run
without a sudo prompt.
EOF
}

log() {
	printf 'deploy-local: %s\n' "$*"
}

need_value() {
	if [ "$#" -lt 2 ] || [ -z "$2" ]; then
		printf 'deploy-local: %s requires a value\n\n' "$1" >&2
		usage >&2
		exit 2
	fi
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--dry-run)
			DRY_RUN=1
			shift
			;;
		--source)
			need_value "$@"
			SOURCE="$2"
			shift 2
			;;
		--root)
			need_value "$@"
			ROOT="$2"
			shift 2
			;;
		--data)
			need_value "$@"
			DATA="$2"
			shift 2
			;;
		--stamp)
			need_value "$@"
			STAMP="$2"
			shift 2
			;;
		--backup-dir)
			need_value "$@"
			BACKUP_DIR="$2"
			shift 2
			;;
		--evidence-dir)
			need_value "$@"
			EVIDENCE_DIR="$2"
			shift 2
			;;
		--expected-commit)
			need_value "$@"
			EXPECTED_COMMIT="$2"
			shift 2
			;;
		--skip-tests)
			SKIP_TESTS=1
			shift
			;;
		--rollback)
			need_value "$@"
			ROLLBACK="$2"
			shift 2
			;;
		--installed)
			need_value "$@"
			INSTALLED="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			printf 'deploy-local: unknown option: %s\n\n' "$1" >&2
			usage >&2
			exit 2
			;;
	esac
done

LIVE_URL="${LIVE_URL%/}"

absolute_path() {
	case "$1" in
		/*) ;;
		*) printf 'deploy-local: %s must be an absolute path: %s\n' "$2" "$1" >&2; exit 2 ;;
	esac
}

absolute_path "$SOURCE" "--source"
absolute_path "$ROOT" "--root"
absolute_path "$DATA" "--data"
absolute_path "$STAMP" "--stamp"
absolute_path "$BACKUP_DIR" "--backup-dir"
absolute_path "$EVIDENCE_DIR" "--evidence-dir"
absolute_path "$INSTALLED" "--installed"

SOURCE="$(realpath -m -- "$SOURCE")"
ROOT="$(realpath -m -- "$ROOT")"
DATA="$(realpath -m -- "$DATA")"
STAMP="$(realpath -m -- "$STAMP")"
BACKUP_DIR="$(realpath -m -- "$BACKUP_DIR")"
EVIDENCE_DIR="$(realpath -m -- "$EVIDENCE_DIR")"
INSTALLED="$(realpath -m -- "$INSTALLED")"

case "$ROOT" in
	/|/usr|/usr/|/etc|/etc/|/var|/var/|/home|/home/|/opt|/opt/)
		printf 'deploy-local: refusing dangerous --root path: %s\n' "$ROOT" >&2
		exit 2
		;;
esac
case "$DATA" in
	"$ROOT"|"$ROOT"/*)
		printf 'deploy-local: --data must not be under --root\n' >&2
		exit 2
		;;
esac
case "$EVIDENCE_DIR" in
	"$ROOT"|"$ROOT"/*|"$SOURCE"|"$SOURCE"/*)
		printf 'deploy-local: --evidence-dir must be outside the source and install trees\n' >&2
		exit 2
		;;
esac
case "$STAMP" in
	"$ROOT"|"$ROOT"/*)
		printf 'deploy-local: --stamp must not be under --root\n' >&2
		exit 2
		;;
esac
case "$BACKUP_DIR" in
	/|"$SOURCE"|"$SOURCE"/*|"$ROOT"|"$ROOT"/*)
		printf 'deploy-local: refusing --backup-dir: %s\n' "$BACKUP_DIR" >&2
		exit 2
		;;
esac
case "$LIVE_URL" in
	http://*|https://*) ;;
	*) printf 'deploy-local: TERALAB_LIVE_URL must start with http(s)://\n' >&2; exit 2 ;;
esac

if [ -n "$EXPECTED_COMMIT" ]; then
	case "$EXPECTED_COMMIT" in
		*[!0-9a-f]*) printf 'deploy-local: invalid --expected-commit: %s\n' "$EXPECTED_COMMIT" >&2; exit 2 ;;
	esac
	[ "${#EXPECTED_COMMIT}" -eq 40 ] \
		|| { printf 'deploy-local: --expected-commit must be 40 hex characters\n' >&2; exit 2; }
fi

if [ -n "$ROLLBACK" ]; then
	if [ "$(id -u)" -ne 0 ]; then
		printf 'deploy-local: rollback requires root\n' >&2
		exit 1
	fi
	if [ ! -f "$ROLLBACK" ] || [ ! -r "$ROLLBACK" ]; then
		printf 'deploy-local: rollback archive is not readable: %s\n' "$ROLLBACK" >&2
		exit 1
	fi
	root_rel="${ROOT#/}"
	entries="$(tar -tzf "$ROLLBACK")" \
		|| { printf 'deploy-local: rollback archive is not a valid tarball: %s\n' "$ROLLBACK" >&2; exit 1; }
	[ -n "$entries" ] || { printf 'deploy-local: rollback archive is empty\n' >&2; exit 1; }
	while IFS= read -r entry; do
		entry="${entry#./}"
		case "$entry" in
			"$root_rel"|"$root_rel"/*) ;;
			*) printf 'deploy-local: refusing rollback entry outside %s: %s\n' "$ROOT" "$entry" >&2; exit 1 ;;
		esac
		case "/$entry/" in
			*/../*) printf 'deploy-local: refusing rollback entry with ..: %s\n' "$entry" >&2; exit 1 ;;
		esac
	done <<<"$entries"
	printf 'deploy-local: restoring %s from %s\n' "$ROOT" "$ROLLBACK"
	tar -xzf "$ROLLBACK" -C /
	systemctl start apache2 php8.4-fpm
	systemctl restart teralab-agent
	printf 'deploy-local: rollback complete; the database and labs were not touched\n'
	exit 0
fi

if [ "$DRY_RUN" -ne 1 ] && [ "$(id -u)" -ne 0 ]; then
	printf 'deploy-local: a real deploy requires root; use --dry-run for preflight\n' >&2
	exit 1
fi

record_failure() {
	local message="$1"
	set +e
	trap - ERR
	printf 'deploy-local: FAILED during %s: %s\n' "$FAIL_STAGE" "$message" >&2
	if [ "$SERVICES_STOPPED" -eq 1 ]; then
		printf 'deploy-local: restarting services after the failed install\n' >&2
		systemctl start apache2 php8.4-fpm 2>/dev/null
		systemctl restart teralab-agent 2>/dev/null
	fi
	if [ "$DRY_RUN" -ne 1 ] && command -v python3 >/dev/null 2>&1 && [ "$EVIDENCE_DIR" != "/" ]; then
		mkdir -p "$EVIDENCE_DIR" 2>/dev/null
		FAILED_JSON="$EVIDENCE_DIR/deploy-$COMMIT-failed.json"
		FAILED_ROLLBACK=""
		if [ -n "$CODE_TAR" ]; then
			FAILED_ROLLBACK="sudo /usr/local/libexec/teralab/deploy-local --rollback $CODE_TAR"
		fi
		DEPLOY_FAILURE_STAGE="$FAIL_STAGE" \
		DEPLOY_FAILURE_MESSAGE="$message" \
		DEPLOY_FAILURE_COMMIT="$COMMIT" \
		DEPLOY_FAILURE_ROLLBACK="$FAILED_ROLLBACK" \
		DEPLOY_FAILURE_BACKUP="$DATA_TAR" \
		DEPLOY_FAILURE_CODE="$CODE_TAR" \
		DEPLOY_FAILURE_PATH="$FAILED_JSON" \
		python3 <<'PY' 2>/dev/null
import datetime
import json
import os

document = {
    "tool": "teralab-deploy-local",
    "pass": False,
    "stage": os.environ["DEPLOY_FAILURE_STAGE"],
    "error": os.environ["DEPLOY_FAILURE_MESSAGE"],
    "commit": os.environ["DEPLOY_FAILURE_COMMIT"],
    "failedAt": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
    "backup": os.environ["DEPLOY_FAILURE_BACKUP"],
    "codeBackup": os.environ["DEPLOY_FAILURE_CODE"],
    "rollback": os.environ["DEPLOY_FAILURE_ROLLBACK"],
}
path = os.environ["DEPLOY_FAILURE_PATH"]
with open(path, "w", encoding="utf-8") as handle:
    json.dump(document, handle, indent=2, sort_keys=True)
    handle.write("\n")
os.chmod(path, 0o644)
PY
		if [ -f "$EVIDENCE_DIR/deploy-$COMMIT-failed.json" ] \
			&& [ "$SOURCE_OWNER" != "unknown" ] && [ "$(id -u)" -eq 0 ]; then
			chown "$SOURCE_OWNER" "$EVIDENCE_DIR/deploy-$COMMIT-failed.json" 2>/dev/null
		fi
		printf 'deploy-local: failure record: %s\n' "$EVIDENCE_DIR/deploy-$COMMIT-failed.json" >&2
	fi
	if [ -n "$CODE_TAR" ]; then
		printf 'deploy-local: roll back the code with:\n  sudo /usr/local/libexec/teralab/deploy-local --rollback %s\n' \
			"$CODE_TAR" >&2
	fi
	printf 'deploy-local: the database and labs were not restored\n' >&2
}

die() {
	printf 'deploy-local: %s\n' "$*" >&2
	record_failure "$*"
	exit 1
}

rc=0
trap 'rc=$?; trap - ERR; record_failure "unexpected failure (status $rc)"; exit "$rc"' ERR

safe_git() {
	git -c "safe.directory=$SOURCE" -C "$SOURCE" "$@"
}

sha256_of() {
	sha256sum -- "$1" | awk '{print $1}'
}

# shellcheck disable=SC2016 # $1/$@ are positional parameters of the inner bash, not this script.
run_as_owner() {
	runuser -u "$SOURCE_OWNER" -- \
		env HOME="$SOURCE_HOME" USER="$SOURCE_OWNER" LOGNAME="$SOURCE_OWNER" \
		PATH="$OWNER_PATH" TMPDIR="${TMPDIR:-/tmp}" \
		bash -c 'cd "$1" || exit 1; shift; exec "$@"' bash "$SOURCE" "$@"
}

check_dir_writable() {
	local dir="$1" label="$2"
	local probe="$dir"
	while [ ! -e "$probe" ]; do
		local parent
		parent="$(dirname "$probe")"
		[ "$parent" != "$probe" ] || break
		probe="$parent"
	done
	if [ ! -d "$probe" ] || [ ! -w "$probe" ]; then
		if [ "$DRY_RUN" -eq 1 ] && [ "$(id -u)" -ne 0 ]; then
			log "note: $label needs root to write ($probe); the real run verifies it"
			return 0
		fi
		die "$label is not writable: $dir"
	fi
}

scan_lab_engines() {
	[ -d "$DATA" ] || return 0
	local data_real proc comm cwd hits=""
	data_real="$(realpath -m -- "$DATA")"
	for proc in /proc/[0-9]*; do
		[ -r "$proc/comm" ] || continue
		comm="$(cat "$proc/comm" 2>/dev/null)" || continue
		case "$comm" in
			qemu*|dynamips|iouyap|vpcs|i86bi*) ;;
			*) continue ;;
		esac
		cwd="$(readlink "$proc/cwd" 2>/dev/null)" || continue
		case "$cwd" in
			"$data_real"|"$data_real"/*)
				hits+="$comm(${proc##*/}) "
				;;
		esac
	done
	[ -z "$hits" ] || die "lab engines are running under $DATA: $hits; ask the owner to stop them"
}

scan_containers() {
	command -v docker >/dev/null 2>&1 || {
		log "docker is not available; skipping the container guard"
		return 0
	}
	local listing hits
	if ! listing="$(docker ps --format '{{.ID}} {{.Names}} {{.Label "teralab.lab"}} {{.Label "teralab.node"}}' 2>/dev/null)"; then
		log "docker ps is not permitted; skipping the container guard"
		return 0
	fi
	hits="$(printf '%s\n' "$listing" | awk 'NF >= 3 { lab=$3; node=$4; if ((lab != "" && lab != "<no value>") || (node != "" && node != "<no value>")) print $1, $2 }')"
	[ -z "$hits" ] || die "TeraLab-labelled containers are running: $hits; ask the owner to stop them"
}

check_free_space() {
	local df_target="$BACKUP_DIR" used=0 available="" path_kb=""
	[ -d "$df_target" ] || df_target="$(dirname "$BACKUP_DIR")"
	[ -d "$df_target" ] || df_target="/tmp"
	available="$(df -Pk "$df_target" | awk 'NR == 2 {print $4}')"
	[ -n "$available" ] || die "cannot determine free space for $BACKUP_DIR"
	for path in "$ROOT" "$DATA"; do
		if [ -e "$path" ]; then
			path_kb="$(du -sk -- "$path" 2>/dev/null | awk '{print $1}')" || path_kb=0
			[ -n "$path_kb" ] || path_kb=0
			used=$((used + path_kb))
		fi
	done
	local required=$((used + 262144))
	[ "$available" -ge "$required" ] \
		|| die "not enough free space on $df_target: ${available} KiB available, ${required} KiB required"
}

post_services() {
	local unit
	for unit in "${SERVICES[@]}"; do
		systemctl is-active --quiet "$unit" || {
			log "service is not active: $unit"
			return 1
		}
	done
	return 0
}

post_health() {
	local json status version
	json="$(curl -fsS --retry 5 --retry-delay 2 --retry-connrefused --max-time 15 "$LIVE_URL/api/v2/health" 2>/dev/null)" || {
		log "health endpoint is unreachable: $LIVE_URL/api/v2/health"
		return 1
	}
	read -r status version <<<"$(
		printf '%s' "$json" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("status",""), d.get("version",""))'
	)" || return 1
	if [ "$status" != "ok" ]; then
		log "health status is '$status', expected ok"
		return 1
	fi
	if [ "$version" != "$VERSION" ]; then
		log "health version '$version' does not match TERALAB_VERSION '$VERSION'"
		return 1
	fi
	return 0
}

post_ui() {
	local code
	code="$(curl -sS --retry 5 --retry-delay 2 --retry-connrefused -o /dev/null -w '%{http_code}' --max-time 15 "$LIVE_URL/ui/" 2>/dev/null)" || return 1
	[ "$code" = "200" ] || { log "UI returned $code, expected 200"; return 1; }
	return 0
}

post_setup_closed() {
	local code
	code="$(curl -sS --retry 3 --retry-delay 2 --retry-connrefused -o /dev/null -w '%{http_code}' --max-time 15 \
		-X POST -H 'Content-Type: application/json' -d '{}' \
		"$LIVE_URL/api/v2/setup" 2>/dev/null)" || return 1
	case "$code" in
		2??) log "setup endpoint answered $code; first-run setup is still open"; return 1 ;;
		'') log "setup endpoint returned no status"; return 1 ;;
	esac
	return 0
}

post_headers() {
	local headers
	headers="$(curl -sS --retry 5 --retry-delay 2 --retry-connrefused -D - -o /dev/null --max-time 15 "$LIVE_URL/ui/" 2>/dev/null)" || return 1
	grep -qi '^content-security-policy:' <<<"$headers" \
		|| { log "Content-Security-Policy header is missing"; return 1; }
	grep -qi '^x-content-type-options:[[:space:]]*nosniff' <<<"$headers" \
		|| { log "X-Content-Type-Options: nosniff is missing"; return 1; }
	grep -qi '^x-frame-options:' <<<"$headers" \
		|| { log "X-Frame-Options header is missing"; return 1; }
	return 0
}

post_install_perms() {
	[ -f "$SOURCE/scripts/check-install-perms.sh" ] \
		|| { log "install-permission checker is missing from the source"; return 1; }
	bash "$SOURCE/scripts/check-install-perms.sh" --root "$ROOT" --data "$DATA"
}

post_root_owner() {
	local bad
	bad="$(find "$ROOT" \( ! -user root -o -perm /022 \) -print -quit 2>/dev/null)" || {
		log "cannot inspect $ROOT ownership"
		return 1
	}
	if [ -n "$bad" ]; then
		log "installed path is not root-owned and non-writable: $bad"
		return 1
	fi
	return 0
}

write_stamp() {
	mkdir -p "$(dirname "$STAMP")"
	DEPLOY_STAMP_PATH="$STAMP" \
	DEPLOY_MIRROR_DIR="$EVIDENCE_DIR" \
	DEPLOY_COMMIT="$COMMIT" \
	DEPLOY_BRANCH="$BRANCH" \
	DEPLOY_VERSION="$VERSION" \
	DEPLOY_ARTIFACT="$ARTIFACT" \
	DEPLOY_SHA="$ARTIFACT_SHA" \
	DEPLOY_BACKUP="$DATA_TAR" \
	DEPLOY_CODE="$CODE_TAR" \
	DEPLOY_TESTS_RAN="$1" \
	DEPLOY_TESTS_SKIPPED="$2" \
	DEPLOY_CHECK_SERVICES="$3" \
	DEPLOY_CHECK_HEALTH="$4" \
	DEPLOY_CHECK_UI="$5" \
	DEPLOY_CHECK_SETUP="$6" \
	DEPLOY_CHECK_PERMS="$7" \
	DEPLOY_OWNER="$SOURCE_OWNER" \
	python3 <<'PY'
import datetime
import json
import os

document = {
    "tool": "teralab-deploy-local",
    "pass": True,
    "commit": os.environ["DEPLOY_COMMIT"],
    "branch": os.environ["DEPLOY_BRANCH"],
    "version": os.environ["DEPLOY_VERSION"],
    "deployedAt": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
    "artifact": os.environ["DEPLOY_ARTIFACT"],
    "artifactSha256": os.environ["DEPLOY_SHA"],
    "backup": os.environ["DEPLOY_BACKUP"],
    "codeBackup": os.environ["DEPLOY_CODE"],
    "tests": {
        "ran": os.environ["DEPLOY_TESTS_RAN"] == "1",
        "skipped": os.environ["DEPLOY_TESTS_SKIPPED"] == "1",
    },
    "checks": {
        "services": os.environ["DEPLOY_CHECK_SERVICES"] == "1",
        "health": os.environ["DEPLOY_CHECK_HEALTH"] == "1",
        "ui": os.environ["DEPLOY_CHECK_UI"] == "1",
        "setupClosed": os.environ["DEPLOY_CHECK_SETUP"] == "1",
        "installPerms": os.environ["DEPLOY_CHECK_PERMS"] == "1",
    },
}

mirror_dir = os.environ["DEPLOY_MIRROR_DIR"]
os.makedirs(mirror_dir, exist_ok=True)
mirror = os.path.join(mirror_dir, f"deploy-{os.environ['DEPLOY_COMMIT']}.json")
with open(mirror, "w", encoding="utf-8") as handle:
    json.dump(document, handle, indent=2, sort_keys=True)
    handle.write("\n")
os.chmod(mirror, 0o644)
os.chown(mirror, int(os.environ["DEPLOY_OWNER_UID"]), int(os.environ["DEPLOY_OWNER_GID"]))

# The canonical pass stamp is written last, after the readable mirror exists.
path = os.environ["DEPLOY_STAMP_PATH"]
with open(path, "w", encoding="utf-8") as handle:
    json.dump(document, handle, indent=2, sort_keys=True)
    handle.write("\n")
os.chmod(path, 0o644)
os.chown(path, 0, 0)
PY
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
FAIL_STAGE="preflight"

[ -d "$SOURCE" ] || die "source tree does not exist: $SOURCE"
safe_git rev-parse --git-dir >/dev/null 2>&1 || die "source is not a git checkout: $SOURCE"

HEAD_COMMIT="$(safe_git rev-parse HEAD)"
COMMIT="$HEAD_COMMIT"
if [ -n "$EXPECTED_COMMIT" ] && [ "$EXPECTED_COMMIT" != "$HEAD_COMMIT" ]; then
	die "expected commit $EXPECTED_COMMIT but HEAD is $HEAD_COMMIT"
fi
[ -n "$EXPECTED_COMMIT" ] || EXPECTED_COMMIT="$HEAD_COMMIT"

BRANCH="$(safe_git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" != "HEAD" ] || die "detached HEAD; check out the candidate branch"

DIRTY="$(safe_git status --porcelain --untracked-files=no)"
[ -z "$DIRTY" ] || die "tracked tree is dirty; commit or stash the changes before deploying"

VERSION_FILE="$SOURCE/TERALAB_VERSION"
[ -f "$VERSION_FILE" ] || die "TERALAB_VERSION is missing from $SOURCE"
VERSION="$(tr -d '[:space:]' <"$VERSION_FILE")"
[ -n "$VERSION" ] || die "TERALAB_VERSION is empty"
safe_git ls-files --error-unmatch TERALAB_VERSION >/dev/null 2>&1 \
	|| die "TERALAB_VERSION is not tracked by git"

[ -f "$INSTALLED" ] || die "installed helper not found: $INSTALLED; reinstall scripts/deploy-local.sh as root first"
installed_hash="$(sha256_of "$INSTALLED")"
source_hash="$(sha256_of "$SOURCE/scripts/deploy-local.sh")"
[ "$installed_hash" = "$source_hash" ] \
	|| die "installed helper is stale; reinstall $SOURCE/scripts/deploy-local.sh at $INSTALLED (source $source_hash, installed $installed_hash)"

SOURCE_OWNER="$(stat -c '%U' "$SOURCE")"
SOURCE_GROUP="$(stat -c '%G' "$SOURCE")"
SOURCE_HOME="$(getent passwd "$SOURCE_OWNER" | cut -d: -f6)"
[ -n "$SOURCE_HOME" ] || die "cannot resolve the home directory of $SOURCE_OWNER"
OWNER_PATH="$SOURCE_HOME/.local/go/bin:$SOURCE_HOME/.local/node/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

check_dir_writable "$BACKUP_DIR" "backup directory"
check_dir_writable "$EVIDENCE_DIR" "evidence directory"
check_free_space
scan_lab_engines
scan_containers

if [ "$DRY_RUN" -eq 1 ]; then
	printf '\ndeploy-local: DRY RUN preflight OK\n'
	printf '  source:          %s (%s, %s)\n' "$SOURCE" "$BRANCH" "$COMMIT"
	printf '  expected commit: %s\n' "$EXPECTED_COMMIT"
	printf '  version:         %s\n' "$VERSION"
	printf '  installed:       %s (hash matches)\n' "$INSTALLED"
	printf '  root/data:       %s / %s\n' "$ROOT" "$DATA"
	printf '  stamp:           %s\n' "$STAMP"
	printf '  backup dir:      %s\n' "$BACKUP_DIR"
	printf '  evidence dir:    %s\n' "$EVIDENCE_DIR"
	printf '  build owner:     %s\n' "$SOURCE_OWNER"
	if [ "$SKIP_TESTS" -eq 1 ]; then
		printf '  tests:           SKIPPED (not valid for a merge)\n'
	else
		printf '  tests:           %s/scripts/test-all.sh as %s\n' "$SOURCE" "$SOURCE_OWNER"
	fi
	printf '  would build:     %s/packaging/teralab/build-deb.sh --output %s/dist\n' "$SOURCE" "$SOURCE"
	printf '  would back up:   %s and %s plus /etc/teralab\n' "$BACKUP_DIR" "$DATA"
	printf '  would install:   apt-get install --reinstall -o Dpkg::Options::=--force-confold <artifact>\n'
	printf '  would stamp:     %s\n' "$STAMP"
	printf '\nNo changes were made.\n'
	exit 0
fi

# ---------------------------------------------------------------------------
# Build as the source owner
# ---------------------------------------------------------------------------
FAIL_STAGE="build"

[ -d "$ROOT" ] || die "installed tree does not exist: $ROOT"
[ -d "$DATA" ] || die "runtime data directory does not exist: $DATA"

command -v runuser >/dev/null 2>&1 || die "runuser is required (util-linux)"
command -v python3 >/dev/null 2>&1 || die "python3 is required"
[ -x "$SOURCE/packaging/teralab/build-deb.sh" ] \
	|| die "build script is missing or not executable: $SOURCE/packaging/teralab/build-deb.sh"

[ -d "$EVIDENCE_DIR" ] || mkdir -p "$EVIDENCE_DIR"
chown "$SOURCE_OWNER:$SOURCE_GROUP" "$EVIDENCE_DIR" 2>/dev/null \
	|| die "cannot hand the evidence directory to $SOURCE_OWNER"
chmod 0750 "$EVIDENCE_DIR"

TESTS_RAN=0
TESTS_SKIPPED=1
if [ "$SKIP_TESTS" -eq 1 ]; then
	log "skipping scripts/test-all.sh as requested; this deploy cannot gate a merge"
else
	log "running scripts/test-all.sh as $SOURCE_OWNER"
	run_as_owner bash "$SOURCE/scripts/test-all.sh" \
		|| die "the local test gate failed; no host changes were made"
	TESTS_RAN=1
	TESTS_SKIPPED=0
fi

log "building the Debian package as $SOURCE_OWNER"
BUILD_START="$(date +%s)"
run_as_owner "$SOURCE/packaging/teralab/build-deb.sh" --output "$SOURCE/dist" \
	|| die "the package build failed"

DEB_VERSION="${VERSION#v}"
case "$DEB_VERSION" in
	*-*) DEB_VERSION="${DEB_VERSION%%-*}~${DEB_VERSION#*-}-1" ;;
	*) DEB_VERSION="${DEB_VERSION}-1" ;;
esac
ARTIFACT="$SOURCE/dist/teralab_${DEB_VERSION}_amd64.deb"
[ -f "$ARTIFACT" ] || die "expected artifact not found: $ARTIFACT"
artifact_mtime="$(stat -c %Y "$ARTIFACT")"
[ "$artifact_mtime" -ge "$((BUILD_START - 5))" ] \
	|| die "artifact is older than this build: $ARTIFACT"
[ "$(dpkg-deb -f "$ARTIFACT" Package)" = "teralab" ] || die "artifact package name is not teralab"
[ "$(dpkg-deb -f "$ARTIFACT" Version)" = "$DEB_VERSION" ] \
	|| die "artifact version does not match TERALAB_VERSION ($DEB_VERSION)"
ARTIFACT_SHA="$(sha256_of "$ARTIFACT")"

CURRENT_HEAD="$(safe_git rev-parse HEAD)"
[ "$CURRENT_HEAD" = "$EXPECTED_COMMIT" ] \
	|| die "HEAD moved during the build ($CURRENT_HEAD); refused to install"
DIRTY="$(safe_git status --porcelain --untracked-files=no)"
[ -z "$DIRTY" ] || die "tracked tree changed during the build; refused to install"

# ---------------------------------------------------------------------------
# Backup
# ---------------------------------------------------------------------------
FAIL_STAGE="backup"
STAMP_ID="$(date +%Y%m%d%H%M%S)"
BRANCH_SAFE="$(printf '%s' "$BRANCH" | tr '/ ' '--' | tr -cd 'A-Za-z0-9._-')"
[ -n "$BRANCH_SAFE" ] || BRANCH_SAFE="branch"
DATA_TAR="$BACKUP_DIR/teralab-$STAMP_ID-pre-$BRANCH_SAFE.tgz"
CODE_TAR="$BACKUP_DIR/teralab-$STAMP_ID-pre-$BRANCH_SAFE-code.tgz"
ROOT_REL="${ROOT#/}"

mkdir -p "$BACKUP_DIR"
log "backing up configuration and runtime data to $DATA_TAR"
tar -czf "$DATA_TAR" --ignore-failed-read -C / etc/teralab \
	|| die "cannot back up /etc/teralab"
for entry in database.sdb database.sdb-wal database.sdb-shm labs logs backups; do
	if [ -e "$DATA/$entry" ]; then
		tar -rzf "$DATA_TAR" -C "$DATA" "$entry" \
			|| die "cannot back up $DATA/$entry"
	fi
done
[ -s "$DATA_TAR" ] || die "the data backup is empty"

log "backing up the installed code to $CODE_TAR"
tar -czf "$CODE_TAR" -C / "$ROOT_REL" || die "cannot back up $ROOT"
[ -s "$CODE_TAR" ] || die "the code backup is empty"
chmod 0600 "$DATA_TAR" "$CODE_TAR"

tar -tzf "$DATA_TAR" >/dev/null 2>&1 || die "the data backup is not a valid tarball"
tar -tzf "$CODE_TAR" "$ROOT_REL" >/dev/null 2>&1 || die "the code backup does not contain $ROOT"
log "both backups verified"

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------
FAIL_STAGE="install"
log "stopping ${SERVICES[*]}"
SERVICES_STOPPED=1
systemctl stop teralab-agent apache2 php8.4-fpm

log "installing $ARTIFACT"
DEBIAN_FRONTEND=noninteractive apt-get install -y --reinstall \
	-o Dpkg::Options::=--force-confold "$ARTIFACT" \
	|| die "apt-get install --reinstall failed; use the printed rollback command"

systemctl enable apache2 php8.4-fpm teralab-agent
systemctl start apache2 php8.4-fpm
systemctl restart teralab-agent
SERVICES_STOPPED=0

# ---------------------------------------------------------------------------
# Post-install checks
# ---------------------------------------------------------------------------
FAIL_STAGE="verify"
CHECK_SERVICES=0
CHECK_HEALTH=0
CHECK_UI=0
CHECK_SETUP=0
CHECK_HEADERS=0
CHECK_PERMS=0
CHECK_ROOT=0
if post_services; then CHECK_SERVICES=1; else log "post-install service check failed"; fi
if post_health; then CHECK_HEALTH=1; else log "post-install health check failed"; fi
if post_ui; then CHECK_UI=1; else log "post-install UI check failed"; fi
if post_setup_closed; then CHECK_SETUP=1; else log "post-install setup-closed check failed"; fi
if post_headers; then CHECK_HEADERS=1; else log "post-install security-header check failed"; fi
if post_install_perms; then CHECK_PERMS=1; else log "post-install permission check failed"; fi
if post_root_owner; then CHECK_ROOT=1; else log "post-install ownership check failed"; fi

if [ "$CHECK_SERVICES$CHECK_HEALTH$CHECK_UI$CHECK_SETUP$CHECK_HEADERS$CHECK_PERMS$CHECK_ROOT" != "1111111" ]; then
	die "one or more post-install checks failed"
fi

FAIL_STAGE="stamp"
DEPLOY_OWNER_UID="$(id -u "$SOURCE_OWNER")"
DEPLOY_OWNER_GID="$(id -g "$SOURCE_OWNER")"
export DEPLOY_OWNER_UID DEPLOY_OWNER_GID
write_stamp "$TESTS_RAN" "$TESTS_SKIPPED" \
	"$CHECK_SERVICES" "$CHECK_HEALTH" "$CHECK_UI" "$CHECK_SETUP" "$CHECK_PERMS" \
	|| die "cannot write the deploy stamp"

MIRROR="$EVIDENCE_DIR/deploy-$COMMIT.json"
log "deploy stamp: $STAMP"
log "evidence mirror: $MIRROR"
printf '\ndeploy-local: PASS\n'
printf '  commit:    %s (%s)\n' "$COMMIT" "$BRANCH"
printf '  version:   %s\n' "$VERSION"
printf '  artifact:  %s\n' "$ARTIFACT"
printf '  sha256:    %s\n' "$ARTIFACT_SHA"
printf '  backup:    %s\n' "$DATA_TAR"
printf '  code:      %s\n' "$CODE_TAR"
printf '  tests:     %s\n' "$([ "$TESTS_SKIPPED" -eq 1 ] && echo skipped || echo ran)"
printf '\nNext: ./scripts/live-check.sh --verify %s\n' "$COMMIT"
