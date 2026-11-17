<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# ADR-0005: Lab JSON v2 with `.unl` compatibility

- Status: Accepted
- Date: 2026-11-17
- Deciders: TeraLab owner, engineering

## Context

UNetLab stores labs as `.unl` XML. The format is widely used by existing
users and is the only interchange format we must preserve. It is less
convenient for a modern SPA: XML parsing, partial updates, and unknown-field
preservation are awkward.

## Decision

Use a canonical `lab.json` schema v2 internally, with `.unl` as an import/
export format.

- `.unl` importer maps known elements/attributes to v2, preserves unknown XML
  in an `extra` map, and validates names, references, port limits, and paths.
- `.unl` exporter writes standard XML and does not silently add vendor
  extensions.
- Lab saves are atomic (write temp, fsync, rename) and carry a revision for
  optimistic concurrency.
- The internal schema is versioned independently of the product version.
- A v1 API adapter reads the same v2 services.

## Consequences

Positive:
- Clean, typed representation for the SPA and agent.
- `.unl` compatibility for existing users and migration.
- Unknown vendor fields survive round-trips.
- Revision/ETag support prevents silent overwrites.

Negative:
- Two representations to test and maintain.
- Some XML constructs may not map perfectly; these must be documented.
- Existing labs are converted on first save; a backup copy is kept.

## Alternatives considered

- **Keep XML internally:** preserves one format, but complicates the SPA and
  validation.
- **Convert to YAML:** human-friendly, but weaker for large graphs and less
  common in this domain.
- **Binary/protobuf:** unnecessary complexity for single-host labs.
