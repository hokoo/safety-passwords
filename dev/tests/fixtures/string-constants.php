<?php

use Carbon_Fields\Carbon_Fields;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_constant_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: constant scenario $label\n" );
		exit( 1 );
	}
}

sp_constant_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_constant_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_constant_assert( class_exists( Settings::class ) && class_exists( Controller::class ), 'plugin did not boot after constants' );
sp_constant_assert( function_exists( 'carbon_set_theme_option' ), 'Carbon Fields did not boot' );

$case = getenv( 'SP_TEST_CONSTANT_CASE' );
$expected_enabled = in_array( $case, [ 'true_bool', 'true_string', 'one_int', 'one_string' ], true );
$expected_min = 'decimal_min' === $case ? '8.5' : 8;
$expected_interval = in_array( $case, [ 'true_bool', 'true_string', 'one_int', 'one_string' ], true ) ? 3 : 0;
$prefix = Settings::$optionPrefix;
$stored = [];
foreach ( [ 'rp_on_registration', 'min_len', 'reset_interval' ] as $slug ) {
	$stored[ $slug ] = carbon_get_theme_option( $prefix . $slug );
}
carbon_set_theme_option( $prefix . 'rp_on_registration', $expected_enabled ? '' : 'yes' );
carbon_set_theme_option( $prefix . 'min_len', 24 );
carbon_set_theme_option( $prefix . 'reset_interval', $expected_interval ? 0 : 9 );
foreach ( array_keys( $stored ) as $slug ) {
	wp_cache_delete( $slug, 'safety-passwords' );
}

sp_constant_assert( Settings::getOption( 'rp_on_registration' ) === $expected_enabled, 'boolean override or type' );
sp_constant_assert( Settings::getOption( 'min_len' ) === $expected_min, 'minimum override or type' );
sp_constant_assert( Settings::getOption( 'reset_interval' ) === $expected_interval, 'interval override or type' );
sp_constant_assert( Settings::getInterval() === $expected_interval, 'runtime interval' );

$repository = Carbon_Fields::resolve( 'container_repository' );
$settings = null;
foreach ( $repository->get_containers( 'theme_options' ) as $container ) {
	if ( 'carbon_fields_container_safety_passwords' === $container->get_id() ) {
		$settings = $container;
		break;
	}
}
sp_constant_assert( $settings !== null, 'settings container missing' );
$html = [];
$overridden_names = [
	$prefix . 'rp_on_registration_disabled',
	$prefix . 'min_len_disabled',
	$prefix . 'reset_interval_disabled',
];
foreach ( $settings->get_fields() as $field ) {
	if ( in_array( $field->get_base_name(), $overridden_names, true ) ) {
		$html[ $field->get_base_name() ] = $field->to_json( false )['html'];
	}
}
sp_constant_assert( count( $html ) === 3, 'overridden fields not shown' );
sp_constant_assert( strpos( $html[ $prefix . 'rp_on_registration_disabled' ], $expected_enabled ? '[Enabled]' : '[Disabled]' ) === 0, 'boolean UI indication' );
sp_constant_assert( strpos( $html[ $prefix . 'min_len_disabled' ], '[' . $expected_min . ']' ) === 0, 'minimum UI indication' );
sp_constant_assert( strpos( $html[ $prefix . 'reset_interval_disabled' ], '[' . $expected_interval . ']' ) === 0, 'interval UI indication' );

$login = 'sp-constant-' . strtolower( wp_generate_password( 12, false, false ) );
$initial_password = wp_generate_password( 32, true, false );
$user_id = wp_create_user( $login, $initial_password, $login . '@example.invalid' );
unset( $initial_password );
sp_constant_assert( is_int( $user_id ) && $user_id > 0, 'synthetic registration' );
$preinit_key = $prefix . 'rp_pre_inited';
sp_constant_assert( (bool) get_user_meta( $user_id, $preinit_key, true ) === $expected_enabled, 'registration reset flag' );
do_action( 'register_new_user', $user_id );
sp_constant_assert( ! get_user_meta( $user_id, $preinit_key, true ), 'self-registration reset flag' );
if ( $expected_enabled ) {
	update_user_meta( $user_id, $preinit_key, true );
}

$user = get_userdata( $user_id );
$password_7 = chr( 65 ) . chr( 97 ) . chr( 49 ) . chr( 33 ) . wp_generate_password( 3, false, false );
$password_8 = chr( 65 ) . chr( 97 ) . chr( 49 ) . chr( 33 ) . wp_generate_password( 4, false, false );
$password_9 = $password_8 . chr( 120 );
$eight_is_valid = 'decimal_min' !== $case;
$errors = new WP_Error();
$_POST['pass1'] = $password_7;
do_action( 'validate_password_reset', $errors, $user );
sp_constant_assert( (bool) $errors->get_error_codes(), 'reset accepted seven characters' );

$errors = new WP_Error();
$_POST['pass1'] = $password_8;
do_action( 'validate_password_reset', $errors, $user );
sp_constant_assert( (bool) $errors->get_error_codes() !== $eight_is_valid, 'reset minimum at eight characters' );
if ( ! $eight_is_valid ) {
	sp_constant_assert( (bool) get_user_meta( $user_id, $preinit_key, true ) === $expected_enabled, 'rejected reset changed flag' );
}

$errors = new WP_Error();
$_POST['pass1'] = $password_9;
do_action( 'validate_password_reset', $errors, $user );
sp_constant_assert( ! $errors->get_error_codes(), 'reset minimum at nine characters' );
reset_password( $user, $password_9 );
unset( $_POST['pass1'], $password_7, $password_8, $password_9 );
sp_constant_assert( ! get_user_meta( $user_id, $preinit_key, true ), 'successful reset left reminder flag' );

// Keep the positive case inside the reminder window, away from either boundary.
$password_age = $expected_interval > 0 ? 2 * DAY_IN_SECONDS : DAY_IN_SECONDS;
update_user_meta( $user_id, $prefix . 'last_reset', time() - $password_age );
delete_user_meta( $user_id, $preinit_key );
do_action( Cron::EVENT_NAME );
sp_constant_assert( (bool) get_user_meta( $user_id, $preinit_key, true ) === ( $expected_interval > 0 ), 'cron zero or positive reminder' );
sp_constant_assert( ! get_user_meta( $user_id, $prefix . 'rp_inited', true ), 'cron unexpectedly started mandatory reset' );

foreach ( $stored as $slug => $value ) {
	carbon_set_theme_option( $prefix . $slug, $value );
	wp_cache_delete( $slug, 'safety-passwords' );
}

// Leave no expired account for the later network policy scenario.
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
sp_constant_assert( wp_delete_user( $user_id ), 'synthetic account cleanup' );

echo "PASS: constant scenario registration, reset minimum, cron interval, settings override\n";
