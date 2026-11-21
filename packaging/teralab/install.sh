#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Install TeraLab v1.6-dev on a Debian 13 host.
#
# The installer never overwrites configuration, the database, labs, or images.
# It is safe to re-run and is the supported upgrade path from v1.1.
set -Eeuo pipefail

ROOT="/opt/teralab"
DATA="/var/lib/teralab"
SOURCE="$(cd "$(dirname "$0")/../.." && pwd)"
OWNER="www-data"
AGENT_BINARY=""
INSTALL_SYSTEMD=1

usage() {
	cat <<EOF
Usage: packaging/teralab/install.sh [options]

Options:
  --root PATH            Install root (default: $ROOT)
  --data PATH            Runtime state (default: $DATA)
  --source PATH          Source tree (default: $SOURCE)
  --agent-binary PATH    Prebuilt teralab-agent (default: build with go)
  --no-systemd           Do not install or reload the systemd unit
  -h, --help             Show this help

Dependencies: php >= 8.4 with pdo_sqlite, qemu-system-x86_64, qemu-img,
composer (when api/vendor is absent), Go (when no agent binary is supplied).
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--root) ROOT="$2"; shift 2 ;;
		--data) DATA="$2"; shift 2 ;;
		--source) SOURCE="$2"; shift 2 ;;
		--agent-binary) AGENT_BINARY="$2"; shift 2 ;;
		--no-systemd) INSTALL_SYSTEMD=0; shift ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; exit 2 ;;
	esac
done

if [ "$(id -u)" -ne 0 ]; then
	printf 'run as root\n' >&2
	exit 1
fi

command -v php >/dev/null || { echo "php is required" >&2; exit 1; }
command -v rsync >/dev/null || { echo "rsync is required" >&2; exit 1; }
getent group "$OWNER" >/dev/null || { echo "group $OWNER is required (install Apache)" >&2; exit 1; }
command -v qemu-system-x86_64 >/dev/null || { echo "qemu-system-x86_64 is required" >&2; exit 1; }
command -v qemu-img >/dev/null || { echo "qemu-img is required" >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo "PHP 8.4+ is required" >&2; exit 1; }
php -m | grep -qx sqlite3 || { echo "php sqlite3 is required" >&2; exit 1; }

# Image uploads (IOL/Dynamips/ISO/qcow2) need limits above the 2M PHP default.
for sapi in apache2 fpm cli; do
	php_conf="/etc/php/8.4/$sapi/conf.d"
	if [ -d "$php_conf" ]; then
		cat > "$php_conf/99-teralab-uploads.ini" <<'PHPINI'
; TeraLab image uploads
upload_max_filesize = 4G
post_max_size = 4G
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
PHPINI
	fi
done

if [ ! -d "$SOURCE/api" ]; then
	printf 'source tree not found: %s\n' "$SOURCE" >&2
	exit 1
fi
if [ ! -f "$SOURCE/api/vendor/autoload.php" ]; then
	command -v composer >/dev/null || { echo "composer is required to install API dependencies" >&2; exit 1; }
	( cd "$SOURCE/api" && composer install --no-dev --no-interaction --prefer-dist )
fi

if ! id -u teralab-vm >/dev/null 2>&1; then
	useradd --system --no-create-home --shell /usr/sbin/nologin teralab-vm
fi
if getent group kvm >/dev/null; then
	usermod -aG kvm teralab-vm
fi

install -d -m 0755 "$ROOT"
rsync -a --delete \
	--exclude '.git' \
	--exclude 'AGENTS.md' \
	--exclude 'agent/teralab-agent' \
	--exclude 'agent/bin' \
	--exclude 'dist' \
	--exclude 'node_modules' \
	--exclude 'ui/node_modules' \
	--exclude 'ui/dist' \
	"$SOURCE/" "$ROOT/"

install -d -m 0755 "$ROOT/bin"
if [ -f "$ROOT/ui/package.json" ]; then
	if [ ! -d "$ROOT/ui/dist" ]; then
		if command -v npm >/dev/null 2>&1; then
			( cd "$ROOT/ui" && npm ci --no-audit --no-fund && npm run build )
		else
			printf 'warning: npm not found; the Vue UI will not be built\n' >&2
		fi
	fi
	if [ -d "$ROOT/ui/dist" ]; then
		install -d -m 0755 "$ROOT/html/ui"
		cp -a "$ROOT/ui/dist/." "$ROOT/html/ui/"
	fi
fi
if [ -n "$AGENT_BINARY" ]; then
	install -m 0755 "$AGENT_BINARY" "$ROOT/bin/teralab-agent"
else
	command -v go >/dev/null || { echo "go is required to build teralab-agent (or use --agent-binary)" >&2; exit 1; }
	( cd "$ROOT/agent" && go build -o "$ROOT/bin/teralab-agent" ./cmd/teralab-agent )
fi

install -d -m 0750 -o root -g "$OWNER" /etc/teralab
if [ ! -f /etc/teralab/agent.secret ]; then
	head -c 32 /dev/urandom | base64 | tr -d '\n' > /etc/teralab/agent.secret
fi
chown root:"$OWNER" /etc/teralab/agent.secret
chmod 0640 /etc/teralab/agent.secret

install -d -m 0751 -o "$OWNER" -g "$OWNER" "$DATA"
install -d -m 0750 -o "$OWNER" -g "$OWNER" "$DATA/labs"
install -d -m 0755 -o "$OWNER" -g "$OWNER" "$DATA/images"
install -d -m 0711 "$DATA/run"
install -d -m 0750 -o "$OWNER" -g "$OWNER" "$DATA/logs"
if [ -d "$DATA/images" ]; then
	find "$DATA/images" -type d -exec chmod 0755 {} +
	find "$DATA/images" -type f -exec chmod a+r {} +
	find "$DATA/images" -type f -name '*.bin' -exec chmod 0755 {} +
fi
install -d -m 0750 -o "$OWNER" -g "$OWNER" /etc/teralab/templates
install -d -m 0750 -o root -g "$OWNER" /run/teralab

if [ ! -L /opt/unetlab ]; then
	if [ -e /opt/unetlab ]; then
		printf '/opt/unetlab exists and is not a symlink; leaving it untouched\n' >&2
	else
		ln -s "$ROOT" /opt/unetlab
	fi
fi

cat > /etc/apache2/conf-available/teralab.conf <<EOF
# Generated by the TeraLab installer.
Alias /api "$ROOT/api/public"
<Directory "$ROOT/api/public">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
    FallbackResource /api/index.php
</Directory>
<Directory "$ROOT/html">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
Alias /about.php "$ROOT/html/about.php"
Alias /images "$ROOT/html/images"
<Directory "$ROOT/html/images">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
Alias /themes "$ROOT/html/themes"
<Directory "$ROOT/html/themes">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
Alias /ui "$ROOT/html/ui"
<Directory "$ROOT/html/ui">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
    FallbackResource /ui/index.html
</Directory>
ProxyPass        /ws/console ws://127.0.0.1:8091/ws
ProxyPassReverse /ws/console ws://127.0.0.1:8091/ws
ProxyPass        /ws/vnc ws://127.0.0.1:8091/vnc
ProxyPassReverse /ws/vnc ws://127.0.0.1:8091/vnc
ProxyPass        /ws/tcp ws://127.0.0.1:8091/tcp
ProxyPassReverse /ws/tcp ws://127.0.0.1:8091/tcp
EOF

if [ "$INSTALL_SYSTEMD" -eq 1 ] && command -v systemctl >/dev/null; then
	install -m 0644 "$ROOT/agent/systemd/teralab-agent.service" /etc/systemd/system/teralab-agent.service
	install -m 0644 "$ROOT/packaging/teralab/teralab-firewall.service" /etc/systemd/system/teralab-firewall.service
	systemctl daemon-reload
	systemctl enable --now teralab-agent.service
	# Applies the lab-bridge rule immediately on upgrade. A host without
	# iptables is valid and the script exits 0; never abort the install if the
	# rule cannot be applied.
	systemctl enable --now teralab-firewall.service \
		|| printf 'warning: the lab bridge firewall rule was not applied; see docs/INSTALL.md\n' >&2
fi

if command -v systemctl >/dev/null 2>&1; then
	if systemctl is-active --quiet apache2 2>/dev/null; then
		systemctl reload apache2 || true
	fi
	if systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
		systemctl reload php8.4-fpm || true
	fi
fi

printf 'TeraLab installed at %s\n' "$ROOT"
printf 'Runtime data: %s\n' "$DATA"
printf 'Lab bridge firewall rule: %s\n' "$(systemctl is-active teralab-firewall.service 2>/dev/null || echo manual)"
printf 'Next: complete first-run setup at /api/v2/setup (one time), then log in.\n'
