# Project instructions

## Repository scope

- This repository contains the `Safety Passwords` WordPress plugin and its Docker-based development environment. The plugin enforces password strength, password history, and periodic password reset policy.
- Treat `plugin-dir/safety-passwords.php`, `plugin-dir/src/`, `plugin-dir/assets/`, `plugin-dir/languages/`, `plugin-dir/readme.txt`, both Composer manifests and lock files, `dev/`, `docker-compose.yml`, `makefile`, and `readme.md` as the primary project surface.
- Preserve PHP 7.4 compatibility, WordPress 5.0+ compatibility through the documented tested version, multisite behavior, activation and deactivation semantics, WP-Cron scheduling, capability checks, WP-CLI behavior, Carbon Fields integration, Stream integration, and existing public hooks and constants.
- Passwords and authentication data are security-sensitive. Never log, print, persist, or include plaintext passwords, reset keys, password hashes, stop-list contents, cookies, session material, database contents, or real user identifiers in code, tests, documentation, commands, or handoffs. Use WordPress password and reset APIs rather than custom cryptography.
- Do not broadly scan or modify `vendor/`, `plugin-dir/vendor/`, `wordpress/`, or `wp-content/`. Enter those trees only when a task requires targeted dependency or WordPress-core evidence.
- Never expose or commit `.env`, SQL dumps, archives, logs, local WordPress state, credentials, tokens, private keys, certificates, or IDE files. Treat material under `dev/nginx/ssl/` and `dev/templates/ssl/` as sensitive even if already present in the repository.

## Delivery workflow

- Use `$delivery-owner` when the user authorizes execution of an epic, backlog, or other multi-task scope. Use `$decompose-work` for task contracts, readiness, dependencies, acceptance criteria, and definitions of done.
- Planning or backlog approval alone does not authorize implementation. Once execution is authorized, continue through consecutive runnable batches without asking for approval at every batch boundary.
- Pull only ready tasks with satisfied dependencies. If one task is blocked, continue independent authorized work and report the blocked task separately.
- At each batch boundary, record the task status, changed artifacts, checks actually run, evidence, newly unblocked work, residual risks, and the next batch. Keep the transition concise and continue.
- Stop only when the authorized scope is complete, no useful authorized work remains, a material decision or failed QA gate requires the user, an unsafe condition arises, or the user asks to pause.
- Do not silently broaden product scope, weaken password or authorization policy, change a public hook, constant, CLI contract, capability, persisted metadata format, or scheduling contract, waive acceptance criteria, deploy, publish, push, merge, or perform destructive external actions without the required authority.

## Multi-agent policy

- The root agent is the delivery owner and retains planning, sequencing, integration, decision gates, and final status ownership.
- While `$delivery-owner` is executing an authorized multi-task scope, assign every ready implementation or repair batch to a fresh `worker`. Implementation includes product code, tests, fixtures, configuration, workflows, migrations, and substantial documentation or runbook changes; a tests-only or docs-only batch is still implementation.
- The root must not absorb the next implementation batch merely because it is ready, small relative to the epic, or adjacent to work it just reviewed. Finish the current batch boundary, close completed threads, and start a fresh bounded worker.
- The root may edit only concise delivery bookkeeping or a minimal integration correction whose context cannot be transferred cleanly. If the change introduces behavior, adds or substantially rewrites a test or procedure, spans multiple artifacts, or grows beyond a local correction, stop and delegate it to a worker. Record any root-authored exception in the batch evidence.
- Use one delegation level by default. Subagents must not delegate again unless the root explicitly authorizes it for a bounded reason.
- Use fresh, bounded subagent turns instead of keeping workers alive across many batches. Close completed agent threads so long delivery runs do not exhaust concurrency slots.
- In the shared checkout, allow only one implementation writer at a time. Do not let a later worker mutate files while the current batch is under review or verification.
- Do not start a later writer until the current worker has stopped, its changes have a stable diff or revision boundary, required verification is complete, and the root has accepted or rejected the batch.
- Parallelize read-only exploration, focused review, and log analysis only when they are independent. Run Composer, Docker, WordPress, WP-CLI, plugin checks, and database-backed verification serially in the shared checkout.
- Every delegated implementation task must include its task contract, AC, DoD, relevant source artifacts, owned files or modules, dependency boundaries, required checks, stopping conditions, and expected return format.
- The root agent reviews and integrates all returned work. A subagent report is evidence, not automatic acceptance.

## Root context discipline

- Keep the root context focused on requirements, task contracts, sequencing, decisions, acceptance evidence, revision boundaries, risks, and final status. Raw exploration, implementation patches, test logs, stack traces, Docker output, database output, and polling output belong in the responsible subagent thread.
- The root must not generate or replay a full implementation patch for a delegated batch. It should review the worker's concise report, `git status`, `git diff --stat`, the changed-path list, and only the specific hunks or symbols needed to decide acceptance.
- Prefer narrow root commands with bounded output: targeted `rg`, focused `sed`, `git status --short`, `git diff --stat`, `git diff --check`, and path- or hunk-scoped diffs. Do not dump complete large files, broad diffs, or full command logs into the root thread unless a material decision cannot be made without them.
- A worker return must be a distilled handoff, not a transcript or full diff: changed paths and behavior, AC/DoD mapping, exact checks and results, revision or commit boundary, blockers, and residual risks. Include only decisive failure excerpts and artifact paths, with all sensitive values redacted.
- A worker may run the smallest targeted checks needed while implementing. After the writer stops, delegate noisy, long-running, Docker-backed, database-backed, repeated, plugin-check, or broad regression verification to `test_monitor`; the root must not execute or poll those commands directly during a multi-batch delivery loop.
- At each accepted batch boundary, write a concise durable checkpoint in the applicable plan or evidence artifact, then use that checkpoint instead of repeatedly reconstructing the batch from full history. Do not duplicate raw command output or sensitive runtime data in delivery documents.
- If an acceptance decision needs deeper evidence, ask the existing subagent for a focused clarification or start a fresh bounded read-only agent. Do not pull the entire subagent transcript into root.

## Agent roles

- `explorer` (`gpt-6-luna`, medium): read-only mapping and evidence gathering. Use it to trace execution paths, locate contracts and tests, and identify affected components before implementation. It must not edit or propose broad speculative rewrites.
- `worker` (`gpt-6-sol`, high): bounded implementation or repair, including tests and substantial delivery documentation. Assign explicit file or module ownership and only the checks proportionate to its change. Use no more than one worker on overlapping code in the shared checkout. It returns a concise evidence handoff rather than raw logs or a full patch.
- `test_monitor` (`gpt-6-luna`, low): execution and observation of an explicitly assigned long-running verification command or serial verification ladder. It may create normal test or runtime artifacts but must not edit product source, tests, documentation, or configuration and must not fix failures.
- `epic_qa` (`gpt-6-sol`, high): independent read-only QA at an epic boundary. It must not have implemented the epic. Do not run it after every routine batch; task-level AC and DoD verification belongs inside the delivery loop.
- Keep the pinned role model unless the user explicitly requests a different model. The root may choose a stronger model for orchestration, but subagents must not inherit that cost accidentally.

## Editing and delivery boundaries

- Inspect `git status` and the relevant diff before editing and before any commit. Preserve unrelated user changes and never discard them to simplify the task.
- Keep changes scoped to the active task. Avoid opportunistic refactors unless they are required by an acceptance criterion or to make the requested change safe.
- Follow the task's agreed delivery boundary. Create commits only when the delivery contract calls for them and authorization and repository state permit it. A commit does not authorize push, merge, deployment, or publication.
- Treat implementation, verification, independent QA, commit, merge, and publication as distinct states with distinct evidence.

## Verification

- Choose the smallest set of checks that proves the active AC and DoD, then run the broader gate required by the task or epic. Report exact commands and outcomes; never claim a check that did not run.
- Run `php -l <changed-file>` for every changed PHP file. Preserve PHP 7.4 syntax compatibility even when the local interpreter is newer.
- Use `composer validate --no-check-publish` at the repository root after changing infrastructure dependencies or the root lock file, and run it from `plugin-dir/` after changing plugin dependencies or its lock file.
- This repository does not currently define an automated PHP test suite or PHPCS command. Do not imply those gates ran. When behavior changes, define and execute the smallest applicable WordPress runtime scenario through the development environment.
- For password validation and history changes, cover the applicable registration, profile update, reset, reuse, and expiry paths without exposing passwords or hashes. For lifecycle changes, cover activation, deferred second-phase activation, deactivation, cron idempotency, and multisite or network behavior as applicable.
- For capabilities, settings, WP-CLI, Carbon Fields, Stream, email, and redirect changes, verify both authorized and unauthorized or failure paths required by the task contract.
- Use the Docker Compose and make targets documented in `readme.md` and `makefile`. Establish prerequisites and existing container state before starting, rebuilding, stopping, or removing services.
- Run Docker, WordPress, WP-CLI, plugin-check, and database-backed checks serially. Check `git status` afterward and report generated files without deleting them automatically.

## Epic QA gate

- After all implementation tasks in an epic are complete, freeze the reviewed batch and launch a fresh `epic_qa` agent before closing the epic or starting work that depends on its acceptance.
- Give Epic QA the epic scope and exclusions, success criteria, risks, all task AC and DoD, the exact diff or revision boundary, actual verification results, and relevant artifacts.
- Epic QA returns exactly one gate state: `pass`, `pass_with_notes`, or `fail`. Any unmet required criterion, missing required evidence, or incomplete delivery is `fail`.
- On `fail`, perform a bounded repair batch and run a fresh QA pass. Do not allow an unbounded worker-reviewer loop; after repeated failure of the same condition, report the blocker and the decision required.
- A human-approved exception must identify the unmet requirement and accepted risk. Never represent an exception as a successful technical check.

## Long-running commands

- In a `$delivery-owner` multi-batch run, assign long or potentially noisy verification to `test_monitor` by default. This includes Composer installation, Docker builds and startup, WordPress or WP-CLI scenarios, plugin-check, database-backed verification, log observation, and any serial ladder whose raw output is not itself a root-level decision artifact.
- Give `test_monitor` the frozen revision or diff boundary, exact command or ordered ladder, working directory, expected artifacts, stopping conditions, and the concise evidence required by the parent.
- Start each assigned command once and observe that same process. Never create polling by repeatedly launching the command.
- Prefer event-driven or reasonably spaced status checks. Do not treat quiet output as failure and do not restart, kill, rebuild, stop, or clean up services without evidence and authority.
- If an approval or automatic review times out, first establish whether the command started or is still running. Report the condition to the root; do not blindly launch a duplicate command.
- Stop monitoring when the command exits, approval is required, cancellation is requested, or a credible unsafe or stuck condition is found. Return the exit status, duration when available, concise redacted failure evidence, artifact paths, and before/after working-tree changes.

## Documentation and handoff

- Update `plugin-dir/readme.txt` and `readme.md` when public behavior, requirements, compatibility, configuration, installation, upgrade behavior, or operational procedures change.
- At final handoff, state what changed, which checks actually ran and their results, the delivery state, remaining work and exclusions, residual risks, and any accepted exceptions. Never include passwords, hashes, reset keys, private user data, or credentials in the handoff.
