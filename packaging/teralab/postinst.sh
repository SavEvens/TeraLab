#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Debian postinst for TeraLab.
#
# Idempotent. It applies the shared host state through apply-state.sh
# (--package keeps the dpkg-owned conffiles untouched), wires PHP-FPM into
# Apache, and verifies the units. It creates missing state but never
# overwrites /etc/teralab/agent.secret or operator data.
set -e

case "$1" in
configure) ;;
*) exit 0 ;;
esac

if command -v systemctl >/dev/null 2>&1; then
	systemctl daemon-reload >/dev/null 2>&1 || true
fi

# Every directory, identity, secret, helper, drop-in, symlink, enablement,
# start and reload step. A genuine failure (for example a missing php8.4-fpm
# installation) fails the install.
/opt/teralab/packaging/teralab/apply-state.sh --package

# PHP-FPM is the packaged SAPI. apply-state.sh writes the teralab pool for
# source installs, enables php8.4-fpm here, and enables mod_proxy_fcgi plus the
# TeraLab Apache configuration for both install paths; only the mod_php
# conflict is package-specific.

# Debian's php8.4 metapackage enables mod_php, which conflicts with the
# FPM-only setup. Disable it with a warning rather than failing.
if command -v a2dismod >/dev/null 2>&1 && command -v a2query >/dev/null 2>&1; then
	if a2query -m php8.4 >/dev/null 2>&1; then
		if a2dismod php8.4 >/dev/null 2>&1; then
			printf 'teralab: disabled mod_php (php8.4); TeraLab is served through PHP-FPM.\n'
		else
			printf 'teralab: warning: could not disable mod_php (php8.4); disable it manually\n' >&2
		fi
	fi
fi

# The FPM master reads the upload-limits drop-in at start.
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet php8.4-fpm; then
	systemctl restart php8.4-fpm >/dev/null 2>&1 \
		|| printf 'teralab: warning: could not restart php8.4-fpm\n' >&2
fi
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet apache2; then
	systemctl reload apache2 >/dev/null 2>&1 \
		|| printf 'teralab: warning: could not reload apache2\n' >&2
fi

# apply-state already enabled and started both units; verify, never abort.
if command -v systemctl >/dev/null 2>&1; then
	if [ -n "${2-}" ]; then
		# Package upgrade: the unit was already active, so enable --now did not
		# reload it. Restart it to run the new binary; open consoles end.
		systemctl try-restart teralab-agent.service >/dev/null 2>&1 \
			|| printf 'teralab: warning: could not restart teralab-agent.service\n' >&2
	fi
	systemctl is-active --quiet teralab-agent.service \
		|| systemctl start teralab-agent.service >/dev/null 2>&1 \
		|| printf 'teralab: warning: teralab-agent.service did not start; see docs/INSTALL.md\n' >&2
	systemctl is-active --quiet teralab-firewall.service \
		|| systemctl start teralab-firewall.service >/dev/null 2>&1 \
		|| printf 'teralab: warning: the lab bridge firewall rule was not applied; see docs/INSTALL.md\n' >&2
fi

exit 0
