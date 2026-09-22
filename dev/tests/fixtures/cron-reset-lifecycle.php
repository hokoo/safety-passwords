<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_cron_reset_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: cron reset $label\n" );
		exit( 1 );
	}
}

function sp_cron_reset_user( $marker ) {
	$ids = get_users( [
		'fields' => 'ids',
		'meta_key' => 'safety_passwords_integration_cron_user',
		'meta_value' => $marker,
	] );
	sp_cron_reset_assert( count( $ids ) === 1, "$marker synthetic account missing" );
	return (int) $ids[0];
}

function sp_cron_reset_create_user( $marker ) {
	$login = 'sp-cron-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, false );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	unset( $password );
	sp_cron_reset_assert( is_int( $id ) && $id > 0, 'synthetic account creation failed' );
	update_user_meta( $id, 'safety_passwords_integration_cron_user', $marker );
	delete_user_meta( $id, Settings::$optionPrefix . 'rp_pre_inited' );
	update_user_meta( $id, Settings::$optionPrefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
	return $id;
}

function sp_cron_reset_password( $user ) {
	for ( $attempt = 0; $attempt < 20; $attempt++ ) {
		$password = wp_generate_password( 32, true, false );
		if ( Controller::is_password_secure( $password, $user ) ) {
			return $password;
		}
	}
	sp_cron_reset_assert( false, 'secure generated password unavailable' );
}

function sp_cron_reset_history_count( $id ) {
	$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
	return is_array( $history ) ? count( $history ) : 0;
}

function sp_cron_reset_assert_history( $id, $password, $before, $label ) {
	$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
	sp_cron_reset_assert( is_array( $history ) && count( $history ) === $before + 1, "$label history not updated" );
	sp_cron_reset_assert( wp_check_password( $password, end( $history ) ), "$label password absent from history" );
}

sp_cron_reset_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_cron_reset_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_cron_reset_assert( class_exists( Controller::class ) && has_action( Cron::EVENT_NAME, [ Controller::class, 'findExpiringPasswords' ] ) !== false, 'plugin cron callback missing' );

$stage = $args[0] ?? '';
sp_cron_reset_assert( in_array( $stage, [ 'first', 'second', 'cleanup' ], true ), 'unknown phase' );
$pending_key = Settings::$optionPrefix . 'rp_inited';
$preinit_key = Settings::$optionPrefix . 'rp_pre_inited';
$last_reset_key = Settings::$optionPrefix . 'last_reset';
carbon_set_theme_option( Settings::$optionPrefix . 'reset_interval', 1 );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
sp_cron_reset_assert( Settings::getInterval() === 1, 'enabled interval not applied' );

if ( $stage === 'first' ) {
	// Earlier UI fixtures leave the administrator overdue; keep this test scoped to its own accounts.
	update_user_meta( get_current_user_id(), $last_reset_key, time() );
	$id = sp_cron_reset_create_user( 'existing' );
	$before_hash = get_userdata( $id )->user_pass;
	$mail_before = $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0;
	$reset = [];
	$reminded = [];
	do_action( Cron::EVENT_NAME );
	sp_cron_reset_assert( get_userdata( $id )->user_pass !== $before_hash, 'first pass did not replace password' );
	sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before + 1, 'first pass did not attempt one email' );
	sp_cron_reset_assert( get_user_meta( $id, $pending_key, true ) === '1', 'persisted mandatory flag is not string one' );
	sp_cron_reset_assert( (int) get_user_meta( $id, $last_reset_key, true ) < time() - DAY_IN_SECONDS, 'first pass changed expiry date' );
	sp_cron_reset_assert( ! get_user_meta( $id, $preinit_key, true ), 'first pass set soft reset flag' );
	echo "PASS: first expired account pass replaced password, attempted mail, and persisted mandatory flag\n";
	return;
}

if ( $stage === 'second' ) {
	$id = sp_cron_reset_user( 'existing' );
	sp_cron_reset_assert( get_user_meta( $id, $pending_key, true ) === '1', 'mandatory flag missing on fresh bootstrap' );
	$before_hash = get_userdata( $id )->user_pass;
	$mail_before = $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0;
	$reset = [];
	$reminded = [];
	Controller::checkUsers( $reset, $reminded );
	$reset = array_map( 'intval', $reset );
	$reminded = array_map( 'intval', $reminded );
	sp_cron_reset_assert( $reset === [], 'repeat processed account' );
	sp_cron_reset_assert( get_userdata( $id )->user_pass === $before_hash, 'repeat replaced pending password' );
	sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before, 'repeat attempted another email' );
	sp_cron_reset_assert( get_user_meta( $id, $pending_key, true ) === '1', 'mail failure cleared pending flag' );
	sp_cron_reset_assert( ! in_array( $id, $reminded, true ), 'pending account was reported as a reminder' );

	$fresh_id = sp_cron_reset_create_user( 'fresh' );
	$fresh_before_hash = get_userdata( $fresh_id )->user_pass;
	$mail_before = $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0;
	$reset = [];
	$reminded = [];
	Controller::checkUsers( $reset, $reminded );
	$reset = array_map( 'intval', $reset );
	$reminded = array_map( 'intval', $reminded );
	sp_cron_reset_assert( $reset === [ $fresh_id ], 'unflagged expired account was not reported alone' );
	sp_cron_reset_assert( get_userdata( $fresh_id )->user_pass !== $fresh_before_hash, 'unflagged account password unchanged' );
	sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before + 1, 'unflagged account had no single email attempt' );
	sp_cron_reset_assert( get_user_meta( $id, $pending_key, true ) === '1' && get_user_meta( $fresh_id, $pending_key, true ) === '1', 'mail failure cleared mandatory flag' );
	sp_cron_reset_assert( ! in_array( $id, $reminded, true ) && ! in_array( $fresh_id, $reminded, true ), 'pending accounts were reported as reminders' );
	echo "PASS: fresh bootstrap skipped pending account and handled unflagged account once after mail failure\n";
	return;
}

$id = sp_cron_reset_user( 'existing' );
$fresh_id = sp_cron_reset_user( 'fresh' );
$reset_user = get_userdata( $fresh_id );
$mail_before = $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0;
$failed_mail = Controller::retrievePassword( $reset_user );
sp_cron_reset_assert( is_wp_error( $failed_mail ) && $failed_mail->get_error_code() === 'retrieve_password_email_failure', 'failed mail did not return a WordPress error' );
sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before + 1, 'failed mail did not attempt delivery once' );
$GLOBALS['safety_passwords_test_mail_success'] = true;
$sent_mail = Controller::retrievePassword( $reset_user );
unset( $GLOBALS['safety_passwords_test_mail_success'] );
sp_cron_reset_assert( $sent_mail === true, 'successful mail did not return true' );
sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before + 2, 'successful mail did not attempt delivery once' );
$reset_key = '';
$silent_reset = Controller::retrievePassword( $reset_user, true, $reset_key );
sp_cron_reset_assert( $silent_reset === true && is_string( $reset_key ) && $reset_key !== '', 'silent reset did not return a key' );
$key_user = check_password_reset_key( $reset_key, $reset_user->user_login );
sp_cron_reset_assert( $key_user instanceof WP_User && $key_user->ID === $fresh_id, 'silent reset key is invalid' );
sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before + 2, 'silent reset attempted email' );
unset( $reset_key, $key_user );
$reset_history_before = sp_cron_reset_history_count( $fresh_id );
$reset_password = sp_cron_reset_password( $reset_user );
$_POST['pass1'] = $reset_password;
$errors = new WP_Error();
do_action( 'validate_password_reset', $errors, $reset_user );
unset( $_POST['pass1'] );
sp_cron_reset_assert( empty( $errors->errors ), 'reset validation rejected generated password' );
reset_password( $reset_user, $reset_password );
sp_cron_reset_assert( get_user_meta( $fresh_id, $pending_key, true ) === '' && get_user_meta( $fresh_id, $preinit_key, true ) === '', 'successful reset retained flags' );
sp_cron_reset_assert( (int) get_user_meta( $fresh_id, $last_reset_key, true ) >= time() - MINUTE_IN_SECONDS, 'successful reset did not renew date' );
sp_cron_reset_assert_history( $fresh_id, $reset_password, $reset_history_before, 'successful reset' );
unset( $reset_password );

require_once ABSPATH . 'wp-admin/includes/user.php';
$profile_user = get_userdata( $id );
$profile_history_before = sp_cron_reset_history_count( $id );
$profile_password = sp_cron_reset_password( $profile_user );
$_POST['email'] = $profile_user->user_email;
$_POST['nickname'] = $profile_user->nickname;
$_POST['pass1'] = $profile_password;
$_POST['pass2'] = $profile_password;
$updated = edit_user( $id );
unset( $_POST['email'], $_POST['nickname'], $_POST['pass1'], $_POST['pass2'] );
sp_cron_reset_assert( $updated === $id, 'real profile update failed' );
sp_cron_reset_assert( get_user_meta( $id, $pending_key, true ) === '' && get_user_meta( $id, $preinit_key, true ) === '', 'profile update retained flags' );
sp_cron_reset_assert( (int) get_user_meta( $id, $last_reset_key, true ) >= time() - MINUTE_IN_SECONDS, 'profile update did not renew date' );
sp_cron_reset_assert_history( $id, $profile_password, $profile_history_before, 'profile update' );
unset( $profile_password );

$before_hash = get_userdata( $id )->user_pass;
$before_date = get_user_meta( $id, $last_reset_key, true );
update_user_meta( $id, $last_reset_key, time() - 2 * DAY_IN_SECONDS );
carbon_set_theme_option( Settings::$optionPrefix . 'reset_interval', 0 );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
sp_cron_reset_assert( Settings::getInterval() === 0, 'zero interval not applied' );
$mail_before = $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0;
do_action( Cron::EVENT_NAME );
sp_cron_reset_assert( get_userdata( $id )->user_pass === $before_hash, 'zero interval replaced password' );
sp_cron_reset_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before, 'zero interval attempted mail' );
sp_cron_reset_assert( ! get_user_meta( $id, $pending_key, true ), 'zero interval set mandatory flag' );
update_user_meta( $id, $last_reset_key, $before_date );
carbon_set_theme_option( Settings::$optionPrefix . 'reset_interval', 1 );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
echo "PASS: real reset and profile update renewed policy; zero interval callback was inert\n";
