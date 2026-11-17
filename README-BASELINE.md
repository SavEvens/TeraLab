# TeraLab v1.1.0 baseline

This repository is the official TeraLab line derived from UNetLab 1.0.2-2
(commit `8ef51974b448318697e5dd18a4e10496f2f56b39`).

## What this bootstrap did

- Installed modern Debian-family dependencies: Apache, PHP, MariaDB, QEMU,
  build tools, and the QEMU/KVM runtime libraries.
- Cloned UNetLab and pinned the 1.0.2-2 commit.
- Created branch `main` and commit/tag `v1.1.0`.
- Added AGPLv3 project licensing, upstream BSD-3 text, NOTICE, PROVENANCE,
  AGENTS.md, and this baseline manifest.

## What it deliberately did NOT do

- It did **not** install, configure, or start UNetLab.
- No Apache vhost, database schema, UNetLab wrappers, engine binaries, or lab
  data were created.
- No host bridge, firewall, or network configuration was changed.
- No third-party apt repository was added.

## Known next milestone

UNetLab 1.0.2-2 is a 2016 PHP-era application. Modern Debian ships PHP 8.x.
The first development milestone is a compatibility port for:

- PHP 8 (removed `mysql_*` APIs, `each()`, old constructors, warnings)
- QEMU path/wrapper compatibility (`/opt/qemu` vs `/usr/bin`)
- dependency and template schema updates

The repository is intentionally a **baseline**, not yet a running UNetLab UI.

## Licence

- TeraLab original code: AGPL-3.0-or-later (see `LICENSE`).
- UNetLab-derived files: BSD-3-Clause (see `LICENSES/BSD-3-Clause.txt`).
- Engine components: separate GPLv2 packages (see `NOTICE`).

## Verify

```sh
./scripts/teralab-project-init.sh --check
```
