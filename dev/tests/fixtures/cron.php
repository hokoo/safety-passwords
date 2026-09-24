<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

function sp_test_event_count( $hook ) {
	$count = 0;
	foreach ( _get_cron_array() as $events ) {
		if ( isset( $events[ $hook ] ) ) {
			$count += count( $events[ $hook ] );
		}
	}
	return $count;
}

sp_test_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_test_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );
sp_test_assert( class_exists( Cron::class ) && class_exists( Controller::class ), 'plugin did not boot' );
sp_test_assert( has_action( Cron::EVENT_NAME, [ Controller::class, 'findExpiringPasswords' ] ) !== false, 'cron callback missing' );
sp_test_assert( function_exists( 'carbon_set_theme_option' ), 'Carbon Fields did not boot' );

wp_clear_scheduled_hook( Cron::EVENT_NAME );
sp_test_assert( sp_test_event_count( Cron::EVENT_NAME ) === 0, 'unexpected initial event' );
Cron::ensureEvent();
$first = wp_next_scheduled( Cron::EVENT_NAME );
sp_test_assert( is_int( $first ) && $first > time(), 'cron event not scheduled in future' );
sp_test_assert( wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'wrong cron recurrence' );
sp_test_assert( sp_test_event_count( Cron::EVENT_NAME ) === 1, 'cron event count after ensure' );
Cron::ensureEvent();
sp_test_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $first, 'ensure changed existing event' );
sp_test_assert( sp_test_event_count( Cron::EVENT_NAME ) === 1, 'ensure duplicated event' );
Cron::stopEvent();
sp_test_assert( sp_test_event_count( Cron::EVENT_NAME ) === 0, 'stop did not remove event' );

$user_id = get_current_user_id();
sp_test_assert( $user_id > 0, 'synthetic test user not loaded' );
$last_reset_key = Settings::$optionPrefix . 'last_reset';
$preinit_key = Settings::$optionPrefix . 'rp_pre_inited';
$last_reset = time() - 2 * DAY_IN_SECONDS;
update_user_meta( $user_id, $last_reset_key, $last_reset );
delete_user_meta( $user_id, $preinit_key );

carbon_set_theme_option( Settings::$optionPrefix . 'reset_interval', 0 );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
sp_test_assert( Settings::getInterval() === 0, 'zero interval not applied' );
do_action( Cron::EVENT_NAME );
sp_test_assert( ! get_user_meta( $user_id, $preinit_key, true ), 'zero interval changed reset state' );
sp_test_assert( (int) get_user_meta( $user_id, $last_reset_key, true ) === $last_reset, 'zero interval changed timestamp' );

carbon_set_theme_option( Settings::$optionPrefix . 'reset_interval', 3 );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
sp_test_assert( Settings::getInterval() === 3, 'enabled interval not applied' );
do_action( Cron::EVENT_NAME );
sp_test_assert( (bool) get_user_meta( $user_id, $preinit_key, true ), 'enabled interval did not mark reminder' );
sp_test_assert( (int) get_user_meta( $user_id, $last_reset_key, true ) === $last_reset, 'enabled interval changed timestamp' );
sp_test_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === 0, 'unexpected mail attempt' );

// Leave one event for the real plugin-deactivation path in the next request.
Cron::ensureEvent();
sp_test_assert( sp_test_event_count( Cron::EVENT_NAME ) === 1, 'event not restored for deactivation check' );

echo "PASS: plugin boot, one twicedaily event, idempotent ensure, stop, zero/enabled callback, no mail attempt\n";
