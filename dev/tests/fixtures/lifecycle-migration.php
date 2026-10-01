<?php

use iTRON\SafetyPasswords\Activation;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_migration_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: lifecycle migration $label\n" );
		exit( 1 );
	}
}

sp_migration_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
sp_migration_assert( class_exists( Activation::class ) && did_action( 'carbon_fields_fields_registered' ) > 0, 'plugin or Carbon unavailable' );
$stage = $args[0] ?? '';
$version_key = 'safety_passwords_initialized_version';
$lock_key = 'safety_passwords_mu_initializing';
$state_key = 'safety_passwords_integration_migration';
$main_site = is_multisite() ? (int) get_network()->site_id : get_current_blog_id();
$origin = get_current_blog_id();
if ( 'ordinary-network-prepare' === $stage || 'ordinary-network-verify' === $stage ) {
	sp_migration_assert( is_multisite() && isset( get_site_option( 'active_sitewide_plugins' )['safety-passwords/safety-passwords.php'] ), 'ordinary network plugin inactive' );
	sp_migration_assert( ! in_array( '10-safety-passwords-test-loader.php', array_map( 'basename', wp_get_mu_plugins() ), true ), 'MU loader present during ordinary network upgrade' );
}
if ( $origin !== $main_site ) {
	switch_to_blog( $main_site );
}

try {
	if ( 'schedule-missing' === $stage ) {
		sp_migration_assert( get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION, 'schedule test requires ready version' );
		wp_unschedule_hook( Cron::EVENT_NAME );
		sp_migration_assert( ! Cron::currentSiteEventIsCanonical(), 'missing event setup' );
	} elseif ( 'schedule-missing-verify-duplicate' === $stage ) {
		sp_migration_assert( get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION && Cron::currentSiteEventIsCanonical(), 'normal load did not restore missing event' );
		wp_schedule_event( time() + 900, 'twicedaily', Cron::EVENT_NAME, [ 'extra' ] );
		sp_migration_assert( ! Cron::currentSiteEventIsCanonical(), 'duplicate event setup' );
	} elseif ( 'schedule-duplicate-verify-wrong' === $stage ) {
		sp_migration_assert( Cron::currentSiteEventIsCanonical(), 'normal load did not remove duplicate event' );
		wp_unschedule_hook( Cron::EVENT_NAME );
		wp_schedule_event( time() + 900, 'hourly', Cron::EVENT_NAME );
		sp_migration_assert( ! Cron::currentSiteEventIsCanonical(), 'wrong recurrence setup' );
	} elseif ( 'schedule-wrong-verify-stable' === $stage ) {
		sp_migration_assert( Cron::currentSiteEventIsCanonical(), 'normal load did not repair wrong recurrence' );
		$verified = get_option( 'safety_passwords_schedule_verified_at' );
		sp_migration_assert( is_numeric( $verified ) && (int) $verified > 0, 'schedule verification marker missing' );
		update_option( $state_key, [ 'event' => wp_next_scheduled( Cron::EVENT_NAME ), 'verified' => (int) $verified ] );
	} elseif ( 'schedule-stable-verify' === $stage ) {
		$state = get_option( $state_key );
		$verified = get_option( 'safety_passwords_schedule_verified_at' );
		sp_migration_assert( is_array( $state ) && isset( $state['event'], $state['verified'] ) && $state['event'] === wp_next_scheduled( Cron::EVENT_NAME ) && is_numeric( $verified ) && $state['verified'] === (int) $verified && Activation::scheduleHealth() === 'healthy', 'healthy load moved event or deep verification' );
		delete_option( $state_key );
	} elseif ( 'schedule-failure-retry' === $stage ) {
		sp_migration_assert( Cron::currentSiteEventIsCanonical(), 'failure test requires canonical event' );
		wp_unschedule_hook( Cron::EVENT_NAME );
		$frozen_cron = get_option( 'cron' );
		$block_cron = function () use ( $frozen_cron ) { return $frozen_cron; };
		add_filter( 'pre_update_option_cron', $block_cron );
		Activation::bootstrapOrdinary();
		remove_filter( 'pre_update_option_cron', $block_cron );
		$failure = get_option( 'safety_passwords_schedule_failure' );
		sp_migration_assert( get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION && ! Cron::currentSiteEventIsCanonical() && Activation::scheduleHealth() === 'degraded' && is_array( $failure ) && $failure['next_retry'] > time(), 'schedule failure was not isolated or cooled down' );
		Activation::bootstrapOrdinary();
		sp_migration_assert( ! Cron::currentSiteEventIsCanonical(), 'schedule cooldown allowed heavy retry' );
		$failure['next_retry'] = time() - 1;
		update_option( 'safety_passwords_schedule_failure', $failure, false );
		Activation::bootstrapOrdinary();
		sp_migration_assert( Cron::currentSiteEventIsCanonical() && Activation::scheduleHealth() === 'healthy' && ! get_option( 'safety_passwords_schedule_failure' ), 'schedule retry failed' );
	} elseif ( 'schedule-legacy' === $stage ) {
		sp_migration_assert( is_multisite() && Cron::currentSiteEventIsCanonical(), 'legacy test requires ready network schedule' );
		$state = [];
		foreach ( get_networks( [ 'fields' => 'ids', 'number' => 0 ] ) as $network_id ) {
			if ( (int) $network_id !== get_current_network_id() ) {
				$other = get_network( (int) $network_id );
				switch_to_blog( (int) $other->site_id );
				$state['other_main'] = (int) $other->site_id;
				$state['other_event'] = wp_next_scheduled( Cron::EVENT_NAME );
				restore_current_blog();
				break;
			}
		}
		$subsite = null;
		foreach ( get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			if ( (int) $site_id !== $main_site ) {
				$subsite = (int) $site_id;
				break;
			}
		}
		sp_migration_assert( null !== $subsite, 'legacy test requires subsite' );
		switch_to_blog( $subsite );
		wp_schedule_event( time() + 900, 'twicedaily', Cron::EVENT_NAME );
		restore_current_blog();
		update_option( $state_key, $state );
		update_option( 'safety_passwords_schedule_verified_at', time() - 86401, false );
		sp_migration_assert( ! Cron::isNormalized() && Activation::scheduleHealth() === 'verification_due', 'legacy event setup' );
	} elseif ( 'schedule-legacy-verify' === $stage ) {
		$state = get_option( $state_key );
		sp_migration_assert( is_array( $state ) && Cron::isNormalized() && Activation::scheduleHealth() === 'healthy', 'normal load did not remove legacy subsite event' );
		if ( isset( $state['other_main'] ) ) {
			switch_to_blog( $state['other_main'] );
			sp_migration_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $state['other_event'], 'schedule repair changed another network' );
			restore_current_blog();
		}
		delete_option( $state_key );
	} elseif ( 'prepare' === $stage || 'ordinary-network-prepare' === $stage ) {
		$login = 'sp-migration-' . strtolower( wp_generate_password( 12, false, false ) );
		$password = wp_generate_password( 32, true, false );
		$id = wp_create_user( $login, $password, $login . '@example.invalid' );
		unset( $password );
		sp_migration_assert( is_int( $id ) && $id > 0, 'synthetic account creation' );
		delete_user_meta( $id, Controller::USER_STOP_LIST_META_KEY );
		$state = [ 'id' => $id ];
		if ( is_multisite() ) {
			foreach ( get_networks( [ 'fields' => 'ids', 'number' => 0 ] ) as $network_id ) {
				if ( (int) $network_id !== get_current_network_id() ) {
					$other = get_network( (int) $network_id );
					switch_to_blog( (int) $other->site_id );
					$state['other_main'] = (int) $other->site_id;
					$state['other_event'] = wp_next_scheduled( Cron::EVENT_NAME );
					$state['other_version'] = get_option( $version_key );
					restore_current_blog();
					break;
				}
			}
		}
		update_option( $state_key, $state );
		update_option( $version_key, 'previous-version', false );
		wp_schedule_event( time() + 900, 'twicedaily', Cron::EVENT_NAME, [ 'legacy' ] );
		if ( is_multisite() ) {
			$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $sites as $site_id ) {
				if ( (int) $site_id !== $main_site ) {
					switch_to_blog( (int) $site_id );
					wp_schedule_event( time() + 900, 'twicedaily', Cron::EVENT_NAME );
					restore_current_blog();
					break;
				}
			}
		}
		sp_migration_assert( ! Cron::isNormalized(), 'legacy scheduler setup' );
	} elseif ( 'verify' === $stage || 'ordinary-network-verify' === $stage ) {
		$state = get_option( $state_key );
		sp_migration_assert( is_array( $state ) && isset( $state['id'] ), 'state missing' );
		$id = (int) $state['id'];
		$user = get_user_by( 'ID', $id );
		$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
		sp_migration_assert( $user instanceof WP_User && is_array( $history ) && in_array( $user->user_pass, $history, true ), 'automatic history migration' );
		$version_current = get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION;
		$cron_normalized = Cron::isNormalized();
		if ( ! $version_current || ! $cron_normalized ) {
			$bootstrap_registered = false !== has_action( 'carbon_fields_fields_registered', [ Activation::class, 'bootstrapOrdinary' ] )
				|| false !== has_action( 'carbon_fields_fields_registered', [ Activation::class, 'bootstrapMustUse' ] );
			$flags = [
				'carbon_ready' => did_action( 'carbon_fields_fields_registered' ) > 0,
				'bootstrap_registered' => $bootstrap_registered,
				'deferred_event' => false !== wp_next_scheduled( 'itron/safety-passwords/activate' ),
				'version_current' => $version_current,
				'cron_normalized' => $cron_normalized,
				'lock_exists' => false !== get_option( $lock_key ),
				'capability_granted' => get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ),
			];
			foreach ( $flags as $label => $value ) {
				fwrite( STDERR, 'lifecycle diagnostic ' . $label . '=' . ( $value ? 'yes' : 'no' ) . "\n" );
			}
			$manual_result = Activation::initialize();
			fwrite( STDERR, 'lifecycle diagnostic manual_result=' . ( $manual_result ? 'yes' : 'no' ) . "\n" );
			fwrite( STDERR, 'lifecycle diagnostic manual_version_current=' . ( get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION ? 'yes' : 'no' ) . "\n" );
			fwrite( STDERR, 'lifecycle diagnostic manual_cron_normalized=' . ( Cron::isNormalized() ? 'yes' : 'no' ) . "\n" );
			sp_migration_assert( false, 'automatic version or scheduler migration' );
		}
		sp_migration_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'capability missing' );
		$scheduled = wp_next_scheduled( Cron::EVENT_NAME );
		sp_migration_assert( Activation::initialize() && $scheduled === wp_next_scheduled( Cron::EVENT_NAME ), 'same-version request changed scheduler' );
		sp_migration_assert( add_option( $lock_key, ( time() + 900 ) . ':fixture', '', false ), 'held lock setup' );
		sp_migration_assert( ! Activation::initialize( true ) && $scheduled === wp_next_scheduled( Cron::EVENT_NAME ), 'held lock allowed repair' );
		delete_option( $lock_key );
		wp_schedule_event( time() + 1200, 'twicedaily', Cron::EVENT_NAME, [ 'blocked-current' ] );
		$frozen_cron = get_option( 'cron' );
		$block_cron = function () use ( $frozen_cron ) { return $frozen_cron; };
		add_filter( 'pre_update_option_cron', $block_cron );
		sp_migration_assert( ! Activation::initialize( true ) && get_option( $version_key ) !== \iTRON\SafetyPasswords\VERSION, 'failed current-version repair stayed marked complete' );
		remove_filter( 'pre_update_option_cron', $block_cron );
		$block_marker = function ( $new ) { return 'previous-version'; };
		update_option( $version_key, 'previous-version', false );
		add_filter( 'pre_update_option_' . $version_key, $block_marker );
		sp_migration_assert( ! Activation::initialize( true ) && get_option( $version_key ) === 'previous-version', 'failed version write marked complete' );
		remove_filter( 'pre_update_option_' . $version_key, $block_marker );
		wp_schedule_event( time() + 1200, 'twicedaily', Cron::EVENT_NAME, [ 'blocked-write' ] );
		$frozen_cron = get_option( 'cron' );
		$block_cron = function () use ( $frozen_cron ) { return $frozen_cron; };
		add_filter( 'pre_update_option_cron', $block_cron );
		sp_migration_assert( ! Activation::initialize( true ) && get_option( $version_key ) === 'previous-version', 'failed cron write marked complete' );
		remove_filter( 'pre_update_option_cron', $block_cron );
		sp_migration_assert( Activation::initialize( true ) && Cron::isNormalized(), 'explicit repair failed' );
		$scheduled = wp_next_scheduled( Cron::EVENT_NAME );
		sp_migration_assert( Activation::initialize( true ) && $scheduled === wp_next_scheduled( Cron::EVENT_NAME ), 'repeat repair changed scheduler' );
		$history_after = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
		sp_migration_assert( $history_after === $history, 'repeat repair changed history' );
		$cap_site = $main_site;
		if ( is_multisite() ) {
			$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $sites as $site_id ) {
				if ( (int) $site_id !== $main_site ) {
					$cap_site = (int) $site_id;
					break;
				}
			}
			sp_migration_assert( $cap_site !== $main_site, 'network capability site missing' );
			switch_to_blog( $cap_site );
		}
		$role_option = wp_roles()->role_key;
		get_role( 'administrator' )->remove_cap( Settings::MANAGE_CAPS );
		$stored_roles = get_option( $role_option );
		sp_migration_assert( empty( $stored_roles['administrator']['capabilities'][ Settings::MANAGE_CAPS ] ), 'capability failure setup' );
		if ( $cap_site !== $main_site ) {
			restore_current_blog();
		}
		$block_capability = function ( $new, $old ) use ( $cap_site ) {
			return get_current_blog_id() === $cap_site ? $old : $new;
		};
		add_filter( 'pre_update_option_' . $role_option, $block_capability, 10, 2 );
		sp_migration_assert( ! Activation::initialize( true ) && get_option( $version_key ) !== \iTRON\SafetyPasswords\VERSION, 'failed capability write marked complete' );
		remove_filter( 'pre_update_option_' . $role_option, $block_capability, 10 );
		if ( $cap_site !== $main_site ) {
			switch_to_blog( $cap_site );
		}
		$stored_roles = get_option( $role_option );
		sp_migration_assert( empty( $stored_roles['administrator']['capabilities'][ Settings::MANAGE_CAPS ] ), 'denied capability write persisted' );
		if ( ! is_multisite() ) {
			sp_migration_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'denied capability write did not expose memory and option mismatch' );
		}
		if ( $cap_site !== $main_site ) {
			restore_current_blog();
		}
		sp_migration_assert( Activation::initialize( true ) && get_option( $version_key ) === \iTRON\SafetyPasswords\VERSION, 'capability write retry failed' );
		if ( $cap_site !== $main_site ) {
			switch_to_blog( $cap_site );
		}
		$stored_roles = get_option( $role_option );
		sp_migration_assert( ! empty( $stored_roles['administrator']['capabilities'][ Settings::MANAGE_CAPS ] ), 'capability retry was not persisted' );
		if ( $cap_site !== $main_site ) {
			restore_current_blog();
		}
		if ( isset( $state['other_main'] ) ) {
			switch_to_blog( $state['other_main'] );
			sp_migration_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $state['other_event'] && get_option( $version_key ) === $state['other_version'], 'other network changed' );
			restore_current_blog();
		}
		delete_option( $state_key );
	} else {
		sp_migration_assert( false, 'unknown stage' );
	}
} finally {
	if ( $origin !== $main_site ) {
		restore_current_blog();
	}
}
sp_migration_assert( get_current_blog_id() === $origin, 'network context changed' );
echo "PASS: lifecycle migration $stage\n";
