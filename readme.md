# DEV Environment for Safety Passwords WordPress plugin

## Requirements
Linux or WSL2, Make, Docker Compose. The isolated integration runner also needs Python 3 and `iproute2` (`ip`).

## Notice
Call all commands from root project directory.

## Installation

```bash
bash ./dev/init.sh && make docker.up
make connect.php 
composer install
```

Don't forget update your hosts file
`127.0.0.1 safetypasswords.local`.

## Development
WP plugin directory `plugin-dir`.

For production, we recommend a must-use (MU) loader because the Plugins screen cannot deactivate it. A filesystem administrator can still remove the loader. Keep the plugin in `wp-content/plugins/safety-passwords/` and put a root-level `wp-content/mu-plugins/safety-passwords-loader.php` containing:

```php
<?php
require_once WP_PLUGIN_DIR . '/safety-passwords/safety-passwords.php';
```

The release ZIP includes the plugin's runtime dependencies. If deploying a source checkout instead, run `composer install --no-dev --no-scripts` in `plugin-dir/` before WordPress loads the plugin. Ordinary installation through the Plugins screen remains supported, including network activation. On multisite, use network activation or an MU loader: policy and settings apply to the current network, not to a single subsite.

The three supported PHP constants override saved settings and show their effective values on the settings page:

| Constant | Supported values | Effect |
| --- | --- | --- |
| `SAFETY_PASSWORDS_RP_ON_REGISTRATION` | `true`, `'true'`, `1`, `'1'`; `false`, `'false'`, `0`, `'0'` | Enables or disables a reset after registration using WordPress `wp_validate_boolean()` semantics. Strings such as `'off'` and `'no'` evaluate to true. |
| `SAFETY_PASSWORDS_MIN_LEN` | Integer or whole-number string; settings field accepts 1–24 | Minimum password length. Decimal strings are not converted to integers. |
| `SAFETY_PASSWORDS_RESET_INTERVAL` | Integer or whole-number string; settings field accepts 0–999 | Days between required resets; 0 disables periodic resets and reminders. |

The constants retain priority over stored Carbon Fields options. Numeric string normalization does not add range validation beyond the existing settings fields.

The expiry reminders in the admin bar and the user's own profile use the same elapsed-time calculation. They show a countdown before the deadline, a change-password notice once it is due, and a separate reset-required notice after a reset starts. A zero reset interval hides both reminders. Rendering these notices does not update user metadata or initiate a reset.

The periodic check starts a mandatory reset and attempts one recovery email for an expired account. Later checks leave that reset pending, including after a failed email attempt. A successful reset or profile password change clears the pending state and renews the expiry date. This profile cleanup also applies on supported WordPress versions before 6.3.

When Safety Passwords is loaded, `wp user update` with a saved password and `wp user reset-password` record the saved WordPress hash in the account's password history, renew expiry, and clear pending reset flags. These administrative changes bypass both strength and reuse checks. One generic warning on stderr per command reports that bypass and the history and expiry update; ordinary command stdout is unchanged. Subsequent web password changes still enforce both checks. A multisite account has one global password and history, including when it belongs to no site, so the update applies across its sites. Commands run with the plugin skipped do not update its state. This version does not reconstruct password changes made before installation or upgrade.

On WordPress 5.0 through 5.6, background reset requests use the core reset-key and mail APIs because the login-page recovery function is not loaded during cron or WP-CLI checks. Newer versions use the core recovery function directly.

Password reset logging reports fixed failure categories (`reset_request_failed`, `mail_delivery_failed`, or `unexpected_result`) and aggregate periodic reset and reminder counts. The `wp safety check-users` command also logs only aggregate counts; its `Success: Done.` output is unchanged. Logs do not include account identifiers or WordPress error details. Older Stream records may contain details from previous plugin versions; assess existing records privately under your site's retention policy. This update does not remove them.

On multisite, Safety Passwords reads its settings from the current network's settings page. With one network, the periodic check and manual `wp safety check-users` cover every account, including accounts that belong to no site. With multiple networks, they cover only accounts with site membership in the current network, including archived, spam, or deleted sites; other-network-only and globally unassigned accounts are excluded. An account shared by networks has one global WordPress password, so a reset by either network affects that account everywhere. Each network keeps one periodic event on its own main site; scheduling or deactivating from a subsite also removes older duplicate events on subsites in that network. Only network administrators with `manage_network_options` can open or save the network settings. The plugin continues to grant its settings capability to administrator roles on existing and newly created sites.

For MU use, the first eligible WordPress request grants administrator capabilities, records current password history, and installs one periodic event on the current network's main site, without ordinary activation or a settings-page visit. Ordinary activation completes setup on the next normal WordPress request; its deferred activation event remains a fallback. Ordinary deactivation removes the schedule and invalidates initialization state so a later activation includes accounts added while the plugin was inactive.

Ordinary and MU installations detect a changed plugin `VERSION` on the next eligible request and initialize the current network automatically. This also applies when new files arrive through Composer, CI, or another file-based deployment: the target WordPress installation and database must be available when a request loads the new code, but the build pipeline need not run WordPress or WP-CLI. A deployment that needs a fresh full initialization must advance the plugin version marker; replacing code without changing `VERSION` does not trigger that pass. Neither reactivation nor visiting settings is required. A failed initialization is retryable with bounded automatic delays; the settings page shows its state and schedule health. Ready installations also check and repair a missing or duplicate periodic event on eligible requests, with a less frequent full network schedule check.

Use the settings page's **Initialize / repair** button when you need to repair the current network immediately, including after a failed automatic attempt or a return from a same-version MU removal. It repairs capabilities, current password history and the schedule without rotating passwords. The action is POST-only and requires a nonce and `manage_options` or the plugin management capability on a single site, or `manage_network_options` on multisite. `wp safety init --url=<main-site-url>` is an optional command for the same repair or for an operator who wants an explicit success or failure result before opening traffic. It requires a working WordPress bootstrap and target database, succeeds idempotently, and exits nonzero if initialization fails or another process holds the initialization lock. In a multi-network installation, choose each network's main-site URL separately; one invocation never scans all networks. This does not change `wp safety check-users` behavior.

The periodic reset check runs through WP-Cron, which needs WordPress requests or an external cron trigger to execute on time. Automatic setup and schedule repair occur when the plugin loads; they do not make WP-Cron run while the site is idle. To remove an MU installation, remove its loader, deactivate any separately active ordinary copy, and delete the `safety_passwords_periodically_reset` event from the main site and any legacy subsites using `wp cron event delete safety_passwords_periodically_reset --url=<site-url>`. Physical loader deletion does not call deactivation: the absent code cannot clean up the event or record accounts added while it is absent. If the same-version loader returns, the retained version marker does not trigger a full history pass. Use **Initialize / repair** or `wp safety init --url=<main-site-url>` to include current passwords for accounts added during the gap; password changes made while the code was absent cannot be reconstructed. Saved settings and existing history remain.

## Isolated WordPress integration checks

The local 1.5 candidate contains the accepted lifecycle, expiry, constants, logging, and integration work. Bulk reset controls and enforced next-login behavior are deferred to a separate E2 delivery; see `docs/plans/2026-09-23-release-candidate.md` for the exact cutoff and pending gates.

Install only the plugin dependencies first, then run the same targets used by CI. WordPress 7.1.2 on PHP 8.5 and 8.2 is the primary tested matrix. WordPress 5.0 and PHP 7.4 remain supported and tested, but WordPress 5 is deprecated for future development. The plugin readme's `Tested up to` value is 7.1.2.

```bash
cd plugin-dir && composer install --no-scripts && cd ..
bash dev/tests/run.sh php85-wp712
bash dev/tests/run.sh php82-wp712
bash dev/tests/run.sh php74-wp50
bash dev/tests/run.sh php74-wp68
bash dev/tests/run.sh php82-wp68
```

Run a target twice to confirm repeatability. The new targets pin WordPress core to 7.1.2 and use the official CLI images for PHP 8.5 and 8.2. The existing targets retain PHP 7.4 with WordPress 5.0 and 6.8, and PHP 8.2 with WordPress 6.8. Each run creates its own Compose project, database and WordPress volume, then removes that project on exit. It does not use the development `.env`, `wp-config.php`, database, or `dev/setup.sh`. Only the download container has internet access; it fetches WordPress core and the Composer lock-pinned official Stream 4.0.0 archive into the disposable site volume. The WordPress test container and database are on a private internal network. The test MU plugin intercepts all mail before WordPress loads its mail function. The runner rejects nonlocal Docker targets and refuses to reuse an existing test project. Do not run it against a real WordPress installation.

The runner selects two `/24` subnets from `10.254.0.0/16` after inspecting existing Docker networks and local IPv4 routes and interfaces. It refuses to create a target if that inspection fails or fewer than two free subnets remain.

## Local release package preparation

Release preparation uses a committed source SHA, not the current `plugin-dir/vendor` or uncommitted plugin files. The builder exports the tracked plugin files into a fresh temporary stage and runs Composer there with `--no-dev --no-scripts --no-plugins --prefer-dist --no-interaction`. It produces `safety-passwords-wp-plugin.zip` with one `safety-passwords/` root and a companion JSON manifest of the source SHA, version, file checksums and ZIP checksum. The existing GitHub release asset basename is retained. These commands only prepare and validate a local package; they do not tag, publish or deploy it.

```bash
source_sha=$(git rev-parse HEAD)
release_dir=$(mktemp -d /tmp/sp-release.XXXXXX)
python3 dev/release/source.py verify --source-sha "$source_sha"
python3 dev/release/package.py build --source-sha "$source_sha" --output-dir "$release_dir"
python3 dev/release/package.py validate --source-sha "$source_sha" \
  --zip "$release_dir/safety-passwords-wp-plugin.zip" \
  --manifest "$release_dir/safety-passwords-wp-plugin.manifest.json"
python3 dev/release/tests/check_package.py --source-sha "$source_sha" \
  --zip "$release_dir/safety-passwords-wp-plugin.zip" \
  --manifest "$release_dir/safety-passwords-wp-plugin.manifest.json"
```

For a published stable release, first verify the exact public tag and current `master` ancestry with `source.py verify --source-sha "$source_sha" --tag v1.5 --publication`; then pass the same `--tag v1.5` to build and validate. Tags may use `v` or no prefix and two or three numeric version parts, but must match the plugin header, `VERSION`, Stable tag and current changelog exactly. An `-rc.N` or `-beta.N` tag requires `--prerelease` on every command and must remain a prerelease outside WordPress.org delivery. A caller handling a downloaded artifact should pass its independently trusted digest to validation with `--expected-zip-sha256`; the companion manifest alone is not that trust source. Build twice into separate temporary directories and compare ZIP and manifest checksums before relying on reproducibility.

The prepared release workflow responds only to a **published GitHub release**; opening or merging PR #21 does not publish one. Its initial verification and delivery helpers run from a pinned public `master` checkout, while the release event SHA identifies the candidate to verify against the public tag and `master` ancestry. The candidate's ZIP is built and tested without publication credentials. This boundary does not protect secrets from someone authorized to change the workflow itself; repository access controls remain necessary. The workflow builds one production ZIP and manifest, and runs all five isolated WordPress targets against that same downloaded, checksum-verified ZIP. Before any write it checks existing GitHub assets and the target distribution branch, and performs a credentialless stable SVN dry run. Stable releases then make one SVN trunk/tag commit, upload only missing exact GitHub assets, and update `stable`; prereleases upload exact assets and update `pre-release` without touching SVN. An existing ZIP, SVN tag or mirror version is an exact no-op only when content matches; a mismatch or rollback attempt fails. Unrelated GitHub release assets and WordPress.org assets are preserved. A failed later stage can be retried against the same release after the underlying cause is fixed; the helpers do not overwrite mismatched prior output.

The workflow uses `GITHUB_TOKEN` for its write-scoped delivery jobs. Only stable delivery targets the `wordpress-org` GitHub environment ([plugin directory](https://wordpress.org/plugins/safety-passwords/)); prereleases bypass that environment and SVN. The environment's `WPORG_USERNAME` and `WPORG_PASSWORD` values are fake placeholders that must be replaced before a real stable release. Existing repository secrets are unchanged, and only the stable SVN commit step receives the environment values; SVN reads the password on stdin, not in a command argument. GitHub will show a Deployment when stable delivery actually runs; none has been started here. To assess public SVN preparation without credentials or publication, use a tagged local package and run `python3 dev/release/svn.py --source-sha "$source_sha" --tag v1.5 --version 1.5 --zip "$release_dir/safety-passwords-wp-plugin.zip" --manifest "$release_dir/safety-passwords-wp-plugin.manifest.json" --expected-zip-sha256 <independently-verified-digest> --dry-run`. This automation is prepared locally; no real release, SVN deployment, secret validity, or live retry is claimed here.

The cron scenario checks plugin boot, one `twicedaily` event, repeat scheduling, removal, and the enabled and zero interval callback paths. The activation scenario checks setup of the periodic event and initial password history on the next normal request. Nine separate WordPress bootstraps check boolean and numeric constants against registration flags, reset minimum length, cron reminders, conflicting saved options, and settings display. The MU scenarios check held and expired startup locks, repeat requests, ordinary/MU transitions, and network bootstrap for unassigned and subsite accounts. The runner then converts its disposable installation to multisite and checks network settings, all-account coverage, site capabilities, authorization, main-site scheduling, duplicate cleanup, and deactivation. Its final phase checks the fallback without Stream, then activates real Stream 4.0.0 and verifies one deferred and one immediate connector record, plus the custom logger override. A temporary MU preloader filters records before Stream activation. The failure-path phase rejects unsafe plugin payloads before insertion, retains their categories and counts, and clears Stream's actor fields. The Stream plugin and filter exist only in the disposable volume. A remote CI result is available only after the workflow has run on GitHub.
