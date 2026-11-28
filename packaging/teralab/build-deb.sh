#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Build the TeraLab Debian package on a Debian 13 build host.
#
# The script stages a copy of the repository, installs the production PHP
# dependencies, builds the Vue UI and the Go agent, lays out the package
# filesystem, downloads a pinned nfpm release (checksum-verified) and then
# checks the resulting artifact. Build outputs stay under dist/, which is
# untracked.
set -Eeuo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
BUILD="$REPO/dist"
OUT="$BUILD"
VERSION=""

NFPM_VERSION="2.49.0"
NFPM_SHA256="2afaa5ffb5e3493402e31e7c67a5a1fb42133e3f66fc315ca0051934125561c7"
NFPM_URL="https://github.com/goreleaser/nfpm/releases/download/v${NFPM_VERSION}/nfpm_${NFPM_VERSION}_Linux_x86_64.tar.gz"

usage() {
	cat <<EOF
Usage: packaging/teralab/build-deb.sh [options]

Build the TeraLab Debian package.

Options:
  --version VERSION   Version to package (default: TERALAB_VERSION)
  --output DIR        Directory for the .deb (default: dist/)
  -h, --help          Show this help

The version is mapped to Debian syntax: a leading "v" is stripped, the first
"-" becomes "~", and "-1" is appended as the revision, so 1.6-dev becomes
1.6~dev-1 and 1.6.0 becomes 1.6.0-1.

Build dependencies: php, composer, go, node/npm, rsync, dpkg-deb, sha256sum,
curl, tar, gzip. nfpm is downloaded into dist/.tools with a pinned version and
checksum; it is build tooling only (MIT) and is never committed or shipped.
EOF
}

fail() {
	printf 'build-deb: %s\n' "$*" >&2
	exit 1
}

require_tool() {
	local tool="$1" hint="${2-}"
	if command -v "$tool" >/dev/null 2>&1; then
		return 0
	fi
	if [ -n "$hint" ]; then
		fail "missing build tool: $tool ($hint)"
	fi
	fail "missing build tool: $tool"
}

# Only ever delete our own staging trees. Resolve the path first so symlinks
# or trailing ../ segments cannot escape $BUILD.
safe_rm() {
	local target canonical
	target="$1"
	canonical="$(realpath -m -- "$target")" || fail "could not resolve path: $target"
	case "$canonical" in
		"$BUILD_CANON"/*) rm -rf -- "$canonical" ;;
		*) fail "refusing to remove a path outside $BUILD: $target" ;;
	esac
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--version)
			if [ "$#" -lt 2 ] || [ -z "$2" ]; then
				usage >&2
				exit 2
			fi
			VERSION="$2"
			shift 2
			;;
		--output)
			if [ "$#" -lt 2 ] || [ -z "$2" ]; then
				usage >&2
				exit 2
			fi
			OUT="$2"
			shift 2
			;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; exit 2 ;;
	esac
done

if [ -z "$VERSION" ]; then
	[ -f "$REPO/TERALAB_VERSION" ] || fail "TERALAB_VERSION is missing from $REPO"
	VERSION="$(tr -d '[:space:]' < "$REPO/TERALAB_VERSION")"
fi
[ -n "$VERSION" ] || fail "empty version"

# semver -> Debian: strip a leading v, first "-" becomes "~", append revision.
DEB_VERSION="${VERSION#v}"
case "$DEB_VERSION" in
	*-*) DEB_VERSION="${DEB_VERSION%%-*}~${DEB_VERSION#*-}-1" ;;
	*) DEB_VERSION="${DEB_VERSION}-1" ;;
esac
case "$DEB_VERSION" in
	[0-9]*) ;;
	*) fail "invalid Debian version derived from '$VERSION': $DEB_VERSION" ;;
esac
case "$DEB_VERSION" in
	*[!A-Za-z0-9.+~-]*) fail "invalid character in Debian version: $DEB_VERSION" ;;
esac

case "$OUT" in
	/*) ;;
	*) OUT="$REPO/$OUT" ;;
esac
mkdir -p "$OUT" "$BUILD"
BUILD_CANON="$(realpath -m "$BUILD")"

require_tool php
require_tool composer
require_tool go "install Go and put it on PATH"
require_tool node "install Node.js and put it on PATH"
require_tool npm "install Node.js/npm and put them on PATH"
require_tool rsync
require_tool dpkg-deb
require_tool sha256sum
require_tool realpath
require_tool curl "required to download the pinned nfpm release"
require_tool tar
require_tool gzip

printf 'TeraLab %s -> Debian version %s\n' "$VERSION" "$DEB_VERSION"

SRC="$BUILD/stage-src"
STAGE="$BUILD/stage"
PAYLOAD="$STAGE/opt/teralab"

safe_rm "$SRC"
safe_rm "$STAGE"
mkdir -p "$SRC"

printf 'Staging the source tree...\n'
rsync -a --delete \
	--exclude '.git' \
	--exclude 'AGENTS.md' \
	--exclude 'agent/teralab-agent' \
	--exclude 'agent/bin' \
	--exclude 'dist' \
	--exclude 'node_modules' \
	--exclude 'ui/node_modules' \
	--exclude 'ui/dist' \
	"$REPO/" "$SRC/"

[ -f "$SRC/api/composer.json" ] || fail "staged API tree is missing composer.json"
printf 'Installing production API dependencies...\n'
( cd "$SRC/api" && composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative )
[ -f "$SRC/api/vendor/autoload.php" ] || fail "composer did not produce api/vendor/autoload.php"

[ -f "$SRC/ui/package.json" ] || fail "staged UI tree is missing package.json"
printf 'Building the Vue UI...\n'
( cd "$SRC/ui" && npm ci --no-audit --no-fund && npm run build )
[ -f "$SRC/ui/dist/index.html" ] || fail "the UI build did not produce ui/dist/index.html"
install -d -m 0755 "$PAYLOAD/html/ui"
cp -a "$SRC/ui/dist/." "$PAYLOAD/html/ui/"
safe_rm "$SRC/ui"

[ -d "$SRC/agent" ] || fail "staged agent tree is missing"
printf 'Building teralab-agent...\n'
install -d -m 0755 "$PAYLOAD/bin"
( cd "$SRC/agent" && go build -o "$PAYLOAD/bin/teralab-agent" ./cmd/teralab-agent )
chmod 0755 "$PAYLOAD/bin/teralab-agent"
[ -x "$PAYLOAD/bin/teralab-agent" ] || fail "the agent build did not produce a binary"

printf 'Laying out /opt/teralab...\n'
install -d -m 0755 "$PAYLOAD/api" "$PAYLOAD/html" "$PAYLOAD/docs" \
	"$PAYLOAD/LICENSES" "$PAYLOAD/packaging/teralab"

rsync -a \
	--exclude 'tests/' \
	--exclude 'phpunit.xml*' \
	--exclude 'phpstan*' \
	--exclude '.phpunit.cache/' \
	--exclude '.phpstan-cache/' \
	--exclude 'var/' \
	"$SRC/api/" "$PAYLOAD/api/"
rsync -a --exclude 'ui/' "$SRC/html/" "$PAYLOAD/html/"
rsync -a "$SRC/docs/" "$PAYLOAD/docs/"
rsync -a "$SRC/LICENSES/" "$PAYLOAD/LICENSES/"

install -m 0644 "$SRC/NOTICE" "$PAYLOAD/NOTICE"
install -m 0644 "$SRC/LICENSE" "$PAYLOAD/LICENSE"
install -m 0644 "$SRC/PROVENANCE.md" "$PAYLOAD/PROVENANCE.md"
install -m 0644 "$SRC/TERALAB_VERSION" "$PAYLOAD/TERALAB_VERSION"
install -m 0755 "$SRC/packaging/teralab/apply-state.sh" "$PAYLOAD/packaging/teralab/apply-state.sh"
install -m 0755 "$SRC/packaging/teralab/teralab-firewall" "$PAYLOAD/packaging/teralab/teralab-firewall"
install -m 0755 "$SRC/packaging/teralab/fpm-socket-acl" "$PAYLOAD/packaging/teralab/fpm-socket-acl"
install -m 0644 "$SRC/packaging/teralab/import-legacy.php" "$PAYLOAD/packaging/teralab/import-legacy.php"
install -m 0644 "$SRC/packaging/teralab/teralab.apache.conf" "$PAYLOAD/packaging/teralab/teralab.apache.conf"
install -m 0644 "$SRC/packaging/teralab/php-fpm-pool.conf" "$PAYLOAD/packaging/teralab/php-fpm-pool.conf"
# Unit templates stay next to apply-state so a source-mode run from the
# packaged tree (for example --ksm without --package) can reinstall them.
install -m 0644 "$SRC/agent/systemd/teralab-agent.service" "$PAYLOAD/packaging/teralab/teralab-agent.service"
install -m 0644 "$SRC/packaging/teralab/teralab-firewall.service" "$PAYLOAD/packaging/teralab/teralab-firewall.service"

printf 'Laying out /etc and the package documentation...\n'
ETC="$STAGE/etc"
install -d -m 0755 "$ETC/apache2/conf-available"
sed 's|@ROOT@|/opt/teralab|g' "$SRC/packaging/teralab/teralab.apache.conf" \
	> "$ETC/apache2/conf-available/teralab.conf"
chmod 0644 "$ETC/apache2/conf-available/teralab.conf"

# The dedicated PHP-FPM pool is a conffile so local tuning survives upgrades.
install -d -m 0755 "$ETC/php/8.4/fpm/pool.d"
install -m 0644 "$SRC/packaging/teralab/php-fpm-pool.conf" "$ETC/php/8.4/fpm/pool.d/teralab.conf"

# The privileged firewall helper runs from a root-only libexec path. The
# source copy under /opt/teralab/packaging stays for reference and for a
# source-mode apply-state run from the packaged tree.
install -d -m 0755 "$STAGE/usr/local/libexec/teralab"
install -m 0755 "$SRC/packaging/teralab/teralab-firewall" "$STAGE/usr/local/libexec/teralab/teralab-firewall"
install -m 0755 "$SRC/packaging/teralab/fpm-socket-acl" "$STAGE/usr/local/libexec/teralab/fpm-socket-acl"

# Keep in sync with packaging/teralab/apply-state.sh.
for sapi in apache2 fpm cli; do
	install -d -m 0755 "$ETC/php/8.4/$sapi/conf.d"
	cat > "$ETC/php/8.4/$sapi/conf.d/99-teralab-uploads.ini" <<'PHPINI'
; TeraLab image uploads
upload_max_filesize = 4G
post_max_size = 4G
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
PHPINI
	chmod 0644 "$ETC/php/8.4/$sapi/conf.d/99-teralab-uploads.ini"
done

install -d -m 0755 "$ETC/systemd/system"
install -m 0644 "$SRC/agent/systemd/teralab-agent.service" "$ETC/systemd/system/teralab-agent.service"
install -m 0644 "$SRC/packaging/teralab/teralab-firewall.service" "$ETC/systemd/system/teralab-firewall.service"
install -d -m 0755 "$ETC/logrotate.d"
install -m 0644 "$SRC/packaging/teralab/logrotate" "$ETC/logrotate.d/teralab"

DOC="$STAGE/usr/share/doc/teralab"
install -d -m 0755 "$DOC"
install -m 0644 "$SRC/packaging/teralab/copyright" "$DOC/copyright"
gzip -9n -c "$SRC/docs/RELEASE-NOTES.md" > "$DOC/changelog.gz"
chmod 0644 "$DOC/changelog.gz"
gzip -t "$DOC/changelog.gz" || fail "changelog.gz is not valid gzip"

# nfpm forces root:root but a tree keeps the source modes. Normalise them so
# the package does not ship group-writable files; only the executables are +x.
find "$PAYLOAD" -type d -exec chmod 0755 {} +
find "$PAYLOAD" -type f -exec chmod 0644 {} +
chmod 0755 "$PAYLOAD/bin/teralab-agent" \
	"$PAYLOAD/packaging/teralab/apply-state.sh" \
	"$PAYLOAD/packaging/teralab/teralab-firewall" \
	"$PAYLOAD/packaging/teralab/fpm-socket-acl"
chmod 0755 "$STAGE/usr/local/libexec/teralab/teralab-firewall" \
	"$STAGE/usr/local/libexec/teralab/fpm-socket-acl"

printf 'Bootstrapping nfpm %s...\n' "$NFPM_VERSION"
install -d -m 0755 "$BUILD/.tools"
NFPM_TARBALL="$BUILD/.tools/nfpm_${NFPM_VERSION}_Linux_x86_64.tar.gz"
NFPM="$BUILD/.tools/nfpm"

if [ -f "$NFPM_TARBALL" ] \
	&& ! printf '%s  %s\n' "$NFPM_SHA256" "$NFPM_TARBALL" | sha256sum -c - >/dev/null 2>&1; then
	printf 'Discarding the cached nfpm tarball: checksum mismatch.\n'
	rm -f "$NFPM_TARBALL"
fi
if [ ! -f "$NFPM_TARBALL" ]; then
	curl -fsSL -o "$NFPM_TARBALL.part" "$NFPM_URL" \
		|| fail "could not download nfpm $NFPM_VERSION"
	mv "$NFPM_TARBALL.part" "$NFPM_TARBALL"
fi
printf '%s  %s\n' "$NFPM_SHA256" "$NFPM_TARBALL" | sha256sum -c - \
	|| fail "nfpm tarball checksum mismatch; refusing to build"

NFPM_TMP="$(mktemp -d "$BUILD/.nfpm.XXXXXX")"
tar -xzf "$NFPM_TARBALL" -C "$NFPM_TMP" nfpm
install -m 0755 "$NFPM_TMP/nfpm" "$NFPM"
rm -rf "$NFPM_TMP"

DEB="$OUT/teralab_${DEB_VERSION}_amd64.deb"
rm -f "$DEB"
printf 'Packaging with nfpm...\n'
(
	cd "$REPO"
	export TERALAB_DEB_VERSION="$DEB_VERSION"
	"$NFPM" package -f "$REPO/packaging/teralab/nfpm.yaml" -p deb -t "$OUT/"
)
[ -f "$DEB" ] || fail "expected artifact not found: $DEB"

printf '\nChecking the artifact...\n'
dpkg-deb -I "$DEB"

[ "$(dpkg-deb -f "$DEB" Package)" = "teralab" ] || fail "Package field is not teralab"
[ "$(dpkg-deb -f "$DEB" Version)" = "$DEB_VERSION" ] || fail "Version field does not match $DEB_VERSION"
[ "$(dpkg-deb -f "$DEB" Architecture)" = "amd64" ] || fail "Architecture field is not amd64"

depends="$(dpkg-deb -f "$DEB" Depends)"
recommends="$(dpkg-deb -f "$DEB" Recommends)"
suggests="$(dpkg-deb -f "$DEB" Suggests)"
[ -n "$depends" ] || fail "Depends field is empty"
[ -n "$recommends" ] || fail "Recommends field is empty"
[ -n "$suggests" ] || fail "Suggests field is empty"

depends_list="$(printf '%s\n' "$depends" | tr ',' '\n' | sed 's/^ *//; s/ *$//')"
for pkg in acl apache2 php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml \
	qemu-system-x86 qemu-utils logrotate; do
	grep -qx "$pkg" <<<"$depends_list" || fail "Depends is missing $pkg"
done
depends_count="$(printf '%s\n' "$depends_list" | grep -c .)"
[ "$depends_count" -eq 10 ] || fail "Depends has $depends_count entries, expected 10"
case "$recommends" in
	*vpcs*) ;;
	*) fail "Recommends is missing vpcs" ;;
esac
case "$suggests" in
	*teralab-dynamips*) ;;
	*) fail "Suggests is missing teralab-dynamips" ;;
esac
printf 'Control fields: ok (Depends, Recommends, Suggests)\n'

listing="$(dpkg-deb -c "$DEB")"
contains() {
	case "$listing" in
		*"$1"*) return 0 ;;
		*) return 1 ;;
	esac
}
require_path() {
	contains "$1" || fail "payload is missing $1"
}

for path in \
	./opt/teralab/bin/teralab-agent \
	./opt/teralab/html/ui/index.html \
	./opt/teralab/api/vendor/autoload.php \
	./opt/teralab/LICENSE \
	./opt/teralab/NOTICE \
	./opt/teralab/PROVENANCE.md \
	./opt/teralab/TERALAB_VERSION \
	./opt/teralab/LICENSES/BSD-3-Clause.txt \
	./opt/teralab/LICENSES/GPL-2.0.txt \
	./opt/teralab/packaging/teralab/apply-state.sh \
	./opt/teralab/packaging/teralab/teralab.apache.conf \
	./opt/teralab/packaging/teralab/teralab-agent.service \
	./opt/teralab/packaging/teralab/teralab-firewall.service \
	./opt/teralab/packaging/teralab/php-fpm-pool.conf \
	./opt/teralab/packaging/teralab/fpm-socket-acl \
	./etc/systemd/system/teralab-agent.service \
	./etc/systemd/system/teralab-firewall.service \
	./etc/apache2/conf-available/teralab.conf \
	./etc/php/8.4/apache2/conf.d/99-teralab-uploads.ini \
	./etc/php/8.4/fpm/conf.d/99-teralab-uploads.ini \
	./etc/php/8.4/fpm/pool.d/teralab.conf \
	./etc/php/8.4/cli/conf.d/99-teralab-uploads.ini \
	./usr/local/libexec/teralab/teralab-firewall \
	./usr/local/libexec/teralab/fpm-socket-acl \
	./etc/logrotate.d/teralab \
	./usr/share/doc/teralab/copyright \
	./usr/share/doc/teralab/changelog.gz; do
	require_path "$path"
done
printf 'Payload files: ok\n'

for path in \
	./opt/teralab/api/tests \
	./opt/teralab/api/var \
	./opt/teralab/ui \
	./opt/teralab/agent \
	./opt/teralab/node_modules; do
	if contains "$path"; then
		fail "payload contains a development path: $path"
	fi
done
if grep -Fq 'node_modules' <<<"$listing"; then
	fail "payload contains node_modules"
fi
if grep -Fq '/.git/' <<<"$listing"; then
	fail "payload contains a .git directory"
fi
if grep -Eq '\.(qcow2|iso|img)$' <<<"$listing"; then
	fail "payload contains a vendor/test disk image"
fi
printf 'No development trees or disk images: ok\n'

CTRL="$BUILD/.control"
safe_rm "$CTRL"
mkdir -p "$CTRL"
dpkg-deb -e "$DEB" "$CTRL"

[ -f "$CTRL/conffiles" ] || fail "control archive has no conffiles"
expected_conffiles=(
	/etc/apache2/conf-available/teralab.conf
	/etc/php/8.4/apache2/conf.d/99-teralab-uploads.ini
	/etc/php/8.4/fpm/conf.d/99-teralab-uploads.ini
	/etc/php/8.4/cli/conf.d/99-teralab-uploads.ini
	/etc/php/8.4/fpm/pool.d/teralab.conf
	/etc/systemd/system/teralab-agent.service
	/etc/systemd/system/teralab-firewall.service
	/etc/logrotate.d/teralab
	/usr/share/doc/teralab/copyright
)
actual_conffiles="$(sed 's|^/||' "$CTRL/conffiles" | sort)"
expected_conffiles_norm="$(printf '%s\n' "${expected_conffiles[@]}" | sed 's|^/||' | sort)"
if [ "$actual_conffiles" != "$expected_conffiles_norm" ]; then
	printf 'expected conffiles:\n%s\nactual conffiles:\n%s\n' \
		"$expected_conffiles_norm" "$actual_conffiles" >&2
	fail "the conffiles list does not match"
fi
printf 'Conffiles: ok\n'

for script in postinst prerm postrm; do
	[ -x "$CTRL/$script" ] || fail "control/$script is missing or not executable"
	sh -n "$CTRL/$script" || fail "control/$script is not valid POSIX shell"
done
printf 'Maintainer scripts: ok\n'

size="$(stat -c %s "$DEB")"
printf '\nAll artifact checks passed.\n'
printf 'Package:    %s\n' "$DEB"
printf 'Size:       %s bytes\n' "$size"
printf 'Version:    %s\n' "$DEB_VERSION"
printf 'Depends:    %s\n' "$depends"
printf 'Recommends: %s\n' "$recommends"
printf 'Suggests:   %s\n' "$suggests"
printf 'Conffiles:\n%s\n' "$actual_conffiles"
