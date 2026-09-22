<?php

namespace iTRON\SafetyPasswords;

class Activation {

	public static function init(): void {
		add_action( 'itron/safety-passwords/activate', [ self::class, 'processSecondPhaseActivation' ] );
		add_action( 'itron/safety-passwords/activate', [ Controller::class, 'putCurrentPasswordsToStopList' ], 20 );
		if ( is_multisite() ) {
			// Fired after the new site's roles are initialized on supported WordPress versions.
			add_action( 'wpmu_new_blog', [ self::class, 'grantCapsForNewSite' ] );
		}
	}

	public static function processActivationHook(): void {
		self::grantCaps();

		// Carbon fields can not be loaded during activation hook,
		// and the plugin can not be properly activated during the activation hook
		// because activation hook runs too late. See wp-admin/plugins.php:do_action( 'activate_' . $plugin );
		// So, we just need to schedule the second phase of activation for the next normal request.
		if ( is_multisite() && get_current_blog_id() !== (int) get_network()->site_id ) {
			switch_to_blog( (int) get_network()->site_id );
			try {
				wp_schedule_single_event( time(), 'itron/safety-passwords/activate' );
			} finally {
				restore_current_blog();
			}
			return;
		}

		wp_schedule_single_event( time(), 'itron/safety-passwords/activate' );
	}

	public static function processSecondPhaseActivation(): void {
		Cron::ensureEvent( true );
	}

	public static function processDeactivationHook(): void {
		Cron::stopEvent();
	}

	public static function grantCaps(): void {
		if ( is_multisite() ) {
			$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::grantCurrentSiteCap();
				} finally {
					restore_current_blog();
				}
			}
		} else {
			self::grantCurrentSiteCap();
		}

		do_action( 'itron/safety-passwords/capabilities/set' );
	}

	public static function grantCapsForNewSite( $site_id ): void {
		switch_to_blog( (int) $site_id );
		try {
			self::grantCurrentSiteCap();
		} finally {
			restore_current_blog();
		}
	}

	private static function grantCurrentSiteCap(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( Settings::MANAGE_CAPS, true );
		}
	}
}
