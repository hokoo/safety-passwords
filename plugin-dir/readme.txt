=== Safety Passwords ===
Contributors: hokku
Tags: user passwords,secure passwords,enforce secure passwords,force secure passwords,secure password validation
Donate link: https://www.paypal.me/igortron
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enforce users to use strong passwords.

== Description ==
This plugin enforces users to use strong passwords. It means that when a user changes his password, the password must contain at least:
 * one uppercase letter
 * one lowercase letter
 * one number
 * one special character

 The minimum length of the password is defined by the plugin setting.

 You can also define the period of time after which the user will be forced to change his password.

 The important feature of the plugin is settings defining by means of PHP constants.

 * SAFETY_PASSWORDS_MIN_LENGTH - the minimum length of the password;
 * SAFETY_PASSWORDS_RESET_INTERVAL - the period of time after which the user will be forced to change his password;
 * SAFETY_PASSWORDS_RP_ON_REGISTRATION - enforce users to change their password after registration.

 The plugin has integration with the Stream plugin.

== Installation ==
0. Upload plugin to the `/wp-content/plugins/` directory
1. Activate the plugin through the \'Plugins\' menu in WordPress
2. Go to Safety Passwords settings page and configure the plugin.

== Screenshots ==
1. Settings page

== Changelog ==