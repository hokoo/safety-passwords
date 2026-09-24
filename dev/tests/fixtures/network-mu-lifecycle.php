<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_network_mu_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: network MU lifecycle $label\n" );
		exit( 1 );
	}
}

function sp_network_mu_events() {
	$count = 0;
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ 'safety_passwords_periodically_reset' ] ) ) {
			$count += count( $events[ 'safety_passwords_periodically_reset' ] );
		}
	}
	return $count;
}

sp_network_mu_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
sp_network_mu_assert( is_multisite() && get_current_blog_id() === (int) get_network()->site_id, 'main network context missing' );
$stage = $args[0] ?? '';
$state_key = 'safety_passwords_integration_network_mu_state';
$subsites = array_values( array_diff( array_map( 'intval', get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] ) ), [ get_current_blog_id() ] ) );
sp_network_mu_assert( count( $subsites ) > 0, 'subsite missing' );
$subsite = $subsites[0];

if ( 'prepare' === $stage ) {
	sp_network_mu_assert( ! class_exists( Cron::class ), 'ordinary plugin still loaded' );
	$ids = [];
	foreach ( [ 'unassigned', 'subsite' ] as $kind ) {
		$login = 'sp-network-mu-' . strtolower( wp_generate_password( 12, false, false ) );
		$password = wp_generate_password( 32, true, false );
		$id = wp_create_user( $login, $password, $login . '@example.invalid' );
		unset( $password );
		sp_network_mu_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
		remove_user_from_blog( $id, get_current_blog_id() );
		if ( 'subsite' === $kind ) {
			add_user_to_blog( $subsite, $id, 'subscriber' );
		}
		$ids[ $kind ] = $id;
	}
	sp_network_mu_assert( get_blogs_of_user( $ids['unassigned'] ) === [], 'unassigned account has membership' );
	update_option( $state_key, [ 'ids' => $ids ] );
	// Exercise initial cleanup of a legacy subsite event.
	switch_to_blog( $subsite );
	wp_schedule_event( time() + 600, 'twicedaily', 'safety_passwords_periodically_reset' );
	restore_current_blog();
	echo "PASS: network MU transition prepared\n";
	return;
}

if ( 'ordinary-pending' === $stage ) {
	sp_network_mu_assert( class_exists( Cron::class ), 'ordinary network plugin unavailable' );
	$state = get_option( $state_key );
	sp_network_mu_assert( is_array( $state ) && isset( $state['ids'] ), 'transition state missing' );
	foreach ( $state['ids'] as $id ) {
		sp_network_mu_assert( ! get_user_meta( (int) $id, Controller::USER_STOP_LIST_META_KEY, true ), 'ordinary network load seeded history early' );
	}
	sp_network_mu_assert( ! get_option( 'safety_passwords_mu_initialized' ), 'ordinary network load set MU marker' );
	sp_network_mu_assert( sp_network_mu_events() === 0, 'ordinary network load scheduled periodic event early' );
	sp_network_mu_assert( wp_next_scheduled( 'itron/safety-passwords/activate' ), 'ordinary network activation lost deferred phase' );
	echo "PASS: ordinary network load retained deferred phase\n";
	return;
}

sp_network_mu_assert( class_exists( Cron::class ) && did_action( 'carbon_fields_fields_registered' ) > 0, 'MU or Carbon unavailable' );
$state = get_option( $state_key );
sp_network_mu_assert( is_array( $state ) && isset( $state['ids'] ), 'state missing' );
sp_network_mu_assert( get_option( 'safety_passwords_mu_initialized' ) && ! get_option( 'safety_passwords_mu_initializing' ), 'completion or lock incorrect' );
foreach ( $state['ids'] as $id ) {
	$history = get_user_meta( (int) $id, Controller::USER_STOP_LIST_META_KEY, true );
	sp_network_mu_assert( is_array( $history ) && count( $history ) === 1, 'network account history incorrect' );
}
sp_network_mu_assert( sp_network_mu_events() === 1 && wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'main event incorrect' );
foreach ( $subsites as $site_id ) {
	switch_to_blog( $site_id );
	sp_network_mu_assert( sp_network_mu_events() === 0, 'subsite event remained' );
	sp_network_mu_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'subsite capability missing' );
	restore_current_blog();
}

if ( 'initial' === $stage ) {
	$slug = '/mu-new-' . strtolower( wp_generate_password( 8, false, false ) ) . '/';
	$new_site = wpmu_create_blog( get_network()->domain, $slug, 'MU New Site', get_current_user_id(), [ 'public' => 0 ], get_current_network_id() );
	sp_network_mu_assert( is_int( $new_site ) && $new_site > 0, 'new site creation failed' );
	switch_to_blog( $new_site );
	sp_network_mu_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'new site capability missing' );
	sp_network_mu_assert( sp_network_mu_events() === 0, 'new site received event' );
	restore_current_blog();
	$state['new_site'] = $new_site;
	$state['scheduled'] = wp_next_scheduled( Cron::EVENT_NAME );
	update_option( $state_key, $state );
} elseif ( 'repeat' === $stage ) {
	sp_network_mu_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $state['scheduled'], 'repeat rescheduled event' );
	switch_to_blog( (int) $state['new_site'] );
	sp_network_mu_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ) && sp_network_mu_events() === 0, 'new site state changed' );
	restore_current_blog();
} else {
	sp_network_mu_assert( false, 'unknown stage' );
}
echo "PASS: network MU bootstrap, all-account history and one main scheduler ($stage)\n";
