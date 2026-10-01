<?php

use iTRON\SafetyPasswords\Activation;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;

function sp_race_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: lifecycle race $label\n" );
		exit( 1 );
	}
}

function sp_race_event_count() {
	$count = 0;
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ Cron::EVENT_NAME ] ) ) {
			$count += count( $events[ Cron::EVENT_NAME ] );
		}
	}
	return $count;
}

sp_race_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
sp_race_assert( ! is_multisite() && class_exists( Activation::class ), 'ordinary single-site plugin unavailable' );
$stage = $args[0] ?? '';
$state_key = 'safety_passwords_integration_race';
$version_key = 'safety_passwords_initialized_version';
$lease_key = 'safety_passwords_mu_initializing';
$barrier = WP_CONTENT_DIR . '/safety-passwords-integration-race';

if ( 'prepare' === $stage ) {
	sp_race_assert( ! file_exists( $barrier . '.held' ) && ! file_exists( $barrier . '.release' ), 'stale barrier' );
	$login = 'sp-race-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, false );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	unset( $password );
	sp_race_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
	delete_user_meta( $id, Controller::USER_STOP_LIST_META_KEY );
	sp_race_assert( add_option( $state_key, [ 'id' => $id ], '', false ), 'state setup' );
	sp_race_assert( update_option( $version_key, 'previous-version', false ), 'old version setup' );
	wp_unschedule_hook( Cron::EVENT_NAME );
	sp_race_assert( ! get_option( $lease_key ) && 0 === sp_race_event_count(), 'pending baseline' );
	echo "PASS: concurrent bootstrap prepared\n";
	return;
}

$state = get_option( $state_key );
sp_race_assert( is_array( $state ) && isset( $state['id'] ), 'state missing' );
$id = (int) $state['id'];
if ( 'contender' === $stage ) {
	sp_race_assert( file_exists( $barrier . '.held' ) && ! file_exists( $barrier . '.release' ), 'holder did not pause' );
	sp_race_assert( get_option( $version_key ) !== \iTRON\SafetyPasswords\VERSION, 'concurrent request published false ready' );
	sp_race_assert( is_string( get_option( $lease_key ) ) && get_option( $lease_key ) !== '', 'holder lease missing' );
	sp_race_assert( 0 === sp_race_event_count(), 'concurrent request scheduled event before holder resumed' );
	sp_race_assert( ! get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true ), 'concurrent request seeded history' );
	sp_race_assert( false !== file_put_contents( $barrier . '.release', 'release' ), 'holder release' );
	echo "PASS: concurrent request observed pending lease without publishing ready\n";
	return;
}

sp_race_assert( 'holder' === $stage, 'unknown stage' );
sp_race_assert( get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION && ! get_option( $lease_key ), 'holder did not finish' );
$user = get_user_by( 'ID', $id );
$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
sp_race_assert( $user instanceof WP_User && is_array( $history ) && count( $history ) === 1 && in_array( $user->user_pass, $history, true ), 'history not seeded exactly once' );
sp_race_assert( 1 === sp_race_event_count() && Cron::currentSiteEventIsCanonical(), 'duplicate or missing main event' );
require_once ABSPATH . 'wp-admin/includes/user.php';
sp_race_assert( wp_delete_user( $id ), 'synthetic account cleanup' );
delete_option( $state_key );
echo "PASS: overlapping bootstraps completed with one history entry and one event\n";
