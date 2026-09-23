<?php

// Loaded as an MU plugin before Stream or Safety Passwords can write a record.
// Keep only two fixed, synthetic events; sanitize both Stream filter stages.
function sp_stream_test_message( $message ) {
	return in_array( $message, [ 'sp-stream-early', 'sp-stream-late' ], true );
}

add_filter( 'wp_stream_current_agent', function () {
	// Avoid Stream's WP-CLI POSIX account lookup even before record filtering.
	return 'synthetic';
} );

add_filter( 'wp_stream_log_data', function ( $data ) {
	if ( ! is_array( $data ) || 'ctm-logger' !== ( $data['connector'] ?? null ) || ! sp_stream_test_message( $data['message'] ?? null ) ) {
		return false;
	}
	$data['args'] = [];
	$data['object_id'] = 0;
	$data['user_id'] = 0;
	$data['context'] = 'general';
	$data['action'] = 'info';
	return $data;
}, PHP_INT_MAX );

add_filter( 'wp_stream_record_array', function ( $record ) {
	if ( ! is_array( $record ) || 'ctm-logger' !== ( $record['connector'] ?? null ) || ! sp_stream_test_message( $record['summary'] ?? null ) ) {
		return [];
	}
	$record['user_id'] = 0;
	$record['object_id'] = 0;
	$record['user_role'] = '';
	$record['ip'] = '';
	$record['context'] = 'general';
	$record['action'] = 'info';
	$record['meta'] = [ 'fixture' => 'synthetic' ];
	return $record;
}, PHP_INT_MAX );

if ( '1' === getenv( 'SP_STREAM_EARLY' ) ) {
	add_action( 'init', function () {
		$GLOBALS['sp_stream_test_early_before_registration'] = 0 === did_action( 'wp_stream_after_connectors_registration' );
		\iTRON\SafetyPasswords\General::getLogger()->info( 'sp-stream-early' );
	}, 6 );
}

if ( '1' === getenv( 'SP_STREAM_CUSTOM_LOGGER' ) ) {
	add_filter( 'itron/safety-passwords/logger', function () {
		return new class extends \Psr\Log\AbstractLogger {
			public function log( $level, $message, array $context = [] ) {
				$GLOBALS['sp_stream_test_custom_calls'] = ( $GLOBALS['sp_stream_test_custom_calls'] ?? 0 ) + 1;
			}
		};
	} );
}
