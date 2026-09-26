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

For contributor testing and the prepared release procedure, see the repository's `readme.md`. The isolated checks cover cron, lifecycle, settings and real Stream 4.0.0 integration across the documented PHP and WordPress test targets. WordPress 7.1.2 on PHP 8.5 and 8.2 is the primary tested matrix. WordPress 5.0 and PHP 7.4 remain supported and tested, but WordPress 5 is deprecated for future development. The runner downloads Stream into a disposable WordPress volume and sanitizes its test records before storage. Release publication is separate from this local candidate.

== Screenshots ==
1. Settings page
2. Setting are overridden by PHP constants
3. Password change notification
4. Password change urgency notification
5. Seamless password change form
6. Weak password is not allowed

== Installation ==
Must-use (MU) installation is recommended for a security plugin: WordPress does not offer a Plugins-screen control to deactivate an MU loader. A filesystem administrator can still remove it. Keep the plugin directory at `/wp-content/plugins/safety-passwords/` and create `/wp-content/mu-plugins/safety-passwords-loader.php` containing:

`<?php require_once WP_PLUGIN_DIR . '/safety-passwords/safety-passwords.php';`

WordPress loads the root loader automatically. Install dependencies before loading it. On the first request after Carbon Fields is ready, the plugin initializes password history, administrator capabilities, and the periodic check. On multisite, it works within the current network and keeps one event on that network's main site. Each network initializes when loaded in its own context.

For an ordinary installation, upload the directory to `/wp-content/plugins/`, activate Safety Passwords in the Plugins screen (network activate for network-wide use), then configure its settings. Initial setup finishes on the next normal WordPress request after activation. Deactivation removes the periodic schedule and invalidates the initialization state for a later activation.

Both ordinary and MU installations detect a new plugin version on a normal request and automatically migrate lifecycle state after Carbon Fields is ready. Reactivation and visiting settings are not required for an upgrade. An interrupted migration remains retryable. The settings page provides an **Initialize / repair** button for administrators (`manage_options` or the plugin management capability on a single site; `manage_network_options` on multisite). It repairs capabilities, current password history, and the schedule; it does not rotate passwords.

For Composer or file-based deployments, run `wp safety init --url=<main-site-url>` after the new code is deployed, with WordPress bootstrapped and the target database available. This command also repairs an already current installation and exits with an error if initialization cannot complete (including a held initialization lock). A CI build without the target WordPress database cannot perform this step. In multisite with multiple networks, run it once per network using each network's main-site URL; there is no automatic all-network scan from one request.

To remove an MU installation, remove its loader and deactivate any separately active ordinary copy. Removing the loader does not run deactivation; remove the `safety_passwords_periodically_reset` event from the main site and any legacy subsites as part of removal. Saved settings and password history remain. After reinstalling a physically removed loader, use **Initialize / repair** or `wp safety init --url=<main-site-url>` to include accounts added while the plugin was absent.


== Changelog ==
= 1.5 =
* Complete ordinary and must-use activation setup, current-network policy and scheduling, and isolated WordPress integration coverage.
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
