<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# ADR-0004: YAML node templates

- Status: Accepted
- Date: 2026-11-17
- Deciders: TeraLab owner, engineering

## Context

UNetLab node templates are PHP files that assign to a `$p` array. They are
executed as code, five are syntactically broken in the baseline, and they are
hard to validate or expose in a UI. The reference deployment shows a device
catalogue with defaults and limits.

## Decision

Define built-in and custom templates in YAML with a versioned schema:

```yaml
schema: 1
id: cisco-vios
name: Cisco vIOS
kind: qemu
icon: Router.png
defaults: {cpu: 1, ram: 512, ethernet: 4, console: telnet}
qemu:
  arch: x86_64
  machine: pc
  nic: virtio-net-pci
  options: ["-no-user-config", "-nodefaults", "-rtc base=utc"]
image: {pattern: "vios-*", required_files: ["*.qcow2"]}
limits: {max_ethernet: 16, max_ram: 8192}
```

Built-in templates live under `/opt/teralab/share/templates` and are
read-only. Custom templates live under `/etc/teralab/templates` and are
editable by admin/editor. The loader validates the schema, numeric ranges,
NIC/option allowlists, and image path patterns. Unknown keys are rejected or
kept in `extra` with a warning.

## Consequences

Positive:
- Templates become data, not executable PHP.
- One schema powers the UI forms, API validation, and the agent.
- Easy to import/export and to test with fixtures.
- Avoids executing template code in the web process.

Negative:
- A migration script must convert the 53 PHP templates.
- Some vendor-specific quirks may need explicit `compat` fields.
- YAML parsing needs a maintained library and strict schema validation.

## Alternatives considered

- **Keep PHP templates:** code execution, poor validation, broken files.
- **JSON templates:** safe, but comments and multi-line options are less
  pleasant for contributors.
- **Database-only templates:** harder to review and version in git.
