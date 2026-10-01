<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Loggers\Stream as SafetyStreamLogger;
use iTRON\SafetyPasswords\Settings;

function sp_stream_failure_test_assert( $condition, $reason ) {
	if ( ! $condition ) {
		throw new RuntimeException( "Stream failure privacy: $reason" );
	}
}

sp_stream_failure_test_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_stream_failure_test_assert( is_multisite() && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_stream_failure_test_assert( get_current_blog_id() === (int) get_network()->site_id, 'main site context missing' );
sp_stream_failure_test_assert( General::getLogger() instanceof SafetyStreamLogger, 'Stream logger unavailable' );
sp_stream_failure_test_assert( '1' === getenv( 'SP_STREAM_FAILURE_PRIVACY' ), 'privacy preloader disabled' );

$prefix = Settings::$optionPrefix;
$network_id = get_current_network_id();
$old_interval = Settings::getOption( 'reset_interval' );
$created = [];
try {
	$soft_login = 'sp-soft-' . strtolower( wp_generate_password( 12, false, false ) );
	$hard_login = 'sp-hard-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, false );
	$soft_id = wp_create_user( $soft_login, $password, $soft_login . '@example.invalid' );
	if ( is_int( $soft_id ) && $soft_id > 0 ) {
		$created[] = $soft_id;
	}
	$hard_id = wp_create_user( $hard_login, $password, $hard_login . '@example.invalid' );
	if ( is_int( $hard_id ) && $hard_id > 0 ) {
		$created[] = $hard_id;
	}
	unset( $password );
	sp_stream_failure_test_assert( count( $created ) === 2, 'fixture account creation' );

	carbon_set_network_option( $network_id, $prefix . 'reset_interval', 1 );
	sp_stream_failure_test_assert( Settings::getInterval() === 1, 'interval setup' );
	update_user_meta( $soft_id, $prefix . 'rp_pre_inited', true );
	$fail_recovery = function ( $errors ) {
		$errors->add( 'sp_fixture_failure', 'Fixture recovery failure.' );
	};
	add_action( 'lostpassword_post', $fail_recovery, 1 );
	$redirect = '/fixture-redirect';
	sp_stream_failure_test_assert( Controller::login_redirect( $redirect, '', get_userdata( $soft_id ) ) === $redirect, 'soft failure changed login redirect' );
	remove_action( 'lostpassword_post', $fail_recovery, 1 );

	update_user_meta( $hard_id, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
	$GLOBALS['safety_passwords_test_mail_success'] = false;
	Controller::findExpiringPasswords();
	sp_stream_failure_test_assert( '1' === get_user_meta( $hard_id, $prefix . 'rp_inited', true ), 'hard failure did not retain mandatory reset' );
	sp_stream_failure_test_assert( ( $GLOBALS['sp_stream_failure_seen']['soft'] ?? 0 ) === 1, 'soft failure event count' );
	sp_stream_failure_test_assert( ( $GLOBALS['sp_stream_failure_seen']['hard'] ?? 0 ) >= 1, 'hard failure event missing' );
	sp_stream_failure_test_assert( ( $GLOBALS['sp_stream_failure_seen']['checking'] ?? 0 ) === 1 && ( $GLOBALS['sp_stream_failure_seen']['summary'] ?? 0 ) === 1, 'periodic summary event count' );

	global $wpdb;
	$stream = wp_stream_get_instance();
	$table = $stream->db->driver->table;
	$meta_table = $stream->db->driver->table_meta;
	sp_stream_failure_test_assert( $table === $wpdb->base_prefix . 'stream' && $meta_table === $wpdb->base_prefix . 'stream_meta', 'unexpected Stream storage' );
	$count = function ( $message ) use ( $wpdb, $table ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE connector = %s AND summary = %s", 'ctm-logger', $message ) );
	};
	sp_stream_failure_test_assert( $count( 'Failed to initiate a password reset at login.' ) === 1, 'soft failure not persisted' );
	sp_stream_failure_test_assert( $count( 'Failed to send a periodic password reset request.' ) >= 1, 'hard failure not persisted' );
	sp_stream_failure_test_assert( $count( 'Checking users for password reset.' ) === 1, 'periodic check not persisted' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE connector = 'ctm-logger' AND summary LIKE 'Number of users to reset password immediately:%'" ) === 1, 'periodic summary not persisted' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key = %s AND meta_value = %s", 'category', 'reset_request_failed' ) ) >= 1, 'fixed request failure category not persisted' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key = %s AND meta_value = %s", 'category', 'mail_delivery_failed' ) ) >= 1, 'fixed mail failure category not persisted' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key = %s", 'resetCount' ) ) === 1, 'summary reset count not persisted' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key = %s", 'reminderCount' ) ) === 1, 'summary reminder count not persisted' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $meta_table WHERE meta_key NOT IN ('fixture', 'category', 'resetCount', 'reminderCount')" ) === 0, 'unexpected Stream metadata stored' );
	sp_stream_failure_test_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE connector = 'ctm-logger' AND (user_id <> 0 OR object_id <> 0 OR ip <> '' OR user_role <> '')" ) === 0, 'identifying Stream actor field stored' );
	echo "PASS: real Stream failure-path payloads, fixed categories, counts and sanitized persistence\n";
} finally {
	carbon_set_network_option( $network_id, $prefix . 'reset_interval', $old_interval );
	if ( ! function_exists( 'wpmu_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	foreach ( $created as $id ) {
		sp_stream_failure_test_assert( wpmu_delete_user( $id ), 'fixture account cleanup' );
	}
}
