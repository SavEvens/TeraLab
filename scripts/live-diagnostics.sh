#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Read-only diagnostics for the installed TeraLab host.
#
# Installed at /usr/local/libexec/teralab/live-diagnostics and run as root
# through the reviewed sudoers entry. It never reads agent.secret, setup.token,
# the database, lab files, or container environment variables, and it never
# changes host state. Every probe is marked OK, SKIP, or FAIL; the script exits
# 0 even when an individual probe fails.
#
# Journal excerpts and HTTP output are bounded and redacted for tokens,
# passwords, secrets, and CSRF values.
set -uo pipefail

PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
export PATH

ROOT="/opt/teralab"
DATA="/var/lib/teralab"
ETC_DIR="/etc/teralab"
HELPER="/usr/local/libexec/teralab"
URL="${TERALAB_LIVE_URL:-http://127.0.0.1}"
SERVICES=(teralab-agent apache2 php8.4-fpm)

usage() {
	cat <<EOF
Usage: live-diagnostics.sh [--help]

Print a read-only, redacted report for the installed TeraLab host. No
arguments; no host changes. Individual failures are marked FAIL and do not
change the exit status.
EOF
}

case "${1:-}" in
	'') ;;
	-h|--help) usage; exit 0 ;;
	*) usage >&2; exit 2 ;;
esac

ok() { printf '[OK]   %s\n' "$*"; }
skip() { printf '[SKIP] %s\n' "$*"; }
fail() { printf '[FAIL] %s\n' "$*"; }

redact() {
	sed -E \
		-e 's/(token|password|secret|csrf|authorization|cookie)([[:space:]]*[=:][[:space:]]*)[^[:space:],;&"]+/\1\2<redacted>/Ig' \
		-e 's/[Bb]earer[[:space:]]+[A-Za-z0-9._~+\/=-]+/Bearer <redacted>/g' \
		-e 's/([?&](token|password|secret|csrf)=)[^&[:space:]]+/\1<redacted>/Ig'
}

printf 'TeraLab live diagnostics\n'
printf 'Generated: %s\n' "$(date -Is 2>/dev/null || date)"
printf 'Host: %s  User: %s\n\n' "$(hostname 2>/dev/null || echo unknown)" "$(id -un 2>/dev/null || echo unknown)"

# 1. Services: state, substate, main PID, start time.
if command -v systemctl >/dev/null 2>&1; then
	for unit in "${SERVICES[@]}"; do
		state="$(systemctl show "$unit" --property=ActiveState --value 2>/dev/null | tr -d '\n')"
		substate="$(systemctl show "$unit" --property=SubState --value 2>/dev/null | tr -d '\n')"
		pid="$(systemctl show "$unit" --property=MainPID --value 2>/dev/null | tr -d '\n')"
		started="$(systemctl show "$unit" --property=ExecMainStartTimestamp --value 2>/dev/null | tr -d '\n')"
		if [ -z "$state" ]; then
			fail "service $unit: no state (unit missing or systemd unavailable)"
		elif [ "$state" = "active" ]; then
			ok "service $unit: active/$substate pid=${pid:-0} started=${started:-unknown}"
		else
			fail "service $unit: $state/$substate pid=${pid:-0}"
		fi
	done
else
	skip "systemd is unavailable; service state not checked"
fi

# 2. Bounded, redacted journal excerpt.
if command -v journalctl >/dev/null 2>&1; then
	if journal="$(journalctl --no-pager --quiet -n 40 -o cat \
		-u teralab-agent -u apache2 -u php8.4-fpm 2>/dev/null)"; then
		count="$(printf '%s\n' "$journal" | grep -c . || true)"
		ok "journal: $count recent lines (redacted)"
		printf '%s\n' "$journal" | redact | sed 's/^/       | /'
	else
		skip "journal: not readable (journalctl failed)"
	fi
else
	skip "journal: journalctl is unavailable"
fi

# 3. Health, version, UI status, and security headers.
health="$(curl -fsS --max-time 10 "$URL/api/v2/health" 2>/dev/null)"
if [ -n "$health" ]; then
	read -r status version <<<"$(
		printf '%s' "$health" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("status",""), d.get("version",""))' 2>/dev/null
	)" || true
	if [ "$status" = "ok" ]; then
		ok "health: status=ok version=${version:-unknown}"
	else
		fail "health: status=${status:-unknown} (expected ok)"
	fi
else
	fail "health: $URL/api/v2/health is not reachable"
fi

page="$(curl -sS -D - -o /dev/null --max-time 10 "$URL/ui/" 2>/dev/null)"
if [ -n "$page" ]; then
	code="$(printf '%s\n' "$page" | awk 'NR == 1 {print $2}')"
	if [ "$code" = "200" ]; then
		ok "ui: HTTP 200 at $URL/ui/"
	else
		fail "ui: HTTP ${code:-unknown} at $URL/ui/"
	fi
	for header in content-security-policy x-content-type-options x-frame-options; do
		if printf '%s' "$page" | grep -qi "^$header:"; then
			ok "header: $header present"
		else
			fail "header: $header missing"
		fi
	done
else
	fail "ui: $URL/ui/ is not reachable"
fi

# 4. Install permission matrix, when a copy of the checker is available.
perms=""
for candidate in \
	"$HELPER/check-install-perms" \
	"$ROOT/scripts/check-install-perms.sh" \
	"$(dirname "$0")/../scripts/check-install-perms.sh"; do
	if [ -f "$candidate" ]; then
		perms="$candidate"
		break
	fi
done
if [ -n "$perms" ]; then
	if output="$(bash "$perms" --root "$ROOT" --data "$DATA" --etc "$ETC_DIR" 2>&1)"; then
		ok "install perms: $(printf '%s' "$output" | redact | tail -n 1)"
	else
		fail "install perms: $(printf '%s' "$output" | redact | tail -n 1)"
	fi
else
	skip "install perms: check-install-perms.sh is not installed"
fi

# 5. Ownership and mode matrix (names and modes only; no file contents).
printf '\nOwnership and modes:\n'
for dir_path in "$ROOT" "$ETC_DIR" "$DATA"; do
	if [ ! -e "$dir_path" ]; then
		skip "mode matrix: $dir_path is absent"
		continue
	fi
	if stat_line="$(stat -c '%A %U:%G' "$dir_path" 2>/dev/null)"; then
		ok "mode matrix: $stat_line $dir_path"
	else
		fail "mode matrix: cannot stat $dir_path"
		continue
	fi
	if entries="$(find "$dir_path" -mindepth 1 -maxdepth 1 -printf '%M %u:%g %f\n' 2>/dev/null | sort)"; then
		if [ -n "$entries" ]; then
			printf '%s\n' "$entries" | sed 's/^/       | /'
		fi
	else
		skip "mode matrix: cannot list $dir_path"
	fi
done

# 6. Read-only Docker inventory for TeraLab-labelled containers only.
if command -v docker >/dev/null 2>&1; then
	if listing="$(docker ps --format '{{.ID}} {{.Names}} {{.Image}} {{.Status}} {{.Label "teralab.lab"}} {{.Label "teralab.node"}}' 2>/dev/null)"; then
		hits="$(printf '%s\n' "$listing" | awk 'NF >= 5 { lab=$5; node=$6; if ((lab != "" && lab != "<no value>") || (node != "" && node != "<no value>")) print }')"
		if [ -n "$hits" ]; then
			fail "containers: TeraLab-labelled containers are running"
			printf '%s\n' "$hits" | sed 's/^/       | /'
		else
			ok "containers: no TeraLab-labelled containers"
		fi
	else
		skip "containers: docker ps is not permitted"
	fi
else
	skip "containers: docker is unavailable"
fi

# 7. TeraLab bridge links (read-only).
if command -v ip >/dev/null 2>&1; then
	if links="$(ip -br link show 2>/dev/null)"; then
		tl_links="$(printf '%s\n' "$links" | awk '$1 ~ /^tl/')"
		if [ -n "$tl_links" ]; then
			ok "network: TeraLab link(s) present"
			printf '%s\n' "$tl_links" | sed 's/^/       | /'
		else
			ok "network: no tl* links"
		fi
	else
		skip "network: ip link is not readable"
	fi
else
	skip "network: ip is unavailable"
fi

printf '\nDiagnostics complete (read-only).\n'
exit 0
