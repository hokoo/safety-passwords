# Safety Passwords 1.5 local release candidate

This candidate branches from accepted E1 commit `80c1a69213f838aa23197cba71e30a02e275301d`. It adds the WordPress 7.1.2/PHP 8.5 and 8.2 platform adaptation from `dfe9073818cdb83f005df7cd1f06dbef725edc91`, the coherent 1.5 plugin version/Stable tag, and release documentation. The earlier 1.4.3 candidate at `257234d` passed its local verification; the separately reviewed 1.5 metadata update passed PHP lint, consistency and diff checks. Current automation scope and evidence are recorded in `2026-09-23-release-automation.md`.

PR #21 tracks `release/1.4.3-ready` against `master`; the branch name is retained while the candidate version changes to 1.5. The previous product and test behavior passed at `257234d`; the scoped version review passed at `9debab3`. Release package verification passed at `3aeea53`; automatic WordPress.org delivery is being implemented under the release automation plan. The deferred E2 branch remains outside the PR.

| Scope | Accepted local evidence |
| --- | --- |
| Original activation extraction and ordinary/MU lifecycle, issue #7 | `9b99ad4`, `0a784e3`, `b43ced0`, `a596b7a`, `8ff3c2d`; accepted E1 QA at `80c1a69`. |
| Expiry notices and repeated reset handling | `fb9e265`, `4f73323`, with final E1 QA at `80c1a69`. |
| Constant overrides, issue #8 | `69f529e`, with final E1 QA at `80c1a69`. |
| Isolated integration runner and privacy-safe Stream/CLI coverage, issue #15 | `49073f2`, `6ae61ca`, `47b3a10`, `80c1a69`; accepted three-target E1 matrix and independent `pass_with_notes`. |
| WP7/PHP8 platform adaptation | Selected `dfe9073` runner/CI targets, PHP 8.5-compatible nullable annotation, and compatibility notes. Both new targets passed before this candidate branch; the exact candidate must pass the gates below. |

E1 QA's operational note remains: older Stream records may contain previous details and need private operator assessment before external publication or deployment. This work did not inspect or delete real records. WordPress 5.0 and PHP 7.4 remain supported and tested; WordPress 5 is deprecated for future development. The repository `Tested up to` value of 7.1.2 is confirmed on this exact candidate.

## Deferred work and gates

- Entire E2/#10 is excluded: enforced next-login soft reset, installation-wide hard coordination, bulk backend, and unfinished bulk UI are not in this branch. The recoverable WIP snapshot is `delivery/issues-lifecycle-expiry` at `f8395575790cf733354bc7ca31e9328ee6664df0`. Its T9-pre, T9-lock, and T9a behavior was implemented and tested locally, but T9b still needs all three browser contexts, a final five-target rerun, and independent T10/E2 QA. Issue #10 is unfinished.
- All five GitHub integration targets passed on `f4d40e4` in both push and pull-request runs, including both WP6.8 targets whose fresh local reruns had been deferred. The new automation requires its own artifact-based verification. Browser and E2 gates apply to the deferred delivery, not to this release cut.
- Publication is a separate decision after remote CI and any required merge approval. Publishing a GitHub release triggers the existing workflow, which pushes built branches and can deploy to WordPress.org. That workflow was not run during the earlier local verification; the current 1.5 edit does not change or run it. PR #21 exists, but no merge, tag, release, deployment, or GitHub issue status change is claimed here.

## Accepted local verification

The earlier 1.4.3 candidate was E1 `80c1a69` plus the nine scoped release paths. All three serial commands exited 0 without observed warnings: `bash dev/tests/run.sh php85-wp712` (WordPress 7.1.2/PHP 8.5.10, ~55.2s), `bash dev/tests/run.sh php82-wp712` (WordPress 7.1.2/PHP 8.2.33, ~37.1s), and `bash dev/tests/run.sh php74-wp50` (WordPress 5.0/PHP 7.4.33, ~40.4s). Lifecycle, expiry, MU, two-network policy, Stream and CLI scenarios passed. All nine hashes and status paths remained unchanged; disposable resources were removed.

PHP lint of both changed PHP files, shell syntax, workflow YAML parsing and `git diff --check` passed for that earlier candidate. Independent focused release QA returned **pass_with_notes**: no technical blocker; the historical Stream assessment remains the operational note. Root accepted that release cut for a scoped local commit. The current 1.5 metadata change does not alter the tested password or runner behavior, but its own review and delivery state must be recorded separately. No remote CI, PHPUnit, PHPCS or plugin-check result is claimed.
