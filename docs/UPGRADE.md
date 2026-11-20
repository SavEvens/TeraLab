<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Upgrading to TeraLab v1.4.6

v1.4.6 keeps `.unl` labs, the v1 API surface, and the `/var/lib/teralab`
layout. The installer is idempotent and is the supported upgrade path.

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
git checkout v1.4.6
sudo packaging/teralab/install.sh
```

What is preserved:

- `/etc/teralab/agent.secret` and any `config.php`;
- `/var/lib/teralab/database.sdb` and its WAL files;
- labs under `/var/lib/teralab/labs` and images under `images`;
- run directories are recreated and reconciled by the agent.

Database migrations are forward-only and run at API startup. The images table
is added by migration `002_images.sql`; existing tables are untouched.

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
routes used by the legacy UI: auth, status, folders, labs, and node
start/stop/wipe. The v1 paths are deprecated and mapped onto v2 services; they
do not bypass authentication.
