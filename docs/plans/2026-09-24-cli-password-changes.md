# WP-CLI password changes for v1.5

## Authorized outcome and boundary

The user authorized implementation on 2026-09-24: standard WP-CLI password changes bypass both strength and reuse validation, update password history, renew expiry, and complete any pending reset. Include an explicit disclaimer. Target the existing PR #21/v1.5 branch `release/1.4.3-ready`, starting at `ade5195`. Delivery is verified local commits; push, merge, release publication, and real-account changes are excluded. Existing untracked `AGENTS.md` and `.codex/` belong to the user.

Scope: `wp user update` when a password is saved and `wp user reset-password`; existing history format; command-scoped integration, documentation, isolated regression coverage. Exclusions: arbitrary SQL, `wp eval`/custom commands, skipped plugin loading, user creation policy, retroactive reconstruction of CLI changes, deferred E2 controls, release automation changes.

Success criteria: successful supported commands record history and clear pending reset without strength/reuse checks; other updates, failures, and periodic invalidation preserve their current semantics; all required verification and independent QA pass.

Dependencies: existing isolated runner and plugin dependencies; local Docker access. Risks: CLI command lifecycle hooks, legacy WordPress callback arguments, overlapping password notifications, partial batch failure, and inadvertently completing the plugin's own periodic reset. Tasking follows `decompose-work` contracts below; one implementation writer and one delegation level.

### T1. Implement CLI completion and regression coverage

Status: completed
Owner: fresh worker
Goal: make supported CLI password changes complete the plugin reset lifecycle with explicit administrative policy bypass.
Scope: plugin CLI integration and Controller helpers, both readmes/changelog, isolated fixtures and runner wiring; translations if applicable.
Out of Scope: exclusions above; no commits by worker or external writes.
DoR: user approved bypass of both checks; existing code and isolated runner available; clean tracked baseline confirmed.
DoD: scoped diff reviewed; changed PHP files pass `php -l`, changed shell scripts pass syntax checks, `git diff --check` passes; serial five-target integration matrix passes; independent epic QA accepts; root creates scoped local commit.
AC:
- Both commands bypass strength and reuse validation and act only on successfully persisted password changes.
- History preserves prior entries and records the saved password using existing WordPress hashes/format; preserve the previous password if missing. No sensitive values enter source literals, logs, or handoffs.
- Completion renews `last_reset`, removes `rp_inited` and `rp_pre_inited`, and clears reset-required reminders. Repeated notifications do not duplicate the same saved hash.
- Non-password updates and failed writes do not alter policy metadata; partial success updates only successful accounts.
- `wp safety check-users` and periodic resets retain pending state and expiry semantics; normal web strength/reuse checks remain effective.
- Disclaimer explicitly names bypass of strength and reuse checks and history/expiry bookkeeping, once per affected command on stderr; ordinary command stdout and success behavior stay compatible.
- Document loaded-plugin requirement, supported commands, global account behavior on multisite, and no retroactive repair; no new settings or migration.
- Real command integration coverage includes weak and reused passwords, history rejection through web validation, state/reminders, unchanged/failed operations, batch handling, ordinary/MU and multisite coverage as applicable.
Dependencies: none blocking implementation; T2 verification and QA required for completion.
Notes/Risks: use command-scoped hooks so CLI-driven test fixtures that simulate web requests and cron are not treated as administrative changes. WordPress 5.0/PHP 7.4 support is required.

### T2. Verify, independently review, and commit

Status: completed
Owner: root with test_monitor and fresh epic_qa
Goal: deliver a verified local commit for the v1.5 PR branch.
Scope: frozen T1 diff, serial `bash dev/tests/run.sh` targets `php85-wp712`, `php82-wp712`, `php74-wp50`, `php74-wp68`, `php82-wp68`, independent QA, checkpoint and commit.
Out of Scope: publication, push, merge, unrelated broad checks.
DoR: T1 writer stopped; root accepts stable diff for verification.
DoD: exact checks/results and QA recorded; scoped commit created; final delivery state reported.
AC: all T1 criteria have source/runtime evidence; no unapproved files committed; no real site/database or email recipients used; QA gate is pass or pass_with_notes with no unmet required criterion.
Dependencies: T1 reviewed implementation.
Notes/Risks: sandbox Docker/network permission may require escalation; runner refuses remote/reused targets and intercepts mail.

## Checkpoints

- Initial: tracked worktree clean, base `ade5195`, current branch matches the locally documented PR #21 head. Live `gh pr view` could not connect under sandbox networking; no external changes attempted.
- Live PR read after network escalation confirmed PR #21 is open, targeting `master` from `release/1.4.3-ready`.
- T1 frozen: worker stopped with eight implementation/documentation/test paths. Root reviewed product hooks, history completion, documentation and fixture assertions; accepted for runtime verification. Worker reports `php -l` passed for Controller, General, PasswordChanges and the PHP fixture; `sh -n` passed for both shell fixtures; `git diff --check` passed. No runtime result yet. New handler digest: `7feed6cd0de3ed63ae629c0a55429ab7a881116b2942b353cad924cc106396b7`; PHP fixture digest: `0254452c514c258a7749216d18297f963159335359c82f3cdf2323b4e2621c4b`. Root-authored changes are delivery bookkeeping only.
- First T2 attempt: `bash dev/tests/run.sh php85-wp712` exited 1 after 124s (WP 7.1.2/PHP 8.5.10). Weak/reused CLI changes, later web validation, reset completion, unchanged/failed operations and batch assertions passed before `CLI multisite subsite context missing`. Remaining targets were not run. Disposable resources were removed and pre-existing development containers preserved; frozen digests/status unchanged. Raw output stays outside repository at `/tmp/safety-passwords-t2-php85-wp712.log`. T1 returns to repair for the multisite context fixture; no criterion waived.

### R1. Repair multisite CLI verification context

Status: completed
Owner: fresh repair worker (initial attempt insufficient; outcome delivered through R3/R5)
Goal: execute the required global-account observation in a real subsite context.
Scope: CLI fixtures and their container-setup integration; inspect existing network fixtures and config setup for context rules.
Out of Scope: weakening the subsite assertion, product scope changes, Docker execution, external writes.
DoR: first runtime failure is identified and writer/monitor stopped.
DoD: source-grounded context repair; static checks pass; fresh serial matrix and independent QA required before final commit.
AC: a real subsite observes CLI-updated global history/reset state; later network tests retain valid configuration; no real environment touched.
Dependencies: T1 frozen implementation and first T2 failure evidence.
Notes/Risks: existing multisite constants can pin WP-CLI to the main site; inspect actual source before selecting a fix.

- R1 frozen: moved removal of the disposable multisite domain/path constants before CLI subsite observation (the runner already removed them later); strengthened the assertion to match the created subsite ID. No product changes. Root accepted the focused diff for rerun. PHP lint, both shell syntax checks and diff check passed. Updated PHP fixture SHA-256 `1f0c89a48880d50d44f9de6ff7542d72285716c7d90c57ab7a0681614a14e1d5`; container setup `94aa072198f3acfca61ea9e1aafd5cb1de45e0eca474686ce06a83abb992d100`; shell phase `261f00d600208f320d8b42e21c35cba34b4b3822f912425309801250b1ef1910`.
- R1 runtime: first target again exited 1 at the same subsite assertion after 66s; prior CLI assertions passed, resources cleaned, frozen digests/status unchanged. Log `/tmp/safety-passwords-t2-r1-php85-wp712.log`. Root rejected R1 as sufficient repair and paused the full ladder for source-based diagnosis; no acceptance criterion waived.

### R2. Diagnose the repeated subsite-context failure

Status: completed
Owner: fresh diagnosis worker, then test_monitor if needed
Goal: identify the exact failing context condition before further repair.
Scope: targeted source investigation and safe diagnostic assertions in the three CLI test fixture paths; conclusive source-based local repair if available.
Out of Scope: product changes, loosening subsite proof, unrelated config changes, repeated blind matrix attempts.
DoR: R1 failed at the same condition and all writers/monitors stopped.
DoD: cause explained from source/runtime evidence; diagnostic target only if needed, followed by verified repair and final matrix/QA before commit.
AC: distinguish expected-site lookup, actual current-site routing and main-site comparison without sensitive output; preserve real subsite and global history verification.
Dependencies: frozen T1/R1 and both failure reports.
Notes/Risks: one bounded diagnostic run allowed; material environment or policy blocker must be reported before extending the loop.

- R2 diagnostic freeze: split the aggregate context assertion into exact subsite-path lookup, main-site selection and wrong-site selection; failure reports only safe request-path/constants booleans. Source inspection did not establish a conclusive cause. Static PHP/shell/diff checks passed; one diagnostic `php85-wp712` target assigned to the monitor. PHP fixture SHA-256 `110ec747badb061496bbeea9e5fdcfc432e575603fffc877f3d0fe970dfab8d0`; other frozen files unchanged.
- R2 result: diagnostic target exited 1 after 66s with `CLI subsite exact path missing`. This identifies the invalid hardcoded site-path assumption before any current-context assertion. Prior CLI assertions passed, disposable cleanup completed, frozen digests/status unchanged. Log `/tmp/safety-passwords-t2-diagnostic-php85-wp712.log`. Root selected actual created-site identity/URL for R3 instead of another guessed route/config change.

### R3. Use the actual created multisite site

Status: completed
Owner: fresh repair worker
Goal: observe account state from the actual created non-main site, independent of subdomain/subdirectory installation mode.
Scope: the three CLI test fixture paths; capture created site identity and resolve its real URL, assert exact identity after separate CLI bootstrap.
Out of Scope: changing the existing network mode, weakening subsite proof, product changes, runtime execution by worker.
DoR: R2 diagnostic establishes hardcoded path lookup failure.
DoD: source-grounded fix, static gates, final serial five-target matrix and QA, scoped commit.
AC: separate CLI invocation enters exactly the created non-main site and observes shared history/reset state; existing network scenarios remain valid.
Dependencies: R2 evidence and existing isolated site creation.
Notes/Risks: pass only synthetic site metadata internally; do not expose account or authentication data.

- R3 frozen: fixture obtains `wp site create --porcelain` identity and `wp site list --site__in=... --field=url`, passes them to a separate CLI bootstrap, and verifies exact non-main site/network identity. This removes the invalid assumption that a created site must use `/subsite/` (creation varies with network mode). Product unchanged. Root reviewed the focused fix; PHP/shell/diff checks passed. Frozen digests: container setup `9bd2b1eda2d91c1931c1b3f7853f14cd1fba9c1deef8761dc3b90e260a849caf`, phase `9dbaa3aaf00a7c589f308ed3ea86220e32257e6af214a2b77b58db7449a7124f`, PHP fixture `6ef1122724094d8b1ee00d87cf3007f34b74cb9e239a6ee743e3dee52b11f988`. Full matrix resumes from the first target.
- Final matrix progress: `php85-wp712` passed in 93s, including exact multisite context and the complete existing regression scenario. `php82-wp712` then exited 1 after 147s during the WordPress archive download (cURL 56/TLS EOF); no assertions ran for that target. Both runs cleaned disposable resources and left source digests/status unchanged. Root authorized one retry of only the failed target, then remaining targets; the passed target is retained. Logs `/tmp/safety-passwords-t2-r3-php85-wp712.log` and `/tmp/safety-passwords-t2-r3-php82-wp712.log`.
- Matrix continuation: authorized `php82-wp712` retry passed in 86s. `php74-wp50` then exited 1 after 31s at fixture expectation `Missing synthetic account update unexpectedly succeeded`; its first weak CLI update succeeded. Last two targets not started. This fixture imposed a missing-user CLI exit-code assumption outside the plugin contract. Frozen digests/status unchanged and disposable cleanup completed. Logs `/tmp/safety-passwords-t2-r3-retry-php82-wp712.log` and `/tmp/safety-passwords-t2-r3-php74-wp50.log`. R4 replaces that assertion with a deterministic real rejected password write.

### R4. Verify real failed writes across CLI versions

Status: completed
Owner: fresh repair worker
Goal: prove failed persistence leaves policy state unchanged without assuming a missing-user exit code across CLI releases.
Scope: CLI shell/PHP fixtures; attempt a password update on an existing synthetic account with a deterministic conflicting email, inspect unchanged history/password/reset state and absence of plugin warning.
Out of Scope: changing WordPress/WP-CLI exit semantics or product code, weakening failed-write coverage.
DoR: oldest target identified the unsupported fixture exit-code assumption.
DoD: static checks, oldest target first then complete final five-target matrix, QA and scoped commit.
AC: actual rejected password write leaves all policy state untouched; partial-batch and successful changes retain their required assertions.
Dependencies: existing two synthetic accounts and frozen product implementation.
Notes/Risks: pass generated candidate on stdin and do not expose command output or authentication data.

- R4 frozen: replaced the missing-user status assertion with a real conflicting-email password update on an existing synthetic account; require a CLI failure diagnostic and prove the rejected candidate/email did not persist, history/current saved hash and pending reset state remain intact. Batch outcome assertions rely on persisted account state across differing CLI exit conventions. Root accepted the focused repair; PHP lint, shell syntax and diff checks passed. SHA-256 PHP fixture `6c7447a926a75abaf03c3da0983149fb67dbce3b9fce9a2aba9c1df73469a356`, shell phase `04b487cafb4fd1ef08aeec3112dd9f1bb5345f17e6d2e92cc9b8fa3ed24fd502`. Product and container-setup digests unchanged. Final matrix prioritizes `php74-wp50`, then the remaining four targets on this final fixture revision.
- R4 runtime: `php74-wp50` passed all new CLI and exact-subsite assertions, then exited 1 after 49s at the subsequent WordPress bootstrap with unavailable-table error. Disposable cleanup completed, no source changes. Log `/tmp/safety-passwords-t2-r4-php74-wp50.log`. R5 investigates the earlier R1 constant-removal relocation, which was not the confirmed subsite-path cause and may affect legacy main-site bootstrap.

### R5. Preserve legacy network bootstrap ordering

Status: completed
Owner: fresh repair worker
Goal: retain baseline bootstrap behavior for main-network operations after the new CLI tests.
Scope: container-setup.sh only; source-based restoration of baseline constant-removal ordering where appropriate.
Out of Scope: product changes, weakening CLI/network assertions, database changes outside disposable runner.
DoR: all new CLI assertions pass on the oldest target; later bootstrap regression isolated to runner sequence.
DoD: source/sequence diagnosis, shell/diff checks, complete final matrix and QA, scoped commit.
AC: new exact-subsite observation and pre-existing subsequent network phases both pass on WordPress 5.0 and newer targets.
Dependencies: R3 actual site URL selection, R4 successful oldest-version CLI assertions.
Notes/Risks: network constants and selected site are distinct; remove any unnecessary R1 configuration change rather than altering supported network behavior.

- R5 frozen: restored constant deletion to the original baseline position before second-network bootstrap. Source confirms the constants branch still resolves the requested site via `get_site_by_path`, so R3's actual subsite URL remains meaningful with the constants present. Root accepted this removal of the unnecessary R1 relocation. Only container setup changed; shell/diff checks passed. SHA-256 `381ca17c8193b53ba09965bc0934ebd8ce962c57c72047bab079f03fa602b563`; all product and R4 fixture hashes unchanged. Oldest target resumes first, followed by the other four on the final sequence.

## Final verification boundary

All five commands passed on the final frozen implementation and fixture revision, serially, through the isolated runner:

| Exact command | Result | Runtime | Duration |
| --- | --- | --- | --- |
| `bash dev/tests/run.sh php74-wp50` | exit 0 | WP 5.0 / PHP 7.4.33 | 57s |
| `bash dev/tests/run.sh php74-wp68` | exit 0 | WP 6.8 / PHP 7.4.33 | 72s |
| `bash dev/tests/run.sh php82-wp68` | exit 0 | WP 6.8 / PHP 8.2.33 | 69s |
| `bash dev/tests/run.sh php85-wp712` | exit 0 | WP 7.1.2 / PHP 8.5.10 | 81s |
| `bash dev/tests/run.sh php82-wp712` | exit 0 | WP 7.1.2 / PHP 8.2.33 | 61s |

Each completed the entire existing integration scenario plus the CLI ordinary/MU/multisite phases: weak/reused passwords accepted, history recorded, web reuse rejected, reset state/reminders completed, rejected writes preserved state, partial and multi-account updates correct, exact subsite observed global state. Final raw logs are `/tmp/safety-passwords-t2-r5-<target>.log`, outside the repository. Final frozen hashes match; no generated repository artifacts; disposable resources removed and pre-existing development services preserved. Prior failed fixture iterations and transient download failure above are superseded by these final passes, not treated as passes themselves.

Implementation and final fixtures were committed locally as `7cd2ac67f0ca9a6d302ba6ae29b89e7d82b0e321` on `release/1.4.3-ready`, the head branch of PR #21. A fresh independent `epic_qa` reviewed `ade5195..7cd2ac6` and returned **pass**. It independently checked source/AC coverage, the five final integration logs and fixture hashes, all four changed PHP files with `php -l`, both shell files with `sh -n`, and `git diff --check`. No credential disclosure, authorization-boundary defect, unmet criterion, or risk exception was identified.

Root accepts T1/T2 and the final repair outcomes as completed at the verified local-commit boundary. R1's initial configuration relocation was superseded by R3's actual site identity and R5's restoration of baseline bootstrap ordering; the rejected intermediate state is not an accepted result. This final bookkeeping update records the QA gate without changing tested implementation or fixtures. Root-authored edits throughout were delivery bookkeeping only.

Remaining boundaries: standard supported commands require the plugin to be loaded; custom/eval commands and historical CLI changes remain excluded. No push, merge, publication, real-account change, or remote CI result is claimed for this follow-up. User-owned `.codex/` and `AGENTS.md` remain untracked and untouched.
