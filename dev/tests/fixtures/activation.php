<?php

use iTRON\SafetyPasswords\Activation;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Settings;

function sp_activation_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

function sp_activation_event_count( $hook ) {
	$count = 0;
	foreach ( _get_cron_array() as $events ) {
		if ( isset( $events[ $hook ] ) ) {
			$count += count( $events[ $hook ] );
		}
	}
	return $count;
}

sp_activation_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe activation test target' );
sp_activation_assert( get_option( 'safety_passwords_integration_target' ) === 'isolated', 'missing isolation marker' );

$deferred_hook = 'itron/safety-passwords/activate';
$stage = $args[0] ?? 'initial';

if ( $stage === 'pending' ) {
	sp_activation_assert( sp_activation_event_count( 'safety_passwords_periodically_reset' ) === 0, 'deactivation before second phase retained periodic event' );
	echo sp_activation_event_count( $deferred_hook ) > 0
		? "CHARACTERIZATION: deactivation before second phase retained deferred event\n"
		: "CHARACTERIZATION: deactivation before second phase removed deferred event\n";
	return;
}

sp_activation_assert( class_exists( Activation::class ) && class_exists( Controller::class ), 'plugin did not boot for activation' );
$user_id = get_current_user_id();
sp_activation_assert( $user_id > 0, 'synthetic test user not loaded' );
$history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );

if ( $stage === 'followup' ) {
	$expected = get_option( 'safety_passwords_integration_activation_state' );
	sp_activation_assert( is_array( $expected ), 'activation baseline missing' );
	sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 1, 'normal bootstrap duplicated periodic event' );
	sp_activation_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $expected['scheduled'], 'normal bootstrap rescheduled periodic event' );
	sp_activation_assert( is_array( $history ) && count( $history ) === $expected['history_count'], 'normal bootstrap changed password history' );
	sp_activation_assert( sp_activation_event_count( $deferred_hook ) === 0, 'deferred event remained after execution' );
	delete_option( 'safety_passwords_integration_activation_state' );
	echo "PASS: normal bootstrap retained one event and unchanged history\n";
	return;
}

sp_activation_assert( $stage === 'initial', 'unknown activation fixture stage' );
sp_activation_assert( is_callable( [ new General(), 'processActivationHook' ] ), 'legacy activation method unavailable' );
sp_activation_assert( is_callable( [ General::class, 'processSecondPhaseActivation' ] ), 'legacy second-phase method unavailable' );
sp_activation_assert( is_callable( [ new General(), 'processDeactivationHook' ] ), 'legacy deactivation method unavailable' );
sp_activation_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'activation capability missing' );
sp_activation_assert( function_exists( 'carbon_get_theme_option' ) && did_action( 'after_setup_theme' ) > 0, 'Carbon Fields unavailable for second phase' );
sp_activation_assert( sp_activation_event_count( $deferred_hook ) === 1, 'activation did not schedule exactly one deferred event' );
sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 0, 'periodic event began before deferred phase' );

$has_cron_callback = has_action( $deferred_hook, [ Activation::class, 'processSecondPhaseActivation' ] ) !== false
	|| has_action( $deferred_hook, [ General::class, 'processSecondPhaseActivation' ] ) !== false;
sp_activation_assert( $has_cron_callback, 'second-phase cron callback missing' );
sp_activation_assert( has_action( $deferred_hook, [ Controller::class, 'putCurrentPasswordsToStopList' ] ) !== false, 'second-phase history callback missing' );
sp_activation_assert( ! is_array( $history ) || count( $history ) === 0, 'history populated before deferred phase' );

do_action( $deferred_hook );
wp_clear_scheduled_hook( $deferred_hook );
sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 1, 'second phase did not install exactly one periodic event' );
sp_activation_assert( wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'second phase used wrong cron recurrence' );
$history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );
sp_activation_assert( is_array( $history ) && count( $history ) === 1, 'second phase did not seed history once' );

update_option( 'safety_passwords_integration_activation_state', [
	'scheduled' => wp_next_scheduled( Cron::EVENT_NAME ),
	'history_count' => count( $history ),
] );
echo "PASS: ordinary activation capability, deferred callbacks, periodic event, and history seed\n";
