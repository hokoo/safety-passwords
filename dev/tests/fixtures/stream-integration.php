<?php

use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Loggers\Stream as SafetyStreamLogger;
use iTRON\SafetyPasswords\Integrations\StreamConnector;
use Psr\Log\NullLogger;

function sp_stream_assert( $condition, $reason ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: Stream integration $reason\n" );
		exit( 1 );
	}
}

sp_stream_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe target' );
sp_stream_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_stream_assert( class_exists( General::class ), 'Safety Passwords unavailable' );
$stage = $args[0] ?? '';

if ( 'absent' === $stage ) {
	sp_stream_assert( ! class_exists( 'WP_Stream\\Connector' ), 'Stream should be absent' );
	sp_stream_assert( General::getLogger() instanceof NullLogger, 'absent Stream should use fallback logger' );
	echo "PASS: absent Stream uses fallback logger\n";
	return;
}

sp_stream_assert( is_multisite(), 'expected disposable network' );
sp_stream_assert( isset( get_site_option( 'active_sitewide_plugins', [] )['stream/stream.php'] ), 'Stream network activation missing' );
sp_stream_assert( function_exists( 'wp_stream_get_instance' ), 'real Stream unavailable' );
$stream = wp_stream_get_instance();
sp_stream_assert( $stream->get_version() === '4.0.0', 'unexpected Stream version' );

if ( 'custom' === $stage ) {
	sp_stream_assert( ! General::getLogger() instanceof SafetyStreamLogger, 'custom logger overridden' );
	General::getLogger()->info( 'sp-stream-late' );
	sp_stream_assert( ( $GLOBALS['sp_stream_test_custom_calls'] ?? 0 ) === 1, 'custom logger did not receive event' );
	foreach ( $stream->connectors->connectors as $connector ) {
		sp_stream_assert( ! $connector instanceof StreamConnector, 'custom logger registered connector' );
	}
	global $wpdb;
	$table = $stream->db->driver->table;
	sp_stream_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) === 2, 'custom event persisted to Stream' );
	echo "PASS: custom logger override retained\n";
	return;
}

sp_stream_assert( 'write' === $stage, 'unknown stage' );
sp_stream_assert( General::getLogger() instanceof SafetyStreamLogger, 'Stream logger not selected' );
sp_stream_assert( ! empty( $GLOBALS['sp_stream_test_early_before_registration'] ), 'early event was not deferred' );
sp_stream_assert( did_action( 'wp_stream_after_connectors_registration' ) > 0, 'Stream registration event missing' );

$registered = 0;
foreach ( $stream->connectors->connectors as $connector ) {
	if ( $connector instanceof StreamConnector && $connector->name === 'ctm-logger' ) {
		$registered++;
		sp_stream_assert( has_action( 'safety_passwords_stream_logger_write', [ $connector, 'callback' ] ) !== false, 'real connector callback missing' );
		sp_stream_assert( is_callable( [ $connector, 'callback_safety_passwords_stream_logger_write' ] ), 'connector action handler missing' );
	}
}
sp_stream_assert( $registered === 1, 'connector count' );

global $wpdb;
$table = $stream->db->driver->table;
$meta_table = $stream->db->driver->table_meta;
sp_stream_assert( $table === $wpdb->base_prefix . 'stream' && $meta_table === $wpdb->base_prefix . 'stream_meta', 'unexpected Stream storage' );
$count = function ( $summary ) use ( $wpdb, $table ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE connector = %s AND summary = %s AND context = %s AND action = %s", 'ctm-logger', $summary, 'general', 'info' ) );
};
sp_stream_assert( $count( 'sp-stream-early' ) === 1, 'early event not persisted exactly once' );
sp_stream_assert( $count( 'sp-stream-late' ) === 0, 'late event already present' );
General::getLogger()->info( 'sp-stream-late' );
sp_stream_assert( $count( 'sp-stream-early' ) === 1 && $count( 'sp-stream-late' ) === 1, 'event persistence count' );
sp_stream_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) === 2, 'unexpected Stream record' );
sp_stream_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE user_id <> 0 OR object_id <> 0 OR ip <> '' OR user_role <> ''" ) === 0, 'identifying record field stored' );
sp_stream_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $meta_table WHERE meta_key <> %s OR meta_value <> %s", 'fixture', 'synthetic' ) ) === 0, 'identifying Stream metadata stored' );
sp_stream_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $meta_table" ) === 2, 'synthetic metadata count' );
echo "PASS: real Stream 4.0.0 connector, deferred and immediate writes, sanitized persistence\n";
