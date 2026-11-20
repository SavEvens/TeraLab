<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# Lab file format (`.unl`)

TeraLab stores every lab in a portable XML format that is a superset of the
modern EVE-NG `.unl` format. The reader stays tolerant: it opens TeraLab v1.x
and v2 files as well as legacy UNetLab and modern EVE-NG labs.

## Dialects

| Dialect | Marker | Notes |
|---|---|---|
| TeraLab v2 | `format="2"` on `<lab>` | Current save format |
| Legacy TeraLab/UNetLab | absolute image paths, per-node `<config>`, `revision` | Read-only input; saving rewrites as v2 |
| EVE-NG | `scripttimeout`/`countdown`/`linkwidth` and/or `<objects>` | Read-only input; saving rewrites as v2 |

Import accepts all three. Save always writes v2.

## Structure

```xml
<lab name="Lab" id="<uuid>" version="1" format="2" revision="7" author="admin"
     scripttimeout="300" countdown="0" linkwidth="2" lock="0" grid="1" sat="-1">
  <description>…</description>
  <body>…</body>
  <topology>
    <nodes>
      <node id="1" name="R1" type="iol" template="iol"
            image="x86_64_crb_linux-adventerprisek9-ms.bin"
            console="telnet" cpu="1" ram="512" ethernet="1" nvram="1024"
            serial="0" delay="0" sat="-1" icon="Router.png" config="1"
            left="120" top="100">
        <interface id="0"  name="e0/0" type="ethernet" network_id="1"/>
        <interface id="16" name="e0/1" type="ethernet" network_id="0"/>
      </node>
    </nodes>
    <networks>
      <network id="1" type="bridge" name="Net-1" left="300" top="120"
               visibility="0" icon="lan.png"/>
    </networks>
  </topology>
  <objects>
    <configs>
      <config id="1">BASE64(startup-config)</config>
    </configs>
    <textobjects>
      <textobject id="3" name="Note" type="text"><data>BASE64(html)</data></textobject>
    </textobjects>
  </objects>
  <extensions format="2">{"version":2}</extensions>
</lab>
```

### Rules

- **Networks are numbered from 1.** `network_id="0"` means “not connected”.
  (A legacy file that defines a real network with id `0` keeps that meaning.)
- **`ethernet`**: for IOL it is the number of four-port adapters
  (`ceil(ports / 4)`); for other engines it is the number of interfaces.
- **Interface ids**: IOL uses Cisco's encoding `slot | port << 4`
  (`e0/0` = 0, `e0/1` = 16, `e1/0` = 1); other engines use the port index.
- **Startup configs** live in `<objects><configs>` as base64, keyed by node id.
  `config="1"` marks a node that has one.
- **Images are references, never paths.** `image`, `iso`, `kernel` and
  `initrd` hold a single name that matches an image's *reference* in TeraLab
  (editable on the Images page). Absolute paths are never written.
- **Text and picture nodes** are not written as `<node>` entries: text becomes
  an EVE-NG `<textobject>`; pictures live in `<extensions>`.
- **`<extensions>`** is TeraLab-only, versioned JSON. EVE-NG ignores it. It is
  where future TeraLab features will live.

## EVE-NG compatibility

A TeraLab export can be placed in an EVE-NG lab folder and opened: EVE-NG
reads the topology, interface numbering, network types and base64 configs.
When EVE-NG re-saves the lab, it drops `<extensions>` and unknown attributes;
the topology and configs survive.

What is preserved but not emulated:

- Cloud/NAT/OVS network types (`pnetX`, `nat0`, `internal`, `ovs`) are kept in
  the file and shown in the editor, but TeraLab runs them as isolated bridges.
  It never changes host networking.
- Docker nodes and EVE-NG's built-in Ethernet switch import as visible nodes
  but have no engine and are never started.
- EVE-NG config sets beyond the default set are preserved as opaque XML.

Limits:

- TeraLab supports up to 16 interfaces per node; larger imported nodes are
  truncated with an import warning.
- Text objects import as plain text with a best-effort position; original
  HTML styling is not preserved.

## Import warnings

`POST /api/v2/labs/import` returns `meta.warnings`:

| Type | Meaning |
|---|---|
| `image_unmapped` | The node references an image that is not in the store; map it on the Images page or in the Inspector |
| `network_unsupported` | Cloud/NAT/OVS network; it will run as an isolated bridge |
| `node_unsupported` | Docker/Ethernet-switch/cloud node; it will not start |
| `interface_gap` | Unconnected EVE-NG ports were reconstructed from the port layout |
| `port_limit` | The node exceeded 16 interfaces |
| `extensions_invalid` | A malformed TeraLab extension block was ignored |

## Migration

Opening a legacy lab is read-only. The first save (including the editor's
autosave) rewrites it as v2. Back up `/var/lib/teralab` before upgrading if
you may need to return to an older TeraLab release; older releases cannot
read v2 image references.

## EVE-NG round-trip checklist

1. Export the lab from TeraLab (`Export` on the Labs page).
2. Place the `.unl` under `/opt/unetlab/labs/<folder>/` on the EVE-NG host.
3. Open it in EVE-NG and confirm nodes, networks, interfaces and configs.
4. Re-export from EVE-NG, import it back into TeraLab and compare the topology.
