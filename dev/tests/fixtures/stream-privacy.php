<?php

// Loaded as an MU plugin before Stream or Safety Passwords can write a record.
// Keep only two fixed, synthetic events; sanitize both Stream filter stages.
function sp_stream_test_message( $message ) {
	return in_array( $message, [ 'sp-stream-early', 'sp-stream-late' ], true );
}

function sp_stream_failure_type( $message ) {
	if ( 'Failed to initiate a password reset at login.' === $message ) {
		return 'soft';
	}
	if ( 'Failed to send a periodic password reset request.' === $message ) {
		return 'hard';
	}
	if ( 'Checking users for password reset.' === $message ) {
		return 'checking';
	}
	if ( is_string( $message ) && preg_match( '/^Number of users to reset password immediately: [0-9]+\. Number of users to remind to reset password soon: [0-9]+$/D', $message ) ) {
		return 'summary';
	}
	return '';
}

function sp_stream_cli_type( $message ) {
	if ( 'Checking users for password reset.' === $message ) {
		return 'checking';
	}
	if ( 'Failed to send a periodic password reset request.' === $message ) {
		return 'hard';
	}
	if ( 'Users to reset.' === $message ) {
		return 'reset';
	}
	if ( 'Users to pre-init.' === $message ) {
		return 'reminder';
	}
	return '';
}

function sp_stream_failure_assert( $condition, $reason ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: Stream failure privacy $reason\n" );
		exit( 1 );
	}
}

if ( '1' === getenv( 'SP_STREAM_FAILURE_PRIVACY' ) ) {
	add_action( 'safety_passwords_stream_logger_write', function ( $level, $message, $meta, $module ) {
		$type = sp_stream_failure_type( $message );
		sp_stream_failure_assert( '' !== $type && 'general' === $module, 'unexpected product payload before insertion' );
		sp_stream_failure_assert( ( 'soft' === $type || 'hard' === $type ? 'error' : 'info' ) === $level, 'unexpected product level' );
		if ( 'soft' === $type || 'hard' === $type ) {
			$expected = 'soft' === $type ? 'reset_request_failed' : 'mail_delivery_failed';
			sp_stream_failure_assert( [ 'category' => $expected ] === $meta, 'unsafe failure context' );
		} elseif ( 'summary' === $type ) {
			sp_stream_failure_assert( is_array( $meta ) && array_keys( $meta ) === [ 'resetCount', 'reminderCount' ] && is_int( $meta['resetCount'] ) && is_int( $meta['reminderCount'] ), 'unsafe summary context' );
			$expected = sprintf( 'Number of users to reset password immediately: %s. Number of users to remind to reset password soon: %s', $meta['resetCount'], $meta['reminderCount'] );
			sp_stream_failure_assert( $expected === $message, 'summary count mismatch' );
		} else {
			sp_stream_failure_assert( [] === $meta, 'unsafe check context' );
		}
		$GLOBALS['sp_stream_failure_seen'][ $type ] = ( $GLOBALS['sp_stream_failure_seen'][ $type ] ?? 0 ) + 1;
	}, 1, 4 );
}

if ( '1' === getenv( 'SP_STREAM_CLI_PRIVACY' ) ) {
	add_action( 'safety_passwords_stream_logger_write', function ( $level, $message, $meta, $module ) {
		$type = sp_stream_cli_type( $message );
		sp_stream_failure_assert( '' !== $type && 'general' === $module, 'unexpected CLI product payload before insertion' );
		sp_stream_failure_assert( ( 'hard' === $type ? 'error' : 'info' ) === $level, 'unexpected CLI product level' );
		if ( 'hard' === $type ) {
			sp_stream_failure_assert( [ 'category' => 'mail_delivery_failed' ] === $meta, 'unsafe CLI failure context' );
		} elseif ( 'reset' === $type || 'reminder' === $type ) {
			sp_stream_failure_assert( [ 'count' => 1 ] === $meta, 'unsafe CLI result context or count' );
		} else {
			sp_stream_failure_assert( [] === $meta, 'unsafe CLI check context' );
		}
	}, 1, 4 );
}

add_filter( 'wp_stream_current_agent', function () {
	// Avoid Stream's WP-CLI POSIX account lookup even before record filtering.
	return 'synthetic';
} );

add_filter( 'wp_stream_log_data', function ( $data ) {
	if ( '1' === getenv( 'SP_STREAM_CLI_PRIVACY' ) ) {
		if ( ! is_array( $data ) || 'ctm-logger' !== ( $data['connector'] ?? null ) ) {
			return false;
		}
		sp_stream_failure_assert( '' !== sp_stream_cli_type( $data['message'] ?? null ), 'unexpected CLI Stream data before insertion' );
		$data['object_id'] = 0;
		$data['user_id'] = 0;
		return $data;
	}
	if ( '1' === getenv( 'SP_STREAM_FAILURE_PRIVACY' ) ) {
		if ( ! is_array( $data ) || 'ctm-logger' !== ( $data['connector'] ?? null ) ) {
			return false;
		}
		sp_stream_failure_assert( '' !== sp_stream_failure_type( $data['message'] ?? null ), 'unexpected Stream data before insertion' );
		$data['object_id'] = 0;
		$data['user_id'] = 0;
		return $data;
	}
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
	if ( '1' === getenv( 'SP_STREAM_CLI_PRIVACY' ) ) {
		if ( ! is_array( $record ) || 'ctm-logger' !== ( $record['connector'] ?? null ) ) {
			return [];
		}
		$type = sp_stream_cli_type( $record['summary'] ?? null );
		sp_stream_failure_assert( '' !== $type, 'unexpected CLI Stream record before insertion' );
		$allowed = 'hard' === $type ? [ 'category' ] : ( 'checking' === $type ? [] : [ 'count' ] );
		$meta = [];
		foreach ( $allowed as $key ) {
			sp_stream_failure_assert( isset( $record['meta'][ $key ] ), 'CLI context missing from Stream record' );
			$meta[ $key ] = $record['meta'][ $key ];
		}
		$record['meta'] = $meta;
		$record['user_id'] = 0;
		$record['object_id'] = 0;
		$record['user_role'] = '';
		$record['ip'] = '';
		return $record;
	}
	if ( '1' === getenv( 'SP_STREAM_FAILURE_PRIVACY' ) ) {
		if ( ! is_array( $record ) || 'ctm-logger' !== ( $record['connector'] ?? null ) ) {
			return [];
		}
		$type = sp_stream_failure_type( $record['summary'] ?? null );
		sp_stream_failure_assert( '' !== $type, 'unexpected Stream record before insertion' );
		$allowed = 'summary' === $type ? [ 'resetCount', 'reminderCount' ] : ( 'checking' === $type ? [] : [ 'category' ] );
		$meta = [];
		foreach ( $allowed as $key ) {
			sp_stream_failure_assert( isset( $record['meta'][ $key ] ), 'product context missing from Stream record' );
			$meta[ $key ] = $record['meta'][ $key ];
		}
		$record['meta'] = $meta;
		$record['user_id'] = 0;
		$record['object_id'] = 0;
		$record['user_role'] = '';
		$record['ip'] = '';
		return $record;
	}
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
