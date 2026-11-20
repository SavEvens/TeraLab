# Provenance — TeraLab v1.4.2

## Official lineage

    UNetLab 1.0.2-2 (commit 8ef51974b448318697e5dd18a4e10496f2f56b39)
        -> TeraLab v1.1.0
        -> TeraLab v1.4.2

- Upstream: https://github.com/dainok/unetlab
- Upstream version: 1.0.2-2
- Upstream commit: 8ef51974b448318697e5dd18a4e10496f2f56b39
- Upstream licence: BSD-3-Clause
- TeraLab version: 1.3.0
- TeraLab code licence: AGPL-3.0-or-later

## Verification

```sh
git cat-file -e 8ef51974b448318697e5dd18a4e10496f2f56b39^{commit}
git show 8ef51974b448318697e5dd18a4e10496f2f56b39:VERSION   # 1.0.2-2
git show 8ef51974b448318697e5dd18a4e10496f2f56b39:LICENSE   # BSD-3-Clause
git merge-base --is-ancestor 8ef51974b448318697e5dd18a4e10496f2f56b39 HEAD
```

## Modification policy

- New TeraLab files: AGPL-3.0-or-later.
- Modified BSD-3 files: retain the BSD-3 notice, add a prominent modification
  notice and date, and identify the AGPLv3 modifications.
- No upstream copyright or licence notice may be removed.
- No code, assets, CSS, templates, lab files or metadata may be copied from an
  external reference deployment.
- The QEMU/Dynamips/IOUtools/kernel components remain separate GPLv2 packages.

## External references

External reference deployments are used only as behavioural/UX references.
They are not upstreams, not version ancestors, and not sources of code,
assets, or version numbers.
