<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Settings;
use iTRON\SafetyPasswords\UserScope;

function sp_cli_privacy_assert( $condition, $reason ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: CLI Stream privacy $reason\n" );
		exit( 1 );
	}
}

sp_cli_privacy_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_cli_privacy_assert( is_multisite() && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_cli_privacy_assert( get_current_blog_id() === (int) get_network()->site_id, 'main site context missing' );
$stage = $args[0] ?? '';
sp_cli_privacy_assert( in_array( $stage, [ 'prepare', 'check' ], true ), 'unknown phase' );
$prefix = Settings::$optionPrefix;
$marker = 'safety_passwords_integration_cli_kind';
$old_interval_option = 'safety_passwords_integration_cli_old_interval';

if ( 'prepare' === $stage ) {
	sp_cli_privacy_assert( add_option( $old_interval_option, Settings::getOption( 'reset_interval' ), '', false ), 'previous policy snapshot unavailable' );
	carbon_set_network_option( get_current_network_id(), $prefix . 'reset_interval', 999 );
	sp_cli_privacy_assert( Settings::getInterval() === 999, 'fixture policy missing' );
	$created = [];
	foreach ( [ 'due', 'reminder' ] as $kind ) {
		$login = 'sp-cli-' . strtolower( wp_generate_password( 12, false, false ) );
		$password = wp_generate_password( 32, true, false );
		$id = wp_create_user( $login, $password, $login . '@example.invalid' );
		unset( $password );
		sp_cli_privacy_assert( is_int( $id ) && $id > 0, 'fixture account creation' );
		$created[] = $id;
		update_user_meta( $id, $marker, $kind );
		delete_user_meta( $id, $prefix . 'rp_inited' );
		delete_user_meta( $id, $prefix . 'rp_pre_inited' );
		$last_reset = 'due' === $kind ? DAY_IN_SECONDS : time() - 999 * DAY_IN_SECONDS + HOUR_IN_SECONDS;
		update_user_meta( $id, $prefix . 'last_reset', $last_reset );
	}
	$threshold = 999 * DAY_IN_SECONDS - General::getPreInitInterval();
	foreach ( UserScope::userIds() as $id ) {
		if ( in_array( (int) $id, $created, true ) || '1' === get_user_meta( $id, $prefix . 'rp_inited', true ) ) {
			continue;
		}
		$last_reset = (int) get_user_meta( $id, $prefix . 'last_reset', true );
		sp_cli_privacy_assert( ! $last_reset || time() - $last_reset <= $threshold, 'other account would affect exact counts' );
	}
	echo "PASS: isolated CLI due and reminder accounts prepared\n";
	return;
}

$due = get_users( [ 'meta_key' => $marker, 'meta_value' => 'due', 'fields' => 'ids', 'blog_id' => 0, 'number' => 0 ] );
$reminder = get_users( [ 'meta_key' => $marker, 'meta_value' => 'reminder', 'fields' => 'ids', 'blog_id' => 0, 'number' => 0 ] );
sp_cli_privacy_assert( count( $due ) === 1 && count( $reminder ) === 1, 'fixture accounts missing' );
sp_cli_privacy_assert( '1' === get_user_meta( $due[0], $prefix . 'rp_inited', true ), 'CLI did not reset due account' );
sp_cli_privacy_assert( '1' === get_user_meta( $reminder[0], $prefix . 'rp_pre_inited', true ) && ! get_user_meta( $reminder[0], $prefix . 'rp_inited', true ), 'CLI did not mark reminder account' );

global $wpdb;
$stream = wp_stream_get_instance();
$table = $stream->db->driver->table;
$meta_table = $stream->db->driver->table_meta;
sp_cli_privacy_assert( $table === $wpdb->base_prefix . 'stream' && $meta_table === $wpdb->base_prefix . 'stream_meta', 'unexpected Stream storage' );
foreach ( [ 'Users to reset.', 'Users to pre-init.' ] as $message ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE connector = %s AND summary = %s AND context = %s AND action = %s", 'ctm-logger', $message, 'general', 'info' ) );
	sp_cli_privacy_assert( $count === 1, 'CLI aggregate event not persisted exactly once' );
}
sp_cli_privacy_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key = %s AND meta_value = %s", 'count', '1' ) ) === 2, 'CLI aggregate counts not persisted' );
sp_cli_privacy_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key = %s AND meta_value <> %s", 'count', '1' ) ) === 0, 'unexpected CLI aggregate count stored' );
sp_cli_privacy_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE connector = 'ctm-logger' AND (summary LIKE 'Users to reset:%' OR summary LIKE 'Users to pre-init:%')" ) === 0, 'identifier-bearing CLI message stored' );
sp_cli_privacy_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE connector = 'ctm-logger' AND (user_id <> 0 OR object_id <> 0 OR ip <> '' OR user_role <> '')" ) === 0, 'identifying Stream actor field stored' );

carbon_set_network_option( get_current_network_id(), $prefix . 'reset_interval', get_option( $old_interval_option ) );
delete_option( $old_interval_option );
if ( ! function_exists( 'wpmu_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}
sp_cli_privacy_assert( wpmu_delete_user( $due[0] ) && wpmu_delete_user( $reminder[0] ), 'fixture account cleanup' );
echo "PASS: real CLI aggregate logs persisted without account identifiers\n";
