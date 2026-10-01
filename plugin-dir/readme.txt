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

Enforce users to use strong passwords.

== Description ==
This plugin enforces users to use strong passwords. It means that when a user changes his password, the password must contain at least:

 * one uppercase letter;
 * one lowercase letter;
 * one number;
 * one special character

 and should be never used before.

The minimum length of the password is defined by the plugin's settings.

You can also define the period of time after which the user will be forced to change his password.

After the period expires, the periodic check starts a mandatory reset and attempts to send one recovery email. Later checks leave that reset pending, even if the email attempt fails. A successful password reset or profile password change clears the pending state and renews the period.

With this plugin loaded, `wp user update` with a password and `wp user reset-password` also complete a pending reset, renew the period, and record the saved password in history. These administrative WP-CLI changes bypass both password strength and password reuse checks; a warning on stderr states this when a password is changed. Later changes through the web forms still check the history. WordPress passwords and this plugin's history are global to an account on multisite, including accounts without site membership. WP-CLI changes made before this version are not repaired automatically. Commands run with the plugin skipped cannot update its history or reset state.

Background reset emails and silent reset links also work on WordPress 5.0 through 5.6, where the login-page recovery function is unavailable during periodic checks.

Password reset logs contain fixed failure categories and aggregate periodic reset and reminder counts, without account identifiers or WordPress error details. The `wp safety check-users` command also logs only aggregate counts and still reports `Success: Done.` Older Stream records may contain details from previous plugin versions; assess them privately under your site's retention policy. This update does not remove them.

On multisite, settings are shared across the current network and can be changed only by network administrators with `manage_network_options`. With one network, periodic and manual checks include all accounts, even those assigned to no site. With multiple networks, they include only accounts with a site membership in the current network, including membership on inactive sites; accounts assigned only to another network or to no site are excluded. An account shared by networks has one WordPress password, so a reset from either network affects that account everywhere. A single periodic event is kept on each network's main site, and older subsite events in that network are removed when scheduling or deactivating. Administrator roles on existing and new sites retain the plugin's settings capability.

Your own profile shows a countdown before the period ends, and the admin bar adds a reminder during the final seven days. Once the period ends, they ask you to change your password without showing a countdown. If a password reset has already been initiated, they ask you to use the password recovery form. Setting the reset interval to 0 hides these reminders.

PHP constants override saved settings and show their effective values on the settings page:

| Constant | Supported values | Effect |
| --- | --- | --- |
| <code>SAFETY_PASSWORDS_MIN_LEN</code> | Integer or whole-number string; settings field accepts 1-24 | Minimum password length. |
| <code>SAFETY_PASSWORDS_RESET_INTERVAL</code> | Integer or whole-number string; settings field accepts 0-999 | Days between required resets; 0 disables periodic resets and reminders. |
| <code>SAFETY_PASSWORDS_RP_ON_REGISTRATION</code> | true, 'true', 1, '1'; false, 'false', 0, '0' | Enables or disables a reset after registration. |

The plugin interprets the registration constant with WordPress's <code>wp_validate_boolean()</code>. Other strings, such as 'off' or 'no', evaluate to true. The numeric constants normalize whole-number strings without changing the existing settings ranges; decimal strings are not converted to integers.

Integrations with other plugins:

 * The plugin has integration with the Stream plugin.

Plugin development is on the [GitHub](https://github.com/hokoo/safety-passwords).

For contributor testing and release preparation, see the repository's `readme.md`.

== Screenshots ==
1. Settings page
2. Setting are overridden by PHP constants
3. Password change notification
4. Password change urgency notification
5. Seamless password change form
6. Weak password is not allowed

== Installation ==
The release package includes runtime dependencies. If installing from a source checkout instead, run `composer install --no-dev --no-scripts` in `plugin-dir/` before WordPress loads the plugin.

For an ordinary installation, upload the package's `safety-passwords` directory to `/wp-content/plugins/` and activate Safety Passwords in the Plugins screen. On multisite, network activate it; individual subsite activation is not supported. Setup finishes on the next normal WordPress request without visiting settings or running WP-CLI. Deactivation removes the periodic schedule and invalidates initialization state for a later activation.

Must-use (MU) installation is recommended for a security plugin: WordPress does not offer a Plugins-screen control to deactivate an MU loader. A filesystem administrator can still remove it. Keep the plugin directory at `/wp-content/plugins/safety-passwords/` and create `/wp-content/mu-plugins/safety-passwords-loader.php` containing:

`<?php require_once WP_PLUGIN_DIR . '/safety-passwords/safety-passwords.php';`

WordPress loads the root loader automatically. On the first eligible WordPress request, the plugin records current password history, grants administrator capabilities, and schedules the periodic check. On multisite, it uses the current network's settings and keeps one event on that network's main site. Each network initializes when loaded in its own context; one request does not initialize every network.

Both ordinary and MU installations detect a changed plugin version on the next eligible request and initialize automatically, including after file, Composer, or CI deployment. The new code must load against the target WordPress database. Reactivation, a settings-page visit, and `wp safety init` are not required. A failed initialization retries with bounded delays. The settings page shows initialization state and schedule health; eligible requests also repair a missing or duplicate periodic event. A code change that needs a fresh full initialization must advance the plugin version marker.

Use the settings page's **Initialize / repair** button to repair the current network now, including after a failed automatic attempt. It repairs capabilities, current password history, and the schedule without rotating passwords. The action requires a POST, nonce, and `manage_options` or the plugin management capability on a single site, or `manage_network_options` on multisite. `wp safety init --url=<main-site-url>` is an optional command for the same repair or for a checked result before opening traffic. It needs a working WordPress bootstrap and database and exits with an error if initialization cannot complete, including when another process holds the initialization lock. In a multi-network installation, select each network's main-site URL separately.

Periodic resets run through WP-Cron, which needs WordPress requests or an external cron trigger to execute on time. Automatic setup and schedule repair occur when the plugin loads; they cannot run while the site is idle.

To remove an MU installation, remove its loader and deactivate any separately active ordinary copy. Removing the loader does not run deactivation; manually remove the `safety_passwords_periodically_reset` event from the main site and any legacy subsites. Saved settings and password history remain. If the same-version loader returns, its retained initialization marker does not trigger a full history pass. Use **Initialize / repair** or `wp safety init --url=<main-site-url>` to include current passwords for accounts added while the plugin was absent. Changes made while its code was absent cannot be reconstructed.

== Frequently Asked Questions ==

= When should I use Initialize / repair? =

Normally, no manual action is needed: initialization and schedule checks run automatically on eligible WordPress requests. Use the button on the settings page when its status reports a retryable initialization error or a degraded schedule and you want to retry immediately. Also use it after restoring a must-use installation that was removed and returned without a plugin version change, so current passwords for accounts added during the gap enter the history. The action repairs administrator capabilities, current password history, and the periodic schedule for the current network; it does not rotate or reset passwords. It cannot reconstruct password changes made while the plugin was absent. If the action fails, check the site state and try again after resolving the cause; automatic retries remain available.


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
