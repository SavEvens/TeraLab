<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

## Summary

## Tests

- [ ] New behaviour has unit tests; bug fixes have a regression test that fails on the parent commit.
- [ ] `./scripts/test-all.sh` passes (paste the suite counts).
- [ ] `bash tests/ci/workflow-tools.sh` passes.
- [ ] ShellCheck and `./scripts/teralab-project-init.sh --check` pass.
- [ ] Licence, artifact-policy, and secret gates pass.

## Live merge gate

- [ ] Exact candidate commit deployed: `<sha>`.
- [ ] `sudo -n /usr/local/libexec/teralab/deploy-local` passed and wrote the stamp.
- [ ] `./scripts/live-check.sh --verify <sha>` passed (smoke; `--full`/`--require-full` for a release).
- [ ] Evidence: `/home/savanna/teralab-work/live-validation/<sha>.json`.
- [ ] Merge tree equals the validated tree, or the merged result was redeployed and revalidated.
- [ ] CI is green on the merged branch.

## Diff hygiene

- [ ] No research notes, screenshots, logs, evidence, credentials, vendor images, or local paths.
- [ ] AGPL/BSD headers intact; NOTICE/PROVENANCE updated where needed.
