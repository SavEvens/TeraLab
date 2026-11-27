<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Upgrading to TeraLab v1.5.8

v1.5.8 is a patch release that changes the user interface only. It keeps `.unl`
labs, the v1 and v2 API surface, and the `/var/lib/teralab` layout unchanged.
There is no database migration, no lab-format change and no server-side change
beyond the version string. The installer is idempotent and is the supported
upgrade path; the steps below are the same as for v1.5.7.

Changes you will notice in the editor and the Images page:

- A node added from a template with mapped images now gets an image prefilled:
  the only mapping, or the first by display name when several are mapped.
  Text and picture nodes still get no image, and templates without a mapping
  still start empty.
- The Inspector image selector offers only the node template's mapped images
  when the template has at least one. A current value that is no longer mapped
  stays visible as `⚠ <reference> (not mapped to this template)`. The ISO
  selector is unchanged.
- Images → **Rename** is one dialog for both the display name and the reference,
  with inline validation and the duplicate-reference 409 shown as a toast. The
  two prompt-based flows are gone. Existing node image values are not rewritten
  by the upgrade.

## Upgrading a .deb install

If TeraLab was installed from the Debian package, upgrade with the new
artifact:

```sh
sudo apt-get install ./teralab_<new-version>_amd64.deb
# or, after installing any new dependencies:
sudo dpkg -i teralab_<new-version>_amd64.deb
```

The package upgrades in place:

- `/etc/teralab/agent.secret`, `/etc/teralab/config.php` and
  `/var/lib/teralab` (database, labs, images, run directories and logs) are
  not replaced or deleted.
- The agent is restarted so it runs the new binary; the agent is version-locked
  to the API, so the restart is required. On startup the agent reconciles its
  state: **running labs are stopped** (non-docker engines are killed and their
  run directories removed), open consoles end, and node control resumes when
  the agent is back. Stop your labs before upgrading.
- The lab bridge firewall unit is started only when it is inactive; it is not
  restarted. PHP-FPM is restarted and Apache reloaded only when they are
  active.
- Database migrations are forward-only and run on the first API request after
  the upgrade. No manual step is needed and `/api/v2/setup` does not run
  again.

### Conffile prompts

The Apache configuration, the three PHP upload-limit drop-ins, both systemd
units, the logrotate configuration and `/usr/share/doc/teralab/copyright` are
dpkg conffiles. dpkg asks only when you edited one of them:

- **Keep the local version** if the edit is intentional (custom Apache
  aliases, unit hardening). Re-check it afterwards against the packaged
  template under `/opt/teralab/packaging/teralab/`; the upgrade does not apply
  the package version over your file.
- **Take the package version** if you did not edit the file, so fixes are
  delivered.

The Apache configuration and both unit files must keep pointing at the install
root (`/opt/teralab`). Do not accept a packaged conffile that changes that root
unless you deliberately moved the install tree.

For source installs, continue with the steps below.

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
git checkout v1.5.8
sudo packaging/teralab/install.sh
```

What is preserved:

- `/etc/teralab/agent.secret` and any `config.php`;
- `/var/lib/teralab/database.sdb` and its WAL files;
- labs under `/var/lib/teralab/labs` and images under `images`;
- run directories are recreated and reconciled by the agent.

Database migrations are forward-only and run at API startup. The images table
is added by migration `002_images.sql`; `008_external_images.sql` adds the
`external` column used by linked images. Existing tables and rows are
otherwise untouched.

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

## IOL embedded configuration in v1.5.6

- **L2 VLANs are applied on boot.** IOS stores VLANs in the VLAN database
  (`vlan.dat`) and, in the default VTP server mode, ignores `vlan` lines in a
  startup configuration. For the L2 image the agent inserts
  `vtp mode transparent` before the VLAN definitions, so they become part of
  the startup config and are created.
- **IPv6-only interfaces are brought up.** IOS does not restore `no shutdown`
  for an interface with an IPv6 address and no IPv4 address. The agent replays
  `no shutdown` on those interfaces after boot, over the console. An explicit
  `shutdown` is never overridden, and a configuration with an `enable` password
  skips the replay with a log line; a timeout is a warning, not a start
  failure.
- **Editing a saved config still needs a wipe.** IOL applies the embedded
  configuration on first boot; NVRAM wins afterwards.

## QEMU parameters in v1.5.6

QEMU parameters now come from the node override, then the template `options`,
then the agent's fallback. Templates may set `qemu_nic`, `qemu_machine`,
`qemu_arch`, `qemu_cpu`, `qemu_accel` and `qemu_disk_interface`; a node can
override each one. The legacy `qemu_options` string is still parsed, with known
flags filling only empty structured fields and the remaining arguments
validated against an allow-list.

- The Catalyst 8000V template uses `vmxnet3`; `e1000` is not a supported C8000V
  vNIC. `virtio` maps to `virtio-net-pci`, and PAN-OS keeps `e1000`.
- With nothing configured, `e1000` remains the fallback, so existing guests keep
  their model.
- Parameters live on the template and the node spec, not in the `.unl` file.
  No lab re-save is needed for this change.

## EtherChannel and LACP on the Linux bridge

The Linux bridge now forwards LACP per port: the agent writes
`/sys/class/net/<bridge>/brif/<tap>/group_fwd_mask` with `0x5` (STP + LACP) by
default. The bridge-level mask cannot allow LACP. This requires Linux ≥ 4.15.

Set `TERALAB_BRIDGE_GROUP_FWD_MASK` to another 16-bit value, or to `0` to
disable the per-port write and use static `channel-group … mode on` instead
(OVS remains supported). A malformed value is rejected when the agent loads its
configuration; a kernel without the attribute logs a warning and the lab still
starts.

## Topology pruning in v1.5.6

A network is a cable/segment and must have at least two attached interfaces.
Deleting a node now detaches the peer, removes the network and clears dangling
`network_id` values; the editor shows this immediately, and `create`, `save`
and `import` enforce the same rule as the authority. A segment shared by three
or more nodes keeps its remaining members. The `.unl` format is unchanged
(detaching is written as `network_id="0"`).

Existing labs are cleaned on their next save or import — there is no bulk sweep
to run. `GET /api/v2/labs/{id}/nodes` also gains the additive `ready` field,
true once the console can be driven.

A v1.5.6 defect in run-directory reclaim is fixed in v1.5.7: a lab whose nodes
had all stopped still pinned its run directory, so reconcile did not reclaim it
after the lab was deleted — see the v1.5.7 release notes.

## Editor and console changes in v1.5.5

The editor loses the Align, Validate and Save buttons. Saving is automatic:
autosave runs 1 s after the last change, Ctrl+S still saves, the save-state
indicator and the conflict dialog still work, and start/stop/wipe still save
first. The removed validation checked node names, images and port counts on
demand; import warnings and node-start errors still report problems, and
passive validation on save is a follow-up.

Other changes you will notice:

- **+ Add link** is in the top bar next to **+ Add node** and is always
  enabled; the link dialog validates the selection.
- The template picker adds 1–20 copies of a template in one action; one undo
  step removes the whole batch.
- The console dock can be dragged by its top edge, keeps its height per browser
  and user, and resets with a double-click (Up/Down also adjust it). Nothing is
  stored server-side.
- The telnet console sends no resize frames any more. A custom client that
  sends `{"type":"resize","cols":…,"rows":…}` is still honoured: the agent
  applies the frame to the node's PTY instead of printing it into the device
  console, and every other frame passes through byte-for-byte. The VNC and TCP
  consoles are unchanged.
- The System page is operational only: no version badge and no raw host JSON;
  grey means absent/idle, red means a real error, and a CPU usage card shows a
  measured percentage. The version stays on **About** and in the header badge;
  the System page no longer duplicates it. About now lists components as
  name + licence with no status colours; the AGPL notices there are unchanged.

No account, permission or lab setting is affected by any of these changes.

## Adopting existing `/opt/unetlab/addons` images

On a host migrating from UNetLab, EVE-NG or PNetLab, the images usually
already exist under `/opt/unetlab/addons` and can be tens or hundreds of GiB.
TeraLab can adopt them **in place**: no file is copied or re-uploaded.

### What linked adoption does

For every selected image, TeraLab creates a symlink inside the official store,
`/var/lib/teralab/images/<kind>/<id>/<basename>`, pointing at the existing
addon file. The database row points at that store path and is marked
`external = 1`. The legacy tree is never modified. Linked images consume no
store space, so they are excluded from the store quota sum.

The agent accepts the resolved link target only because
`/opt/unetlab/addons` is on the approved extra-root list (see
`TERALAB_IMAGE_EXTRA_ROOTS` below). A link aiming outside the store and the
approved roots is still rejected.

### UI: Images → Adopt images…

An administrator opens **Images → Adopt images…**, reviews the dry-run table
(file, kind, size, proposed reference and template), adjusts template choices
if needed, and confirms. The dry run reports each file as new, existing,
duplicate, unreadable or skipped; import results are shown per file and the
list refreshes with a "linked" badge. The endpoint is admin-only.

### CLI: `import-legacy.php --legacy-addons`

```sh
sudo php /opt/teralab/packaging/teralab/import-legacy.php \
    --legacy-addons=/opt/unetlab/addons
```

The option defaults to `/opt/unetlab/addons`. When `--legacy-addons` is given,
`--legacy-db` is optional. The CLI runs as root, so files that `www-data`
cannot read still import; it prints a per-file summary and has no runtime
limit.

### Permissions

The API and UI path runs as `www-data`, which must be able to read the addons
tree. The dry run flags unreadable files and does not import them. If the tree
is not world-readable, either grant read access to `www-data` or use the CLI
path above, which runs as root.

### Licence files are never registered

`iourc` and any file whose name looks like a licence (`licence`/`license`) are
always skipped. Only image files are recorded; no vendor licence material is
copied into the store or the database.

### Removing a linked image

Deleting a linked image (the "Unlink" action, or
`DELETE /api/v2/images/{id}`) removes the database row and the store-side
symlink. The legacy file under `/opt/unetlab/addons` is never deleted.

### `TERALAB_IMAGE_EXTRA_ROOTS`

The agent reads the colon-separated `TERALAB_IMAGE_EXTRA_ROOTS` environment
variable (systemd default: `/opt/unetlab/addons`). Each entry must be an
absolute path; relative paths are rejected at startup. Extra roots only widen
which resolved targets are accepted — they are never scanned automatically,
and a root that does not exist is inert. If the addons tree lives somewhere
other than `/opt/unetlab/addons`, add its path to this variable and restart
the agent.

### Warning: `/opt/unetlab` may be TeraLab's own symlink

Check the path before creating or relying on a tree there:

```sh
readlink -f /opt/unetlab
```

On a TeraLab host, `/opt/unetlab` is normally a symlink to `/opt/teralab`
(created by the installer and `scripts/setup-runtime-paths.sh`). In that case
`/opt/unetlab/addons` lives **inside the install tree**, and the installer's
`rsync --delete` will replace it on the next upgrade. The intended migration
case is a host with a real `/opt/unetlab` directory, where the addons tree is
outside the install tree. If the addons tree is elsewhere and that is not the
configured default, add its path to `TERALAB_IMAGE_EXTRA_ROOTS`.

### Dangling links

If the addons tree is moved or removed, affected nodes fail to start with a
clear "file does not exist" error rather than booting the wrong disk. Unlink
the affected image and adopt it again from the new location. Note that
re-adoption of a dangling row currently reports `duplicate` until the stale row
is unlinked, so unlink first.

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
image name, the API reports the missing file; upload the image or adopt it from
an addons tree (see above) and retry.

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
