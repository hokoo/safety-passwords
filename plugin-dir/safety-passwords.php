<?php
/*
Plugin Name: Safety Passwords
Description: Forces all users to have a strong password.
Network: true
Version: 1.2
Author: iTRON
License: GPL2
*/

namespace iTRON\SafetyPasswords;

const PLUGIN_SLUG = 'safety-passwords';
const VERSION     = '1.2';

const PLUGIN_MAIN_FILE_PATH = __FILE__;
define( __NAMESPACE__ . '\PLUGIN_NAME', plugin_basename( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( __NAMESPACE__ . '\OPTIONS_MODE', is_multisite() ? 'network' : 'theme_options' );

require_once __DIR__ . '/vendor/autoload.php';

$general = new General();
$general->init();
add_action( 'in_plugin_update_message-' . PLUGIN_NAME, __NAMESPACE__ . '\upgradeMessage', 10, 2 );

register_activation_hook(
	PLUGIN_MAIN_FILE_PATH,
	[ $general, 'processActivationHook' ]
);

register_deactivation_hook(
	PLUGIN_MAIN_FILE_PATH,
	[ $general, 'processDeactivationHook' ]
);

function upgradeMessage( $data, $response ) {
	if( isset( $data['upgrade_notice'] ) ) :
		printf(
			'<div class="update-message">%s</div>',
			wp_kses( wpautop( $data['upgrade_notice'] ), 'post' )
		);
	endif;
}
