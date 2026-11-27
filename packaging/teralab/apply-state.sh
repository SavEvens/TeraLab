#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Apply the idempotent TeraLab host state.
#
# This is the single implementation of every state mutation a TeraLab host
# needs: dependency checks, the PHP upload drop-ins, the teralab-vm user, the
# agent secret, the data directories, the /opt/unetlab compatibility link, the
# Apache configuration, the systemd units and the optional KSM settings.
#
# It is called by packaging/teralab/install.sh after the source copy/build
# steps, and by the Debian package's postinst. It never overwrites operator
# configuration, the database, labs, images or an existing agent secret, and
# it is safe to re-run.
set -Eeuo pipefail

ROOT="/opt/teralab"
DATA="/var/lib/teralab"
OWNER="www-data"
INSTALL_SYSTEMD=1
INSTALL_KSM=0
PACKAGE_MODE=0
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

usage() {
	cat <<EOF
Usage: packaging/teralab/apply-state.sh [options]

Apply the idempotent TeraLab host state. Run as root.

Options:
  --root PATH     Install root (default: $ROOT)
  --data PATH     Runtime state (default: $DATA)
  --no-systemd    Do not install or start the systemd units
  --ksm           Enable Kernel Samepage Merging for labs and containers
  --package       Keep dpkg-owned conffiles untouched (package postinst mode)
  -h, --help      Show this help

--package skips the PHP ini drop-ins, the Apache configuration and both unit
files because the Debian package ships them as conffiles; every directory,
user, secret, symlink, enablement, start and reload step still runs.

Dependencies: php >= 8.4 with sqlite3, qemu-system-x86_64, qemu-img.
EOF
}

need_value() {
	if [ "$#" -lt 2 ] || [ -z "$2" ]; then
		printf '%s needs a value\n' "$1" >&2
		usage >&2
		exit 2
	fi
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--root) need_value "$1" "${2-}"; ROOT="$2"; shift 2 ;;
		--data) need_value "$1" "${2-}"; DATA="$2"; shift 2 ;;
		--no-systemd) INSTALL_SYSTEMD=0; shift ;;
		--ksm) INSTALL_KSM=1; shift ;;
		--package) PACKAGE_MODE=1; shift ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; exit 2 ;;
	esac
done

case "$ROOT" in
	/*) ;;
	*) printf -- '--root must be an absolute path\n' >&2; exit 2 ;;
esac
case "$DATA" in
	/*) ;;
	*) printf -- '--data must be an absolute path\n' >&2; exit 2 ;;
esac

if [ "$(id -u)" -ne 0 ]; then
	printf 'run as root\n' >&2
	exit 1
fi

command -v php >/dev/null || { echo "php is required" >&2; exit 1; }
getent group "$OWNER" >/dev/null || { echo "group $OWNER is required (install Apache)" >&2; exit 1; }
command -v qemu-system-x86_64 >/dev/null || { echo "qemu-system-x86_64 is required" >&2; exit 1; }
command -v qemu-img >/dev/null || { echo "qemu-img is required" >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo "PHP 8.4+ is required" >&2; exit 1; }
php -m | grep -qx sqlite3 || { echo "php sqlite3 is required" >&2; exit 1; }

# Image uploads (IOL/Dynamips/ISO/qcow2) need limits above the 2M PHP default.
# In package mode dpkg owns the six conffiles this script would otherwise
# write (Apache configuration, three PHP ini drop-ins and both units). The
# logrotate file and the doc copyright are package-only and never written
# here. Keep this skip list in sync with nfpm.yaml's type: config entries and
# build-deb.sh's expected conffiles when either changes.
if [ "$PACKAGE_MODE" -eq 0 ]; then
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
fi

if ! id -u teralab-vm >/dev/null 2>&1; then
	useradd --system --no-create-home --shell /usr/sbin/nologin teralab-vm
fi
if getent group kvm >/dev/null; then
	usermod -aG kvm teralab-vm
fi

install -d -m 0750 -o root -g "$OWNER" /etc/teralab
if [ ! -f /etc/teralab/agent.secret ]; then
	( umask 077; head -c 32 /dev/urandom | base64 | tr -d '\n' > /etc/teralab/agent.secret )
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

# /run may be a read-only tmpfs or absent in containers; never abort there.
# The agent unit also declares RuntimeDirectory=teralab.
if [ -d /run ] && [ -w /run ]; then
	install -d -m 0750 -o root -g "$OWNER" /run/teralab \
		|| printf 'warning: could not create /run/teralab\n' >&2
else
	printf 'warning: /run is missing or not writable; skipped /run/teralab\n' >&2
fi

if [ ! -L /opt/unetlab ]; then
	if [ -e /opt/unetlab ]; then
		printf '/opt/unetlab exists and is not a symlink; leaving it untouched\n' >&2
	else
		ln -s "$ROOT" /opt/unetlab
	fi
fi

# In package mode the Apache configuration is a dpkg conffile.
if [ "$PACKAGE_MODE" -eq 0 ]; then
	apache_template="$SCRIPT_DIR/teralab.apache.conf"
	if [ ! -f "$apache_template" ]; then
		printf 'missing Apache template: %s\n' "$apache_template" >&2
		exit 1
	fi
	if [ -d /etc/apache2/conf-available ]; then
		{
			while IFS= read -r line || [ -n "$line" ]; do
				printf '%s\n' "${line//@ROOT@/$ROOT}"
			done < "$apache_template"
		} > /etc/apache2/conf-available/teralab.conf
		chmod 0644 /etc/apache2/conf-available/teralab.conf
	else
		printf 'warning: /etc/apache2/conf-available is missing; is Apache installed?\n' >&2
	fi
fi

if command -v a2enmod >/dev/null 2>&1; then
	a2enmod proxy proxy_wstunnel rewrite >/dev/null 2>&1 \
		|| printf 'warning: could not enable Apache modules proxy, proxy_wstunnel and rewrite\n' >&2
	# The TeraLab configuration has no PHP handler of its own: .php must be
	# executed, never served as a file. Prefer PHP-FPM (the supported SAPI) and
	# accept an enabled mod_php, but do not enable the site without either.
	PHP_HANDLER=0
	if [ -f /etc/apache2/conf-available/php8.4-fpm.conf ]; then
		a2enmod proxy_fcgi setenvif >/dev/null 2>&1 \
			|| printf 'warning: could not enable Apache modules proxy_fcgi and setenvif\n' >&2
		a2enconf php8.4-fpm >/dev/null 2>&1 \
			|| printf 'warning: could not enable the php8.4-fpm Apache configuration\n' >&2
		PHP_HANDLER=1
	elif command -v a2query >/dev/null 2>&1 && a2query -m php8.4 >/dev/null 2>&1; then
		printf 'note: php8.4-fpm is not installed; using the enabled mod_php for PHP\n' >&2
		PHP_HANDLER=1
	else
		printf 'warning: no PHP handler found (install php8.4-fpm); not enabling the TeraLab Apache configuration, because it would serve api/public/index.php as a file\n' >&2
	fi
else
	printf 'warning: a2enmod not found; enable mod_proxy, mod_proxy_wstunnel, mod_rewrite, mod_proxy_fcgi and mod_setenvif, and the php8.4-fpm and teralab configurations, manually\n' >&2
	PHP_HANDLER=1
fi
if [ "${PHP_HANDLER:-1}" -eq 1 ] && command -v a2enconf >/dev/null 2>&1; then
	a2enconf teralab >/dev/null 2>&1 \
		|| printf 'warning: could not enable the Apache configuration "teralab"\n' >&2
elif [ "${PHP_HANDLER:-1}" -eq 1 ]; then
	printf 'warning: a2enconf not found; enable the Apache configuration "teralab" manually\n' >&2
fi

if [ "$INSTALL_SYSTEMD" -eq 1 ] && command -v systemctl >/dev/null; then
	# In package mode dpkg owns both unit files (conffiles).
	if [ "$PACKAGE_MODE" -eq 0 ]; then
		# A checkout has agent/systemd; the packaged tree keeps the templates
		# next to this script, so source-mode runs work from a .deb install too.
		agent_unit="$ROOT/agent/systemd/teralab-agent.service"
		[ -f "$agent_unit" ] || agent_unit="$SCRIPT_DIR/teralab-agent.service"
		firewall_unit="$SCRIPT_DIR/teralab-firewall.service"
		for pair in "$agent_unit:/etc/systemd/system/teralab-agent.service" \
			"$firewall_unit:/etc/systemd/system/teralab-firewall.service"; do
			unit_src="${pair%%:*}"
			unit_dst="${pair#*:}"
			if [ -f "$unit_src" ]; then
				install -m 0644 "$unit_src" "$unit_dst"
			elif [ -f "$unit_dst" ]; then
				printf 'note: keeping the installed unit %s (no template shipped)\n' "$unit_dst" >&2
			else
				printf 'warning: no unit template for %s; systemd will not start it\n' "$unit_dst" >&2
			fi
		done
	fi
	if [ "$INSTALL_KSM" -eq 1 ]; then
		# Opt-in KSM. The unit turns KSM on and tunes the scanner; the drop-ins
		# mark the services' processes as mergeable. MemoryKSM= is a
		# systemd.exec(5) setting (systemd 254+; PR_SET_MEMORY_MERGE, kernel
		# 6.4+), applied by systemd to each process it starts for the unit.
		# Only ksm.conf is written in each drop-in directory; other drop-ins
		# are left untouched. Re-running rewrites the same content.
		if [ ! -e /sys/kernel/mm/ksm/run ]; then
			printf 'warning: /sys/kernel/mm/ksm is missing; this kernel has no KSM support\n' >&2
		fi
		cat > /etc/systemd/system/teralab-ksm.service <<'KSMUNIT'
# Generated by the TeraLab installer (install.sh --ksm).
[Unit]
Description=TeraLab Kernel Samepage Merging

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'echo 1 > /sys/kernel/mm/ksm/run; echo 4000 > /sys/kernel/mm/ksm/pages_to_scan; echo 50 > /sys/kernel/mm/ksm/sleep_millisecs'

[Install]
WantedBy=multi-user.target
KSMUNIT
		chmod 0644 /etc/systemd/system/teralab-ksm.service
		install -d -m 0755 /etc/systemd/system/docker.service.d
		cat > /etc/systemd/system/docker.service.d/ksm.conf <<'KSMCONF'
# Generated by the TeraLab installer (install.sh --ksm).
[Service]
MemoryKSM=yes
KSMCONF
		chmod 0644 /etc/systemd/system/docker.service.d/ksm.conf
		install -d -m 0755 /etc/systemd/system/teralab-agent.service.d
		cat > /etc/systemd/system/teralab-agent.service.d/ksm.conf <<'KSMCONF'
# Generated by the TeraLab installer (install.sh --ksm).
[Service]
MemoryKSM=yes
KSMCONF
		chmod 0644 /etc/systemd/system/teralab-agent.service.d/ksm.conf
	fi
	systemctl daemon-reload >/dev/null 2>&1 \
		|| printf 'warning: systemctl daemon-reload failed\n' >&2
	systemctl enable --now teralab-agent.service \
		|| printf 'warning: teralab-agent.service did not start; see docs/INSTALL.md\n' >&2
	# Applies the lab-bridge rule immediately on upgrade. A host without
	# iptables is valid and the unit exits 0; never abort the install if the
	# rule cannot be applied.
	systemctl enable --now teralab-firewall.service \
		|| printf 'warning: the lab bridge firewall rule was not applied; see docs/INSTALL.md\n' >&2
	if [ "$INSTALL_KSM" -eq 1 ]; then
		if systemctl enable --now teralab-ksm.service; then
			printf 'KSM: teralab-ksm.service is enabled and running\n'
		else
			printf 'warning: teralab-ksm.service did not start; check that the kernel provides /sys/kernel/mm/ksm\n' >&2
		fi
		printf 'KSM: the Docker drop-in is read on the next Docker start. Restart Docker while no labs are running:\n'
		printf 'KSM:   sudo systemctl restart docker\n'
		printf 'KSM: the agent drop-in applies the next time teralab-agent starts; the installer does not restart it.\n'
	fi
elif [ "$INSTALL_KSM" -eq 1 ]; then
	printf 'warning: --ksm needs systemd; no KSM unit was installed (--no-systemd, or systemctl is missing)\n' >&2
fi

if command -v systemctl >/dev/null 2>&1; then
	if systemctl is-active --quiet apache2 2>/dev/null; then
		systemctl reload apache2 || true
	fi
	if systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
		systemctl reload php8.4-fpm || true
	fi
fi
