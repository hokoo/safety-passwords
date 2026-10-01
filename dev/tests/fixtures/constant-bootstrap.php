<?php

// WP-CLI --require loads this before WordPress and the active plugin.
$cases = [
	'false_bool'   => [ false, 8, 0 ],
	'false_string' => [ 'false', '8', '0' ],
	'true_bool'    => [ true, 8, 3 ],
	'true_string'  => [ 'true', '8', '3' ],
	'zero_int'     => [ 0, 8, 0 ],
	'zero_string'  => [ '0', '8', '0' ],
	'one_int'      => [ 1, 8, 3 ],
	'one_string'   => [ '1', '8', '3' ],
	'decimal_min'  => [ false, '8.5', 0 ],
];

$case = getenv( 'SP_TEST_CONSTANT_CASE' );
if ( ! isset( $cases[ $case ] ) ) {
	fwrite( STDERR, "FAIL: unknown constant scenario\n" );
	exit( 1 );
}

define( 'SAFETY_PASSWORDS_RP_ON_REGISTRATION', $cases[ $case ][0] );
define( 'SAFETY_PASSWORDS_MIN_LEN', $cases[ $case ][1] );
define( 'SAFETY_PASSWORDS_RESET_INTERVAL', $cases[ $case ][2] );
