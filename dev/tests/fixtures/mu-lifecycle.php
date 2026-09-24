<?php

use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_mu_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: MU lifecycle $label\n" );
		exit( 1 );
	}
}

function sp_mu_events( $hook ) {
	$count = 0;
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ $hook ] ) ) {
			$count += count( $events[ $hook ] );
		}
	}
	return $count;
}

sp_mu_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
$stage = $args[0] ?? '';
$history_key = 'safety-passwords_stop-list';
$state_key = 'safety_passwords_integration_mu_state';
$complete_key = 'safety_passwords_mu_initialized';
$lock_key = 'safety_passwords_mu_initializing';
$deferred_hook = 'itron/safety-passwords/activate';
$cron_hook = 'safety_passwords_periodically_reset';

if ( 'removed' === $stage ) {
	sp_mu_assert( ! class_exists( Cron::class ), 'MU plugin still loaded after loader removal' );
	sp_mu_assert( get_option( $complete_key ) && sp_mu_events( $cron_hook ) === 1, 'physical MU removal did not retain one scheduled event' );
	echo "PASS: physical MU removal retained one event for manual cleanup\n";
	return;
}

if ( 'prepare' === $stage || 'prepare-return' === $stage || 'prepare-ordinary' === $stage ) {
	sp_mu_assert( ! class_exists( Cron::class ), 'ordinary plugin still loaded' );
	if ( 'prepare-ordinary' === $stage ) {
		sp_mu_assert( sp_mu_events( $cron_hook ) === 0, 'manual MU event cleanup incomplete' );
	}
	$login = 'sp-mu-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, false );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	unset( $password );
	sp_mu_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
	sp_mu_assert( ! get_user_meta( $id, $history_key, true ), 'new account already has history' );
	update_option( 'prepare-ordinary' === $stage ? 'safety_passwords_integration_ordinary_transition' : $state_key, [ 'id' => $id, 'stage' => $stage ] );
	if ( 'prepare' === $stage ) {
		sp_mu_assert( add_option( $lock_key, ( time() + 900 ) . ':fixture', '', false ), 'held lock setup' );
		wp_schedule_single_event( time() + 600, $deferred_hook );
		sp_mu_assert( sp_mu_events( $deferred_hook ) === 1, 'pending ordinary event missing' );
	}
	echo "PASS: MU transition prepared\n";
	return;
}

sp_mu_assert( class_exists( Cron::class ) && class_exists( Controller::class ), 'MU plugin unavailable' );
$state = get_option( $state_key );
sp_mu_assert( is_array( $state ) && isset( $state['id'] ), 'transition state missing' );
$id = (int) $state['id'];
$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
$history_count = is_array( $history ) ? count( $history ) : 0;

if ( 'held' === $stage ) {
	sp_mu_assert( ! get_option( $complete_key ) && $history_count === 0 && sp_mu_events( $cron_hook ) === 0, 'held lock allowed initialization' );
	sp_mu_assert( sp_mu_events( $deferred_hook ) === 1, 'held lock removed pending ordinary event' );
	sp_mu_assert( update_option( $lock_key, ( time() - 1 ) . ':fixture' ), 'expired lock setup' );
	echo "PASS: held MU lock deferred initialization\n";
	return;
}

if ( 'failed' === $stage ) {
	sp_mu_assert( ! get_option( $complete_key ) && ! get_option( $lock_key ), 'failed history write marked setup complete or held lock' );
	sp_mu_assert( $history_count === 0 && sp_mu_events( $cron_hook ) === 0, 'failed history write advanced setup' );
	sp_mu_assert( sp_mu_events( $deferred_hook ) === 0, 'expired lock was not reclaimed' );
	echo "PASS: failed MU history write remained retryable\n";
	return;
}

sp_mu_assert( did_action( 'carbon_fields_fields_registered' ) > 0 && function_exists( 'carbon_get_theme_option' ), 'Carbon was not ready' );
sp_mu_assert( get_option( $complete_key ) && ! get_option( $lock_key ), 'completion or lock state incorrect' );
sp_mu_assert( $history_count === 1, 'history not seeded exactly once' );
sp_mu_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'administrator capability missing' );
sp_mu_assert( sp_mu_events( $cron_hook ) === 1 && wp_get_schedule( $cron_hook ) === 'twicedaily', 'main scheduler incorrect' );
sp_mu_assert( sp_mu_events( $deferred_hook ) === 0, 'stale deferred event remained' );

if ( 'initial' === $stage || 'return' === $stage ) {
	update_option( $state_key, [ 'id' => $id, 'scheduled' => wp_next_scheduled( $cron_hook ) ] );
} elseif ( 'repeat' === $stage || 'return-repeat' === $stage ) {
	sp_mu_assert( wp_next_scheduled( $cron_hook ) === $state['scheduled'], 'repeat request rescheduled event' );
} else {
	sp_mu_assert( false, 'unknown stage' );
}
echo "PASS: MU bootstrap, history, capabilities and one scheduler ($stage)\n";
