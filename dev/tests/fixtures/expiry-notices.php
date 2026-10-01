<?php

namespace iTRON\SafetyPasswords {
	// Keep the UI boundary cases deterministic without changing the plugin API.
	function time(): int {
		return $GLOBALS['sp_expiry_test_now'] ?? \time();
	}
}

namespace {

use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Settings;

function sp_expiry_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: expiry UI $label\n" );
		exit( 1 );
	}
}

function sp_expiry_interval( $days ) {
	carbon_set_theme_option( Settings::$optionPrefix . 'reset_interval', $days );
	wp_cache_delete( 'reset_interval', 'safety-passwords' );
	sp_expiry_assert( Settings::getInterval() === $days, 'interval setup' );
}

function sp_expiry_render( $profile_user, $label ) {
	$user_id = get_current_user_id();
	$keys = [ Settings::$optionPrefix . 'last_reset', Settings::$optionPrefix . 'rp_inited' ];
	$before = [];
	foreach ( $keys as $key ) {
		$before[ $key ] = [ metadata_exists( 'user', $user_id, $key ), get_user_meta( $user_id, $key, true ) ];
	}

	$bar = new \WP_Admin_Bar();
	General::addAdminBarMenu( $bar );
	$node = $bar->get_node( 'safety-passwords' );
	$bar_text = $node ? wp_strip_all_tags( $node->title ) : null;

	ob_start();
	General::addUserProfileNotice( $profile_user );
	$profile_text = wp_strip_all_tags( ob_get_clean() );
	foreach ( $keys as $key ) {
		sp_expiry_assert( $before[ $key ] === [ metadata_exists( 'user', $user_id, $key ), get_user_meta( $user_id, $key, true ) ], "$label changed metadata" );
	}

	sp_expiry_assert( ! is_string( $bar_text ) || ! preg_match( '/-\d+\s+days?\b/i', $bar_text ), "$label admin bar has negative days" );
	sp_expiry_assert( ! preg_match( '/-\d+\s+days?\b/i', $profile_text ), "$label profile has negative days" );
	return [ $bar_text, $profile_text ];
}

sp_expiry_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_expiry_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_expiry_assert( class_exists( General::class ) && function_exists( 'carbon_set_theme_option' ), 'plugin did not boot' );
if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
}

$user_id = get_current_user_id();
sp_expiry_assert( $user_id > 0, 'synthetic current user missing' );
$profile_user = get_userdata( $user_id );
sp_expiry_assert( $profile_user instanceof \WP_User, 'current profile missing' );
$last_reset_key = Settings::$optionPrefix . 'last_reset';
$pending_key = Settings::$optionPrefix . 'rp_inited';
$GLOBALS['sp_expiry_test_now'] = 2000000000;
$now = $GLOBALS['sp_expiry_test_now'];
$day = DAY_IN_SECONDS;
$mode = $args[0] ?? 'ordinary';
sp_expiry_assert( in_array( $mode, [ 'ordinary', 'mu' ], true ), 'unknown fixture mode' );

sp_expiry_interval( 30 );
$cases = $mode === 'mu'
	? [ 'mu_old_timestamp' => 130 * $day ]
	: [
		'months_overdue' => 130 * $day,
		'before_reminder' => 23 * $day - 1,
		'window_start' => 23 * $day,
		'after_window_start' => 23 * $day + 1,
		'before_expiry' => 30 * $day - 1,
		'at_expiry' => 30 * $day,
		'after_expiry' => 30 * $day + 1,
		'one_day_overdue' => 31 * $day,
		'many_days_overdue' => 45 * $day,
	];

foreach ( $cases as $label => $age ) {
	$last_reset = $now - $age;
	update_user_meta( $user_id, $last_reset_key, $last_reset );
	delete_user_meta( $user_id, $pending_key );
	list( $bar, $profile ) = sp_expiry_render( $profile_user, $label );
	sp_expiry_assert( (int) get_user_meta( $user_id, $last_reset_key, true ) === $last_reset, "$label changed timestamp" );
	sp_expiry_assert( ! get_user_meta( $user_id, $pending_key, true ), "$label changed reset state" );
	sp_expiry_assert( $profile !== '', "$label profile notice missing" );
	if ( $label === 'before_reminder' ) {
		sp_expiry_assert( $bar === null, 'admin bar appeared before reminder window' );
		sp_expiry_assert( $profile === sprintf( __( 'Next password change in %s days.', 'safety-passwords' ), 8 ), 'pre-window rounding changed' );
	} else {
		sp_expiry_assert( is_string( $bar ) && $bar !== '', "$label admin bar notice missing" );
	}
	if ( in_array( $label, [ 'window_start', 'after_window_start', 'before_expiry' ], true ) ) {
		$days = $label === 'before_expiry' ? 1 : 7;
		sp_expiry_assert( $bar === sprintf( __( 'Change password in %s days', 'safety-passwords' ), $days ), "$label admin bar rounding changed" );
		sp_expiry_assert( $profile === sprintf( __( 'Please, change your password in %s days.', 'safety-passwords' ), $days ), "$label profile rounding changed" );
	}
	if ( in_array( $label, [ 'at_expiry', 'after_expiry', 'one_day_overdue', 'many_days_overdue', 'months_overdue', 'mu_old_timestamp' ], true ) ) {
		sp_expiry_assert( ! preg_match( '/\bin\s+\d+\s+days?\b/i', $bar ), "$label admin bar still shows countdown" );
		sp_expiry_assert( ! preg_match( '/\bin\s+\d+\s+days?\b/i', $profile ), "$label profile still shows countdown" );
		$due = __( 'Password change is due. Change your password.', 'safety-passwords' );
		sp_expiry_assert( $bar === $due && $profile === $due, "$label due message missing" );
	}
}

if ( $mode === 'mu' ) {
	echo "PASS: MU old timestamp renders without negative countdown or metadata mutation\n";
	return;
}

$last_reset = $now - 130 * $day;
update_user_meta( $user_id, $last_reset_key, $last_reset );
list( $overdue_bar, $overdue_profile ) = sp_expiry_render( $profile_user, 'overdue baseline' );
update_user_meta( $user_id, $pending_key, true );
list( $pending_bar, $pending_profile ) = sp_expiry_render( $profile_user, 'reset pending' );
sp_expiry_assert( $pending_bar !== $overdue_bar && $pending_profile !== $overdue_profile, 'pending reset not distinct from overdue' );
$pending_notice = __( 'Password reset is required. Use the password recovery form.', 'safety-passwords' );
sp_expiry_assert( $pending_bar === $pending_notice && $pending_profile === $pending_notice, 'reset pending message missing' );
sp_expiry_assert( (int) get_user_meta( $user_id, $last_reset_key, true ) === $last_reset, 'pending reset changed timestamp' );
sp_expiry_assert( (bool) get_user_meta( $user_id, $pending_key, true ), 'pending reset changed state' );

update_user_meta( $user_id, $last_reset_key, $now );
list( $pending_bar, $pending_profile ) = sp_expiry_render( $profile_user, 'reset pending before reminder window' );
sp_expiry_assert( $pending_bar === $pending_notice && $pending_profile === $pending_notice, 'reset pending hidden before reminder window' );

sp_expiry_interval( 0 );
list( $bar, $profile ) = sp_expiry_render( $profile_user, 'disabled interval' );
sp_expiry_assert( $bar === null && $profile === '', 'disabled interval rendered reminder' );

sp_expiry_interval( 30 );
delete_user_meta( $user_id, $last_reset_key );
delete_user_meta( $user_id, $pending_key );
list( $bar, $profile ) = sp_expiry_render( $profile_user, 'missing timestamp' );
sp_expiry_assert( $bar === null && $profile !== '', 'missing timestamp fallback changed' );
sp_expiry_assert( ! metadata_exists( 'user', $user_id, $last_reset_key ), 'missing timestamp was persisted' );

sp_expiry_interval( 60 );
update_user_meta( $user_id, $last_reset_key, $now - 45 * $day );
sp_expiry_interval( 30 );
list( $bar, $profile ) = sp_expiry_render( $profile_user, 'shortened interval' );
$due = __( 'Password change is due. Change your password.', 'safety-passwords' );
sp_expiry_assert( $bar === $due && $profile === $due, 'shortened interval due message missing' );

$other_profile = new \WP_User();
$other_profile->ID = $user_id + 1;
ob_start();
General::addUserProfileNotice( $other_profile );
$other_notice = ob_get_clean();
sp_expiry_assert( $other_notice === '', 'other profile received current user notice' );

echo "PASS: expiry UI boundaries, overdue state, pending reset, disabled interval, fallback and profile scope\n";
}
