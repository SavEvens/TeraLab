<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Authoring labs in YAML

TeraLab can compile a one-file YAML lab description into a normal lab. YAML is
an authoring layer only: **`.unl` remains the only stored format**. The compiled
lab is written through the ordinary create/save pipeline, so the editor,
snapshots, export, trash and EVE-NG interoperability behave exactly as they do
for a canvas-built lab.

YAML can reach a lab in four ways:

- **Labs → Import** — choose or drag a `.yaml`/`.yml` file (the same button
  accepts `.unl`).
- **Create project → From YAML** — paste YAML, validate it, optionally choose a
  folder, and create the lab.
- **`POST /api/v2/labs/import`** — multipart upload with a `.yaml`/`.yml` file.
- **`POST /api/v2/labs/{id}/apply`** — replace an existing stopped lab's
  topology from YAML (see [Apply and staleness](#apply-and-staleness)).

Two complete examples are published in
[`docs/examples/`](examples/): an IOL OSPF triangle and a mixed IOL/VPCS lab.
Both are generic labs with no vendor images and no lab-specific content. They
document the file format; adapt interface names, image references, and
addresses to your own images and topology before starting them.

## Quick start

```yaml
schema: 1
name: OSPF triangle
author: TeraLab contributors
description: |
  Three IOL routers in OSPF area 0, one segment per router pair.
tags: [ospf, ccna]

layout:
  mode: tree            # grid | tree
  root: R1              # tree mode: BFS root node
  spacing: 240          # px between placed items

defaults:               # applied to every node unless overridden
  template: iol
  image: x86_64_crb_linux-adventerprisek9-ms.bin
  ram: 512
  ports: 4              # interface count, not IOL adapters

networks:               # ids 1..N in declaration order
  lan-a: {}
  lan-b:
    name: Branch LAN    # optional display name
    visibility: 0       # hidden in the editor
  mgmt: {}

links:                  # p2p: one auto-created network per entry
  - [R1.e0/0, R2.e0/0]
  - endpoints: [R2.e0/1, R3.e0/0]
    name: r2-r3         # optional name for the auto network

nodes:                  # ids 1..N in declaration order
  R1:
    ram: 768
    position: {left: 480, top: 160}
    config: |           # startup configs are inline, always
      hostname R1
      interface Ethernet0/1
       ip address 10.0.12.1 255.255.255.252
      router ospf 1
       network 10.0.12.0 0.0.0.3 area 0
    links:              # interface name -> network key
      e0/1: lan-a
      e0/2: lan-b
  R2:
    config: |
      hostname R2
      interface Ethernet0/1
       ip address 10.0.13.1 255.255.255.252
    links:
      e0/2: lan-a
      e0/3: mgmt
  R3:
    links:
      e0/1: lan-b
      e0/2: mgmt
```

Save this as `ospf-triangle.yaml` and import it. The lab name comes from the
YAML, not from the file name; the file name is kept only as provenance. The
same content is published as
[`docs/examples/ospf-triangle.yaml`](examples/ospf-triangle.yaml).

A minimal lab is also valid:

```yaml
schema: 1
name: One PC
defaults:
  template: vpcs
nodes:
  PC1: {}
```

## Document structure

One YAML document describes one lab. Node and network **keys are the
identity**; numeric ids are assigned by the compiler in declaration order.
The schema is strict: unknown fields are rejected rather than ignored.

### Top-level fields

| Field | Required | Meaning |
|---|---|---|
| `schema` | yes | Must be the integer `1`. |
| `name` | yes | Lab name. Trimmed; at most 128 bytes; no `/`, `\`, or control characters. |
| `author` | no | Author shown on the lab. Used on import/apply when present (see [Author](#author)). |
| `description` | no | Lab description text. |
| `body` | no | Lab body text shown in the editor's notes area. |
| `tags` | no | List of strings. Stored tags are trimmed; at most 20 tags of 32 bytes each are kept. |
| `layout` | no | Auto-layout options: `mode`, `root`, `spacing`. |
| `defaults` | no | Defaults applied to every node unless the node overrides them. |
| `networks` | no | Mapping of network key → network options. |
| `links` | no | Point-to-point link list; each entry creates its own network. |
| `nodes` | yes | Mapping of node key → node body. At least one node is required. |

Node keys and network keys must be non-empty text of at most 64 bytes, without
leading or trailing whitespace and without control characters. A node key is
also the node's display name. Keep keys simple; they appear verbatim in the
editor.

### Node fields

| Field | Required | Meaning |
|---|---|---|
| `template` | yes* | Template id (for example `iol`, `vpcs`, `docker`). Required on the node **or** in `defaults`. The template's `kind` becomes the node type; node type is never hand-authored. |
| `image` | no | Image-store reference by name. For a `kind: docker` template this is a container reference and is stored verbatim. An unknown image-store reference (non-docker) is a warning, not an error. |
| `cpu` | no | Virtual CPUs. Clamped to at least 1. |
| `ram` | no | RAM in MiB. Clamped to at least 64. |
| `ports` | no | Number of interfaces (0–16). This is an interface count, never an IOL adapter count. |
| `console` | no | Console kind, normally `telnet` or `vnc`. Defaults to `telnet`. |
| `serial` | no | Serial port count; clamped to 0–8. |
| `nvram` | no | NVRAM size; clamped to 0–4096. |
| `delay` | no | Start delay; clamped to 0–3600. |
| `icon` | no | Icon file name. Falls back to the template's icon. |
| `position` | no | `{left, top}` in pixels. Each missing axis is filled by auto-layout; negative values become 0. |
| `config` | no | Inline startup config text (see [Configs](#configs)). |
| `links` | no | Mapping of interface name → declared network key. |

### `defaults` fields

`defaults` accepts `template`, `image`, `cpu`, `ram`, `ports`, `console`,
`serial`, `nvram`, `delay`, and `icon`. It does not accept `position`, `config`,
or `links`; those are per-node only.

Precedence for each value is:

1. the node field,
2. the document's `defaults`,
3. the template's own `defaults` (where the template defines it),
4. the compiler's built-in default.

`template` and `image` use only the first two levels. `nvram` and `delay` are
not read from template defaults. The built-in defaults are CPU 1, RAM 512,
serial 0, nvram 0, delay 0, console `telnet`, and no image. `ports` falls back
to the template's `defaults.ethernet`, or 0 when the template does not define
one.

### Network fields

```yaml
networks:
  lan-a: {}                 # key is the identity and the default display name
  lan-b:
    name: Branch LAN        # optional display name
    type: bridge            # default; non-bridge types only warn (see Warnings)
    visibility: 0           # 0 hides the network in the editor; 1 shows it
    icon: lan.png           # optional icon
    position: {left: 300, top: 120}
```

| Field | Required | Meaning |
|---|---|---|
| `name` | no | Display name; defaults to the network key. |
| `type` | no | Network type; defaults to `bridge`. TeraLab currently runs every network as an isolated Linux bridge, so other types produce `network_unsupported`. |
| `visibility` | no | `0` or `1` (booleans are accepted). `0` hides the network in the editor. |
| `icon` | no | Icon file name. |
| `position` | no | `{left, top}`; missing axes are placed at the centroid of the member nodes. |

Declared networks receive ids `1..N` in declaration order. Point-to-point
`links` entries then receive the next ids, in link-list order.

### Point-to-point `links`

The top-level `links` list is sugar for "create a network, attach exactly two
interfaces". Both forms are accepted:

```yaml
links:
  - [R1.e0/0, R2.e0/0]                  # two endpoints
  - endpoints: [R2.e0/1, R3.e0/0]       # mapping form
    name: r2-r3                         # optional network name
```

Each entry must have exactly two endpoints written as `node.interface`. The
generated network is named `link-1`, `link-2`, … after the entry's position in
the list, unless `name` is given. Endpoints must reference declared nodes and
must not repeat an interface already used by another link or by the node's own
`links` map. Self-links are rejected.

### `layout`

| Field | Required | Meaning |
|---|---|---|
| `mode` | no | `tree` (default) or `grid`. |
| `root` | no | Tree mode: node key to start the breadth-first walk from. Must be a declared node. Defaults to the first declared node. |
| `spacing` | no | Integer 1–10000, in pixels; default 240. |

Auto-layout only fills **missing** coordinates. An authored `left` or `top`
always wins, even if only one axis is given. In `grid` mode nodes are placed
row-major, four per row, starting at 80 px. In `tree` mode the link graph is
walked breadth-first from `root`; each level becomes a column and nodes within
a level are ordered by declaration order. Nodes the walk cannot reach form a
tail row below the last level. Every network without an authored position is
placed at the centroid of its member nodes.

Layout is deterministic: the same document and link graph always produce the
same coordinates. After import the positions are ordinary lab coordinates, so
the editor owns them like any other lab.

## Templates, images, and node types

- The compiler resolves `template` through the normal template repository:
  custom templates take precedence over built-in templates of the same id.
- A node without a template on the node or in `defaults` is an error. An
  unknown template id is an error that names the node.
- The node `type` is the template's `kind` (`qemu`, `vpcs`, `iol`, `dynamips`,
  or `docker`). You cannot choose a type that differs from the template.
- Template defaults (CPU, RAM, interface count, console, serial, icon) are
  merged before the YAML defaults and node values, as described above.
- An unknown image-store reference is not an error: the lab imports with an
  `image_unmapped` warning, and you can map the image later in the Inspector
  or on **Appliances → Images**. This matches `.unl` import.
- For a `kind: docker` node, `image` is a container reference and bypasses the
  image store. Omit it to use the container reference from the template.

### Configs

`config` is inline text only. There is no `config_file`, include, or multi-file
upload in this release. Use a YAML block scalar (`|`) for multi-line configs.
The text must be UTF-8, may be empty, and may not exceed 1 MB. Configs are
compiled into the lab's normal base64 config storage; the `.unl` format is not
changed.

IOL applies an embedded startup config on the node's first boot; after that the
configuration saved on the device wins. Edit the YAML config and **wipe** the
node before starting it again to re-apply the embedded config. See
[`LAB-FORMAT.md`](LAB-FORMAT.md#what-the-engines-apply-at-boot).

## Interface addressing

Node links use the same interface grammar as `.unl` import:

| Form | Example | Port index |
|---|---|---|
| Slash form | `e0/0`, `ethernet1/2` | `slot * 4 + port` |
| Prefixed index | `eth0`, `e3`, `ethernet12` | the number |
| Plain integer | `0`, `12` | the number |

The slash form reflects IOL's four-port adapter layout. `e0/0` is index 0,
`e0/3` is index 3, and `e1/0` is index 4 — the first interface on the second
adapter. Parsing is positional (`slot * 4 + port`), so `e0/4` also resolves to
index 4; the writer then normalizes the stored name to `e1/0`. The writer
materializes names in the same order:

- IOL: `e0/0`, `e0/1`, `e0/2`, `e0/3`, `e1/0`, …
- VPCS: `eth0`, `eth1`, …
- Other engines: `e0`, `e1`, …

The materialized interface list runs from 0 to `max(ports, highest linked
index + 1) - 1`. For example, `ports: 1` with a link on `e0/3` produces four
interfaces, because the linked index forces the list to grow. Interfaces that
are not linked remain present and unconnected (`network_id="0"` in the `.unl`).

Limits and rules:

- At most 16 interfaces per node. Index 15 is the highest allowed, which the
  canonical IOL naming calls `e3/3`; `e4/0` (index 16) is rejected.
- Ports are interfaces, not adapters. Do not pre-divide by four for IOL; write
  the interface count you want.
- Different spellings that resolve to the same index (for example `e0` and
  `eth0`, or `e0/1` and `1`) cannot both be linked on the same node; that is a
  "linked more than once" error.
- An unparseable interface name is an error naming the node and interface.
- Serial interfaces are not supported by this format.

## Networks and links: the link invariant

After compilation, the normal lab normalization applies the same link
invariant as create/save/import: **a network with fewer than two attached
interfaces is removed**, and any interface that referenced it becomes
unconnected. A declared network therefore needs at least two attached
interfaces to survive; use a point-to-point `links` entry for a guaranteed
two-endpoint network. This pruning happens at import/apply time. The validate
endpoint's `counts` describe the compiled document before pruning.

A network can be referenced from any number of node interfaces, subject to the
64-network and 16-port caps. A node interface can be attached to only one
network. A lab of unlinked nodes is legal (it is what the editor can produce),
but a network without two attachments will not be stored.

## Caps and clamps

| Limit | Value | Behaviour |
|---|---|---|
| YAML document (input) | 10 MB | Hard error above the cap. |
| Compiled `.unl` (import/apply) | 10 MB | Hard error before writing; separate from the YAML input cap. |
| Nodes | 64 | Hard error. |
| Declared networks | 64 | Hard error. |
| Declared + generated networks | 64 | Hard error. |
| Interfaces per node | 16 | Hard error for `ports` or a linked index above the limit. |
| Inline config | 1 MB | Hard error; must be UTF-8. |
| Node/network key | 64 bytes | Hard error. |
| Lab name | 128 bytes | Hard error. |
| `cpu` | ≥ 1 | Clamped. |
| `ram` | ≥ 64 | Clamped. |
| `serial` | 0–8 | Clamped. |
| `nvram` | 0–4096 | Clamped. |
| `delay` | 0–3600 | Clamped. |
| `position.left` / `position.top` | ≥ 0 | Negative values become 0. |

The two 10 MB caps are independent. The input cap counts the YAML bytes you
upload or paste. The compiled lab is XML containing base64 configs, topology
metadata, and the embedded YAML source, so it can be larger than the input,
especially with several large inline configs. A YAML under 10 MB that compiles
to a `.unl` over 10 MB is rejected with `422` before anything is written:
import creates no lab, and apply leaves the existing lab file and revision
unchanged. Validate remains a no-write compile check; the compiled-size cap is
enforced by the import and apply paths before they write.

## Errors

A compile error stops the whole document at the first problem. `POST
/api/v2/labs/validate` reports it in `data.errors` with HTTP 200; import and
apply return HTTP 422 with the same message. Messages name the offending node,
network, link, or port wherever one is involved.

| Situation | Example message |
|---|---|
| Empty document | `The YAML document is empty.` |
| Document over 10 MB | `The YAML document is larger than 10 MB.` |
| YAML syntax error | `Invalid YAML at line 12: …` or `Invalid YAML: …` |
| Document is not a mapping | `The YAML document must be a mapping.` |
| Unknown top-level field | `Unknown top-level field "mystery".` |
| Wrong/missing schema | `The YAML document must declare schema: 1.` |
| Missing/invalid lab name | `Lab name is required.` / `Invalid lab name.` |
| Wrong field type | `Author must be text.` / `tags must be a list of strings.` |
| Bad layout | `layout.mode must be "grid" or "tree".` / `layout.spacing must be an integer between 1 and 10000.` |
| Unknown layout root | `The layout root "Z" is not a declared node.` |
| Bad `defaults` | `defaults.ram must be an integer.` / `defaults: unknown field "x".` |
| Bad network block | `networks must be a mapping of network keys.` / `Network "lan": visibility must be 0 or 1.` |
| Too many declared networks | `At most 64 declared networks are allowed.` |
| Too many networks in total | `At most 64 networks are allowed (declared networks plus generated link networks).` |
| Missing `nodes` | `nodes is required.` / `nodes must be a mapping of node keys.` |
| Too many nodes | `At most 64 nodes are allowed.` |
| Bad node key | `Node keys must be non-empty text without control characters.` / `Duplicate node key "A".` |
| Unknown node field | `Node "R1": unknown field "bogus".` |
| Missing template | `Node "R1": template is required (set "template" on the node or in "defaults").` |
| Unknown template | `Node "R1": template "does-not-exist" was not found.` |
| Bad port count | `Node "A": ports must be 0 or more.` / `Node "A": at most 16 ports are allowed.` |
| Bad config | `Node "A": config is larger than 1 MB.` / `Node "A": config must be UTF-8 text.` |
| Malformed node links | `Node "A": links must map interface names to network keys.` |
| Unknown network reference | `Node "A": network "missing" is not declared.` |
| Unparseable interface | `Node "A": interface "foo" cannot be parsed.` |
| Interface over the port cap | `Node "A": interface "e4/0" exceeds the 16-port limit.` |
| Interface linked twice | `Node "A": interface "e0" is linked more than once.` |
| Bad p2p list length | `Link 1: a point-to-point link needs exactly two endpoints.` |
| Bad p2p endpoint string | `Link 1: endpoint "R1" must be written as "node.interface".` |
| Unknown p2p node | `Link 1: node "Z" is not declared.` |
| Self-link | `Link 1: a node cannot link to itself.` |

HTTP status summary:

| Endpoint | Compile error | Other errors |
|---|---|---|
| `POST /api/v2/labs/validate` | `200` with `valid: false` | `422` for a missing/non-string `yaml` or bad `filename` |
| `POST /api/v2/labs/import` | `422` | `413` body too large; `422` empty/oversized upload or compiled `.unl` over 10 MB; `403` role/CSRF |
| `POST /api/v2/labs/{id}/apply` | `422` | `409` running or stale revision; `422` compiled `.unl` over 10 MB; `404` unknown lab; `403` no access, role, or CSRF |

## Warnings

Warnings never block import or apply. YAML uses the same warning objects as
`.unl` import: each has `type`, `message`, and, where relevant, `node`,
`network`, or `value`.

| Type | Meaning |
|---|---|
| `image_unmapped` | An image reference is not in the image store yet. Map it on **Appliances → Images** or in the Inspector; the node will not start until it is mapped. |
| `image_missing` | A docker node has no container reference in the node or template; it will not start. Normal docker templates require a reference, so this is a defensive/shared case. |
| `network_unsupported` | The network uses a non-bridge `type`; TeraLab runs it as an isolated bridge and never changes host networking. |
| `node_unsupported` | The node type has no engine. Every template kind in current releases has an engine, so a template-derived YAML lab does not normally produce this; it is shared with `.unl` import for forward compatibility. |
| `run_state_reset` | Apply changed the node order or names so a surviving id would map to a different node; all previous node run directories were wiped to prevent cross-inheritance. |

Example warning:

```json
{
  "type": "image_unmapped",
  "node": "R1",
  "value": "missing-image.bin",
  "message": "image \"missing-image.bin\" is not mapped to a stored image."
}
```

## Validate without writing

`POST /api/v2/labs/validate` compiles and resolves templates but writes
nothing and touches no lab. It requires the `editor` role and the CSRF header.

```sh
curl -b jar -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"yaml":"schema: 1\nname: Dry run\ndefaults: {template: vpcs}\nnodes: {PC1: {}}\n","filename":"dry-run.yaml"}' \
  $B/api/v2/labs/validate
```

Response, always HTTP 200 for a well-formed request:

```json
{
  "status": "success",
  "data": {
    "valid": true,
    "errors": [],
    "warnings": [],
    "counts": {"nodes": 1, "networks": 0, "links": 0}
  }
}
```

- `valid` is `false` when compilation failed; `errors` then holds the first
  error message and `counts` are all zero.
- `counts.links` is the number of cabled interface ends, not the number of
  `links` entries: a point-to-point entry contributes 2.
- `counts.networks` describes the compiled document before the link invariant
  removes networks with fewer than two attachments.

The **Create project → From YAML** dialog and the editor's **YAML** panel use
this endpoint for their Validate button.

## Import

`POST /api/v2/labs/import` (multipart field `file`; `editor` role and CSRF)
accepts `.unl` and YAML:

1. A `.yaml` or `.yml` extension selects the YAML compiler.
2. A `.unl` extension selects the XML parser.
3. Any other extension is sniffed: content whose first non-whitespace byte is
   `<` (a UTF-8 BOM is tolerated) is treated as XML; everything else is
   treated as YAML.
4. If the XML path fails on a file that does not look like XML, the import
   retries as YAML. If both fail, the `422` message contains both errors, each
   prefixed with `XML:` or `YAML:`.

An imported YAML lab is a new lab: new id, revision 1, the uploading account
as owner, and the YAML `author` when it is present. The top-level `name`
becomes the lab name. An empty or oversized upload (over 10 MB) is rejected
with `422`. A YAML document within the input cap can still compile to a `.unl`
over the separate 10 MB compiled-lab cap; that is also rejected with `422`,
before a lab is created.

The import response has the usual shape, with warnings in `meta.warnings`:

```json
{
  "status": "success",
  "data": {"id": "…", "name": "OSPF triangle", "revision": 1, "…": "…"},
  "meta": {"warnings": []}
}
```

The audit entry is `lab.import` with `{"source":"yaml"}` (or `"unl"`).

## Apply and staleness

`POST /api/v2/labs/{id}/apply` replaces an existing lab's topology and
metadata from YAML. It requires the `editor` role, the CSRF header, and access
to the lab. The body is:

```json
{
  "yaml": "schema: 1\nname: OSPF triangle\n…",
  "filename": "ospf-triangle.yaml",
  "revision": 7
}
```

Only `yaml` is required. `filename` is optional; when omitted, the existing
provenance file name is kept (or `lab.yaml` when the lab has no marker).
`revision` is optional; when supplied it must equal the lab's current revision.

Apply compiles the document and replaces the lab **wholesale**:

- preserved: lab id, file path/folder, owner, and `created_at`;
- replaced: name, description, body, tags, `node_list`, and `network_list`;
- bumped: `revision` by exactly one, with `updated_at` refreshed;
- restamped: the YAML provenance marker (full source, sha256, file name, new
  revision);
- run directories: governed by the old and new `id → name` maps; surviving
  nodes keep their run directories only when their identity is unchanged, and
  some applies wipe all old run directories (see
  [Node identity and run directories](#node-identity-and-run-directories)).

Before writing, apply enforces the separate 10 MB compiled-`.unl` cap. A lab
whose serialized form would exceed it returns `422` and is not modified.

Guards and errors:

| Condition | Response |
|---|---|
| Any node is not `stopped` | `409` — `Stop and wipe the lab before applying YAML.` |
| `revision` is supplied and does not match | `409` — `The lab was modified by another session.`, with `details.revision` set to the current revision |
| Compile error | `422` with the compiler message |
| Compiled lab would exceed 10 MB | `422`; nothing is written, and the lab file and revision are unchanged |
| Unknown lab | `404` |
| No access, wrong role, or missing CSRF | `403` |

A successful apply returns HTTP 200:

```json
{
  "status": "success",
  "data": {"id": "…", "revision": 8, "…": "…"},
  "meta": {"warnings": []}
}
```

Apply works on any lab you may edit, even one that was created on the canvas.
The editor's **YAML** source panel, however, is shown only for labs that carry
the provenance marker described below.

### Node identity and run directories

YAML node ids are positional: `1..N` in declaration order. Run directories are
keyed by node id, so reusing one after a reorder would hand a node another
node's NVRAM, disks, or saved config. Apply therefore compares the old and new
node `id → name` maps before writing:

- **Safe mapping** — every old id that survives the apply still has the same
  name. Surviving nodes keep their run directories; old ids absent from the new
  topology are stopped and wiped.
- **Changed mapping** — a surviving old id now refers to a different name,
  because nodes were reordered or renamed. Apply stops and wipes **all** old
  node run directories before writing, so no node can inherit another node's
  state. New nodes start with clean run directories.

Adding nodes after the existing ones, or removing only trailing nodes, keeps
surviving ids unchanged and is safe. Inserting or removing a node before the
end renumbers later nodes, and renaming any node changes its mapping; both
situations trigger the wipe-all path.

**Recommendation:** if your change reorders or renames nodes, stop and wipe the
lab yourself before applying. Apply still protects you by wiping all old run
directories, but an explicit wipe makes the reset unambiguous.

### Staleness

The provenance marker records the lab revision at which the YAML was applied.
After a canvas edit and save, the marker revision is lower than the lab
revision:

- the editor's **YAML** panel shows a staleness warning: the canvas and the
  stored source disagree;
- applying always **replaces** the canvas; the YAML source wins;
- if you edited the panel text, apply replaces the canvas with your edited
  YAML;
- if the stored source is unchanged, Apply remains enabled while the lab is
  stale. Applying it re-applies the stored YAML and discards the canvas edits —
  an explicit reset to the last applied source. When the lab is not stale,
  unchanged text disables Apply;
- pass the current `revision` with apply to get a `409` instead of silently
  overwriting a lab that another session changed in the meantime.

### Stopped-lab requirement

Apply never stops a running lab for you. Stop every node first (the API
message says "Stop and wipe"). If you changed a node's embedded startup config,
wipe that node as well, because IOL and other engines may otherwise keep the
configuration saved on the device. Nodes removed by the apply are wiped
automatically, and a reorder or rename wipes all old node run directories (see
[Node identity and run directories](#node-identity-and-run-directories)). Wipe
the lab yourself when reordering or renaming nodes.

### Editor YAML panel

For a lab with a provenance marker, the editor toolbar shows a **YAML** button.
The panel displays the stored file name, a short sha256, and the revision at
which the source was applied, and lets you edit the source in place.

- **Validate** calls the validate endpoint and shows errors, warnings, and
  counts without writing.
- **Apply** is disabled while any node is running, while validation reports
  errors, and while the source is unchanged **and the lab is not stale**. A
  stale lab with unchanged text is still applyable: it restores the stored YAML
  over the canvas edits. The tooltip explains the current reason.
- A stale source (marker revision lower than the lab revision) shows a warning
  that applying replaces the canvas changes with the stored source (or with
  your edited text, if you changed it).
- A `409` from apply (running lab or stale revision) is shown inline in the
  panel; a successful apply reloads the editor from the server through the
  normal external-change path.

## Author

The YAML `author` field is used whenever it is present:

- on import it becomes the lab author (otherwise the uploading account is
  used);
- on apply it replaces the lab author (otherwise the existing author is kept).

For reproducible fixtures, set `author: TeraLab contributors` or your own name.
Do not put credentials, tokens, or personal contact details in a lab file.

## Provenance marker

Import and apply store the full YAML source and its metadata inside the lab's
TeraLab-only `<extensions>` block in the `.unl` (the lab's `extensions.source`
object):

```json
{
  "source": {
    "kind": "yaml",
    "name": "ospf-triangle.yaml",
    "sha256": "…",
    "revision": 1,
    "text": "schema: 1\n…"
  }
}
```

`GET /api/v2/labs/{id}` exposes it as `data.extensions.source`. `name` is the
basename of the uploaded/applied file, `sha256` is the hash of the exact text,
`revision` is the lab revision at apply/import time, and `text` is the exact
YAML. The editor's **YAML** button appears only when this marker is present.

The marker keeps the YAML source in the lab file, so any inline configs appear
twice: once inside `text` and once base64-encoded in `<objects><configs>`. The
10 MB input cap and the separate 10 MB compiled-`.unl` cap bound the size;
because the source is embedded in the marker, a YAML near the input cap will
usually exceed the compiled cap. Keep sources and inline configs small.

### EVE-NG re-save caveat

EVE-NG ignores `<extensions>` and drops it when it re-saves a lab. After an
EVE-NG round-trip:

- the lab remains a normal TeraLab v2 lab and its topology/configs are intact;
- the provenance marker is gone, so the editor's YAML source panel no longer
  appears;
- applying or importing YAML again recreates the marker.

TeraLab's own export and re-import preserve `<extensions>`, so the marker
survives a TeraLab round-trip.

## Determinism

The compiler is a pure function of the YAML text and file name:

- ids are assigned in declaration order (declared networks, then p2p networks,
  then nodes);
- interface indexes, names, and materialized port counts follow the fixed
  grammar above;
- grid/tree layout uses fixed origins, spacing, and traversal order;
- there is no randomness and no wall-clock input;
- the provenance sha256 is the hash of the exact source text.

The same YAML always produces the same compiled topology, coordinates, and
provenance hash. Lab identity, owner, timestamps, and file path are storage
metadata assigned per lab, so two imports are two labs with identical topology
but different identity metadata.

## Non-goals

This release deliberately does not include:

- reverse export (`.unl` → YAML); a lab's YAML source is available only if it
  was imported or applied with a marker;
- `config_file`, config includes, or multi-file uploads (configs are inline);
- serial-interface links;
- text or picture objects;
- cloud, NAT, OVS, or other host-network types (non-bridge types only warn and
  run as isolated bridges);
- server-side git integration or automatic re-apply from a repository;
- any change to the editor's save path or to the `.unl` storage format;
- an `organic` layout mode (only `grid` and `tree` are implemented).

## See also

- [`LAB-FORMAT.md`](LAB-FORMAT.md) — the stored `.unl` format, engines, and
  EVE-NG compatibility.
- [`API.md`](API.md) — API quick reference, including validate and apply.
- [`openapi.yaml`](openapi.yaml) — request/response schemas.
- [`examples/ospf-triangle.yaml`](examples/ospf-triangle.yaml) and
  [`examples/mixed-iol-vpcs.yaml`](examples/mixed-iol-vpcs.yaml) — complete,
  importable examples.
