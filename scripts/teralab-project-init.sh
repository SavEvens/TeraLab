#!/usr/bin/env bash
# ============================================================================
# teralab-project-init.sh
#
# One-shot TeraLab v1.1.0 baseline bootstrap for a fresh Debian-family server.
#
# What it does:
#   1. Installs modern UNetLab *dependencies* only (Apache, PHP, MariaDB, QEMU,
#      build tools). It does NOT install, configure, or start UNetLab itself.
#   2. Clones UNetLab and pins the licence-clean 1.0.2-2 commit.
#   3. Creates a TeraLab fork branch and a v1.1.0 baseline commit + tag.
#   4. Writes the AGPLv3 LICENSE, BSD-3 upstream text, NOTICE, PROVENANCE,
#      AGENTS.md, README-BASELINE, and the baseline manifest.
#
# The repo is placed at:  <home-of-invoking-user>/unetlab
#
# This script is deliberately conservative:
#   - no third-party apt repositories
#   - no UNetLab vhost, database schema, wrappers, engines, or lab data
#   - no host bridge/firewall/network changes
#   - no git push
# ============================================================================

set -Eeuo pipefail

UPSTREAM_URL="https://github.com/dainok/unetlab.git"
UPSTREAM_COMMIT="8ef51974b448318697e5dd18a4e10496f2f56b39"
UPSTREAM_VERSION="1.0.2-2"
TERALAB_VERSION="1.1.0"
TERALAB_TAG="v1.1.0"
DEFAULT_BRANCH="main"
AGPL_URL="https://www.gnu.org/licenses/agpl-3.0.txt"
AGPL_SHA256="0d96a4ff68ad6d4b6f1f30f713b18d5184912ba8dd389f86aa7710db079abcb0"

SELF="$(readlink -f "$0")"
SCRIPT_NAME="$(basename "$SELF")"
LOG="${TMPDIR:-/tmp}/teralab-project-init-$$.log"

DIR_OVERRIDE=""
NO_DEPS=0
NO_COMMIT=0
CHECK_ONLY=0
DRY_RUN=0
FORCE=0
VERBOSE=0
GIT_NAME="${TERALAB_GIT_NAME:-TeraLab Bootstrap}"
GIT_EMAIL="${TERALAB_GIT_EMAIL:-bootstrap@teralab.invalid}"

usage() {
  cat <<'EOF'
TeraLab v1.1.0 baseline bootstrap

Usage: sudo ./teralab-project-init.sh [options]

Options:
  --dir PATH          Repo directory (default: <invoking-user-home>/unetlab)
  --no-deps           Skip apt package installation
  --no-commit         Write baseline files but do not commit/tag
  --check             Verify an existing baseline only; make no changes
  --dry-run           Print the plan; make no changes
  --force             Overwrite generated baseline files if they exist
  --user-name NAME    Git author name (default: TeraLab Bootstrap)
  --user-email EMAIL  Git author email (default: bootstrap@teralab.invalid)
  --verbose           Verbose shell tracing
  -h, --help          This help

Exit codes: 0 success, 1 verification/setup failure, 2 usage error.
EOF
}

log()  { printf '[%s] %s\n' "$(date -u '+%H:%M:%SZ')" "$*"; }
warn() { printf '[%s] WARNING: %s\n' "$(date -u '+%H:%M:%SZ')" "$*" >&2; }
die()  { printf '[%s] ERROR: %s\n' "$(date -u '+%H:%M:%SZ')" "$*" >&2; exit 1; }

on_error() {
  local rc=$?
  printf '\n[bootstrap] failed at line %s (exit %s)\n' "${BASH_LINENO[0]:-?}" "$rc" >&2
  printf '[bootstrap] log: %s\n' "$LOG" >&2
  exit "$rc"
}
trap on_error ERR

# ---------------------------------------------------------------- arguments
ORIG_ARGS=("$@")
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) DIR_OVERRIDE="${2:-}"; [ -n "$DIR_OVERRIDE" ] || { usage; exit 2; }; shift 2 ;;
    --no-deps) NO_DEPS=1; shift ;;
    --no-commit) NO_COMMIT=1; shift ;;
    --check) CHECK_ONLY=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    --force) FORCE=1; shift ;;
    --user-name) GIT_NAME="${2:-}"; [ -n "$GIT_NAME" ] || { usage; exit 2; }; shift 2 ;;
    --user-email) GIT_EMAIL="${2:-}"; [ -n "$GIT_EMAIL" ] || { usage; exit 2; }; shift 2 ;;
    --verbose) VERBOSE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage; exit 2 ;;
  esac
done

[ "$VERBOSE" -eq 1 ] && set -x

# Start logging as early as possible.
exec > >(tee -a "$LOG") 2>&1

# ---------------------------------------------------------------- identity
if [ -n "${SUDO_USER:-}" ]; then
  REAL_USER="$SUDO_USER"
else
  REAL_USER="$(id -un)"
fi
REAL_HOME="$(getent passwd "$REAL_USER" 2>/dev/null | awk -F: '{print $6}')"
[ -n "${REAL_HOME:-}" ] || REAL_HOME="$HOME"
[ -d "$REAL_HOME" ] || die "home directory not found for $REAL_USER"

if [ -n "$DIR_OVERRIDE" ]; then
  REPO_DIR="$(realpath -m -- "$DIR_OVERRIDE")"
else
  REPO_DIR="$REAL_HOME/unetlab"
fi
REPO_DIR="$(realpath -m -- "$REPO_DIR")"
BASELINE_DIR="$REPO_DIR/baseline"
LICENSES_DIR="$REPO_DIR/LICENSES"

# ---------------------------------------------------------------- preflight
if [ ! -r /etc/os-release ]; then
  die "/etc/os-release not found; Debian-family required"
fi
# shellcheck disable=SC1091
. /etc/os-release
OS_ID="${ID:-unknown}"
OS_LIKE="${ID_LIKE:-}"
OS_NAME="${PRETTY_NAME:-$OS_ID}"
if ! printf '%s %s' "$OS_ID" "$OS_LIKE" | grep -qiE '(^| )debian( |$)|debian'; then
  warn "this script targets Debian-family systems; detected: $OS_NAME"
fi
command -v apt-get >/dev/null 2>&1 || die "apt-get not found; Debian-family required"

if [ "$(id -u)" -ne 0 ] && [ "$NO_DEPS" -eq 0 ] && [ "$CHECK_ONLY" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
  if command -v sudo >/dev/null 2>&1; then
    log "re-executing with sudo for dependency installation"
    exec sudo -E bash "$SELF" "${ORIG_ARGS[@]}"
  fi
  die "run as root (or install sudo), or pass --no-deps"
fi

if [ "$DRY_RUN" -eq 0 ] && [ "$CHECK_ONLY" -eq 0 ]; then
  command -v git >/dev/null 2>&1 || die "git is required (install git or rerun without --no-deps)"
fi

log "host: $OS_NAME"
log "repo: $REPO_DIR"
log "user: $REAL_USER ($REAL_HOME)"

if [ "$DRY_RUN" -eq 1 ]; then
  log "DRY RUN — planned packages and actions:"
  log "  required: git curl ca-certificates jq unzip rsync build-essential pkg-config cmake ninja-build python3 python3-venv python3-pip"
  log "            apache2 libapache2-mod-php php php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd php-sqlite3 php-imagick"
  log "            mariadb-server qemu-system-x86 qemu-utils iproute2 nftables dnsmasq-base tcpdump"
  log "            libpcap-dev libssl-dev libglib2.0-dev libpixman-1-dev libsdl2-dev libusb-1.0-0-dev libaio-dev libcap-ng-dev libattr1-dev libcurl4-openssl-dev libssh-dev libxml2-dev libelf-dev"
  log "            bc dos2unix libyaml-0-2 python3-pexpect python3-requests python3-cryptography"
  log "  optional: composer nodejs npm bridge-utils genisoimage xorriso mariadb-client mysql-client"
  log "            linux-headers-$(uname -r) liburing-dev libpmem-dev libspice-server-dev libguestfs-tools"
  log "            libc6-i386 lib32z1 lib32gcc-s1 uml-utilities iptables net-tools docker.io ovmf sshpass autossh cpulimit"
  log "            zip vim libnet-pcap-perl libsdl1.2debian dynamips vpcs shellcheck"
  log "  clone: $UPSTREAM_URL @ $UPSTREAM_COMMIT"
  log "  branch: $DEFAULT_BRANCH; tag: $TERALAB_TAG"
  log "  write: LICENSE, LICENSES/BSD-3-Clause.txt, NOTICE, PROVENANCE.md, AGENTS.md, README-BASELINE.md, baseline/, scripts/$SCRIPT_NAME"
  log "  no UNetLab installation, no vhost, no database schema, no engines"
  exit 0
fi

# ---------------------------------------------------------------- dependency helpers
SKIPPED_PKGS=()

apt_show() { apt-cache show "$1" >/dev/null 2>&1; }

install_required() {
  local missing=() p
  for p in "$@"; do
    apt_show "$p" || missing+=("$p")
  done
  if [ "${#missing[@]}" -gt 0 ]; then
    die "required packages unavailable: ${missing[*]}"
  fi
  log "installing required dependencies"
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    --no-install-recommends \
    -o Dpkg::Options::=--force-confdef \
    -o Dpkg::Options::=--force-confold \
    "$@"
}

install_optional() {
  local available=() p
  for p in "$@"; do
    if apt_show "$p"; then
      available+=("$p")
    else
      SKIPPED_PKGS+=("$p")
    fi
  done
  if [ "${#available[@]}" -gt 0 ]; then
    log "installing optional dependencies: ${available[*]}"
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
      --no-install-recommends \
      -o Dpkg::Options::=--force-confdef \
      -o Dpkg::Options::=--force-confold \
      "${available[@]}" || warn "some optional packages failed to install"
  fi
}

# ---------------------------------------------------------------- dependencies
REQUIRED_PKGS=(
  git curl ca-certificates jq unzip rsync
  build-essential pkg-config cmake ninja-build
  python3 python3-venv python3-pip
  apache2 libapache2-mod-php
  php php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd php-sqlite3 php-imagick
  mariadb-server
  qemu-system-x86 qemu-utils
  iproute2 nftables dnsmasq-base tcpdump
  libpcap-dev libssl-dev libglib2.0-dev libpixman-1-dev libsdl2-dev
  libusb-1.0-0-dev libaio-dev libcap-ng-dev libattr1-dev
  libcurl4-openssl-dev libssh-dev libxml2-dev libelf-dev
  bc dos2unix libyaml-0-2
  python3-pexpect python3-requests python3-cryptography
)
OPTIONAL_PKGS=(
  composer nodejs npm
  bridge-utils genisoimage xorriso
  mariadb-client mysql-client
  "linux-headers-$(uname -r)"
  liburing-dev libpmem-dev libspice-server-dev
  libguestfs-tools libc6-i386 lib32z1 lib32gcc-s1
  uml-utilities iptables net-tools
  docker.io ovmf
  sshpass autossh cpulimit zip vim
  libnet-pcap-perl libsdl1.2debian
  dynamips vpcs shellcheck
)

if [ "$NO_DEPS" -eq 0 ] && [ "$CHECK_ONLY" -eq 0 ]; then
  log "updating apt metadata"
  DEBIAN_FRONTEND=noninteractive apt-get update
  install_required "${REQUIRED_PKGS[@]}"
  install_optional "${OPTIONAL_PKGS[@]}"
else
  log "skipping apt installation (--no-deps)"
fi

# PHP compatibility warning: the app is 2016-era, the distro PHP is modern.
if command -v php >/dev/null 2>&1; then
  PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo 0)"
  if [ "${PHP_MAJOR:-0}" -lt 8 ]; then
    warn "Debian default PHP is ${PHP_MAJOR}.x (end-of-life). Do not ship this stack; plan a modern PHP port."
  else
    warn "UNetLab 1.0.2-2 targets PHP 5/7; a PHP ${PHP_MAJOR}.x compatibility port is required before the UI runs."
  fi
fi

# Best-effort service/module setup. This is dependency setup only.
if [ "$NO_DEPS" -eq 0 ] && [ "$CHECK_ONLY" -eq 0 ]; then
  if command -v a2enmod >/dev/null 2>&1; then
    a2enmod -q rewrite headers >/dev/null 2>&1 || true
    if command -v a2query >/dev/null 2>&1 && ! a2query -m 2>/dev/null | grep -qi '^php'; then
      PHP_LOAD="$(ls /etc/apache2/mods-available/php*.load 2>/dev/null | head -n1 || true)"
      if [ -n "$PHP_LOAD" ]; then
        a2enmod -q "$(basename "$PHP_LOAD" .load)" >/dev/null 2>&1 || true
      fi
    fi
  fi
  if [ -d /run/systemd/system ]; then
    systemctl enable apache2 mariadb >/dev/null 2>&1 || true
    systemctl start apache2 mariadb >/dev/null 2>&1 || true
  fi
fi

# ---------------------------------------------------------------- clone / fork
if [ "$CHECK_ONLY" -eq 1 ]; then
  [ -d "$REPO_DIR/.git" ] \
    || die "check: repository not found at $REPO_DIR"
  GIT=(git -c "safe.directory=$REPO_DIR" -C "$REPO_DIR")
else
  if [ -e "$REPO_DIR" ] && [ ! -d "$REPO_DIR/.git" ]; then
    if [ -n "$(ls -A "$REPO_DIR" 2>/dev/null || true)" ]; then
      die "$REPO_DIR exists and is not a git repository; refusing to overwrite"
    fi
  fi

  if [ ! -d "$REPO_DIR/.git" ]; then
    log "cloning UNetLab into $REPO_DIR"
    mkdir -p "$(dirname "$REPO_DIR")"
    git clone "$UPSTREAM_URL" "$REPO_DIR"
  else
    log "existing git repository found; fetching"
    git -C "$REPO_DIR" fetch --all --tags --prune
  fi

  GIT=(git -c "safe.directory=$REPO_DIR" -C "$REPO_DIR")

  if [ -n "$("${GIT[@]}" status --porcelain)" ]; then
    die "repository has local changes; commit or stash them before running this script"
  fi

  log "checking out $UPSTREAM_COMMIT"
  "${GIT[@]}" cat-file -e "$UPSTREAM_COMMIT^{commit}" 2>/dev/null \
    || die "upstream commit not found: $UPSTREAM_COMMIT"

  # Preserve any user work: create main only if absent, never reset an existing branch.
  if "${GIT[@]}" show-ref --verify --quiet "refs/heads/$DEFAULT_BRANCH"; then
    "${GIT[@]}" checkout "$DEFAULT_BRANCH"
    "${GIT[@]}" merge-base --is-ancestor "$UPSTREAM_COMMIT" HEAD \
      || die "branch $DEFAULT_BRANCH does not contain the upstream commit"
  else
    "${GIT[@]}" checkout -b "$DEFAULT_BRANCH" "$UPSTREAM_COMMIT"
  fi

  # Local commit identity for the baseline commit/tag.
  "${GIT[@]}" config user.name "$GIT_NAME"
  "${GIT[@]}" config user.email "$GIT_EMAIL"

  # Upstream remote: keep provenance; never overwrite an existing origin.
  if "${GIT[@]}" remote get-url origin >/dev/null 2>&1; then
    ORIGIN_URL="$("${GIT[@]}" remote get-url origin)"
    if [ "$ORIGIN_URL" = "$UPSTREAM_URL" ] || [ "$ORIGIN_URL" = "${UPSTREAM_URL%.git}" ]; then
      if ! "${GIT[@]}" remote get-url upstream >/dev/null 2>&1; then
        "${GIT[@]}" remote rename origin upstream
      fi
    fi
  fi
fi

# ---------------------------------------------------------------- verify upstream licence
log "verifying upstream version and licence"
GOT_VERSION="$("${GIT[@]}" show "$UPSTREAM_COMMIT:VERSION" | tr -d '[:space:]')"
[ "$GOT_VERSION" = "$UPSTREAM_VERSION" ] \
  || die "upstream VERSION mismatch: expected $UPSTREAM_VERSION, got $GOT_VERSION"

UP_LICENSE="$("${GIT[@]}" show "$UPSTREAM_COMMIT:LICENSE")"
printf '%s' "$UP_LICENSE" | grep -q 'Redistribution and use in source and binary forms' \
  || die "upstream LICENSE does not look like BSD-3-Clause"
printf '%s' "$UP_LICENSE" | grep -q 'Neither the name of the UNetLab Ltd' \
  || die "upstream LICENSE is missing the no-endorsement clause"

# ---------------------------------------------------------------- baseline files
if [ "$CHECK_ONLY" -eq 0 ]; then
  if "${GIT[@]}" rev-parse --verify --quiet "refs/tags/$TERALAB_TAG" >/dev/null; then
    TAG_COMMIT="$("${GIT[@]}" rev-list -n1 "$TERALAB_TAG")"
    HEAD_COMMIT="$("${GIT[@]}" rev-parse HEAD)"
    if [ "$TAG_COMMIT" != "$HEAD_COMMIT" ]; then
      die "tag $TERALAB_TAG already exists on a different commit; refusing to move it"
    fi
    if [ -f "$REPO_DIR/AGENTS.md" ] && [ -f "$REPO_DIR/LICENSE" ] && [ -f "$REPO_DIR/TERALAB_VERSION" ]; then
      log "existing $TERALAB_TAG baseline detected; skipping file creation"
      SKIP_FILES=1
    else
      die "tag exists but baseline files are missing; refusing to guess"
    fi
  else
    SKIP_FILES=0
  fi

  if [ "$SKIP_FILES" -eq 0 ]; then
    EXISTING=0
    for f in LICENSES/BSD-3-Clause.txt NOTICE PROVENANCE.md AGENTS.md README-BASELINE.md TERALAB_VERSION baseline/manifest.json "scripts/$SCRIPT_NAME"; do
      [ -e "$REPO_DIR/$f" ] && EXISTING=1
    done
    if [ "$EXISTING" -eq 1 ] && [ "$FORCE" -eq 0 ]; then
      die "baseline files already exist; pass --force to overwrite"
    fi

    mkdir -p "$LICENSES_DIR" "$BASELINE_DIR"

    # Exact upstream BSD-3 text.
    "${GIT[@]}" show "$UPSTREAM_COMMIT:LICENSE" > "$LICENSES_DIR/BSD-3-Clause.txt"
    chmod 0644 "$LICENSES_DIR/BSD-3-Clause.txt"

    # AGPLv3 text, verified against a pinned checksum.
    AGPL_TMP="$(mktemp)"
    AGPL_OK=0
    if command -v curl >/dev/null 2>&1; then
      if curl -fsSL "$AGPL_URL" -o "$AGPL_TMP"; then
        if echo "$AGPL_SHA256  $AGPL_TMP" | sha256sum -c --status; then
          AGPL_OK=1
        fi
      fi
    fi
    if [ "$AGPL_OK" -eq 0 ] && [ -f /usr/share/common-licenses/AGPL-3 ]; then
      cp /usr/share/common-licenses/AGPL-3 "$AGPL_TMP"
      if echo "$AGPL_SHA256  $AGPL_TMP" | sha256sum -c --status; then
        AGPL_OK=1
      fi
    fi
    [ "$AGPL_OK" -eq 1 ] || die "could not obtain a verified AGPLv3 text"

    {
      cat <<EOF
TeraLab v$TERALAB_VERSION
Copyright (C) 2026 TeraLab contributors

This project is licensed under the GNU Affero General Public License,
version 3 or (at your option) any later version.

UNetLab-derived files remain under BSD-3-Clause. See
LICENSES/BSD-3-Clause.txt and NOTICE for the component map.

EOF
      cat "$AGPL_TMP"
    } > "$REPO_DIR/LICENSE"
    rm -f "$AGPL_TMP"
    chmod 0644 "$REPO_DIR/LICENSE"

    cat > "$REPO_DIR/LICENSES/README.md" <<'EOF'
# Licence map

- `LICENSE` — GNU AGPLv3 for TeraLab original code (v1.1.0).
- `BSD-3-Clause.txt` — exact UNetLab 1.0.2-2 BSD-3-Clause text.
- UNetLab-derived files remain BSD-3-Clause. See `NOTICE` and `PROVENANCE.md`.
- QEMU, Dynamips, IOUtools and the Linux kernel are separate GPLv2 components,
  not relicensed by this project.
EOF

    cat > "$REPO_DIR/NOTICE" <<'EOF'
TeraLab v1.1.0 — component notices

TeraLab original code:
  Licence: AGPL-3.0-or-later
  See: LICENSE

UNetLab-derived code:
  Upstream: https://github.com/dainok/unetlab
  Version: 1.0.2-2
  Commit: 8ef51974b448318697e5dd18a4e10496f2f56b39
  Licence: BSD-3-Clause (see LICENSES/BSD-3-Clause.txt)
  The BSD-3 copyright, conditions and disclaimer are retained.

Separate engine components (not vendored by this baseline):
  QEMU — GPLv2
  Dynamips — GPLv2
  IOUtools — GPLv2
  Linux kernel — GPLv2
  These are separate programs/packages and remain under their own licences.

Third-party libraries:
  Their original licences apply; see the package manifests and source headers.

TeraLab is an independent UNetLab-derived fork. It is not endorsed by UNetLab
or its contributors.
EOF

    cat > "$REPO_DIR/PROVENANCE.md" <<'EOF'
# Provenance — TeraLab v1.1.0

## Official lineage

    UNetLab 1.0.2-2 (commit 8ef51974b448318697e5dd18a4e10496f2f56b39)
        -> TeraLab v1.1.0

- Upstream: https://github.com/dainok/unetlab
- Upstream version: 1.0.2-2
- Upstream commit: 8ef51974b448318697e5dd18a4e10496f2f56b39
- Upstream licence: BSD-3-Clause
- TeraLab version: 1.1.0
- TeraLab code licence: AGPL-3.0-or-later

## Verification

```sh
git cat-file -e 8ef51974b448318697e5dd18a4e10496f2f56b39^{commit}
git show 8ef51974b448318697e5dd18a4e10496f2f56b39:VERSION   # 1.0.2-2
git show 8ef51974b448318697e5dd18a4e10496f2f56b39:LICENSE   # BSD-3-Clause
git merge-base --is-ancestor 8ef51974b448318697e5dd18a4e10496f2f56b39 HEAD
```

## Modification policy

- New TeraLab files: AGPL-3.0-or-later.
- Modified BSD-3 files: retain the BSD-3 notice, add a prominent modification
  notice and date, and identify the AGPLv3 modifications.
- No upstream copyright or licence notice may be removed.
- No code, assets, CSS, templates, lab files or metadata may be copied from an
  external reference deployment.
- The QEMU/Dynamips/IOUtools/kernel components remain separate GPLv2 packages.

## External references

External reference deployments are used only as behavioural/UX references.
They are not upstreams, not version ancestors, and not sources of code,
assets, or version numbers.
EOF

    cat > "$REPO_DIR/README-BASELINE.md" <<'EOF'
# TeraLab v1.1.0 baseline

This repository is the official TeraLab line derived from UNetLab 1.0.2-2
(commit `8ef51974b448318697e5dd18a4e10496f2f56b39`).

## What this bootstrap did

- Installed modern Debian-family dependencies: Apache, PHP, MariaDB, QEMU,
  build tools, and the QEMU/KVM runtime libraries.
- Cloned UNetLab and pinned the 1.0.2-2 commit.
- Created branch `main` and commit/tag `v1.1.0`.
- Added AGPLv3 project licensing, upstream BSD-3 text, NOTICE, PROVENANCE,
  AGENTS.md, and this baseline manifest.

## What it deliberately did NOT do

- It did **not** install, configure, or start UNetLab.
- No Apache vhost, database schema, UNetLab wrappers, engine binaries, or lab
  data were created.
- No host bridge, firewall, or network configuration was changed.
- No third-party apt repository was added.

## Known next milestone

UNetLab 1.0.2-2 is a 2016 PHP-era application. Modern Debian ships PHP 8.x.
The first development milestone is a compatibility port for:

- PHP 8 (removed `mysql_*` APIs, `each()`, old constructors, warnings)
- QEMU path/wrapper compatibility (`/opt/qemu` vs `/usr/bin`)
- dependency and template schema updates

The repository is intentionally a **baseline**, not yet a running UNetLab UI.

## Licence

- TeraLab original code: AGPL-3.0-or-later (see `LICENSE`).
- UNetLab-derived files: BSD-3-Clause (see `LICENSES/BSD-3-Clause.txt`).
- Engine components: separate GPLv2 packages (see `NOTICE`).

## Verify

```sh
./scripts/teralab-project-init.sh --check
```
EOF

    cat > "$REPO_DIR/AGENTS.md" <<'EOF'
# AGENTS.md — TeraLab v1.1.0

## Mission

TeraLab is the official, strong-copyleft, UNetLab-derived lab platform.
This repository starts at v1.1.0, based on UNetLab 1.0.2-2.

Goals:
- modernise UNetLab for current Debian/PHP/QEMU
- build a maintainable, secure, documented platform
- keep the project genuinely open source under AGPLv3
- preserve upstream provenance and BSD-3 notices
- never import proprietary or unlicensed code

## Non-negotiable licensing rules

1. New TeraLab files are `AGPL-3.0-or-later`:
   `SPDX-License-Identifier: AGPL-3.0-or-later`.
2. UNetLab-derived files remain `BSD-3-Clause`. Retain the copyright notice,
   conditions, and disclaimer. Do not remove or alter them.
3. Modified BSD-3 files must carry a prominent modification notice with a
   date and an AGPLv3 notice for the modifications. A file-level header is
   sufficient; per-section comments are optional.
4. Do not relicense upstream BSD-3 code. Do not claim a mixed file is
   “AGPLv3 only”.
5. Keep `LICENSES/`, `NOTICE`, and `PROVENANCE.md` current.
6. Do not change the licence model without written approval from the project
   owner and legal counsel.
7. QEMU, Dynamips, IOUtools, and the Linux kernel are separate GPLv2
   components. Keep them as separate packages/processes; do not link them into
   an AGPLv3 combined work. Provide their source and notices.
8. Do not add a dependency with an incompatible licence. Audit every new
   dependency and record it.
9. Do not bundle vendor images, IOS/firmware, or lab content without a clear
   right to redistribute it.
10. Do not use UNetLab, EVE-NG, PNetLab, or other project names/logos in a way
    that implies endorsement.

## Clean-room and reference rules

- External reference deployments may be observed only through their frontend.
- No SSH, shell, database, filesystem, API token, or source access.
- Do not copy code, CSS, images, templates, texts, assets, lab files, or
  metadata from any reference deployment.
- Reference deployments are not upstreams, not version ancestors, and not
  sources of version numbers.
- Observation produces behavioural specs and screenshots; implementation is
  independent. Keep logs of what was observed vs. implemented.
- Do not clone the visual identity pixel-for-pixel. TeraLab has its own design.

## Versioning

- Official line: UNetLab 1.0.2-2 -> TeraLab v1.1.0 -> semver.
- Tags are annotated `vMAJOR.MINOR.PATCH`.
- Never rewrite or move an existing TeraLab tag.
- Never reuse upstream tags.
- Version/tag changes require owner approval and release notes.
- Upstream `VERSION` remains 1.0.2-2 for provenance; product version is
  `TERALAB_VERSION`.

## Release obligations

- AGPLv3: users interacting with a modified service over a network must be
  offered the corresponding source. Maintain an About/Licences page with
  copyright, licence, no-warranty notice, and a source link.
- GPLv2 components: provide source or a valid written offer; include licence
  text and notices; mark modifications; no extra restrictions.
- BSD-3: include the notice in source and binary documentation.
- Run `./scripts/teralab-project-init.sh --check` before release.
- Legal review is required before any public distribution.

## Security and operations

- No secrets in the repository. No default passwords.
- No telemetry or phone-home.
- No destructive commands, no host network/bridge/firewall changes without
  approval.
- Least privilege; pin dependencies; record an SBOM.
- Do not install EOL PHP or third-party apt repositories just to run legacy
  code. Port forward.

## Workflow

- Work on branches; use signed-off commits (`git commit -s`).
- Every change: tests, docs, licence header, NOTICE/PROVENANCE update.
- Do not force-push shared branches.
- Stop and ask when a change affects licensing, distribution, or provenance.

## Forbidden

- Copying from any external reference deployment.
- Removing BSD/GPL notices.
- Mixing GPLv2-only and AGPLv3 code into one combined work.
- Using another project’s trademarks.
- Distributing without source for GPL/AGPL components.
EOF

    echo "$TERALAB_VERSION" > "$REPO_DIR/TERALAB_VERSION"

    # Preserve the upstream .gitignore; append the TeraLab block once.
    if ! grep -q '# TeraLab local/runtime artifacts' "$REPO_DIR/.gitignore" 2>/dev/null; then
      cat >> "$REPO_DIR/.gitignore" <<'EOF'

# TeraLab local/runtime artifacts
/baseline/*.log
/baseline/bootstrap.log
/vendor/
/node_modules/
/.env
/data/
/logs/
*.unl
EOF
    fi

    # Baseline manifests. Record only the project's dependency set, not every
    # package present on the host.
    {
      echo "# TeraLab baseline dependency manifest"
      echo "# package	version (or 'not installed')"
      for p in "${REQUIRED_PKGS[@]}" "${OPTIONAL_PKGS[@]}"; do
        v="$(dpkg-query -W -f='${Version}' "$p" 2>/dev/null || true)"
        if [ -n "$v" ]; then
          printf '%s\t%s\n' "$p" "$v"
        else
          printf '%s\t%s\n' "$p" "not installed"
        fi
      done
    } | sort > "$BASELINE_DIR/apt-manifest.txt"
    {
      echo "generated: $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
      echo "os: $OS_NAME"
      echo "repo: $REPO_DIR"
      echo "git: $(git --version 2>/dev/null || echo missing)"
      echo "php: $(php -v 2>/dev/null | head -n1 || echo missing)"
      echo "apache: $(apache2 -v 2>/dev/null | head -n1 || echo missing)"
      echo "mariadb: $(mariadb --version 2>/dev/null || mysql --version 2>/dev/null || echo missing)"
      echo "qemu: $(qemu-system-x86_64 --version 2>/dev/null | head -n1 || echo missing)"
      echo "kvm: $([ -e /dev/kvm ] && echo present || echo missing)"
    } > "$BASELINE_DIR/versions.txt"
    printf '%s\n' "${SKIPPED_PKGS[@]:-}" | sed '/^$/d' > "$BASELINE_DIR/skipped-packages.txt"

    python3 - "$BASELINE_DIR/manifest.json" "$REPO_DIR" "$OS_NAME" "$UPSTREAM_VERSION" "$UPSTREAM_COMMIT" "$TERALAB_VERSION" <<'PY'
import json, sys, datetime
path, repo, os_name, up_ver, up_commit, tl_ver = sys.argv[1:7]
data = {
    "product": "TeraLab",
    "line": "official-unetlab",
    "version": tl_ver,
    "upstream": "UNetLab",
    "upstream_version": up_ver,
    "upstream_commit": up_commit,
    "repo": repo,
    "host_os": os_name,
    "generated_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "licenses": {
        "project": "AGPL-3.0-or-later",
        "upstream": "BSD-3-Clause",
        "engine_components": "GPL-2.0"
    }
}
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh, indent=2)
    fh.write("\n")
PY

    # Keep the bootstrap script in-tree.
    mkdir -p "$REPO_DIR/scripts"
    DEST_SCRIPT="$(realpath -m -- "$REPO_DIR/scripts/$SCRIPT_NAME")"
    if [ "$SELF" != "$DEST_SCRIPT" ]; then
      install -m 0755 "$SELF" "$DEST_SCRIPT"
    fi

    if [ "$NO_COMMIT" -eq 0 ]; then
      "${GIT[@]}" add -- LICENSE LICENSES NOTICE PROVENANCE.md AGENTS.md \
        README-BASELINE.md TERALAB_VERSION .gitignore baseline \
        "scripts/$SCRIPT_NAME"
      if ! "${GIT[@]}" diff --cached --quiet; then
        "${GIT[@]}" -c user.name="$GIT_NAME" -c user.email="$GIT_EMAIL" \
          commit --signoff -m "chore(release): TeraLab v$TERALAB_VERSION - official UNetLab $UPSTREAM_VERSION baseline"
      fi
      if ! "${GIT[@]}" rev-parse --verify --quiet "refs/tags/$TERALAB_TAG" >/dev/null; then
        "${GIT[@]}" -c user.name="$GIT_NAME" -c user.email="$GIT_EMAIL" \
          tag -a "$TERALAB_TAG" \
          -m "TeraLab v$TERALAB_VERSION - official UNetLab $UPSTREAM_VERSION baseline"
      fi
    else
      log "--no-commit: files written; review and commit manually"
    fi
  fi

  cp -f "$LOG" "$BASELINE_DIR/bootstrap.log" 2>/dev/null || true
fi

# ---------------------------------------------------------------- verification
log "verifying baseline"
FAIL=0
pass() { log "PASS  $1"; }
fail() { log "FAIL  $1"; FAIL=1; }

if [ -d "$REPO_DIR/.git" ]; then pass "repo is a git repository"; else fail "repo is a git repository"; fi
if "${GIT[@]}" cat-file -e "$UPSTREAM_COMMIT^{commit}" >/dev/null 2>&1; then
  pass "upstream commit exists"
else
  fail "upstream commit exists"
fi
if "${GIT[@]}" merge-base --is-ancestor "$UPSTREAM_COMMIT" HEAD >/dev/null 2>&1; then
  pass "upstream commit is an ancestor"
else
  fail "upstream commit is an ancestor"
fi
GOT_UP_VER="$("${GIT[@]}" show "$UPSTREAM_COMMIT:VERSION" 2>/dev/null | tr -d '[:space:]')"
if [ "$GOT_UP_VER" = "$UPSTREAM_VERSION" ]; then
  pass "upstream VERSION is $UPSTREAM_VERSION"
else
  fail "upstream VERSION is $UPSTREAM_VERSION (got $GOT_UP_VER)"
fi
GOT_TL_VER="$(cat "$REPO_DIR/TERALAB_VERSION" 2>/dev/null || true)"
if [ "$GOT_TL_VER" = "$TERALAB_VERSION" ]; then
  pass "TERALAB_VERSION is $TERALAB_VERSION"
else
  fail "TERALAB_VERSION is $TERALAB_VERSION (got $GOT_TL_VER)"
fi
if grep -q 'GNU AFFERO GENERAL PUBLIC LICENSE' "$REPO_DIR/LICENSE" 2>/dev/null \
   && grep -q 'Version 3' "$REPO_DIR/LICENSE" 2>/dev/null; then
  pass "LICENSE is AGPLv3"
else
  fail "LICENSE is AGPLv3"
fi
UP_LIC_SHA="$("${GIT[@]}" show "$UPSTREAM_COMMIT:LICENSE" 2>/dev/null | sha256sum | cut -d' ' -f1)"
FILE_LIC_SHA="$(sha256sum "$REPO_DIR/LICENSES/BSD-3-Clause.txt" 2>/dev/null | cut -d' ' -f1 || true)"
if [ -n "$UP_LIC_SHA" ] && [ "$UP_LIC_SHA" = "$FILE_LIC_SHA" ]; then
  pass "BSD-3 text matches upstream"
else
  fail "BSD-3 text matches upstream"
fi
for f in AGENTS.md NOTICE PROVENANCE.md README-BASELINE.md baseline/manifest.json; do
  if [ -s "$REPO_DIR/$f" ]; then pass "$f exists"; else fail "$f exists"; fi
done
if [ "$NO_COMMIT" -eq 0 ]; then
  if "${GIT[@]}" rev-parse --verify --quiet "refs/tags/$TERALAB_TAG" >/dev/null 2>&1; then
    TAG_REV="$("${GIT[@]}" rev-list -n1 "$TERALAB_TAG")"
    TAG_SUBJECT="$("${GIT[@]}" show -s --format=%s "$TAG_REV" 2>/dev/null || true)"
    if "${GIT[@]}" merge-base --is-ancestor "$TAG_REV" HEAD >/dev/null 2>&1 \
       && printf '%s' "$TAG_SUBJECT" | grep -q "TeraLab v$TERALAB_VERSION"; then
      pass "tag $TERALAB_TAG points at the TeraLab baseline"
    else
      fail "tag $TERALAB_TAG points at the TeraLab baseline"
    fi
  else
    fail "tag $TERALAB_TAG exists"
  fi
else
  log "SKIP  tag $TERALAB_TAG exists (--no-commit)"
fi

if [ "$FAIL" -ne 0 ]; then
  die "baseline verification failed"
fi

log "baseline complete: $REPO_DIR"
log "branch: $DEFAULT_BRANCH; tag: $TERALAB_TAG"
log "UNetLab application was NOT installed, configured, or started."
log "Next: modernise PHP 8/QEMU compatibility before running the UI."
log "Add your new remote, e.g.: git -C '$REPO_DIR' remote add origin <url>"
log "Log: $LOG"
