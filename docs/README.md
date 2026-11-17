<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab documentation

This directory contains the engineering documentation for the TeraLab
modernization. The baseline code is UNetLab 1.0.2-2 (BSD-3-Clause); new
TeraLab material is AGPL-3.0-or-later.

## Index

| Document | Purpose |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Target components, privilege model, runtime layout |
| [DEV-VM.md](DEV-VM.md) | Nested development VM and local workflow |
| [SECURITY.md](SECURITY.md) | Threat model, controls, known legacy debt, reporting |
| [TESTING.md](TESTING.md) | Test levels, fixtures, validation IDs, commands |
| [UX.md](UX.md) | Product principles, journeys, benchmark method |
| [REFERENCE-FEATURE-MATRIX.md](REFERENCE-FEATURE-MATRIX.md) | Observed reference capabilities vs TeraLab vs target |
| [REFERENCE-OBSERVATIONS.md](REFERENCE-OBSERVATIONS.md) | Clean-room observation log |
| [TEST-IMAGES.md](TEST-IMAGES.md) | Local vendor image inventory and handling rules |
| [PHASE0-REPORT.md](PHASE0-REPORT.md) | Phase 0 results and exit status |
| [adr/](adr/) | Architecture decision records |

## Status

Phase 0 (discovery and guardrails) is complete. The next milestone is
v1.2.0, the PHP 8.4/QEMU 10 runtime foundation.

## Conventions

- Requirement IDs (`FR-*`, `NFR-*`) and validation IDs (`V-*`) follow the
  internal modernization plan.
- “Must/Should/May” follow RFC 2119 intent.
- Reference observations are behavioural only. No reference source, CSS,
  images, text, templates, lab files, or metadata are copied into this
  repository.
- New documentation files must begin with the AGPL SPDX header.
