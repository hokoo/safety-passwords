<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_boundary_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: network boundary $label\n" );
		exit( 1 );
	}
}

function sp_boundary_user( $kind ) {
	$login = 'sp-boundary-' . $kind;
	sp_boundary_assert( ! username_exists( $login ), 'synthetic account already exists' );
	$password = wp_generate_password( 32, true, false );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	unset( $password );
	sp_boundary_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
	remove_user_from_blog( $id, get_current_blog_id() );
	return $id;
}

function sp_boundary_memberships( $user_id ) {
	$network_ids = [];
	foreach ( get_blogs_of_user( $user_id, true ) as $site ) {
		$network_ids[ (int) $site->site_id ] = true;
	}
	return $network_ids;
}

function sp_boundary_event_count() {
	$count = 0;
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ Cron::EVENT_NAME ] ) ) {
			$count += count( $events[ Cron::EVENT_NAME ] );
		}
	}
	return $count;
}

sp_boundary_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_boundary_assert( is_multisite() && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe network target' );
sp_boundary_assert( class_exists( Controller::class ) && class_exists( Cron::class ), 'plugin unavailable' );
$stage = $args[0] ?? '';
$second_network_id = 2;
$second_domain = 'sp-second-network.example.invalid';
$sentinel_args = [ 'network-boundary-sentinel' ];
$kinds = [ 'current', 'other', 'shared', 'inactive', 'inactive-second', 'unassigned' ];
sp_boundary_assert( get_current_network_id() === 1 && get_current_blog_id() === (int) get_network()->site_id, 'first-network main site request did not resolve natively' );

if ( 'prepare' === $stage ) {
	sp_boundary_assert( ! get_network( $second_network_id ), 'second network already exists' );
	sp_boundary_assert( get_option( 'safety_passwords_mu_initialized' ), 'first-network MU completion missing' );
	if ( ! function_exists( 'populate_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/schema.php';
	}
	$created = populate_network( $second_network_id, $second_domain, 'network-admin@example.invalid', 'Second Network' );
	clean_network_cache( $second_network_id );
	sp_boundary_assert( true === $created && get_network( $second_network_id ), 'second network creation' );
	$ids = [];
	foreach ( $kinds as $kind ) {
		$ids[ $kind ] = sp_boundary_user( $kind );
	}
	$second_site = wpmu_create_blog( $second_domain, '/', 'Second Network Main', $ids['other'], [ 'public' => 0 ], $second_network_id );
	sp_boundary_assert( is_int( $second_site ) && $second_site > 0, 'second-network site creation' );
	update_network_option( $second_network_id, 'main_site', $second_site );
	sp_boundary_assert( (int) get_network( $second_network_id )->site_id === $second_site, 'second-network main site missing' );
	$second_subsite = wpmu_create_blog( $second_domain, '/boundary-subsite/', 'Second Network Subsite', $ids['other'], [ 'public' => 0 ], $second_network_id );
	sp_boundary_assert( is_int( $second_subsite ) && $second_subsite > 0, 'second-network subsite creation' );
	switch_to_blog( $second_subsite );
	update_option( 'safety_passwords_integration_target', 'isolated' );
	restore_current_blog();
	$second_inactive_site = wpmu_create_blog( $second_domain, '/boundary-inactive/', 'Second Inactive Member Site', $ids['other'], [ 'public' => 0 ], $second_network_id );
	sp_boundary_assert( is_int( $second_inactive_site ) && $second_inactive_site > 0, 'second-network inactive site creation' );
	add_user_to_blog( $second_inactive_site, $ids['inactive-second'], 'subscriber' );
	foreach ( [ 'archived', 'spam', 'deleted' ] as $status ) {
		update_blog_status( $second_inactive_site, $status, 1 );
	}
	add_user_to_blog( get_current_blog_id(), $ids['current'], 'subscriber' );
	add_user_to_blog( get_current_blog_id(), $ids['shared'], 'subscriber' );
	add_user_to_blog( $second_site, $ids['shared'], 'subscriber' );
	$inactive_site = wpmu_create_blog( get_network()->domain, '/boundary-inactive/', 'Inactive Member Site', get_current_user_id(), [ 'public' => 0 ], get_current_network_id() );
	sp_boundary_assert( is_int( $inactive_site ) && $inactive_site > 0, 'inactive member site creation' );
	add_user_to_blog( $inactive_site, $ids['inactive'], 'subscriber' );
	foreach ( [ 'archived', 'spam', 'deleted' ] as $status ) {
		update_blog_status( $inactive_site, $status, 1 );
	}
	sp_boundary_assert( isset( sp_boundary_memberships( $ids['current'] )[1] ), 'current account membership missing' );
	sp_boundary_assert( ! isset( sp_boundary_memberships( $ids['other'] )[1] ) && isset( sp_boundary_memberships( $ids['other'] )[2] ), 'other-only membership incorrect' );
	sp_boundary_assert( isset( sp_boundary_memberships( $ids['shared'] )[1] ) && isset( sp_boundary_memberships( $ids['shared'] )[2] ), 'shared membership incorrect' );
	sp_boundary_assert( isset( sp_boundary_memberships( $ids['inactive'] )[1] ) && get_blogs_of_user( $ids['inactive'] ) === [], 'inactive account membership incorrect' );
	sp_boundary_assert( ! isset( sp_boundary_memberships( $ids['inactive-second'] )[1] ) && isset( sp_boundary_memberships( $ids['inactive-second'] )[2] ) && get_blogs_of_user( $ids['inactive-second'] ) === [], 'second inactive account membership incorrect' );
	sp_boundary_assert( sp_boundary_memberships( $ids['unassigned'] ) === [], 'unassigned account has membership' );
	switch_to_blog( $second_site );
	update_option( 'safety_passwords_integration_target', 'isolated' );
	carbon_set_network_option( $second_network_id, Settings::$optionPrefix . 'reset_interval', 3 );
	$sentinel_timestamp = time() + 600;
	wp_schedule_event( $sentinel_timestamp, 'twicedaily', Cron::EVENT_NAME, $sentinel_args );
	sp_boundary_assert( wp_next_scheduled( Cron::EVENT_NAME, $sentinel_args ) === $sentinel_timestamp && wp_get_schedule( Cron::EVENT_NAME, $sentinel_args ) === 'twicedaily', 'second-network event scheduling' );
	sp_boundary_assert( sp_boundary_event_count() === 1, 'second-network sentinel missing' );
	restore_current_blog();
	// A fresh request must run the MU completion path after both networks exist.
	delete_option( 'safety_passwords_mu_initialized' );
	echo "PASS: two-network boundary prepared\n";
	return;
}

sp_boundary_assert( 'verify' === $stage, 'unknown stage' );
sp_boundary_assert( get_network( $second_network_id ) && get_option( 'safety_passwords_mu_initialized' ), 'first-network MU completion did not finish' );
$sites = get_sites( [ 'network_id' => $second_network_id, 'fields' => 'ids', 'number' => 0 ] );
sp_boundary_assert( count( $sites ) === 3, 'second-network sites missing' );
$second_site = (int) get_network( $second_network_id )->site_id;
$ids = [];
foreach ( $kinds as $kind ) {
	$user = get_user_by( 'login', 'sp-boundary-' . $kind );
	sp_boundary_assert( $user instanceof WP_User, 'synthetic account missing' );
	$ids[ $kind ] = (int) $user->ID;
}

$failures = [];
$check = function ( $condition, $label ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};
foreach ( [ 'current', 'shared', 'inactive' ] as $kind ) {
	$user = get_user_by( 'ID', $ids[ $kind ] );
	$history = get_user_meta( $ids[ $kind ], Controller::USER_STOP_LIST_META_KEY, true );
	$check( is_array( $history ) && count( $history ) === 1 && in_array( $user->user_pass, $history, true ), "$kind history omitted by MU completion" );
}
foreach ( [ 'other', 'inactive-second', 'unassigned' ] as $kind ) {
	$check( ! get_user_meta( $ids[ $kind ], Controller::USER_STOP_LIST_META_KEY, true ), "$kind history changed by first-network MU completion" );
}
switch_to_blog( $second_site );
$second_event = wp_next_scheduled( Cron::EVENT_NAME, $sentinel_args );
$check( $second_event && sp_boundary_event_count() === 1, 'second-network event changed by first-network MU completion' );
restore_current_blog();

$first_event = wp_next_scheduled( Cron::EVENT_NAME );
Cron::ensureEvent();
$check( $first_event && wp_next_scheduled( Cron::EVENT_NAME ) === $first_event && sp_boundary_event_count() === 1, 'first-network event changed by ensure' );
switch_to_blog( $second_site );
$check( wp_next_scheduled( Cron::EVENT_NAME, $sentinel_args ) === $second_event && sp_boundary_event_count() === 1, 'second-network event changed by first-network ensure' );
restore_current_blog();

sp_boundary_assert( Settings::getInterval() === 1, 'first-network reset interval missing' );
$prefix = Settings::$optionPrefix;
$before = [];
foreach ( $kinds as $kind ) {
	$id = $ids[ $kind ];
	update_user_meta( $id, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
	$user = get_user_by( 'ID', $id );
	$before[ $kind ] = [
		'password' => $user->user_pass,
		'history' => get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true ),
		'last_reset' => get_user_meta( $id, $prefix . 'last_reset', true ),
		'rp_inited' => get_user_meta( $id, $prefix . 'rp_inited', true ),
		'rp_pre_inited' => get_user_meta( $id, $prefix . 'rp_pre_inited', true ),
	];
}
do_action( Cron::EVENT_NAME );
foreach ( [ 'current', 'shared', 'inactive' ] as $kind ) {
	$id = $ids[ $kind ];
	$user = get_user_by( 'ID', $id );
	$check( $user->user_pass !== $before[ $kind ]['password'] && get_user_meta( $id, $prefix . 'rp_inited', true ) === '1', "$kind account not reset by first-network callback" );
}
foreach ( [ 'other', 'inactive-second', 'unassigned' ] as $kind ) {
	$id = $ids[ $kind ];
	$user = get_user_by( 'ID', $id );
	$check(
		$user->user_pass === $before[ $kind ]['password'] &&
		get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true ) === $before[ $kind ]['history'] &&
		get_user_meta( $id, $prefix . 'last_reset', true ) === $before[ $kind ]['last_reset'] &&
		get_user_meta( $id, $prefix . 'rp_inited', true ) === $before[ $kind ]['rp_inited'] &&
		get_user_meta( $id, $prefix . 'rp_pre_inited', true ) === $before[ $kind ]['rp_pre_inited'],
		"$kind account changed by first-network callback"
	);
}
switch_to_blog( $second_site );
$check( wp_next_scheduled( Cron::EVENT_NAME, $sentinel_args ) === $second_event && sp_boundary_event_count() === 1, 'second-network event changed by first-network callback' );
restore_current_blog();

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: network boundary $failure\n" );
	}
	exit( 1 );
}
sp_boundary_assert( update_option( 'safety_passwords_integration_first_event', $first_event, false ), 'first-network event snapshot missing' );
foreach ( $ids as $id ) {
	// Every synthetic account is due under the second network's distinct policy.
	// A wrong-scope reset must therefore be visible through its pending flag.
	update_user_meta( $id, $prefix . 'last_reset', DAY_IN_SECONDS );
	delete_user_meta( $id, $prefix . 'rp_inited' );
	delete_user_meta( $id, $prefix . 'rp_pre_inited' );
}
echo "PASS: first-network MU history, reset scope and scheduler isolation; reverse request prepared\n";
