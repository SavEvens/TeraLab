<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# teralab-dynamips packaging

Dynamips is GPLv2 software and remains a separate package and process. TeraLab
does not link it into the AGPLv3 code base and does not ship IOS images.

## Build

```sh
sudo apt-get install cmake make gcc libelf-dev libpcap-dev
packaging/dynamips/build.sh --out dist
sudo dpkg -i dist/teralab-dynamips_0.2.23-1_amd64.deb
```

The script downloads the pinned upstream tarball, verifies its SHA-256, builds
the stable amd64 binary, and creates a `.deb` containing the binary plus the
GPLv2 notice and the exact source location. The tarball is never committed.

## Runtime

The agent looks for `/usr/bin/dynamips` (override with `TERALAB_DYNAMIPS`).
Availability is reported by `GET /v1/engines` and `GET /api/v2/engines`. A node
uses `engine: "dynamips"` with a user-supplied `.bin` IOS image; the agent runs
it as `teralab-vm` with a loopback console.

CI builds this package on every change. A live IOS boot requires a
user-supplied image and is not part of CI or the repository.

## Licence

- Dynamips: GPLv2 (separate work).
- Build script and documentation: AGPL-3.0-or-later.
- GPLv2 text: `LICENSES/GPL-2.0.txt`.
