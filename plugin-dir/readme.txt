=== Safety Passwords ===
Contributors: hokku
Tags: user passwords,secure passwords,enforce secure passwords,force secure passwords,secure password validation
Donate link: https://www.paypal.me/igortron
Requires at least: 5.0
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.4.3
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

For contributor testing, see the isolated WordPress integration check commands in the repository's `readme.md`. They cover cron, lifecycle, settings and real Stream 4.0.0 integration across the documented PHP and WordPress test targets. WordPress 7.1.2 on PHP 8.5 and 8.2 is the primary tested matrix. WordPress 5.0 and PHP 7.4 remain supported and tested, but WordPress 5 is deprecated for future development. The runner downloads Stream into a disposable WordPress volume and sanitizes its test records before storage.

== Screenshots ==
1. Settings page
2. Setting are overridden by PHP constants
3. Password change notification
4. Password change urgency notification
5. Seamless password change form
6. Weak password is not allowed

== Installation ==
0. Upload plugin to the `/wp-content/plugins/` directory
1. Activate the plugin through the \'Plugins\' menu in WordPress
2. Go to Safety Passwords settings page and configure the plugin.

The plugin completes its initial password history and periodic reset setup on the next normal WordPress request after activation.

When upgrading an existing ordinary installation to this network policy, reactivate the plugin once so the deferred phase seeds history for accounts in scope, refreshes site capabilities, and establishes the main-site schedule. Visiting settings alone only checks the schedule. An MU installation initializes automatically on its first request.

For a must-use installation, keep the plugin directory in `/wp-content/plugins/safety-passwords/` and create a PHP loader directly in `/wp-content/mu-plugins/` that requires `/wp-content/plugins/safety-passwords/safety-passwords.php`. WordPress loads that root loader automatically; no Plugins-menu activation or settings-page visit is needed. After Carbon Fields is ready on the first request, the plugin seeds current password history, grants administrator capabilities, and schedules the periodic check. On multisite, it seeds accounts in scope and keeps one event on each network's main site. Later requests preserve the history and schedule. New sites receive the capability when created.

To remove a must-use installation, remove the loader and then deactivate any ordinary copy if it is active. Deactivation clears scheduled events; simply deleting the MU loader cannot run a WordPress deactivation callback, so clear the `safety_passwords_periodically_reset` scheduled event on the main site (and any legacy subsite events) as part of removal. Removing the loader does not erase password history or settings. Before reinstalling a physically removed MU loader, delete the private `safety_passwords_mu_initialized` option on the main site so accounts added during its absence are included on the next request.


== Changelog ==
= 1.4.3 =
* Complete ordinary and must-use activation setup, current-network policy and scheduling, and isolated WordPress integration coverage.
* Correct password expiry notices, repeated reset handling, constant overrides, and privacy-safe logging.
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
