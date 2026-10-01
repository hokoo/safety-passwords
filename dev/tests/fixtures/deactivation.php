<?php

if ( DB_HOST !== 'db' || DB_NAME !== 'safety_passwords_integration' || get_option( 'safety_passwords_integration_target' ) !== 'isolated' ) {
	fwrite( STDERR, "FAIL: unsafe deactivation test target\n" );
	exit( 1 );
}

if ( wp_next_scheduled( 'safety_passwords_periodically_reset' ) ) {
	fwrite( STDERR, "FAIL: plugin deactivation retained cron event\n" );
	exit( 1 );
}

echo "PASS: ordinary deactivation removed cron event\n";
