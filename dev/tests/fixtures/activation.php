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
	sp_activation_assert( sp_activation_event_count( $deferred_hook ) === 0, 'deactivation retained deferred event' );
	echo "PASS: deactivation before second phase removed deferred event\n";
	return;
}

sp_activation_assert( class_exists( Activation::class ) && class_exists( Controller::class ), 'plugin did not boot for activation' );
$user_id = get_current_user_id();
sp_activation_assert( $user_id > 0, 'synthetic test user not loaded' );
$history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );

if ( $stage === 'lease' ) {
	global $wpdb;
	$option = 'safety_passwords_mu_initializing';
	$claim = new ReflectionMethod( Activation::class, 'claimMuLock' );
	$renew = new ReflectionMethod( Activation::class, 'renewMuLock' );
	$owns = new ReflectionMethod( Activation::class, 'ownsMuLock' );
	$release = new ReflectionMethod( Activation::class, 'releaseMuLock' );
	foreach ( [ $claim, $renew, $owns, $release ] as $method ) {
		$method->setAccessible( true );
	}
	$owner = $claim->invoke( null );
	sp_activation_assert( is_string( $owner ), 'lease claim failed' );
	$near_expiry = (string) ( time() + 120 ) . substr( $owner, strpos( $owner, ':' ) );
	sp_activation_assert( 1 === $wpdb->update( $wpdb->options, [ 'option_value' => $near_expiry ], [ 'option_name' => $option, 'option_value' => $owner ] ), 'lease expiry setup failed' );
	$owner = $near_expiry;
	sp_activation_assert( true === $renew->invokeArgs( null, [ &$owner ] ), 'owned lease did not renew' );
	sp_activation_assert( (int) strtok( $owner, ':' ) > time() + 600 && true === $owns->invoke( null, $owner ), 'renewed lease was not owned' );
	$expired = (string) ( time() - 1 ) . substr( $owner, strpos( $owner, ':' ) );
	sp_activation_assert( 1 === $wpdb->update( $wpdb->options, [ 'option_value' => $expired ], [ 'option_name' => $option, 'option_value' => $owner ] ), 'expired lease setup failed' );
	$owner = $expired;
	sp_activation_assert( false === $renew->invokeArgs( null, [ &$owner ] ), 'expired owner renewed lease' );
	$new_owner = $claim->invoke( null );
	sp_activation_assert( is_string( $new_owner ) && $new_owner !== $owner, 'expired lease was not claimable' );
	$release->invoke( null, $owner );
	sp_activation_assert( true === $owns->invoke( null, $new_owner ), 'stale owner released new lease' );
	$release->invoke( null, $new_owner );
	sp_activation_assert( ! get_option( $option ), 'new owner did not release lease' );
	delete_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY );
	$taken_over = null;
	$user_queries_after_takeover = 0;
	$count_user_queries = function () use ( &$user_queries_after_takeover ) {
		++$user_queries_after_takeover;
	};
	$take_over_during_grant = function () use ( &$taken_over, $wpdb, $option, $claim, $count_user_queries, &$take_over_during_grant ) {
		remove_action( 'itron/safety-passwords/capabilities/set', $take_over_during_grant );
		$current = get_option( $option, '' );
		$expired = (string) ( time() - 1 ) . substr( $current, strpos( $current, ':' ) );
		if ( 1 === $wpdb->update( $wpdb->options, [ 'option_value' => $expired ], [ 'option_name' => $option, 'option_value' => $current ] ) ) {
			$taken_over = $claim->invoke( null );
			add_action( 'pre_get_users', $count_user_queries );
		}
	};
	add_action( 'itron/safety-passwords/capabilities/set', $take_over_during_grant );
	sp_activation_assert( ! Activation::initialize( true ), 'initializer completed after losing lease' );
	remove_action( 'pre_get_users', $count_user_queries );
	sp_activation_assert( is_string( $taken_over ) && true === $owns->invoke( null, $taken_over ), 'takeover during initialization failed' );
	sp_activation_assert( 0 === $user_queries_after_takeover, 'lost initializer continued user traversal' );
	sp_activation_assert( ! get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true ), 'lost initializer updated history' );
	sp_activation_assert( get_option( 'safety_passwords_initialized_version' ) !== \iTRON\SafetyPasswords\VERSION, 'lost initializer published completion' );
	$release->invoke( null, $taken_over );
	sp_activation_assert( Activation::initialize(), 'initialization did not recover after takeover' );
	echo "PASS: lease renewal, expiry takeover, lost-owner abort, and recovery\n";
	return;
}

if ( $stage === 'repair' ) {
	wp_clear_scheduled_hook( Cron::EVENT_NAME );
	delete_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY );
	do_action( $deferred_hook );
	$repaired_history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );
	sp_activation_assert( is_array( $repaired_history ) && count( $repaired_history ) === 1, 'public activation hook did not repair history' );
	sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 1 && wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'public activation hook did not repair schedule' );
	sp_activation_assert( get_option( 'safety_passwords_initialized_version' ) === \iTRON\SafetyPasswords\VERSION, 'public activation hook did not complete repair' );
	echo "PASS: public activation hook repaired missing history and schedule\n";
	return;
}

if ( $stage === 'transition' ) {
	$inactive_account = get_option( 'safety_passwords_integration_ordinary_transition' );
	sp_activation_assert( is_array( $inactive_account ) && isset( $inactive_account['id'] ), 'inactive account state missing' );
	sp_activation_assert( get_option( 'safety_passwords_initialized_version' ) === \iTRON\SafetyPasswords\VERSION, 'ordinary reactivation did not complete' );
	sp_activation_assert( sp_activation_event_count( $deferred_hook ) === 0, 'ordinary reactivation retained deferred event' );
	sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 1, 'ordinary reactivation did not schedule one periodic event' );
	sp_activation_assert( ! get_option( 'safety_passwords_mu_initialized' ), 'ordinary reactivation retained MU completion marker' );
	$before = is_array( $history ) ? count( $history ) : 0;
	do_action( $deferred_hook );
	$after = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );
	sp_activation_assert( ( is_array( $after ) ? count( $after ) : 0 ) === $before, 'repeat deferred hook duplicated history' );
	$inactive_history = get_user_meta( (int) $inactive_account['id'], Controller::USER_STOP_LIST_META_KEY, true );
	sp_activation_assert( is_array( $inactive_history ) && count( $inactive_history ) === 1, 'ordinary reactivation omitted inactive account' );
	sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 1, 'repeat deferred hook duplicated periodic event' );
	echo "PASS: ordinary reactivation after MU completed on first load\n";
	return;
}

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
sp_activation_assert( did_action( 'wp_loaded' ) > 0, 'WordPress full-load hook unavailable' );
sp_activation_assert( get_option( 'safety_passwords_initialized_version' ) === \iTRON\SafetyPasswords\VERSION, 'activation did not complete on next request' );
sp_activation_assert( sp_activation_event_count( $deferred_hook ) === 0, 'completed activation retained deferred event' );
sp_activation_assert( sp_activation_event_count( Cron::EVENT_NAME ) === 1, 'activation did not install exactly one periodic event' );
sp_activation_assert( wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'activation used wrong cron recurrence' );

$has_cron_callback = has_action( $deferred_hook, [ Activation::class, 'processSecondPhaseActivation' ] ) !== false
	|| has_action( $deferred_hook, [ General::class, 'processSecondPhaseActivation' ] ) !== false;
sp_activation_assert( $has_cron_callback, 'second-phase cron callback missing' );
sp_activation_assert( is_array( $history ) && count( $history ) === 1, 'activation did not seed history once' );
$scheduled = wp_next_scheduled( Cron::EVENT_NAME );
do_action( $deferred_hook );
sp_activation_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $scheduled, 'repeat deferred hook rescheduled periodic event' );
$history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );
sp_activation_assert( is_array( $history ) && count( $history ) === 1, 'repeat deferred hook duplicated history' );

update_option( 'safety_passwords_integration_activation_state', [
	'scheduled' => wp_next_scheduled( Cron::EVENT_NAME ),
	'history_count' => count( $history ),
] );
echo "PASS: ordinary activation completed capability, history, and schedule on first load\n";
