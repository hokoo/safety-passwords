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

### R2a. Validated release source and package

Status: completed
Goal: one validated installable package from the intended source revision.
Scope: narrow source/tag/version validator, staging build, ZIP validator, focused negative checks and contributor instructions.
Out of Scope: publication helpers, workflows, product behavior and E2.
DoR: R1 accepted; reference mapping complete.
DoD: static checks, actual isolated Composer production build and artifact/autoload/negative checks via monitor; reviewed scoped commit.
AC: stable tags accept existing v1.5/two-or-three-part numeric convention; tag commit must belong to master for publication; header/constant/Stable tag/changelog agree. Build only plugin files and locked production dependencies in fresh staging, with no root Composer scripts or workspace deletion. One safe ZIP root, required runtime/autoload files, exact checksum/source manifest, no unsafe archive paths or development/sensitive files; repeat builds reproducible for retry comparison. PR validation needs no publication credentials.
Dependencies: R1 and pinned reference sources below.
Notes/Risks: prerelease packages are allowed only as prereleases and never authorize WordPress.org delivery.

### R2b. Automatic release delivery and retry behavior

Status: ready
Goal: stable release publication automatically delivers the verified artifact to WordPress.org.
Scope: replace existing release workflow, focused publish/SVN/mirror helpers, PR build validation, operational instructions and failure/retry verification.
Out of Scope: actual public release, secrets/configuration writes, deferred E2, new tag-triggered release creation.
DoR: accepted R2a package/manifest interface.
DoD: workflow/static validation; isolated local-SVN and mocked GitHub/mirror success/failure/retry scenarios; credentialless public SVN dry-run if reachable; independent QA follows.
AC: retain release.published trigger and stable/pre-release mirrors; stable source/version and required CI gates precede mutations; validate the downloaded artifact/checksum; production secrets only in stable deploy job; read-only defaults and minimum job permissions, pinned actions, serialized non-cancelling deployment. Existing release asset/SVN tag must match or fail, never silently overwrite; prevent stale release rollback. Update stable mirror only after successful SVN delivery; prereleases never touch SVN. Dry-run never mutates GitHub/WordPress.org and requires no real secrets. Preserve existing assets unless intentionally supplied.
Dependencies: R2a.
Notes/Risks: WPORG_USERNAME/WPORG_PASSWORD names exist; values/authentication validity remain untested. Use password stdin for SVN, no secret argv or logs. No claim that local tests prove real credentials or actual deployment.

### R3. Independent QA and update PR #21

Status: waiting_dependency
Goal: deliver the verified 1.5 automation for review.
Scope: independent release/security QA, actual check evidence, scoped commits/push and PR title/body update.
Out of Scope: merge, release creation, WordPress.org publication or issue closure.
DoR: R1/R2a/R2b implementation frozen and required checks complete.
DoD: QA pass/pass_with_notes, exact pushed revision and PR link, truthful CI/publication status.
AC: PR describes final trigger, package, checks, required secret names and remaining publication boundary; no unverified deployment claim; deferred work remains excluded.
Dependencies: R1, R2a, R2b.
Notes/Risks: remote CI may require a bounded repair; no automatic waiver.

## Evidence

- R2a accepted after repair on `9debab3`: source verification, two isolated production builds, both validations, ZIP and manifest byte comparisons, and `check_package.py` negative/local-publication-ref scenarios all passed. Both ZIPs contain 266 entries / 1,869,039 bytes, SHA-256 `59413cd6188c4d21339811c63d506d31af51961ad857c3b3c56e3fcee41eba98`; artifacts remain in `/tmp/sp-release-a.yn6Iqa` and `/tmp/sp-release-b.dBDT2Q`. Composer's random autoloader suffix was replaced only in temporary staging with a source-derived value. Frozen files, HEAD and working-tree paths stayed unchanged during verification. The initial escalation timed out before process creation; read-only verification then passed in the default sandbox. No publication occurred. R2b is now ready.
- R2a first runtime ladder on `9debab3`: source verification, build A, validation A and build B passed; byte comparison failed (266 entries in each archive, differing ZIP hashes). Manifest comparison and negative scenarios did not run after that failure. Four frozen helper/readme hashes and status stayed unchanged. Temporary archives are retained under `/tmp/sp-release-a.6ICERu` and `/tmp/sp-release-b.bLhBIA` for a bounded reproducibility repair; no publication or workspace dependency mutation occurred.
- Official actionlint v1.7.12 archive checksum was verified and its binary reported 1.7.12 at `/tmp/safety-passwords-actionlint.3Dqak6/actionlint`. This is tool readiness only, not workflow validation. Verified archive SHA-256: `8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8`.
- Official action pins resolved read-only for the planned Ubuntu 22.04 release workflow: checkout v4.4.0 `11d5960a326750d5838078e36cf38b85af677262`; upload-artifact v4.6.2 `ea165f8d65b6e75b540449e92b4886f43607fa02`; download-artifact v4.3.0 `d3f86a106a0bac45b974a628896c90dbdf5c8093`; setup-php 2.37.2 `f3e473d116dcccaddc5834248c87452386958240`. Official actionlint v1.7.12 has a Linux amd64 binary and checksum list; the monitor must verify the checksum before using it. No installation or execution is claimed yet.
- Reference decisions: [cf7-telegram at 10b285c](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/.github/workflows/build-zip.yml) supplies release.published, staged production dependencies and deploy-before-mirror sequencing. [wp-site-options at cac5cde](https://github.com/hokoo/wp-site-options/blob/cac5cdebbfbbb574a172b04316c3c7f624b5419b/.github/workflows/release.yml) supplies strict source/version checks, artifact identity and exact-content retry guards. Do not adopt tag-triggered release creation, vendor-free packaging, blind asset clobber, or credentials in process arguments. Existing published tags include v1.4 and v1.4.2, so v1.5 is compatible.
- The existing 10up stable helper requires credentials even for dry-run and treats an existing SVN tag as success without content comparison. Use a bounded standard-SVN helper informed by the reference implementation to establish the required exact-content/credentialless-dry-run contract; no custom authentication or cryptography.
- R1 accepted for its scoped commit: header, VERSION, Stable tag and current changelog consistently use 1.5. PHP lint of the changed entrypoint, targeted metadata consistency and diff checks pass; no product behavior changed. Root updated delivery bookkeeping only. Release automation remains R2; no new runtime or publication result is inferred from the version edit.
- Starting revision `f4d40e41b1589949397e6f6c90a295808bf90b42`, PR #21 open. All five existing GitHub integration targets passed on this revision (both push and pull-request runs), including the previously deferred fresh WP6.8 targets.
- Existing distribution branches `stable` and `pre-release` are present. Existing deployment workflow triggers on `release.published`; its write-all permissions and mixed build/deploy steps require a bounded redesign within the same delivery trigger.
