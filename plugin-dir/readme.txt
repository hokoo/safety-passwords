=== Safety Passwords ===
Contributors: hokku
Tags: user passwords,secure passwords,enforce secure passwords,force secure passwords,secure password validation
Donate link: https://www.paypal.me/igortron
Requires at least: 5.0
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Require stronger passwords and prevent password reuse.

== Description ==
Safety Passwords helps protect WordPress accounts by checking new passwords. Each password must meet the minimum length set by the site administrator and contain an uppercase letter, a lowercase letter, a number, and a special character. Users cannot reuse a password already in their history.

The administrator can also require a password change after a chosen number of days. Users see a countdown on their profile and a reminder in the admin bar during the last seven days. If the password expires, the plugin asks them to change it or use the password recovery form. Set the reset interval to 0 to turn off periodic resets and reminders.

Installing Safety Passwords as a must-use (MU) plugin is recommended. It loads automatically, and WordPress does not show a Plugins-screen control to deactivate it. A filesystem administrator can still remove the loader. See Installation below.

On WordPress multisite, network administrators manage one set of settings for the current network. Install it as MU or network activate it; activation on an individual site is not supported. WordPress shares an account's password across networks, so a password reset on one network affects that account everywhere. See the multisite FAQ for which accounts each network checks.

The plugin can also record events with the Stream plugin.

[Developers and contributors can find setup instructions on GitHub](https://github.com/hokoo/safety-passwords#developer-quick-start).

= Override settings with PHP constants =

PHP constants override saved settings; the settings page shows their effective values:

* `SAFETY_PASSWORDS_MIN_LEN`: integer or whole-number string for minimum length; the settings field accepts 1-24.
* `SAFETY_PASSWORDS_RESET_INTERVAL`: integer or whole-number string for days between required resets; the settings field accepts 0-999, and 0 disables periodic resets and reminders.
* `SAFETY_PASSWORDS_RP_ON_REGISTRATION`: `true`, `'true'`, `1`, or `'1'` enables a reset after registration; `false`, `'false'`, `0`, or `'0'` disables it.

WordPress `wp_validate_boolean()` interprets the registration constant; other strings such as `'off'` or `'no'` evaluate to true. The numeric constants accept whole-number strings without changing the settings ranges; decimal strings are not converted to integers.

== Screenshots ==
1. Settings page
2. Settings are overridden by PHP constants
3. Password change notification
4. Password change urgency notification
5. Seamless password change form
6. Weak password is not allowed

== Installation ==
= Must-use installation (recommended) =

Place the release package's `safety-passwords` directory in `/wp-content/plugins/`. Create `/wp-content/mu-plugins/` if it does not exist, then create `/wp-content/mu-plugins/safety-passwords-loader.php` containing:

`<?php require_once WP_PLUGIN_DIR . '/safety-passwords/safety-passwords.php';`

WordPress loads this file automatically. If you cannot manage site files, ask your site administrator or hosting provider to set up the loader.

= Standard installation =

Place the release package's `safety-passwords` directory in `/wp-content/plugins/` and activate Safety Passwords from the Plugins screen. On multisite, network activate it rather than activating it on an individual site. The first normal WordPress request completes setup; no manual command is needed.

== Frequently Asked Questions ==

= What happens when a password expires? =

The periodic check starts a required reset and attempts one recovery email. Later checks keep the reset pending, even if that email fails; they do not retry the email. A successful reset or profile password change clears the pending state and renews the period. Before expiry, the profile shows a countdown and the admin bar shows a reminder in the final seven days. After expiry, they ask for a password change without a countdown; if a reset is already pending, they point to the recovery form. An interval of 0 disables periodic resets and these reminders.

= How does this work on multisite? =

Settings apply to the current network. Only network administrators with `manage_network_options` can change them. On a single-network installation, periodic and manual checks include every account, even one without site membership. On a multi-network installation, each network checks accounts with membership on at least one of its sites, including inactive sites. It excludes accounts assigned only to another network or to no site. Passwords and password history belong to the WordPress account globally, so a reset of an account shared by networks affects it everywhere.

The plugin keeps one periodic event on each network's main site. When scheduling or deactivating, it removes older events on that network's subsites. Administrator roles on existing and new sites retain the plugin's settings capability. MU setup runs in each network's own context; loading one network does not initialize all networks.

= When should I use Initialize / repair? =

Normally, no manual action is needed. The first eligible request records current password history, grants administrator capabilities, and schedules checks. A changed plugin version triggers automatic setup on the next eligible request, including after file, Composer, or CI deployment, if the new code loads against the target database. Failed setup retries with bounded delays. Eligible requests also repair missing or duplicate periodic events. A code change that requires a fresh full initialization must advance the plugin version marker.

The settings page shows its diagnostic panel only while setup is pending, running, or failed, or the schedule is degraded. A ready installation with a healthy schedule or routine automatic verification due shows no panel. Use **Initialize / repair** after an error or degraded schedule to retry now. It repairs capabilities, current password history, and the schedule for the current network without rotating passwords. It is also needed after restoring a same-version MU installation to include accounts added during its absence. It cannot reconstruct password changes made while the plugin was absent. If repair fails, resolve the cause and retry; automatic retries remain available.

The button requires a POST request, nonce, and `manage_options` or the plugin management capability on a single site, or `manage_network_options` on multisite. `wp safety init --url=<main-site-url>` performs the same repair or gives a checked result before opening traffic. It needs a working WordPress bootstrap and database, and exits with an error if setup cannot finish, including while another process holds the initialization lock. For multiple networks, use each network's main-site URL separately.

= When do periodic checks run? =

Periodic resets use WP-Cron. WordPress requests or an external cron trigger are needed for them to run on time. Automatic setup and schedule repair also require the plugin to load; they cannot run while the site is idle. Standard plugin deactivation removes the periodic schedule and invalidates setup state for later activation.

= How do I remove or restore an MU installation? =

Remove the MU loader and deactivate any separately active standard copy. Removing the loader does not run deactivation, so manually remove the `safety_passwords_periodically_reset` event from the network's main site and any legacy subsites. Saved settings and password history remain. If the same-version loader returns, its retained setup marker does not trigger a full history pass. Run **Initialize / repair** or `wp safety init --url=<main-site-url>` to include current passwords for accounts added during the gap. Password changes made while the code was absent cannot be reconstructed.

= What happens when an administrator changes a password with WP-CLI? =

With the plugin loaded, `wp user update` with a password and `wp user reset-password` complete any pending reset, renew the period, and record the saved password in history. These administrative changes bypass password strength and reuse checks; WP-CLI warns on stderr when a password is changed. Later changes through web forms still check password history. Changes made with the plugin skipped cannot update its history or reset state, and WP-CLI changes made before version 1.5 are not repaired automatically.

= What is recorded in logs? =

The plugin integrates with Stream. Current password reset logs use fixed failure categories and aggregate periodic reset and reminder counts, without account identifiers or WordPress error details. `wp safety check-users` likewise logs only aggregate counts and reports `Success: Done.` Older Stream records may contain details from previous versions. Site operators should review those records privately under their retention policy; updating the plugin does not remove them.

= Are older WordPress versions supported? =

Background reset emails and silent reset links also work on WordPress 5.0 through 5.6, where the login-page recovery function is unavailable during periodic checks.

= Can I install from a source checkout? =

The release package includes runtime dependencies. If installing from a source checkout instead, run `composer install --no-dev --no-scripts` in `plugin-dir/` before WordPress loads the plugin.


== Changelog ==
= 1.5 =
* Complete ordinary and must-use setup on normal requests, automatically repair scheduling, and retain current-network policy.
* Correct password expiry notices, repeated reset handling, constant overrides, and privacy-safe logging.
* Track standard WP-CLI password changes in history and expiry state, with an explicit strength and reuse bypass warning.
* Test WordPress 7.1.2 with PHP 8.5 and 8.2; retain PHP 7.4 and WordPress 5 compatibility.

= 1.4.2 =
* Dependencies updated.

= 1.4.1 =
* Put currently used passwords to the stop list on activation.

= 1.4 =
* Previously used password are not allowed to use again.

= 1.3 =
* Fatal error on cron event fixed in php 8. https://github.com/hokoo/safety-passwords/issues/6
* Text of a log message fixed.
* php.ini added for local dev.
* error log watcher git fixed for local dev.

= 1.2 =
* Stream plugin integration fixed. https://github.com/hokoo/safety-passwords/issues/11

= 1.1 =
* Failing to set 0 as the password reset interval and 1 as the minimum password length fixed.

= 1.0 =
* Initial release
