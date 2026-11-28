#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Apply the idempotent TeraLab host state.
#
# This is the single implementation of every state mutation a TeraLab host
# needs: dependency checks, the service identities, the PHP upload drop-ins,
# the data directory matrix, the ImageStore group, the agent secret, the
# first-run setup token, the PHP-FPM pool, the /opt/unetlab compatibility
# link, the Apache configuration, the firewall helper, the systemd units and
# drop-ins, and the optional KSM settings.
#
# It is called by packaging/teralab/install.sh after the source copy/build
# steps, and by the Debian package's postinst. It never overwrites operator
# configuration, the database, labs, images or an existing agent secret, and
# it is safe to re-run.
set -Eeuo pipefail

ROOT="/opt/teralab"
DATA="/var/lib/teralab"
SERVICE_USER="teralab-api"
SERVICE_GROUP="teralab"
IMAGE_GROUP="teralab-images"
FPM_POOL_DIR="/etc/php/8.4/fpm/pool.d"
FPM_SOCKET="/run/php/php8.4-fpm-teralab.sock"
HELPER_DIR="/usr/local/libexec/teralab"
SETUP_TOKEN="/etc/teralab/setup.token"
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

--package skips the PHP ini drop-ins, the Apache configuration, the PHP-FPM
pool and both unit files because the Debian package ships them as conffiles;
every directory, user, secret, helper, drop-in, symlink, enablement, start and
reload step still runs.

Dependencies: php >= 8.4 with sqlite3, php8.4-fpm, qemu-system-x86_64,
qemu-img and setfacl (the acl package). setfacl is required so Apache can
reach the dedicated FastCGI socket without adding www-data to teralab. If a
filesystem does not support ACLs, the data root falls back to mode 0711 with an
owner-only database.
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
command -v qemu-system-x86_64 >/dev/null || { echo "qemu-system-x86_64 is required" >&2; exit 1; }
command -v qemu-img >/dev/null || { echo "qemu-img is required" >&2; exit 1; }
command -v setfacl >/dev/null || { echo "setfacl is required (install the acl package)" >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo "PHP 8.4+ is required" >&2; exit 1; }
php -m | grep -qx sqlite3 || { echo "php sqlite3 is required" >&2; exit 1; }

# Image uploads (IOL/Dynamips/ISO/qcow2) need limits above the 2M PHP default.
# In package mode dpkg owns the seven conffiles this script would otherwise
# write (Apache configuration, three PHP ini drop-ins, the PHP-FPM pool and
# both units). The logrotate file and the doc copyright are package-only and
# never written here. Keep this skip list in sync with nfpm.yaml's type:
# config entries and build-deb.sh's expected conffiles when either changes.
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

# Service identities. The API never runs as www-data: Apache hands the request
# to the dedicated PHP-FPM pool, which drops to teralab-api:teralab. The agent
# keeps root (it is the privileged broker) but its socket and secret are in
# the teralab group, which www-data is never in.
if ! getent group "$SERVICE_GROUP" >/dev/null; then
	groupadd --system "$SERVICE_GROUP"
fi
if ! id -u "$SERVICE_USER" >/dev/null 2>&1; then
	useradd --system --gid "$SERVICE_GROUP" --home-dir /nonexistent \
		--no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
else
	usermod -g "$SERVICE_GROUP" "$SERVICE_USER"
	usermod --home /nonexistent --shell /usr/sbin/nologin "$SERVICE_USER" 2>/dev/null || true
fi
if ! getent group "$IMAGE_GROUP" >/dev/null; then
	groupadd --system "$IMAGE_GROUP"
fi
# The API writes image files with group teralab-images; membership lets it
# read/rewrite them without world access. -aG is idempotent. Never www-data.
if getent group "$IMAGE_GROUP" >/dev/null; then
	usermod -aG "$IMAGE_GROUP" "$SERVICE_USER"
fi

SERVICE_UID="$(id -u "$SERVICE_USER")"
SERVICE_GID="$(getent group "$SERVICE_GROUP" | cut -d: -f3)"
IMAGE_GID="$(getent group "$IMAGE_GROUP" | cut -d: -f3)"

# A package install over a legacy source tree keeps the files dpkg did not
# replace (and the tree root itself) with the old owner. The code and helpers
# must be root-owned and never group/world writable; source installs already
# chown the rsynced tree, so this is a no-op there.
if [ -d "$ROOT" ]; then
	chown -R root:root "$ROOT"
	chmod -R go-w "$ROOT"
fi

# www-data must never share the TeraLab trust boundary. Remove a stale
# membership left by an older install instead of failing the upgrade.
if id -nG www-data 2>/dev/null | tr ' ' '\n' | grep -qx "$SERVICE_GROUP"; then
	gpasswd -d www-data "$SERVICE_GROUP" >/dev/null 2>&1 \
		|| printf 'warning: could not remove www-data from group %s\n' "$SERVICE_GROUP" >&2
fi

if ! id -u teralab-vm >/dev/null 2>&1; then
	useradd --system --no-create-home --shell /usr/sbin/nologin teralab-vm
fi
if getent group kvm >/dev/null; then
	usermod -aG kvm teralab-vm
fi
# teralab-vm is the fallback engine identity (TERALAB_PER_LAB_UID=0). It needs
# the image store through the shared group, and nothing else.
usermod -aG "$IMAGE_GROUP" teralab-vm

install -d -m 0750 -o root -g "$SERVICE_GROUP" /etc/teralab
if [ ! -f /etc/teralab/agent.secret ]; then
	( umask 077; head -c 32 /dev/urandom | base64 | tr -d '\n' > /etc/teralab/agent.secret )
fi
chown root:"$SERVICE_GROUP" /etc/teralab/agent.secret
chmod 0640 /etc/teralab/agent.secret
# First-run setup token. The API accepts POST /api/v2/setup only while no user
# exists and requires this value in the X-TeraLab-Setup-Token header. It is
# generated once, never overwritten, so a failed setup attempt can be retried
# with the same token; the API disables setup after the first user regardless.
# root:teralab 0640 lets the PHP-FPM pool identity (teralab-api, primary group
# teralab) read it to verify the header. It is never world-readable, and
# www-data is not in teralab. install.sh and the package postinst print it
# once on creation; an operator can read it from this file as root.
if [ ! -f "$SETUP_TOKEN" ]; then
	( umask 077; head -c 32 /dev/urandom | base64 | tr -d '\n' > "$SETUP_TOKEN" )
fi
chown root:"$SERVICE_GROUP" "$SETUP_TOKEN"
chmod 0640 "$SETUP_TOKEN"
# Drop a stale named ACL left by an older, www-data-based install.
if command -v setfacl >/dev/null 2>&1; then
	setfacl -x u:www-data /etc/teralab /etc/teralab/agent.secret "$SETUP_TOKEN" 2>/dev/null || true
fi
# Custom YAML templates are admin-written through the API, so the service
# identity owns this directory instead of www-data.
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_GROUP" /etc/teralab/templates

# Data directory matrix (see docs/INSTALL.md). The API owns its data; the
# database and its WAL/SHM are owner-only; the image store is read-only for
# engines through the teralab-images group; the agent state remains root-only.
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/labs"
# Reserved by the matrix; the API's template store is /etc/teralab/templates.
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/templates"
install -d -m 0700 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/backups"
install -d -m 0700 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/restore"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/logs"
install -d -m 0755 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/images"
install -d -m 0711 -o root -g root "$DATA/run"
install -d -m 0700 -o root -g root "$DATA/state"
# Upload staging is shared with the agent (root) and is never public.
install -d -m 2770 -o "$SERVICE_USER" -g "$SERVICE_GROUP" "$DATA/images/tmp"

# Existing databases are tightened here as well as by the API, so a source
# upgrade fixes a 0644 database before the first request.
for db_file in "$DATA/database.sdb" "$DATA/database.sdb-wal" "$DATA/database.sdb-shm"; do
	if [ -e "$db_file" ]; then
		chown "$SERVICE_USER:$SERVICE_GROUP" "$db_file"
		chmod 0600 "$db_file"
	fi
done

# Migration from a www-data-owned tree: the data the API reads and writes must
# belong to the API identity, not to the web-server user. -h changes a symlink
# itself, never a target outside the store. State stays root-owned and is
# deliberately absent from this list.
for owned_path in "$DATA/labs" "$DATA/backups" "$DATA/restore" "$DATA/logs" \
	/etc/teralab/templates; do
	[ -d "$owned_path" ] || continue
	find "$owned_path" -mindepth 1 -exec chown -h "$SERVICE_USER:$SERVICE_GROUP" {} + \
		|| printf 'warning: could not change ownership under %s\n' "$owned_path" >&2
done

# The image store is readable by the agent's engine identities through
# teralab-images, never world-readable. The API identity owns the files (it
# uploads and rewrites them) and the group carries engine read access.
# IOL/Dynamips binaries stay executable for the group; disk images and ISOs
# are group-read only. Adopted external images are symlinks and are skipped.
if [ -d "$DATA/images" ]; then
	find "$DATA/images" -type d \
		! -path "$DATA/images/tmp" ! -path "$DATA/images/tmp/*" \
		-exec chown "$SERVICE_USER:$SERVICE_GROUP" {} + \
		-exec chmod 0755 {} +
	find "$DATA/images" -type f ! -path "$DATA/images/tmp/*" \
		-exec chown "$SERVICE_USER:$IMAGE_GROUP" {} +
	find "$DATA/images" -type f ! -path "$DATA/images/tmp/*" \
		-name '*.bin' -exec chmod 0750 {} +
	find "$DATA/images" -type f ! -path "$DATA/images/tmp/*" \
		! -name '*.bin' -exec chmod 0640 {} +
fi

# Engine identities traverse the data root but cannot list it. The named
# image-group entry is what lets per-lab numeric UIDs (which have the
# teralab-images supplementary GID, not the teralab group) reach the run and
# image paths; teralab-vm is covered for fallback mode. Without ACLs the
# documented 0711 fallback grants the same traverse-only access. The database
# is 0600 either way.
if command -v setfacl >/dev/null 2>&1 \
	&& setfacl -m "u:teralab-vm:--x" "$DATA" 2>/dev/null \
	&& setfacl -m "g:${IMAGE_GROUP}:--x" "$DATA" 2>/dev/null \
	&& setfacl -m "u:teralab-vm:r-x" "$DATA/images" 2>/dev/null \
	&& setfacl -m "u:teralab-vm:--x" "$DATA/run" 2>/dev/null; then
	:
else
	chmod 0711 "$DATA"
	printf 'note: POSIX ACLs are unavailable; data root is 0711 and the database is 0600\n' >&2
fi

# /run may be a read-only tmpfs or absent in containers; never abort there.
# The agent unit also declares RuntimeDirectory=teralab.
if [ -d /run ] && [ -w /run ]; then
	install -d -m 0750 -o root -g "$SERVICE_GROUP" /run/teralab \
		|| printf 'warning: could not create /run/teralab\n' >&2
else
	printf 'warning: /run is missing or not writable; skipped /run/teralab\n' >&2
fi

# The privileged firewall helper is executed by root, so it must never live in
# a user-writable tree. /opt/teralab/packaging/... keeps the source copy.
if [ -f "$SCRIPT_DIR/teralab-firewall" ]; then
	firewall_source="$SCRIPT_DIR/teralab-firewall"
elif [ -f "$ROOT/packaging/teralab/teralab-firewall" ]; then
	firewall_source="$ROOT/packaging/teralab/teralab-firewall"
else
	firewall_source=""
fi
if [ -n "$firewall_source" ]; then
	install -d -m 0755 -o root -g root "$HELPER_DIR"
	install -m 0755 -o root -g root "$firewall_source" "$HELPER_DIR/teralab-firewall"
else
	printf 'warning: no teralab-firewall source found; the firewall unit will not run\n' >&2
fi

# Apache's FastCGI client ACL hook. It is a helper rather than a pool
# directive because php-fpm ignores listen.owner/group when listen.acl_users
# is set; this keeps the socket matrix intact and grants www-data connect
# permission on that one socket only.
if [ -f "$SCRIPT_DIR/fpm-socket-acl" ]; then
	fpm_acl_source="$SCRIPT_DIR/fpm-socket-acl"
elif [ -f "$ROOT/packaging/teralab/fpm-socket-acl" ]; then
	fpm_acl_source="$ROOT/packaging/teralab/fpm-socket-acl"
else
	fpm_acl_source=""
fi
if [ -n "$fpm_acl_source" ]; then
	install -d -m 0755 -o root -g root "$HELPER_DIR"
	install -m 0755 -o root -g root "$fpm_acl_source" "$HELPER_DIR/fpm-socket-acl"
else
	printf 'warning: no fpm-socket-acl source found; Apache will not reach the teralab pool\n' >&2
fi

if [ ! -L /opt/unetlab ]; then
	if [ -e /opt/unetlab ]; then
		printf '/opt/unetlab exists and is not a symlink; leaving it untouched\n' >&2
	else
		ln -s "$ROOT" /opt/unetlab
	fi
fi

# In package mode the Apache configuration and the PHP-FPM pool are dpkg
# conffiles.
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

	# The dedicated pool is what gives TeraLab its own PHP identity. It is
	# written here for source installs and shipped as a conffile in the .deb.
	pool_template="$SCRIPT_DIR/php-fpm-pool.conf"
	[ -f "$pool_template" ] || pool_template="$ROOT/packaging/teralab/php-fpm-pool.conf"
	if [ -d "$FPM_POOL_DIR" ]; then
		if [ -f "$pool_template" ]; then
			install -m 0644 -o root -g root "$pool_template" "$FPM_POOL_DIR/teralab.conf"
		else
			printf 'missing PHP-FPM pool template: %s\n' "$pool_template" >&2
			exit 1
		fi
	else
		printf 'warning: %s is missing; install php8.4-fpm before serving PHP\n' "$FPM_POOL_DIR" >&2
	fi
elif [ ! -f "$FPM_POOL_DIR/teralab.conf" ]; then
	printf 'warning: the packaged PHP-FPM pool %s is missing; restore the conffile\n' \
		"$FPM_POOL_DIR/teralab.conf" >&2
fi

if command -v a2enmod >/dev/null 2>&1; then
	# mod_headers carries the XFO/CSP/Referrer-Policy/nosniff/Permissions-Policy
	# response headers for /ui, /themes, /images and /about.php. The packaged
	# Apache configuration fails "apache2ctl -t" without it, so this must
	# succeed; a failure is reported and the reload below will surface it.
	a2enmod proxy proxy_wstunnel rewrite headers >/dev/null 2>&1 \
		|| printf 'warning: could not enable Apache modules proxy, proxy_wstunnel, rewrite and headers; the teralab configuration needs mod_headers and Apache will fail a configuration test without it\n' >&2
	# The TeraLab configuration pins its own PHP-FPM socket in a
	# directory-scoped SetHandler, so .php is executed, never served as a
	# file, and it never falls back to the shared pool. The distro-wide
	# php8.4-fpm configuration is optional: it only supplies a default
	# handler for other vhosts.
	PHP_HANDLER=0
	if [ -d "$FPM_POOL_DIR" ]; then
		a2enmod proxy_fcgi setenvif >/dev/null 2>&1 \
			|| printf 'warning: could not enable Apache modules proxy_fcgi and setenvif\n' >&2
		if [ -f /etc/apache2/conf-available/php8.4-fpm.conf ]; then
			a2enconf php8.4-fpm >/dev/null 2>&1 \
				|| printf 'warning: could not enable the php8.4-fpm Apache configuration\n' >&2
		fi
		PHP_HANDLER=1
	else
		printf 'warning: no PHP-FPM pool directory (%s); not enabling the TeraLab Apache configuration, because its SetHandler would point at a missing socket\n' "$FPM_POOL_DIR" >&2
	fi
else
	printf 'warning: a2enmod not found; enable mod_proxy, mod_proxy_wstunnel, mod_rewrite, mod_headers, mod_proxy_fcgi and mod_setenvif, and the teralab configuration, manually\n' >&2
	printf 'warning: ensure %s loads and %s exists\n' "$FPM_POOL_DIR/teralab.conf" "$FPM_SOCKET" >&2
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
	# Generated identity drop-in. It also corrects a stale unit conffile kept
	# through an upgrade: systemd applies the last Environment= assignment, and
	# drop-ins are read after the unit, so these values win.
	install -d -m 0755 /etc/systemd/system/teralab-agent.service.d
	identity_dropin=/etc/systemd/system/teralab-agent.service.d/10-teralab-identity.conf
	cat > "$identity_dropin" <<EOF
# Generated by packaging/teralab/apply-state.sh; rewritten on every run.
[Service]
Environment=TERALAB_AGENT_SOCKET_GROUP=$SERVICE_GROUP
Environment=TERALAB_AGENT_ALLOWED_UIDS=$SERVICE_UID
Environment=TERALAB_AGENT_SECRET_FILE=/etc/teralab/agent.secret
Environment=TERALAB_IMAGE_GID=$IMAGE_GID
EOF
	chmod 0644 "$identity_dropin"

	# php-fpm 8.4 has no per-pool umask, so the owner-only umask is a service
	# setting. It is applied to the FPM master and inherited by every worker.
	# The ExecStartPost applies the Apache client ACL whenever php8.4-fpm
	# (re)creates its socket. A graceful reload inherits the socket, so the
	# ACL survives reloads; a full restart re-runs ExecStartPost. The leading
	# "-" keeps a missing ACL tool from failing php8.4-fpm; apply-state
	# reports the real error below.
	install -d -m 0755 /etc/systemd/system/php8.4-fpm.service.d
	fpm_acl_dropin=/etc/systemd/system/php8.4-fpm.service.d/10-teralab-socket-acl.conf
	cat > "$fpm_acl_dropin" <<EOF
# Generated by packaging/teralab/apply-state.sh; rewritten on every run.
[Service]
UMask=0077
ExecStartPost=-$HELPER_DIR/fpm-socket-acl
EOF
	chmod 0644 "$fpm_acl_dropin"
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
	# enable --now does not restart an already-active unit, so a source install
	# that skipped the documented service stop would keep the old agent
	# identity (socket group/UID/image GID) until a restart. The package
	# postinst restarts it on upgrade; warn only for the source path.
	agent_was_active=0
	if systemctl is-active --quiet teralab-agent.service 2>/dev/null; then
		agent_was_active=1
	fi
	systemctl enable --now teralab-agent.service \
		|| printf 'warning: teralab-agent.service did not start; see docs/INSTALL.md\n' >&2
	if [ "$agent_was_active" -eq 1 ] && [ "$PACKAGE_MODE" -eq 0 ]; then
		printf 'warning: teralab-agent.service was already running and keeps its old identity until restarted; stop labs, then run: sudo systemctl restart teralab-agent\n' >&2
	fi
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
	# The pool file is read by the FPM master. Enable it, then reload when it
	# is already running (graceful) or start it after a fresh install.
	if systemctl cat php8.4-fpm.service >/dev/null 2>&1; then
		systemctl enable php8.4-fpm.service >/dev/null 2>&1 \
			|| printf 'warning: could not enable php8.4-fpm.service\n' >&2
		if systemctl is-active --quiet php8.4-fpm 2>/dev/null; then
			systemctl reload php8.4-fpm \
				|| printf 'warning: could not reload php8.4-fpm; the teralab pool may need a restart\n' >&2
		else
			systemctl start php8.4-fpm \
				|| printf 'warning: php8.4-fpm did not start; check %s\n' "$FPM_POOL_DIR/teralab.conf" >&2
		fi
		if systemctl is-active --quiet php8.4-fpm 2>/dev/null \
			&& [ -x "$HELPER_DIR/fpm-socket-acl" ]; then
			"$HELPER_DIR/fpm-socket-acl" \
				|| printf 'warning: could not set the Apache ACL on %s\n' "$FPM_SOCKET" >&2
		fi
	fi
	if systemctl is-active --quiet apache2 2>/dev/null; then
		systemctl reload apache2 || true
	fi
fi

printf 'TeraLab identity: %s (uid %s), group %s (gid %s), image group %s (gid %s)\n' \
	"$SERVICE_USER" "$SERVICE_UID" "$SERVICE_GROUP" "$SERVICE_GID" "$IMAGE_GROUP" "$IMAGE_GID"
printf 'TeraLab PHP-FPM pool: %s (socket %s)\n' "$FPM_POOL_DIR/teralab.conf" "$FPM_SOCKET"
printf 'TeraLab first-run setup token: %s (root:%s 0640; printed once when created)\n' \
	"$SETUP_TOKEN" "$SERVICE_GROUP"
