#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (C) 2026 TeraLab contributors
#
# Verify the installer/package permission matrix (SEC-06).
#
# The check is read-only: it uses stat() and getfacl(1) when present and never
# changes a mode or an owner. It is safe on any host and skips paths that are
# absent, so CI and a developer checkout pass without a TeraLab install. Run it
# after install.sh or the .deb postinst; a fresh and an upgraded install must
# both pass.
#
# Fails when:
#   - the database or its WAL/SHM is group/world readable, or not owned by
#     teralab-api;
#   - an image-store directory is not 0755, an image file is not 0640 (0750
#     for .bin) with group teralab-images, or an image path is writable by
#     group/other;
#   - installed code or a root helper is group/world writable or not root-owned;
#   - www-data is in the teralab group or can read the agent secret;
#   - the first-run setup token is present and is not root:teralab 0640;
#   - teralab-api is not a member of teralab-images;
#   - the agent state directory is writable by teralab-vm or teralab-api.
set -Euo pipefail

ROOT="/opt/teralab"
DATA="/var/lib/teralab"
ETC_DIR="/etc/teralab"
HELPER="/usr/local/libexec/teralab/teralab-firewall"
SERVICE_USER="teralab-api"
SERVICE_GROUP="teralab"
IMAGE_GROUP="teralab-images"

MODE_0640=$((8#640))
MODE_0750=$((8#750))
MODE_0755=$((8#755))
MODE_2770=$((8#2770))
REPORT_LIMIT=20

usage() {
	cat <<EOF
Usage: scripts/check-install-perms.sh [options]

Verify TeraLab file ownership and modes. Read-only; exits 0 when every
checked path matches the matrix or is absent.

Options:
  --root PATH     Installed tree      (default: $ROOT)
  --data PATH     Runtime state      (default: $DATA)
  --etc PATH      Configuration      (default: $ETC_DIR)
  --helper PATH   Firewall helper    (default: $HELPER)
  -h, --help      Show this help
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--root)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			ROOT="$2"
			shift 2
			;;
		--data)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			DATA="$2"
			shift 2
			;;
		--etc)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			ETC_DIR="$2"
			shift 2
			;;
		--helper)
			[ "$#" -ge 2 ] || { usage >&2; exit 2; }
			HELPER="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			usage >&2
			exit 2
			;;
	esac
done

status=0
checked=0

fail() {
	printf 'install perms: FAIL: %s\n' "$*" >&2
	status=1
}

skip() {
	printf 'install perms: skipped %s\n' "$*"
}

# stat_mode prints the numeric mode with the setuid/setgid/sticky bits, or
# fails. Bash arithmetic needs an explicit base, because "0755" is decimal 755.
# stderr is deliberately not redirected: a stat failure must be visible.
stat_mode() {
	local raw
	raw="$(stat -c '%a' -- "$1")" || return 1
	printf '%s' "$((8#$raw))"
}

stat_owner() {
	stat -c '%U' -- "$1"
}

stat_uid() {
	stat -c '%u' -- "$1"
}

stat_group() {
	stat -c '%G' -- "$1"
}

stat_gid() {
	stat -c '%g' -- "$1"
}

describe() {
	stat -c '%A %U:%G' -- "$1" || printf '<stat failed>'
}

# perm_bits converts an ACL rwx string to a numeric mask.
perm_bits() {
	local perms="$1" bits=0
	case "$perms" in *r*) bits=$((bits | 4)) ;; esac
	case "$perms" in *w*) bits=$((bits | 2)) ;; esac
	case "$perms" in *x*) bits=$((bits | 1)) ;; esac
	printf '%s' "$bits"
}

# acl_allows_write PATH PRINCIPAL reports whether a named user or one of the
# principal's groups has an effective write entry. The ACL mask is applied.
acl_allows_write() {
	local path="$1" principal="$2" acl line mask=7 name perms groups
	command -v getfacl >/dev/null 2>&1 || return 1
	acl="$(getfacl -cp -- "$path" 2>/dev/null)" || return 1
	while IFS= read -r line; do
		case "$line" in
			mask::*) mask="$(perm_bits "${line##*:}")" ;;
		esac
	done <<<"$acl"
	# Named user entry.
	while IFS= read -r line; do
		case "$line" in
			user:"$principal":*)
				perms="${line##*:}"
				(( ( $(perm_bits "$perms") & mask & 2 ) != 0 )) && return 0
				;;
		esac
	done <<<"$acl"
	# Named group entries, only for groups the principal belongs to.
	groups=" $(id -nG "$principal" 2>/dev/null) "
	while IFS= read -r line; do
		case "$line" in
			group:*)
				name="${line#group:}"
				name="${name%%:*}"
				perms="${line##*:}"
				case "$groups" in
					*" $name "*)
						(( ( $(perm_bits "$perms") & mask & 2 ) != 0 )) && return 0
						;;
				esac
				;;
		esac
	done <<<"$acl"
	return 1
}

# An install is considered present if any principal path exists. A fully
# absent host (CI, a docs checkout) skips without failing.
install_present=0
for present_path in "$ROOT" "$DATA" "$ETC_DIR"; do
	if [ -e "$present_path" ]; then
		install_present=1
	fi
done

# 1. Database, WAL and SHM must not be readable by group or other, and must be
#    owned by the API identity.
if [ -d "$DATA" ]; then
	for db_file in "$DATA/database.sdb" "$DATA/database.sdb-wal" "$DATA/database.sdb-shm"; do
		[ -e "$db_file" ] || continue
		checked=$((checked + 1))
		if ! mode="$(stat_mode "$db_file")"; then
			fail "cannot stat $db_file"
			continue
		fi
		if (( mode & 0044 )); then
			fail "$db_file is group/world readable ($(describe "$db_file"))"
		fi
		if ! owner="$(stat_owner "$db_file")"; then
			fail "cannot stat the owner of $db_file"
			continue
		fi
		if [ "$owner" != "$SERVICE_USER" ]; then
			fail "$db_file is owned by $owner, expected $SERVICE_USER"
		fi
	done
else
	skip "$DATA (no runtime data directory)"
fi

# 2. teralab-api must retain its supplementary teralab-images membership so it
#    can read and rewrite API-created image files.
if id -u "$SERVICE_USER" >/dev/null 2>&1; then
	if ! service_groups="$(id -nG "$SERVICE_USER")"; then
		fail "cannot read the groups of $SERVICE_USER"
	elif ! printf '%s\n' " $service_groups " | grep -Eq "(^| )${IMAGE_GROUP}( |$)"; then
		fail "$SERVICE_USER is not a member of $IMAGE_GROUP"
	fi
elif [ "$install_present" -eq 1 ]; then
	fail "$SERVICE_USER is missing on an installed host"
else
	skip "$SERVICE_USER (not installed)"
fi

# 3. Image store: directories 0755, non-.bin files 0640, .bin files 0750,
#    files in group teralab-images, and no group/world write. images/tmp is the
#    documented exception (2770 staging). Symlinks from adopted external images
#    are skipped by -type.
images_dir="$DATA/images"
if [ -d "$images_dir" ]; then
	if ! find "$images_dir" -type d \
		! -path "$images_dir/tmp" ! -path "$images_dir/tmp/*" -print0 >/dev/null; then
		fail "find failed while checking image directories under $images_dir"
	else
		bad_dirs=0
		while IFS= read -r -d '' path; do
			checked=$((checked + 1))
			if ! mode="$(stat_mode "$path")"; then
				fail "cannot stat image directory $path"
				continue
			fi
			if (( mode & 0022 )); then
				bad_dirs=$((bad_dirs + 1))
				if (( bad_dirs <= REPORT_LIMIT )); then
					fail "image directory is group/world writable: $path ($(describe "$path"))"
				fi
			fi
			if (( mode != MODE_0755 )); then
				bad_dirs=$((bad_dirs + 1))
				if (( bad_dirs <= REPORT_LIMIT )); then
					fail "image directory mode is $(printf '%04o' "$mode"), expected 0755: $path"
				fi
			fi
		done < <(find "$images_dir" -type d \
			! -path "$images_dir/tmp" ! -path "$images_dir/tmp/*" -print0)
		if (( bad_dirs > REPORT_LIMIT )); then
			fail "$bad_dirs image-directory violations under $images_dir (first $REPORT_LIMIT shown)"
		fi
	fi

	if ! find "$images_dir" -type f \
		! -path "$images_dir/tmp/*" -print0 >/dev/null; then
		fail "find failed while checking image files under $images_dir"
	else
		bad_files=0
		while IFS= read -r -d '' path; do
			checked=$((checked + 1))
			if ! mode="$(stat_mode "$path")"; then
				fail "cannot stat image file $path"
				continue
			fi
			if ! group="$(stat_group "$path")"; then
				fail "cannot stat the group of image file $path"
				continue
			fi
			case "$path" in
				*.bin) expected_mode=$MODE_0750 ;;
				*) expected_mode=$MODE_0640 ;;
			esac
			if (( mode & 0022 )); then
				bad_files=$((bad_files + 1))
				if (( bad_files <= REPORT_LIMIT )); then
					fail "image file is group/world writable: $path ($(describe "$path"))"
				fi
			fi
			if (( mode != expected_mode )); then
				bad_files=$((bad_files + 1))
				if (( bad_files <= REPORT_LIMIT )); then
					fail "image file mode is $(printf '%04o' "$mode"), expected $(printf '%04o' "$expected_mode"): $path"
				fi
			fi
			if [ "$group" != "$IMAGE_GROUP" ]; then
				bad_files=$((bad_files + 1))
				if (( bad_files <= REPORT_LIMIT )); then
					fail "image file group is $group, expected $IMAGE_GROUP: $path"
				fi
			fi
		done < <(find "$images_dir" -type f \
			! -path "$images_dir/tmp/*" -print0)
		if (( bad_files > REPORT_LIMIT )); then
			fail "$bad_files image-file violations under $images_dir (first $REPORT_LIMIT shown)"
		fi
	fi

	if [ -d "$images_dir/tmp" ]; then
		checked=$((checked + 1))
		if ! mode="$(stat_mode "$images_dir/tmp")"; then
			fail "cannot stat $images_dir/tmp"
		elif (( mode != MODE_2770 )); then
			fail "$images_dir/tmp mode is $(printf '%04o' "$mode"), expected 2770"
		fi
	else
		fail "$images_dir/tmp is missing"
	fi
else
	skip "$images_dir (no image store)"
fi

# 4. Installed code must be root-owned and not group/world writable. Cap the
#    per-category output so a stale user-owned install does not flood a log;
#    the summary still reports the complete count. A find failure is a hard
#    failure: it means the tree could not be inspected.
if [ -d "$ROOT" ]; then
	if ! find "$ROOT" \( -type f -o -type d \) -perm /022 -print0 >/dev/null; then
		fail "find failed while checking group/world-writable paths under $ROOT"
	else
		writable_count=0
		while IFS= read -r -d '' path; do
			checked=$((checked + 1))
			writable_count=$((writable_count + 1))
			if (( writable_count <= REPORT_LIMIT )); then
				fail "group/world writable: $path ($(describe "$path"))"
			fi
		done < <(find "$ROOT" \( -type f -o -type d \) -perm /022 -print0)
		if (( writable_count > REPORT_LIMIT )); then
			fail "$writable_count group/world-writable paths under $ROOT (first $REPORT_LIMIT shown)"
		fi
	fi

	if ! find "$ROOT" ! -user root -print0 >/dev/null; then
		fail "find failed while checking ownership under $ROOT"
	else
		nonroot_count=0
		while IFS= read -r -d '' path; do
			checked=$((checked + 1))
			nonroot_count=$((nonroot_count + 1))
			if (( nonroot_count <= REPORT_LIMIT )); then
				fail "not owned by root: $path ($(describe "$path"))"
			fi
		done < <(find "$ROOT" ! -user root -print0)
		if (( nonroot_count > REPORT_LIMIT )); then
			fail "$nonroot_count paths under $ROOT are not owned by root (first $REPORT_LIMIT shown)"
		fi
	fi
else
	skip "$ROOT (no installed tree)"
fi

# 5. The root helpers live outside the tree and must be root:root, not
#    writable by the install user or any service identity. The firewall unit
#    and the php8.4-fpm ExecStartPost hook both execute them as root.
helper_dir="$(dirname "$HELPER")"
helper_present=0
for helper_file in "$HELPER" "$helper_dir/fpm-socket-acl"; do
	if [ -e "$helper_file" ]; then
		helper_present=1
		checked=$((checked + 1))
		if ! mode="$(stat_mode "$helper_file")"; then
			fail "cannot stat root helper $helper_file"
			continue
		fi
		if ! owner_uid="$(stat_uid "$helper_file")"; then
			fail "cannot stat the owner of root helper $helper_file"
			continue
		fi
		(( mode & 0022 )) && fail "root helper is group/world writable: $helper_file"
		[ "$owner_uid" = "0" ] \
			|| fail "root helper is not owned by root: $helper_file"
	else
		skip "$helper_file (not installed)"
	fi
done
# Only protect the directory when a helper is actually installed; otherwise
# --helper in a temporary/absent location would flag that unrelated parent.
if [ "$helper_present" -eq 1 ] && [ -d "$helper_dir" ]; then
	checked=$((checked + 1))
	if ! mode="$(stat_mode "$helper_dir")"; then
		fail "cannot stat root helper directory $helper_dir"
	elif ! owner_uid="$(stat_uid "$helper_dir")"; then
		fail "cannot stat the owner of root helper directory $helper_dir"
	else
		(( mode & 0022 )) && fail "root helper directory is group/world writable: $helper_dir"
		[ "$owner_uid" = "0" ] \
			|| fail "root helper directory is not owned by root: $helper_dir"
	fi
fi

# 6. www-data must not share the teralab group or be able to read the secret.
if id -u www-data >/dev/null 2>&1; then
	if id -nG www-data | tr ' ' '\n' | grep -qx "$SERVICE_GROUP"; then
		fail "www-data is a member of the $SERVICE_GROUP group"
	fi
	secret="$ETC_DIR/agent.secret"
	if [ -e "$secret" ]; then
		checked=$((checked + 1))
		if ! mode="$(stat_mode "$secret")"; then
			fail "cannot stat $secret"
		else
			(( mode & 0004 )) && fail "$secret is world readable"
			if ! secret_gid="$(stat_gid "$secret")"; then
				fail "cannot stat the group of $secret"
			else
				secret_group="$(getent group "$secret_gid" | cut -d: -f1)"
				if (( mode & 0040 )) \
					&& id -nG www-data | tr ' ' '\n' | grep -qx "$secret_group"; then
					fail "www-data can read $secret through group $secret_group"
				fi
			fi
		fi
		if ! owner_uid="$(stat_uid "$secret")"; then
			fail "cannot stat the owner of $secret"
		elif [ "$owner_uid" = "$(id -u www-data)" ]; then
			fail "$secret is owned by www-data"
		fi
		acl_allows_write "$secret" www-data \
			&& fail "www-data has an ACL write entry on $secret"
		# Read is what matters for a secret; check that separately.
		if command -v getfacl >/dev/null 2>&1 \
			&& getfacl -cp -- "$secret" 2>/dev/null | grep -Eq '^user:www-data:.*r'; then
			fail "www-data has an ACL read entry on $secret"
		fi
	else
		skip "$secret (not created yet)"
	fi
else
	skip "www-data (user absent)"
fi

# 7. The first-run setup token, when it exists, is root:teralab 0640. The
#    group read bit lets the teralab-api PHP-FPM identity verify the setup
#    header; www-data is not in the teralab group. It is absent on hosts where
#    setup has never run and on checkouts; that is not a failure, because the
#    API stops accepting it after the first user anyway.
setup_token="$ETC_DIR/setup.token"
if [ -e "$setup_token" ]; then
	checked=$((checked + 1))
	if ! mode="$(stat_mode "$setup_token")"; then
		fail "cannot stat $setup_token"
	elif ! token_uid="$(stat_uid "$setup_token")"; then
		fail "cannot stat the owner of $setup_token"
	elif ! token_gid="$(stat_gid "$setup_token")"; then
		fail "cannot stat the group of $setup_token"
	else
		token_group="$(getent group "$token_gid" | cut -d: -f1)"
		if (( mode & 0007 )); then
			fail "$setup_token is world accessible ($(describe "$setup_token"))"
		fi
		if (( mode != MODE_0640 )); then
			fail "$setup_token mode is $(printf '%04o' "$mode"), expected 0640"
		fi
		[ "$token_uid" = "0" ] \
			|| fail "$setup_token is not owned by root: $(describe "$setup_token")"
		[ "$token_group" = "$SERVICE_GROUP" ] \
			|| fail "$setup_token group is $token_group, expected $SERVICE_GROUP"
	fi
else
	skip "$setup_token (not created yet)"
fi

# 8. The agent state directory must not be writable by an engine or the API.
state_dir="$DATA/state"
if [ -d "$state_dir" ]; then
	checked=$((checked + 1))
	if ! mode="$(stat_mode "$state_dir")"; then
		fail "cannot stat $state_dir"
	elif ! state_uid="$(stat_uid "$state_dir")"; then
		fail "cannot stat the owner of $state_dir"
	elif ! state_gid="$(stat_gid "$state_dir")"; then
		fail "cannot stat the group of $state_dir"
	else
		state_group="$(getent group "$state_gid" | cut -d: -f1)"
		(( mode & 0002 )) && fail "$state_dir is world writable"
		for principal in teralab-vm teralab-api; do
			id -u "$principal" >/dev/null 2>&1 || continue
			if (( mode & 0200 )) && [ "$state_uid" = "$(id -u "$principal")" ]; then
				fail "$state_dir is writable by $principal (owner write)"
			fi
			if (( mode & 0020 )) \
				&& id -nG "$principal" | tr ' ' '\n' | grep -qx "$state_group"; then
				fail "$state_dir is writable by $principal (group $state_group)"
			fi
			acl_allows_write "$state_dir" "$principal" \
				&& fail "$state_dir is writable by $principal (ACL)"
		done
	fi
else
	skip "$state_dir (not created yet)"
fi

if [ "$status" -eq 0 ]; then
	printf 'install perms OK (%d paths checked)\n' "$checked"
fi

exit "$status"
