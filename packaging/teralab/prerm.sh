#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Debian prerm for TeraLab. Stops the units only on remove, never on upgrade,
# and never deletes data.
set -e

case "$1" in
remove)
	if command -v systemctl >/dev/null 2>&1; then
		systemctl stop teralab-agent.service teralab-firewall.service >/dev/null 2>&1 || true
		systemctl disable teralab-agent.service teralab-firewall.service >/dev/null 2>&1 || true
	fi
	if command -v a2disconf >/dev/null 2>&1; then
		a2disconf teralab >/dev/null 2>&1 \
			|| printf 'teralab: warning: could not disable the Apache configuration "teralab"\n' >&2
	fi
	# The pool is a conffile and survives a plain remove. It names the
	# teralab-api user, so leaving it behind breaks unrelated PHP sites if that
	# user is later removed. Delete the pool and the generated socket-ACL
	# drop-in, then reload FPM only when it is installed and active. This case
	# runs for remove only, never for upgrade.
	rm -f /etc/php/8.4/fpm/pool.d/teralab.conf \
		/etc/systemd/system/php8.4-fpm.service.d/10-teralab-socket-acl.conf \
		2>/dev/null || true
	rmdir /etc/systemd/system/php8.4-fpm.service.d >/dev/null 2>&1 || true
	if command -v systemctl >/dev/null 2>&1; then
		systemctl daemon-reload >/dev/null 2>&1 || true
		if command -v php-fpm8.4 >/dev/null 2>&1 || [ -x /usr/sbin/php-fpm8.4 ]; then
			if systemctl is-active --quiet php8.4-fpm; then
				systemctl reload-or-restart php8.4-fpm >/dev/null 2>&1 || true
			fi
		fi
	fi
	;;
esac

exit 0
