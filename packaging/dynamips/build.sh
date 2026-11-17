#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Build the separate teralab-dynamips package from pinned upstream source.
#
# Dynamips is GPLv2 software. It is built and packaged as a separate work and
# is not linked into the AGPLv3 TeraLab code. The source tarball is downloaded
# at build time; upstream source is never committed to this repository.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

VERSION="0.2.23"
URL="https://github.com/GNS3/dynamips/archive/refs/tags/v${VERSION}.tar.gz"
SHA256="503bbb52c03f91900ea8dbe8bd0b804b76e2e28d0b7242624e0d3c52dda441a1"
PACKAGE="teralab-dynamips"

WORK="${TERALAB_DYNAMIPS_WORK:-/tmp/teralab-dynamips-build}"
OUT="${TERALAB_DYNAMIPS_OUT:-$PWD/dist}"
JOBS="${JOBS:-$(nproc)}"

usage() {
	cat <<EOF
Usage: packaging/dynamips/build.sh [options]

Options:
  --work PATH   Build directory (default: $WORK)
  --out PATH    Output directory for the .deb (default: ./dist)
  --jobs N      Parallel build jobs (default: nproc)
  -h, --help    Show this help

Build dependencies: cmake, make, gcc, libelf-dev, libpcap-dev.
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--work) WORK="$2"; shift 2 ;;
		--out) OUT="$2"; shift 2 ;;
		--jobs) JOBS="$2"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; exit 2 ;;
	esac
done

for tool in curl cmake make gcc dpkg-deb sha256sum; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		printf 'missing build tool: %s\n' "$tool" >&2
		exit 1
	fi
done
for header in /usr/include/libelf.h /usr/include/pcap.h; do
	if [ ! -f "$header" ]; then
		printf 'missing build dependency providing %s (install libelf-dev libpcap-dev)\n' "$header" >&2
		exit 1
	fi
done

src="$WORK/dynamips-${VERSION}"
rm -rf "$WORK"
mkdir -p "$WORK" "$OUT"

tarball="$WORK/dynamips-${VERSION}.tar.gz"
printf 'downloading %s\n' "$URL"
curl -fsSL -o "$tarball" "$URL"
printf '%s  %s\n' "$SHA256" "$tarball" | sha256sum -c -
tar -xzf "$tarball" -C "$WORK"

cmake -S "$src" -B "$src/build" \
	-DCMAKE_BUILD_TYPE=Release \
	-DDYNAMIPS_CODE=stable \
	-DDYNAMIPS_ARCH=amd64 \
	-DDYNAMIPS_RENAME=stable
cmake --build "$src/build" --parallel "$JOBS"

binary="$src/build/stable/dynamips"
if [ ! -x "$binary" ]; then
	binary="$(find "$src/build" -type f -name dynamips -perm -u+x | head -n1)"
fi
if [ -z "${binary:-}" ] || [ ! -x "$binary" ]; then
	printf 'dynamips binary was not produced\n' >&2
	exit 1
fi

stage="$WORK/stage"
mkdir -p \
	"$stage/DEBIAN" \
	"$stage/usr/bin" \
	"$stage/usr/share/doc/$PACKAGE"
install -m 0755 "$binary" "$stage/usr/bin/dynamips"
nvram_export="$(find "$src/build" -type f -name nvram_export -perm -u+x | head -n1)"
if [ -n "${nvram_export:-}" ]; then
	install -m 0755 "$nvram_export" "$stage/usr/bin/nvram_export"
fi

# GPLv2 notice and the exact source location for the corresponding source.
install -m 0644 "$ROOT/packaging/dynamips/copyright" "$stage/usr/share/doc/$PACKAGE/copyright"
install -m 0644 "$ROOT/LICENSES/GPL-2.0.txt" "$stage/usr/share/doc/$PACKAGE/GPL-2.0.txt"
printf 'source: %s\nsha256: %s\n' "$URL" "$SHA256" \
	> "$stage/usr/share/doc/$PACKAGE/SOURCE"

installed_size=$(du -sk "$stage" | awk '{print $1}')
cat > "$stage/DEBIAN/control" <<EOF
Package: $PACKAGE
Version: ${VERSION}-1
Section: net
Priority: optional
Architecture: amd64
Maintainer: TeraLab contributors <noreply@teralab.invalid>
Homepage: https://github.com/SavEvens/TeraLab
Installed-Size: $installed_size
Depends: libc6, libelf1t64, libpcap0.8t64, zlib1g
Conflicts: dynamips, unetlab-dynamips
Replaces: dynamips, unetlab-dynamips
Description: Dynamips engine for TeraLab (separate GPLv2 package)
 Dynamips is a Cisco router emulator. TeraLab runs it as a separate
 unprivileged process; this package contains only the upstream binary and
 its GPLv2 notices. Cisco IOS images are not included.
EOF

deb="$OUT/${PACKAGE}_${VERSION}-1_amd64.deb"
dpkg-deb --build --root-owner-group "$stage" "$deb" >/dev/null
printf 'built %s\n' "$deb"
printf 'verify with: dpkg-deb -c %s\n' "$deb"
