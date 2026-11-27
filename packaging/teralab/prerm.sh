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
	;;
esac

exit 0
