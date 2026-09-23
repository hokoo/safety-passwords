# Safety Passwords 1.4.3 local release candidate

This candidate branches from accepted E1 commit `80c1a69213f838aa23197cba71e30a02e275301d`. It adds only the WordPress 7.1.2/PHP 8.5 and 8.2 platform adaptation from `dfe9073818cdb83f005df7cd1f06dbef725edc91`, the coherent 1.4.3 plugin version/Stable tag, and release documentation. The verified local candidate is `257234d`; no external delivery action had occurred at that checkpoint.

The owner subsequently requested a pull request. This authorizes pushing `release/1.4.3-ready` and opening a PR against `master`; merge, tagging, release publication and deployment remain separate. PR preparation changes delivery bookkeeping only, so the product and test files retain the verified `257234d` boundary. The deferred E2 branch remains local and outside the PR.

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
- The accepted E1 boundary already passed both WP6.8 targets; fresh WP6.8 reruns are deferred secondary checks while their CI definitions remain. Browser and E2 gates apply to the deferred delivery, not to this release cut.
- Publication is a separate decision after remote CI and any required merge approval. Publishing a GitHub release triggers the existing workflow, which pushes built branches and can deploy to WordPress.org. It was not run during this local preparation. No GitHub issue status change, tag, release, merge, or push occurred.

## Accepted local verification

The frozen candidate was E1 `80c1a69` plus the nine scoped release paths. All three serial commands exited 0 without observed warnings: `bash dev/tests/run.sh php85-wp712` (WordPress 7.1.2/PHP 8.5.10, ~55.2s), `bash dev/tests/run.sh php82-wp712` (WordPress 7.1.2/PHP 8.2.33, ~37.1s), and `bash dev/tests/run.sh php74-wp50` (WordPress 5.0/PHP 7.4.33, ~40.4s). Lifecycle, expiry, MU, two-network policy, Stream and CLI scenarios passed. All nine hashes and status paths remained unchanged; disposable resources were removed.

PHP lint of both changed PHP files, shell syntax, workflow YAML parsing and `git diff --check` passed. Independent focused release QA returned **pass_with_notes**: no technical blocker; this record now replaces the pending runtime status, and the historical Stream assessment remains the operational note. Root accepted the release cut for a scoped local commit. Root-authored changes after the frozen check are delivery bookkeeping only; product and test files are unchanged. No remote CI, PHPUnit, PHPCS or plugin-check result is claimed.
