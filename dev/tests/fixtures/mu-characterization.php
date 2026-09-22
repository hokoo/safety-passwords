<?php

use iTRON\SafetyPasswords\Cron;

if ( DB_HOST !== 'db' || DB_NAME !== 'safety_passwords_integration' || get_option( 'safety_passwords_integration_target' ) !== 'isolated' ) {
	fwrite( STDERR, "FAIL: unsafe MU test target\n" );
	exit( 1 );
}
if ( ! class_exists( Cron::class ) ) {
	fwrite( STDERR, "FAIL: MU plugin did not boot\n" );
	exit( 1 );
}

echo wp_next_scheduled( Cron::EVENT_NAME )
	? "CHARACTERIZATION: MU startup has a cron event\n"
	: "CHARACTERIZATION: MU startup has no cron event\n";
