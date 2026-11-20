<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Upgrading to TeraLab v1.5.2

v1.5.2 keeps `.unl` labs, the v1 API surface, and the `/var/lib/teralab`
layout. The installer is idempotent and is the supported upgrade path. This is
a patch release: no database migration and no lab-format change.

## Before upgrading

1. Stop the agent: `sudo systemctl stop teralab-agent`.
2. Back up:
   ```sh
   sudo tar czf /var/backups/teralab-$(date +%F).tgz \
       /etc/teralab /var/lib/teralab
   ```
3. Note the installed version: `cat /opt/teralab/TERALAB_VERSION`.

## Upgrade

```sh
git fetch --tags
git checkout v1.5.2
sudo packaging/teralab/install.sh
```

What is preserved:

- `/etc/teralab/agent.secret` and any `config.php`;
- `/var/lib/teralab/database.sdb` and its WAL files;
- labs under `/var/lib/teralab/labs` and images under `images`;
- run directories are recreated and reconciled by the agent.

Database migrations are forward-only and run at API startup. The images table
is added by migration `002_images.sql`; existing tables are untouched.

### Lab format migration

v1.5.1 adds portable image references (migration `007_image_references.php`),
which backfills a reference for every stored image. Labs keep importing in
their old format, but the **first save** of a lab — including the editor's
autosave — rewrites it in the new EVE-NG-compatible format (`format="2"`).
Older TeraLab releases cannot read that format, so keep the backup above
until you are sure you will not roll back. See `docs/LAB-FORMAT.md`.

### Lab bridge firewall

The installer now also enables `teralab-firewall.service`, which allows
forwarded IP traffic between TeraLab lab bridges (IPv4, plus IPv6 when
`ip6tables` is available). On a Docker host, `br_netfilter` plus a `FORWARD`
policy of `DROP` otherwise drops IP traffic between lab nodes while
ARP and CDP still pass, so labs look connected but cannot ping. Hosts installed
with `--no-systemd`, or hosts whose firewall manager reloads the `FORWARD`
chain, can apply the same rule manually:

```sh
sudo /opt/teralab/packaging/teralab/teralab-firewall
```

Until the rule is present, IP traffic between lab nodes is dropped (IPv4
always; IPv6 where the host's `FORWARD` policy is `DROP`). See
`docs/INSTALL.md` for details.

## Recovering a lab affected by v1.5.1

v1.5.1 could destroy a running lab's connectivity when a single node start
failed (including the harmless "node is already running"): the API cleared the
lab's networking while the engines kept running, so interfaces stayed `up/up`
and answered L1 keepalives while ARP, CDP and ping stopped working. v1.5.2
stops that from happening, but it does not rebuild links that were already
deleted.

To recover a lab opened under v1.5.1:

1. Stop the lab (Stop, or stop each node).
2. Start the lab again.

The start rebuilds the bridge and taps. No data is lost: engine nvram, including
configured IP addresses, lives in the run directory and survives the restart, so
node configuration does not need to be re-entered. No database migration and no
lab-format change is involved; there is nothing else to run.

## Importing a v1.1 database and labs

v1.1 stored users in `/opt/unetlab/data/database.sdb` with unsalted SHA-256
passwords. Import them once:

```sh
php /opt/teralab/packaging/teralab/import-legacy.php \
    --legacy-db /opt/unetlab/data/database.sdb \
    --legacy-labs /opt/unetlab/labs \
    --data-dir /var/lib/teralab --root /opt/teralab
```

Every imported user keeps a one-time legacy hash. On the first successful
login the password is rehashed with Argon2id and the legacy hash is cleared.
Existing users are skipped, and lab files are copied without overwriting
newer labs.

## Verify

```sh
systemctl status teralab-agent
curl -fsS http://127.0.0.1/api/v2/health
curl -fsS http://127.0.0.1/api/v2/version
```

Open an existing lab and start one node. If a legacy lab references a missing
image name, the API reports the missing file; upload the image and retry.

## Rollback

1. Stop the agent.
2. Restore the backup over `/etc/teralab` and `/var/lib/teralab`.
3. Check out the previous tag and re-run the installer.
4. Start the agent.

Forward-only migrations mean a database written by v1.2 may contain newer
tables; restoring the backup is required for a clean rollback.

## v1 compatibility

`html/api.php` loads the Slim 4 application and serves `/api/v2/*` plus the v1
routes used by legacy clients: auth, status, folders, labs, and node
start/stop/wipe. The v1 paths are deprecated and mapped onto v2 services; they
do not bypass authentication.
