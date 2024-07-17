=== Safety Passwords ===
Contributors: hokku
Tags: user passwords,secure passwords,enforce secure passwords,force secure passwords,secure password validation
Donate link: https://www.paypal.me/igortron
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enforce users to use strong passwords.

== Description ==
This plugin enforces users to use strong passwords. It means that when a user changes his password, the password must contain at least:

 * one uppercase letter;
 * one lowercase letter;
 * one number;
 * one special character.

The minimum length of the password is defined by the plugin's settings.

You can also define the period of time after which the user will be forced to change his password.

The important feature of the plugin is settings defining by means of PHP constants.

 * <code>SAFETY_PASSWORDS_MIN_LENGTH</code> - (int/string, number of symbols) the minimum length of the password;
 * <code>SAFETY_PASSWORDS_RESET_INTERVAL</code> - (int/string, days) the period of time after which the user will be forced to change his password;
 * <code>SAFETY_PASSWORDS_RP_ON_REGISTRATION</code> - (bool) whether enforce users to change their password after registration or not.

Integrations with other plugins:

 * The plugin has integration with the Stream plugin.

Plugin development is on the [GitHub](https://github.com/hokoo/safety-passwords).

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


== Changelog ==
= 1.1 =
* Failing to set 0 as the password reset interval and 1 as the minimum password length fixed.

= 1.0 =
* Initial release
