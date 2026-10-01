<?php

// This disposable MU fixture pauses one ordinary bootstrap after it claims the lease.
if ( 'holder' !== getenv( 'SP_TEST_RACE_ROLE' ) ) {
	return;
}

add_action( 'itron/safety-passwords/capabilities/set', function () {
	if ( DB_HOST !== 'db' || DB_NAME !== 'safety_passwords_integration' || get_option( 'safety_passwords_integration_target' ) !== 'isolated' ) {
		throw new RuntimeException( 'Unsafe lifecycle race target.' );
	}
	$base = WP_CONTENT_DIR . '/safety-passwords-integration-race';
	if ( false === file_put_contents( $base . '.held', 'held' ) ) {
		throw new RuntimeException( 'Lifecycle race barrier failed.' );
	}
	$deadline = microtime( true ) + 30;
	while ( ! file_exists( $base . '.release' ) ) {
		if ( microtime( true ) >= $deadline ) {
			throw new RuntimeException( 'Lifecycle race barrier timed out.' );
		}
		usleep( 50000 );
		clearstatcache( true, $base . '.release' );
	}
} );
