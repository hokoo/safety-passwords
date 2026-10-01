<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_reverse_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: reverse network boundary $label\n" );
		exit( 1 );
	}
}

function sp_reverse_event_count() {
	$count = 0;
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ Cron::EVENT_NAME ] ) ) {
			$count += count( $events[ Cron::EVENT_NAME ] );
		}
	}
	return $count;
}

function sp_reverse_history_count( $id ) {
	$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
	return is_array( $history ) ? count( $history ) : 0;
}

sp_reverse_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_reverse_assert( is_multisite() && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing site isolation marker' );
sp_reverse_assert( class_exists( Controller::class ) && class_exists( Cron::class ), 'plugin unavailable' );
$stage = $args[0] ?? '';
sp_reverse_assert( in_array( $stage, [ 'second', 'subsite', 'second-reset', 'finish' ], true ), 'unknown stage' );
$second_network_id = 2;
$second_domain = 'sp-second-network.example.invalid';
$second_network = get_network( $second_network_id );
sp_reverse_assert( $second_network && $second_network->domain === $second_domain, 'second network missing' );
$second_main = (int) $second_network->site_id;
$first_main = (int) get_network( 1 )->site_id;
$second_event_option = 'safety_passwords_integration_second_event';
$first_event_option = 'safety_passwords_integration_first_event';
$prefix = Settings::$optionPrefix;
$ids = [];
foreach ( [ 'current', 'other', 'shared', 'inactive', 'inactive-second', 'unassigned' ] as $kind ) {
	$user = get_user_by( 'login', 'sp-boundary-' . $kind );
	sp_reverse_assert( $user instanceof WP_User, 'fixture account missing' );
	$ids[ $kind ] = (int) $user->ID;
}

if ( 'finish' === $stage ) {
	sp_reverse_assert( get_current_network_id() === 1 && get_current_blog_id() === $first_main, 'first URL did not return to first network' );
	sp_reverse_assert( Settings::getInterval() === 1, 'first-network policy changed' );
	$first_event = (int) get_option( $first_event_option );
	sp_reverse_assert( $first_event > 0 && wp_next_scheduled( Cron::EVENT_NAME ) === $first_event && wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily' && sp_reverse_event_count() === 1, 'first-network scheduler changed' );
	foreach ( [ 'current', 'inactive', 'unassigned' ] as $kind ) {
		$id = $ids[ $kind ];
		$expected_history = 'unassigned' === $kind ? 0 : 1;
		sp_reverse_assert( sp_reverse_history_count( $id ) === $expected_history && ! get_user_meta( $id, $prefix . 'rp_inited', true ) && (int) get_user_meta( $id, $prefix . 'last_reset', true ) === DAY_IN_SECONDS, "$kind account changed by second-network request" );
	}
	foreach ( [ 'other', 'shared', 'inactive-second' ] as $kind ) {
		sp_reverse_assert( '1' === get_user_meta( $ids[ $kind ], $prefix . 'rp_inited', true ), "$kind reset missing after second-network callback" );
	}
	switch_to_blog( $second_main );
	$second_event = (int) get_option( $second_event_option );
	sp_reverse_assert( $second_event > 0 && wp_next_scheduled( Cron::EVENT_NAME ) === $second_event && sp_reverse_event_count() === 1, 'second-network scheduler changed after return' );
	delete_option( $second_event_option );
	restore_current_blog();
	sp_reverse_assert( get_current_blog_id() === $first_main, 'first-network context was not restored' );
	delete_option( $first_event_option );
	if ( ! function_exists( 'wpmu_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	foreach ( $ids as $id ) {
		sp_reverse_assert( wpmu_delete_user( $id ), 'fixture account cleanup' );
	}
	echo "PASS: first URL restored first network, policy, accounts and scheduler; fixture accounts cleaned\n";
	return;
}

sp_reverse_assert( get_current_network_id() === $second_network_id, 'second URL did not resolve second network' );
if ( 'subsite' === $stage ) {
	$subsite_record = get_site_by_path( $second_domain, '/boundary-subsite/' );
	sp_reverse_assert( $subsite_record instanceof WP_Site && get_current_blog_id() !== $second_main && get_current_blog_id() === (int) $subsite_record->blog_id, 'second subsite URL did not resolve natively' );
	sp_reverse_assert( ! wp_next_scheduled( Cron::EVENT_NAME ) && sp_reverse_event_count() === 0, 'second subsite has unexpected scheduler' );
	$subsite = get_current_blog_id();
	sp_reverse_assert( has_action( Cron::EVENT_NAME, [ Controller::class, 'findExpiringPasswords' ] ) !== false, 'subsite cron callback unavailable' );
	$sentinel = time() + 600;
	wp_schedule_event( $sentinel, 'twicedaily', Cron::EVENT_NAME );
	sp_reverse_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $sentinel, 'subsite legacy event setup' );
	do_action( Cron::EVENT_NAME );
	foreach ( [ 'other', 'shared', 'inactive-second' ] as $kind ) {
		sp_reverse_assert( ! get_user_meta( $ids[ $kind ], $prefix . 'rp_inited', true ), "$kind reset from second subsite" );
	}
	sp_reverse_assert( get_current_blog_id() === $subsite && wp_next_scheduled( Cron::EVENT_NAME ) === $sentinel, 'subsite callback changed context or event' );
	Cron::ensureEvent();
	sp_reverse_assert( get_current_blog_id() === $subsite && sp_reverse_event_count() === 0, 'subsite ensure did not restore context or clear legacy event' );
	switch_to_blog( $second_main );
	sp_reverse_assert( wp_next_scheduled( Cron::EVENT_NAME ) === (int) get_option( $second_event_option ) && sp_reverse_event_count() === 1, 'subsite ensure changed second main event' );
	restore_current_blog();
	sp_reverse_assert( get_current_blog_id() === $subsite, 'subsite context not restored' );
	echo "PASS: native second subsite request skips reset and preserves main scheduler and context\n";
	return;
}

sp_reverse_assert( get_current_blog_id() === $second_main && get_option( 'safety_passwords_mu_initialized' ), 'second-network MU completion missing' );
sp_reverse_assert( Settings::getInterval() === ( 'second' === $stage ? 3 : 4 ), 'second-network policy not loaded' );
$second_event = wp_next_scheduled( Cron::EVENT_NAME );
sp_reverse_assert( $second_event, 'second-network canonical scheduler missing' );
sp_reverse_assert( wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'second-network canonical recurrence incorrect' );
sp_reverse_assert( sp_reverse_event_count() === 1, 'second-network duplicate scheduler remains' );
sp_reverse_assert( ! wp_next_scheduled( Cron::EVENT_NAME, [ 'network-boundary-sentinel' ] ), 'second-network sentinel was not replaced' );
$inactive_sites = get_sites( [ 'network_id' => $second_network_id, 'path' => '/boundary-inactive/', 'fields' => 'ids', 'number' => 0 ] );
sp_reverse_assert( count( $inactive_sites ) === 1, 'second-network inactive site missing' );
switch_to_blog( (int) $inactive_sites[0] );
sp_reverse_assert( sp_reverse_event_count() === 0, 'inactive second-network site has scheduler' );
restore_current_blog();
sp_reverse_assert( get_current_blog_id() === $second_main, 'inactive second-network context not restored' );
sp_reverse_assert( sp_reverse_history_count( $ids['other'] ) === 1 && sp_reverse_history_count( $ids['inactive-second'] ) === 1 && sp_reverse_history_count( $ids['shared'] ) === 2, 'second-network MU history scope' );
sp_reverse_assert( sp_reverse_history_count( $ids['current'] ) === 1 && sp_reverse_history_count( $ids['inactive'] ) === 1 && sp_reverse_history_count( $ids['unassigned'] ) === 0, 'first-only or unassigned history changed by second-network MU bootstrap' );
foreach ( [ 'current', 'inactive', 'unassigned' ] as $kind ) {
	$id = $ids[ $kind ];
	sp_reverse_assert( ! get_user_meta( $id, $prefix . 'rp_inited', true ) && (int) get_user_meta( $id, $prefix . 'last_reset', true ) === DAY_IN_SECONDS, "$kind account changed by second-network bootstrap" );
}

if ( 'second' === $stage ) {
	sp_reverse_assert( current_user_can( 'manage_network_options' ), 'second-network admin permission missing' );
	$repository = \Carbon_Fields\Carbon_Fields::resolve( 'container_repository' );
	$settings = null;
	foreach ( $repository->get_containers( 'network' ) as $container ) {
		if ( $container->get_id() === 'carbon_fields_container_safety_passwords' ) {
			$settings = $container;
			break;
		}
	}
	sp_reverse_assert( $settings !== null && $settings->get_datastore()->get_object_id() === $second_network_id, 'Carbon page datastore points outside second network' );
	$field = $settings->get_root_field_by_name( $prefix . 'reset_interval' );
	sp_reverse_assert( $field !== null && $field->get_datastore()->get_object_id() === $second_network_id, 'Carbon field datastore points outside second network' );
	$field->load();
	sp_reverse_assert( (int) $field->get_value() === 3, 'Carbon page did not load second-network policy' );
	sp_reverse_assert( (int) carbon_get_network_option( 1, $prefix . 'reset_interval' ) === 1, 'first-network policy missing before save' );
	$request_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$request_post = $_POST;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$nonce_name = $settings->get_id() . '_nonce';
	$_POST = [ $nonce_name => wp_create_nonce( $nonce_name ) ];
	foreach ( $settings->get_fields() as $setting_field ) {
		$setting_field->load();
		$_POST[ $setting_field->get_name() ] = $setting_field->get_value();
	}
	// Carbon's form key includes its field-name prefix; use the registered field.
	$_POST[ $field->get_name() ] = '4';
	$network_admin = get_current_user_id();
	wp_set_current_user( $ids['other'] );
	$_POST[ $nonce_name ] = wp_create_nonce( $nonce_name );
	sp_reverse_assert( ! current_user_can( 'manage_network_options' ) && ! $settings->is_valid_attach_for_object( null ) && ! $settings->is_valid_save(), 'second-network site admin passed Carbon save checks' );
	wp_set_current_user( $network_admin );
	$_POST[ $nonce_name ] = wp_create_nonce( $nonce_name );
	sp_reverse_assert( $settings->is_valid_save(), 'second-network admin denied Carbon save' );
	$cli_redirect_handler = 'WP_CLI\\Utils\\wp_redirect_handler';
	$cli_redirect_priority = has_filter( 'wp_redirect', $cli_redirect_handler );
	if ( false !== $cli_redirect_priority ) {
		remove_filter( 'wp_redirect', $cli_redirect_handler, $cli_redirect_priority );
	}
	$redirect_attempted = false;
	$capture_redirect = function () use ( &$redirect_attempted ) {
		$redirect_attempted = true;
		return false;
	};
	add_filter( 'wp_redirect', $capture_redirect, PHP_INT_MAX );
	try {
		$settings->save();
	} finally {
		remove_filter( 'wp_redirect', $capture_redirect, PHP_INT_MAX );
		if ( false !== $cli_redirect_priority ) {
			add_filter( 'wp_redirect', $cli_redirect_handler, $cli_redirect_priority );
		}
		$_POST = $request_post;
		if ( null === $request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $request_method;
		}
	}
	sp_reverse_assert( $redirect_attempted, 'Carbon save did not request its normal redirect' );
	$field->load();
	sp_reverse_assert( (int) $field->get_value() === 4 && Settings::getInterval() === 4 && (int) carbon_get_network_option( 1, $prefix . 'reset_interval' ) === 1, 'Carbon save crossed network policy boundary' );
	sp_reverse_assert( add_option( $second_event_option, $second_event, '', false ), 'second-network event snapshot missing' );
	echo "PASS: native second-network MU bootstrap, Carbon page load and save, history and scheduler\n";
	return;
}

sp_reverse_assert( $second_event === (int) get_option( $second_event_option ), 'second-network scheduler changed before callback' );
$protected = [];
foreach ( [ 'current', 'inactive', 'unassigned' ] as $kind ) {
	$id = $ids[ $kind ];
	$protected[ $kind ] = [
		'password' => get_userdata( $id )->user_pass,
		'history' => get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true ),
		'last_reset' => get_user_meta( $id, $prefix . 'last_reset', true ),
		'rp_inited' => get_user_meta( $id, $prefix . 'rp_inited', true ),
	];
}
$eligible = [];
foreach ( [ 'other', 'shared', 'inactive-second' ] as $kind ) {
	$id = $ids[ $kind ];
	sp_reverse_assert( ! get_user_meta( $id, $prefix . 'rp_inited', true ) && (int) get_user_meta( $id, $prefix . 'last_reset', true ) === DAY_IN_SECONDS, "$kind second-network reset setup missing" );
	$eligible[ $kind ] = get_userdata( $id )->user_pass;
}
do_action( Cron::EVENT_NAME );
foreach ( $eligible as $kind => $old_hash ) {
	$id = $ids[ $kind ];
	sp_reverse_assert( get_userdata( $id )->user_pass !== $old_hash && '1' === get_user_meta( $id, $prefix . 'rp_inited', true ), "$kind account was not reset by second-network callback" );
}
foreach ( $protected as $kind => $before ) {
	$id = $ids[ $kind ];
	sp_reverse_assert( get_userdata( $id )->user_pass === $before['password'] && get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true ) === $before['history'] && get_user_meta( $id, $prefix . 'last_reset', true ) === $before['last_reset'] && get_user_meta( $id, $prefix . 'rp_inited', true ) === $before['rp_inited'], "$kind account changed by second-network callback" );
}
sp_reverse_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $second_event && sp_reverse_event_count() === 1, 'second-network callback changed scheduler' );
switch_to_blog( $first_main );
sp_reverse_assert( wp_next_scheduled( Cron::EVENT_NAME ) === (int) get_option( $first_event_option ) && sp_reverse_event_count() === 1, 'second-network callback changed first scheduler' );
restore_current_blog();
sp_reverse_assert( get_current_blog_id() === $second_main, 'second-network context not restored' );
echo "PASS: second-network reset scope, first-network isolation and both schedulers\n";
