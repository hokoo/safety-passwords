<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Settings;

function sp_cli_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: CLI password $label\n" );
		exit( 1 );
	}
}

function sp_cli_user( $mode, $kind ) {
	$user = get_user_by( 'login', 'sp-cli-' . $mode . '-' . $kind );
	sp_cli_assert( $user instanceof WP_User, 'synthetic account missing' );
	return $user;
}

function sp_cli_history( $user ) {
	$history = get_user_meta( $user->ID, Controller::USER_STOP_LIST_META_KEY, true );
	return is_array( $history ) ? $history : [];
}

function sp_cli_pending( $user ) {
	$prefix = Settings::$optionPrefix;
	return get_user_meta( $user->ID, $prefix . 'rp_inited', true ) === '1' &&
		get_user_meta( $user->ID, $prefix . 'rp_pre_inited', true ) === '1' &&
		(int) get_user_meta( $user->ID, $prefix . 'last_reset', true ) < time() - DAY_IN_SECONDS;
}

function sp_cli_complete( $user, $history_size ) {
	$history = sp_cli_history( $user );
	$prefix = Settings::$optionPrefix;
	sp_cli_assert( count( $history ) === $history_size, 'history count' );
	sp_cli_assert( end( $history ) === get_userdata( $user->ID )->user_pass, 'saved hash absent from history' );
	sp_cli_assert( get_user_meta( $user->ID, $prefix . 'rp_inited', true ) === '', 'hard reset flag retained' );
	sp_cli_assert( get_user_meta( $user->ID, $prefix . 'rp_pre_inited', true ) === '', 'soft reset flag retained' );
	sp_cli_assert( (int) get_user_meta( $user->ID, $prefix . 'last_reset', true ) >= time() - MINUTE_IN_SECONDS, 'expiry not renewed' );
	wp_set_current_user( $user->ID );
	ob_start();
	General::addUserProfileNotice( $user );
	$notice = wp_strip_all_tags( ob_get_clean() );
	sp_cli_assert( strpos( $notice, 'Next password change' ) !== false, 'reset reminder retained' );
}

function sp_cli_web_rejects( $user, $candidate ) {
	$proposed = get_userdata( $user->ID );
	$proposed->user_pass = $candidate;
	$errors = new WP_Error();
	do_action( 'user_profile_update_errors', $errors, true, $proposed );
	sp_cli_assert( in_array( Controller::PASSWORD_CHECK_FAILURE_CODE, $errors->get_error_codes(), true ), 'web profile validation accepted password' );
}

sp_cli_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
if ( is_multisite() ) {
	switch_to_blog( (int) get_network()->site_id );
}
sp_cli_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
if ( is_multisite() ) {
	restore_current_blog();
}
sp_cli_assert( class_exists( Controller::class ) && class_exists( General::class ), 'plugin unavailable' );
$stage = $args[0] ?? '';
$mode = $args[1] ?? '';
sp_cli_assert( in_array( $stage, [ 'prepare', 'weak', 'arm', 'unchanged', 'reused', 'reset', 'partial', 'multi', 'network-observe', 'finish' ], true ), 'invalid stage' );
sp_cli_assert( in_array( $mode, [ 'ordinary', 'mu', 'network' ], true ), 'invalid mode' );
sp_cli_assert( ( 'network' === $mode ) === is_multisite(), 'wrong site mode' );
$prefix = Settings::$optionPrefix;

if ( 'finish' === $stage ) {
	$restore_interval = isset( $args[2] ) ? (int) $args[2] : -1;
	sp_cli_assert( in_array( $restore_interval, [ 1, 30 ], true ), 'invalid restored interval' );
	if ( is_multisite() ) {
		carbon_set_network_option( get_current_network_id(), $prefix . 'reset_interval', $restore_interval );
	} else {
		carbon_set_theme_option( $prefix . 'reset_interval', $restore_interval );
	}
	wp_cache_delete( 'reset_interval', 'safety-passwords' );
	sp_cli_assert( Settings::getInterval() === $restore_interval, 'interval restoration' );
	echo "PASS: CLI password fixture restored interval\n";
	return;
}

if ( 'prepare' === $stage ) {
	if ( is_multisite() ) {
		carbon_set_network_option( get_current_network_id(), $prefix . 'reset_interval', 30 );
	} else {
		carbon_set_theme_option( $prefix . 'reset_interval', 30 );
	}
	wp_cache_delete( 'reset_interval', 'safety-passwords' );
	sp_cli_assert( Settings::getInterval() === 30, 'interval setup' );
	foreach ( [ 'primary', 'secondary' ] as $kind ) {
		$login = 'sp-cli-' . $mode . '-' . $kind;
		$password = wp_generate_password( 32, true, false );
		$id = wp_create_user( $login, $password, $login . '@example.invalid' );
		unset( $password );
		sp_cli_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
		if ( is_multisite() ) {
			remove_user_from_blog( $id, get_current_blog_id() );
		}
		sp_cli_assert( sp_cli_history( get_userdata( $id ) ) === [], 'unexpected initial history' );
		update_user_meta( $id, $prefix . 'rp_inited', true );
		update_user_meta( $id, $prefix . 'rp_pre_inited', true );
		update_user_meta( $id, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
	}
	echo "PASS: CLI password fixture prepared\n";
	return;
}

$primary = sp_cli_user( $mode, 'primary' );
$secondary = sp_cli_user( $mode, 'secondary' );
if ( 'network-observe' === $stage ) {
	$expected_site_id = $args[2] ?? '';
	sp_cli_assert( is_string( $expected_site_id ) && ctype_digit( $expected_site_id ) && (int) $expected_site_id > 0, 'invalid expected subsite ID' );
	$subsite = get_site( (int) $expected_site_id );
	sp_cli_assert( $subsite instanceof WP_Site, 'created subsite missing' );
	sp_cli_assert( (int) $subsite->network_id === get_current_network_id(), 'created subsite belongs to another network' );
	sp_cli_assert( (int) $subsite->blog_id !== (int) get_network()->site_id, 'created site is the network main site' );
	sp_cli_assert( get_current_blog_id() === (int) $subsite->blog_id, 'CLI did not select created subsite' );
	sp_cli_complete( $primary, 7 );
	echo "PASS: multisite account policy state is global\n";
	return;
}
if ( 'weak' === $stage ) {
	$candidate = trim( stream_get_contents( STDIN ) );
	sp_cli_assert( $candidate !== '' && ! Controller::is_password_secure( $candidate, $primary ), 'candidate is not weak' );
	sp_cli_complete( $primary, 2 );
	$history = sp_cli_history( $primary );
	sp_cli_assert( $history[0] !== $history[1], 'previous hash not preserved' );
	sp_cli_assert( wp_check_password( $candidate, $history[1] ), 'weak password absent from history' );
	sp_cli_web_rejects( $primary, $candidate );
	echo "PASS: weak CLI update completed reset and preserved history\n";
	return;
}

if ( 'arm' === $stage ) {
	update_user_meta( $primary->ID, $prefix . 'rp_inited', true );
	update_user_meta( $primary->ID, $prefix . 'rp_pre_inited', true );
	update_user_meta( $primary->ID, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
	sp_cli_assert( sp_cli_pending( $primary ), 'reset setup failed' );
	echo "PASS: CLI password reset state armed\n";
	return;
}

if ( 'unchanged' === $stage ) {
	$candidate = trim( stream_get_contents( STDIN ) );
	sp_cli_assert( $candidate !== '', 'missing transient failed-write candidate' );
	sp_cli_assert( sp_cli_pending( $primary ), 'non-password or failed command cleared pending state' );
	sp_cli_assert( count( sp_cli_history( $primary ) ) === 2, 'non-password or failed command changed history' );
	$history = sp_cli_history( $primary );
	$persisted = get_userdata( $primary->ID );
	sp_cli_assert( end( $history ) === $persisted->user_pass, 'non-password or failed command changed password' );
	sp_cli_assert( ! wp_check_password( $candidate, $persisted->user_pass ), 'rejected candidate became active' );
	sp_cli_assert( $persisted->user_email === 'sp-cli-' . $mode . '-primary@example.invalid', 'rejected email change persisted' );
	sp_cli_assert( email_exists( 'sp-cli-' . $mode . '-secondary@example.invalid' ) === $secondary->ID, 'conflicting email ownership changed' );
	echo "PASS: non-password and failed CLI updates preserved policy state\n";
	return;
}

if ( 'reused' === $stage ) {
	$candidate = trim( stream_get_contents( STDIN ) );
	sp_cli_assert( $candidate !== '', 'missing transient candidate' );
	sp_cli_complete( $primary, 4 );
	$history = sp_cli_history( $primary );
	sp_cli_assert( wp_check_password( $candidate, $history[2] ) && wp_check_password( $candidate, $history[3] ), 'reused password not recorded twice' );
	Controller::completeCliPasswordChange( $primary, $history[2], $history[3] );
	sp_cli_assert( count( sp_cli_history( $primary ) ) === 4, 'repeated saved hash duplicated' );
	sp_cli_web_rejects( $primary, $candidate );
	echo "PASS: CLI reuse accepted and later web reuse rejected\n";
	return;
}

if ( 'reset' === $stage ) {
	sp_cli_complete( $primary, 5 );
	echo "PASS: CLI reset-password completed policy state\n";
	return;
}

if ( 'multi' === $stage ) {
	sp_cli_complete( $primary, 7 );
	sp_cli_complete( $secondary, 2 );
	echo "PASS: CLI batch reset completed both accounts with one disclaimer\n";
	return;
}

sp_cli_complete( $primary, 6 );
sp_cli_assert( sp_cli_pending( $secondary ), 'failed batch account cleared pending state' );
sp_cli_assert( sp_cli_history( $secondary ) === [], 'failed batch account changed history' );
echo "PASS: partial CLI batch updated only persisted account\n";
