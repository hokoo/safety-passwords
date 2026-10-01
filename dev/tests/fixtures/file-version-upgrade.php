<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;

function sp_file_upgrade_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: file version upgrade $label\n" );
		exit( 1 );
	}
}

sp_file_upgrade_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
sp_file_upgrade_assert( ! is_multisite() && defined( 'iTRON\SafetyPasswords\VERSION' ), 'ordinary plugin unavailable' );
$stage = $args[0] ?? '';
$state_key = 'safety_passwords_integration_file_upgrade';
$version_key = 'safety_passwords_initialized_version';
if ( 'prepare' === $stage ) {
	sp_file_upgrade_assert( \iTRON\SafetyPasswords\VERSION === '1.4.99' && get_option( $version_key ) === '1.4.99', 'old installed file did not complete' );
	$login = 'sp-upgrade-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, false );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	unset( $password );
	sp_file_upgrade_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
	delete_user_meta( $id, Controller::USER_STOP_LIST_META_KEY );
	sp_file_upgrade_assert( add_option( $state_key, [ 'id' => $id, 'event' => wp_next_scheduled( Cron::EVENT_NAME ) ], '', false ), 'state setup' );
	echo "PASS: prior plugin file version prepared\n";
	return;
}
sp_file_upgrade_assert( 'verify' === $stage, 'unknown stage' );
$state = get_option( $state_key );
sp_file_upgrade_assert( is_array( $state ) && isset( $state['id'], $state['event'] ), 'state missing' );
sp_file_upgrade_assert( \iTRON\SafetyPasswords\VERSION === '1.5' && get_option( $version_key ) === '1.5', 'new file version did not bootstrap' );
$id = (int) $state['id'];
$user = get_user_by( 'ID', $id );
$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
sp_file_upgrade_assert( $user instanceof WP_User && is_array( $history ) && count( $history ) === 1 && in_array( $user->user_pass, $history, true ), 'new version did not seed missing current history' );
sp_file_upgrade_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $state['event'] && Cron::currentSiteEventIsCanonical(), 'file upgrade changed canonical event' );
require_once ABSPATH . 'wp-admin/includes/user.php';
sp_file_upgrade_assert( wp_delete_user( $id ), 'synthetic account cleanup' );
delete_option( $state_key );
echo "PASS: normal request completed copied plugin file upgrade\n";
