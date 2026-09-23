# Safety Passwords 1.5 — release automation

Outcome: update PR #21 to version 1.5 and prepare automatic WordPress.org delivery from an explicitly published GitHub release. Preparation includes implementation, verification, independent QA, commits and updating the existing PR; it does not authorize merging, creating tags/releases, changing secrets, or publishing to WordPress.org. The existing PR branch name is retained. E2/#10 remains deferred.

Sources: current release/integration workflows, plugin metadata and lock file; `hokoo/wp-site-options` and `hokoo/cf7-telegram`; official GitHub, WordPress and deployment-action documentation. Only one implementation writer runs at a time; runtime/build checks use the monitor, and final QA remains independent.

### R1. Version 1.5

Status: completed
Goal: coherent current release metadata.
Scope: plugin header/constant, Stable tag/changelog, current readme and candidate documentation.
Out of Scope: product behavior, historical evidence rewrites, branch renaming, publication.
DoR: owner explicitly selected 1.5.
DoD: PHP lint, version consistency and diff checks; reviewed scoped commit.
AC: current metadata says exactly 1.5; original 1.4.3 verification remains historical; #10 stays excluded.
Dependencies: none known.
Notes/Risks: existing PR #21 and branch remain the delivery boundary.

### R2. Verified release build and delivery workflow

Status: needs_design
Goal: publish a validated production package to WordPress.org when a stable GitHub release is published.
Scope: release workflow, necessary build/validation helpers, focused checks and operational documentation; adapt evidenced practices from both reference repositories.
Out of Scope: running a live deployment, changing credentials, broad product changes or deferred E2.
DoR: reference mapping and concrete trigger/build/verification/retry contract are recorded before implementation.
DoD: static checks and actual isolated package build/negative scenarios; reviewed local commit; no publication during verification.
AC: production dependencies only; coherent release tag/version metadata; no development/sensitive artifacts; stable/prerelease isolation; least necessary permissions and release serialization; safe repeat/failure behavior; existing distribution branch contract preserved unless explicitly changed; no secrets needed for PR validation.
Dependencies: R1; source mapping.
Notes/Risks: existing WPORG_USERNAME/WPORG_PASSWORD secret names are present; values and authentication validity have not been inspected or tested.

### R3. Independent QA and update PR #21

Status: waiting_dependency
Goal: deliver the verified 1.5 automation for review.
Scope: independent release/security QA, actual check evidence, scoped commits/push and PR title/body update.
Out of Scope: merge, release creation, WordPress.org publication or issue closure.
DoR: R1/R2 implementation frozen and required checks complete.
DoD: QA pass/pass_with_notes, exact pushed revision and PR link, truthful CI/publication status.
AC: PR describes final trigger, package, checks, required secret names and remaining publication boundary; no unverified deployment claim; deferred work remains excluded.
Dependencies: R1, R2.
Notes/Risks: remote CI may require a bounded repair; no automatic waiver.

## Evidence

- R1 accepted for its scoped commit: header, VERSION, Stable tag and current changelog consistently use 1.5. PHP lint of the changed entrypoint, targeted metadata consistency and diff checks pass; no product behavior changed. Root updated delivery bookkeeping only. Release automation remains R2; no new runtime or publication result is inferred from the version edit.
- Starting revision `f4d40e41b1589949397e6f6c90a295808bf90b42`, PR #21 open. All five existing GitHub integration targets passed on this revision (both push and pull-request runs), including the previously deferred fresh WP6.8 targets.
- Existing distribution branches `stable` and `pre-release` are present. Existing deployment workflow triggers on `release.published`; its write-all permissions and mixed build/deploy steps require a bounded redesign within the same delivery trigger.
