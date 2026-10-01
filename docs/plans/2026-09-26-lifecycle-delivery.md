# Lifecycle initialization and upgrade delivery

Baseline: `02a5059`. User request: recommend MU installation; handle Composer/file updates without update hooks; provide admin and deployment CLI initialization; cover ordinary and multisite lifecycle.

Outcome: loaded plugin converges to current lifecycle state without reactivation or visiting settings. Explicit administrative initialization can repair that state safely.
Scope: lifecycle state/version checks, cron normalization, existing initialization, admin action, additive CLI command, documentation and isolated regression scenarios.
Exclusions: password-policy changes, dependency/version bumps, automatic all-network deployment, publishing, pushing, merging or deployment.
Delivery: scoped verified commits after independent QA; no external publication. Preserve unrelated `.codex/` and `AGENTS.md`.
Constraints: PHP 7.4 / WP 5.0 compatibility; current hooks, options, activation deferral and cron recurrence; one writer; no sensitive runtime output. Network operations stay in the selected network.
Risks: concurrent requests, partial writes, same-version repairs, pending deferred activation, MU removal/reinstallation, multi-network isolation. CLI runs against initialized WordPress and the target database during deployment, not an offline Composer build.
Success: automatic ordinary/MU upgrades and explicit admin/CLI initialization converge idempotently; failures remain retryable; authorization and isolation hold; existing activation/deactivation paths pass.
Tasking: decompose-work contracts below; refine from source evidence before each bounded assignment.

## T1 — Shared lifecycle initialization and automatic migration
Status: completed
Goal: upgrade loaded ordinary/MU installations without relying on updater hooks.
Scope: Activation/Cron and a dedicated lifecycle class if warranted; focused fixtures and runner integration.
Out of scope: admin/CLI UI and public documentation.
DoR: existing lifecycle and test entry points inspected; preserve deferred ordinary activation.
AC: stored version checked after dependencies are ready; current-version normal requests avoid initialization scans; migration normalizes one main-site event and removes subsite events; existing correct schedule remains stable; history/capability initialization is idempotent; locks and completion verification prevent false success; partial failure retries; legacy MU markers do not suppress version migration; network context restored and other networks untouched.
DoD: PHP lint for changed PHP and shell syntax; focused new runtime scenarios plus existing isolated lifecycle ladder pass; root accepts stable diff; scoped commit after epic QA.
Dependencies: none known.
Notes: existing option formats/public callbacks remain compatible; deactivation invalidates relevant initialization state.

## T2 — Administrative and deployment entry points
Status: completed
Goal: operators can explicitly initialize/repair after deploy or interrupted installation.
Scope: settings/admin action, additive `wp safety init`, relevant fixtures, both README files.
Out of scope: shutdown button for MU, CI infrastructure changes, all-network CLI flag.
DoR: T1 service contract accepted.
AC: admin action is POST with nonce and existing site-management permission or manage_network_options on multisite; unauthorized/invalid requests do not mutate lifecycle state; visible safe success/failure status; no nested settings forms; CLI uses same service and exits nonzero on failure/held lock; repeat calls preserve correct schedule/history; documentation recommends MU, explains filesystem removal and deploy-time DB requirement, per-network --url selection, automatic upgrades and manual recovery.
DoD: PHP/shell lint and isolated runtime authorized/unauthorized/failure/CLI scenarios pass; both readmes agree with behavior; root accepts diff; independent epic QA; scoped commit.
Dependencies: T1 reviewed service.
Notes: MU cannot be disabled through Plugins UI; filesystem access still permits removal. Preserve existing CLI subcommands.

## Verification and acceptance
Initial checks: clean tracked tree, baseline `02a5059`; only unrelated untracked `.codex/` and `AGENTS.md`.
Runtime commands are assigned serially to test_monitor after each writer stops. Minimum epic ladder: existing runner on PHP 7.4/WP 5.0 and a supported current target; extend only for new behavior or failures. No claim of PHP unit/PHPCS suite.
Independent epic QA reviews contracts, exact diff, runtime evidence and documentation before commit/completion.

Mapping accepted: Carbon `carbon_fields_fields_registered` is the ready hook; ordinary pending activation must retain deferral. Existing MU lease and main-site scope provide compatible locking/isolation. T1 assigned to fresh `lifecycle_core` worker; T2 waits for accepted service and runtime evidence.
T1 service design: `Activation::initialize(bool $force = false): bool`; explicit admin/CLI callers use `true` to repair/reseed despite a current version. False must cover held lease and failed verification; callers cannot report successful initialization on false.
T1 frozen for runtime: Activation/Cron, new lifecycle-migration fixture and container-setup integration. Worker reports PHP lint (three files), `sh -n` runner fixture, `git diff --check` passed. Root reviewed scoped diff and preserved deferred ordinary activation. `core_runtime` assigned `bash dev/tests/run.sh php74-wp50`; T2 remains blocked on this verification. No commit yet.
T1 first runtime failed (exit 1, ~33s): ordinary activation/bootstrap passed; first ordinary migration verify failed `automatic version or scheduler migration`. No PHP/WordPress error; runner cleanup completed and worktree unchanged. Evidence `/tmp/safety-passwords-t1-php74-wp50.log`. Fresh `core_repair` assigned bounded diagnosis/repair; acceptance withheld.
T1 diagnostic runtime (same command, exit 1, ~30s; `/tmp/safety-passwords-t1-diagnostic.log`) confirmed bootstrap ran but initialization returned false before cron. Root and worker identified unconditional `switch_to_blog()` in single-site capability verification; WordPress only loads that API for multisite. Worker added a direct single-site role check, kept network path, reran three PHP lints, shell syntax and diff check successfully. Fixed diff frozen for another oldest-version runtime; no criteria waived.
T1 fixed runtime: unprivileged attempt exited before provisioning (Docker access); elevated retry started once, exit 1 after ~75s (`/tmp/safety-passwords-t1-fixed.log`). Ordinary and MU migration, network lifecycle passed; existing two-network scenario failed `first-network MU completion did not finish`. Worktree unchanged; isolated cleanup completed. Fresh `mu_marker_repair` assigned compatibility of legacy MU completion invalidation with current version marker. T2 still waits.
T1 accepted implementation/runtime boundary: legacy MU marker compatibility fixed in Activation; PHP lint/diff check pass. `bash dev/tests/run.sh php74-wp50` elevated once passed, exit 0 ~50s (`/tmp/safety-passwords-t1-compat.log`), including full existing scenarios and added migration cases; cleanup completed, worktree unchanged. Root accepts T1 for T2 dependency. T1 remains review pending epic QA/commit. T2 assigned next; no root product edits.

## R1 — Verify persisted capabilities after initialization
Status: completed
Goal: never mark initialization successful after a failed role-option write.
Scope: Activation capability verification and focused lifecycle failure scenario.
Out of scope: capability changes or T2 controls.
DoR: T2 writer stopped and batch accepted/rejected before next writer.
AC: denied persistence of administrator capability produces false initialization result, no current completion marker, and retry succeeds after write restored; check single-site and network context; ordinary capability behavior unchanged.
DoD: PHP lint, meaningful isolated failure/retry scenario and runtime gate, scoped diff review, epic QA/commit.
Dependencies: shared-checkout writer boundary for T2.
Notes: root verified WP_Role::add_cap updates memory before WP_Roles::add_cap calls update_option; current get_role()->has_cap check alone cannot prove persistence. No root product edit made.

T2 frozen: Settings admin control/handler, additive CLI init, controls fixture/runner, both readmes. Root reviewed scoped product diff; worker PHP lint (3 files), shell syntax, diff check passed. `core_diagnostic_runtime` assigned a fresh bounded PHP7.4/WP5 runtime on current T1+T2 diff. R1 waits for this writer-free review boundary.
T2 runtime exit 1 ~50s (`/tmp/safety-passwords-controls-wp50.log`): controls admin scenarios passed, later reverse-network isolation assertion failed `first-only or unassigned history changed by second-network`. Worktree unchanged/cleanup complete. Root rejects T2 verification boundary for repair, authorizes fresh `controls_and_persistence_repair` to fix fixture ordering if CLI initialization invalidated old baselines (preserve original assertions), implement R1, and add authenticated non-network administrator/valid-nonce denial evidence. No acceptance criterion waived.
T2/R1 repair frozen: capability check now verifies stored role option; failure/retry fixture covers single-site and subsite persisted writes. Added authenticated site-admin valid-nonce denial. Boundary failure was fixture ordering: explicit first-network CLI repair mutated history after snapshot; moved before baseline and retained original assertions, with first-network snapshot surrounding second-network CLI. Worker three PHP lints, shell syntax, diff check pass. Root accepts focused repair for final runtime ladder: PHP7.4/WP5 then PHP8.2/WP7.1.2, serial via test_monitor.
Final runtime ladder passed on frozen T1/T2/R1 diff: `bash dev/tests/run.sh php74-wp50` exit 0 ~57s and `bash dev/tests/run.sh php82-wp712` exit 0 ~65s. Logs `/tmp/safety-passwords-final-wp50.log`, `/tmp/safety-passwords-final-wp712.log`. Includes controls, migration/write failures, CLI, single/multi-network, lifecycle and existing regressions. Monitor confirmed no containers remain and identical before/after worktree. Root accepts technical batches for independent epic QA; commit still pending.

## Q1 — Close independent QA evidence and test privacy gaps
Status: completed
Goal: directly prove ordinary network upgrade and eliminate nonce arguments from test traces.
Scope: controls helper and runner sequence; migration fixture if needed; sanitize affected transient test trace lines without deleting logs.
Out of scope: product behavior or relaxed acceptance.
DoR: first independent QA returned fail with two concrete findings; writer stopped.
AC: ordinary network plugin migrates on fresh request without activation/settings, with history/caps/main event/subsite cleanup asserted; nonce generated inside helper after user selection, never passed in diagnostic stack arguments; auth/nonce/failure tests remain meaningful; sanitized log evidence preserves PASS/FAIL labels.
DoD: PHP/shell/diff checks, serial oldest/current WordPress runner passes, fresh independent QA pass, scoped commit.
Dependencies: T1/T2 implementation present; previous technical runs passed but QA incomplete.
Notes: first QA (`lifecycle_epic_qa`) found missing direct ordinary multisite upgrade evidence and one-time nonce in helper call frames in successful test logs. No product bug found in that review. Fresh `qa_lifecycle_repair` assigned; no exception accepted and completion withheld.
Q1 frozen: explicit ordinary-network prepare/verify before MU load now exercises migration on separate bootstraps. Controls helper generates nonce after user selection from a boolean intent, eliminating nonce arguments. Worker sanitized affected helper trace frames in three earlier logs while retaining PASS/FAIL evidence. Two PHP lints, shell syntax, diff check and static argument check passed; root reviewed narrow changes. Serial runtime rerun assigned, logs `/tmp/safety-passwords-qa-wp50.log` and `/tmp/safety-passwords-qa-wp712.log`; must explicitly confirm new scenario and nonce-free traces before fresh QA.
Q1 runtime passed: `bash dev/tests/run.sh php74-wp50` exit 0 ~55s, `bash dev/tests/run.sh php82-wp712` exit 0 ~62s. Both logs contain ordinary-network prepare/verify PASS once each and no FAIL labels. Monitor inspected nine controls helper trace frames per log: third argument boolean, no nonce string argument or sensitive assignment-output pattern detected. No containers remain; worktree unchanged. Root accepts repair for fresh independent QA.

## Final acceptance
Fresh independent `lifecycle_final_qa` returned **pass** on the final frozen diff and both final runtime logs. All T1/T2/R1/Q1 criteria accepted, no waived requirements or user exceptions. Root delivery is the scoped local commit containing this checkpoint; no push, merge, deployment or publication is included. No root-authored product edits; root changed only this delivery bookkeeping.
Residual documented operational limitation: removing an MU loader cannot invoke deactivation; remove remaining scheduled events manually, and explicitly initialize after same-version reinstallation. CLI requires deployed WordPress and target database access and is run separately per network. Unrelated `.codex/` and `AGENTS.md` remain untouched/untracked.
