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

The three supported PHP constants override saved settings and show their effective values on the settings page:

| Constant | Supported values | Effect |
| --- | --- | --- |
| `SAFETY_PASSWORDS_RP_ON_REGISTRATION` | `true`, `'true'`, `1`, `'1'`; `false`, `'false'`, `0`, `'0'` | Enables or disables a reset after registration using WordPress `wp_validate_boolean()` semantics. Strings such as `'off'` and `'no'` evaluate to true. |
| `SAFETY_PASSWORDS_MIN_LEN` | Integer or whole-number string; settings field accepts 1–24 | Minimum password length. Decimal strings are not converted to integers. |
| `SAFETY_PASSWORDS_RESET_INTERVAL` | Integer or whole-number string; settings field accepts 0–999 | Days between required resets; 0 disables periodic resets and reminders. |

The constants retain priority over stored Carbon Fields options. Numeric string normalization does not add range validation beyond the existing settings fields.

The expiry reminders in the admin bar and the user's own profile use the same elapsed-time calculation. They show a countdown before the deadline, a change-password notice once it is due, and a separate reset-required notice after a reset starts. A zero reset interval hides both reminders. Rendering these notices does not update user metadata or initiate a reset.

The periodic check starts a mandatory reset and attempts one recovery email for an expired account. Later checks leave that reset pending, including after a failed email attempt. A successful reset or profile password change clears the pending state and renews the expiry date. This profile cleanup also applies on supported WordPress versions before 6.3.

On WordPress 5.0 through 5.6, background reset requests use the core reset-key and mail APIs because the login-page recovery function is not loaded during cron or WP-CLI checks. Newer versions use the core recovery function directly.

On multisite, Safety Passwords reads its settings from the current network's settings page. With one network, the periodic check and manual `wp safety check-users` cover every account, including accounts that belong to no site. With multiple networks, they cover only accounts with site membership in the current network, including archived, spam, or deleted sites; other-network-only and globally unassigned accounts are excluded. An account shared by networks has one global WordPress password, so a reset by either network affects that account everywhere. Each network keeps one periodic event on its own main site; scheduling or deactivating from a subsite also removes older duplicate events on subsites in that network. Only network administrators with `manage_network_options` can open or save the network settings. The plugin continues to grant its settings capability to administrator roles on existing and newly created sites.

For MU use, leave the plugin directory in `wp-content/plugins/safety-passwords/` and place a root-level PHP loader in `wp-content/mu-plugins/` that requires `WP_PLUGIN_DIR . '/safety-passwords/safety-passwords.php'` (see `dev/tests/fixtures/mu-loader.php`). The first request after Carbon Fields registers its fields grants capabilities, seeds current password history, and installs one periodic event on the main site, without ordinary activation or a settings-page visit. An interrupted first setup can retry after its 15-minute private option lock expires. Ordinary activation continues to defer its setup to the next request. Deactivation removes the scheduler and invalidates the MU completion marker so a later MU install includes accounts added while the plugin was inactive.

When upgrading an existing ordinary installation to this network policy, reactivate the plugin once. Its deferred next-request phase seeds history for accounts in scope, refreshes site capabilities, and establishes the main-site schedule. Visiting the settings page alone checks scheduling and does not seed history. MU installations and upgrades initialize without this step.

To remove an MU installation, remove its loader, deactivate any separately active ordinary copy, and delete the `safety_passwords_periodically_reset` event from the main site and any legacy subsites using `wp cron event delete safety_passwords_periodically_reset --url=<site-url>`. Physical loader deletion does not call deactivation and cannot clean up scheduled events automatically. Before reinstalling a physically removed loader, run `wp option delete safety_passwords_mu_initialized --url=<main-site-url>` so accounts added while it was absent are included in the next bootstrap. Password history and saved settings remain.

## Isolated WordPress integration checks

Install only the plugin dependencies first, then run the same command used by CI:

```bash
cd plugin-dir && composer install --no-scripts && cd ..
bash dev/tests/run.sh php74-wp50
bash dev/tests/run.sh php74-wp68
bash dev/tests/run.sh php82-wp68
```

Run a target twice to confirm repeatability. The targets cover PHP 7.4 with WordPress 5.0 and 6.8, and PHP 8.2 with WordPress 6.8. Each run creates its own Compose project, database and WordPress volume, then removes that project on exit. It does not use the development `.env`, `wp-config.php`, database, or `dev/setup.sh`. Only the download container has internet access; it fetches WordPress core and the Composer lock-pinned official Stream 4.0.0 archive into the disposable site volume. The WordPress test container and database are on a private internal network. The test MU plugin intercepts all mail before WordPress loads its mail function. The runner rejects nonlocal Docker targets and refuses to reuse an existing test project. Do not run it against a real WordPress installation.

The runner selects two `/24` subnets from `10.254.0.0/16` after inspecting existing Docker networks and local IPv4 routes and interfaces. It refuses to create a target if that inspection fails or fewer than two free subnets remain.

The cron scenario checks plugin boot, one `twicedaily` event, repeat scheduling, removal, and the enabled and zero interval callback paths. The activation scenario checks the deferred setup of the periodic event and initial password history on the next normal request. Nine separate WordPress bootstraps check boolean and numeric constants against registration flags, reset minimum length, cron reminders, conflicting saved options, and settings display. The MU scenarios check held and expired startup locks, repeat requests, ordinary/MU transitions, and network bootstrap for unassigned and subsite accounts. The runner then converts its disposable installation to multisite and checks network settings, all-account coverage, site capabilities, authorization, main-site scheduling, duplicate cleanup, and deactivation. Its final phase checks the fallback without Stream, then activates real Stream 4.0.0 and verifies one deferred and one immediate connector record, plus the custom logger override. A temporary MU preloader filters records before Stream activation and replaces identifying fields and metadata with fixed synthetic values before insertion. The Stream plugin and filter exist only in the disposable volume. A remote CI result is available only after the workflow has run on GitHub.
