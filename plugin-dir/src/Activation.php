<?php

namespace iTRON\SafetyPasswords;

class Activation {

	public static function init(): void {
		add_action( 'itron/safety-passwords/activate', [ self::class, 'processSecondPhaseActivation' ] );
		add_action( 'itron/safety-passwords/activate', [ Controller::class, 'putCurrentPasswordsToStopList' ], 20 );
	}

	public static function processActivationHook(): void {
		self::grantCaps();

		// Carbon fields can not be loaded during activation hook,
		// and the plugin can not be properly activated during the activation hook
		// because activation hook runs too late. See wp-admin/plugins.php:do_action( 'activate_' . $plugin );
		// So, we just need to schedule the second phase of activation for the next normal request.
		wp_schedule_single_event( time(), 'itron/safety-passwords/activate' );
	}

	public static function processSecondPhaseActivation(): void {
		Cron::ensureEvent( true );
	}

	public static function processDeactivationHook(): void {
		Cron::stopEvent();
	}

	public static function grantCaps(): void {
		$role = get_role( 'administrator' );
		$role->add_cap( Settings::MANAGE_CAPS, true );

		do_action( 'itron/safety-passwords/capabilities/set' );
	}
}