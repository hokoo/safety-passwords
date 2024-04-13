<?php
/*
Plugin Name: Enforce Strong User Passwords
Description: Forces all users to have a strong password when they're changing it on their profile page.
Network: true
Version: 1.0
Author: iTRON
License: GPL2
*/

namespace iTRON\SafetyPasswords;

define( 'PLUGIN_SLUG', 'safety-passwords' );
define( 'PLUGIN_NAME', plugin_basename( __FILE__ ) );
define( 'PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIN_MAIN_FILE_PATH', __FILE__ );
define( 'VERSION', '1.0' );
define( 'OPTIONS_MODE', is_multisite() ? 'network' : 'theme_options' );

require_once __DIR__ . '/vendor/autoload.php';

$general = new General();
$general->init();

register_activation_hook(
	PLUGIN_MAIN_FILE_PATH,
	[ $general, 'processActivationHook' ]
);

register_deactivation_hook(
	PLUGIN_MAIN_FILE_PATH,
	[ $general, 'processDeactivationHook' ]
);
