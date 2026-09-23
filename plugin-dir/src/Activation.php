<?php

namespace iTRON\SafetyPasswords;

class Activation {
	private const MU_COMPLETE_OPTION = 'safety_passwords_mu_initialized';
	private const MU_LOCK_OPTION = 'safety_passwords_mu_initializing';
	private const MU_LOCK_SECONDS = 900;

	public static function init(): void {
		add_action( 'itron/safety-passwords/activate', [ self::class, 'processSecondPhaseActivation' ] );
		add_action( 'itron/safety-passwords/activate', [ Controller::class, 'putCurrentPasswordsToStopList' ], 20 );
		if ( is_multisite() ) {
			// Fired after the new site's roles are initialized on supported WordPress versions.
			add_action( 'wpmu_new_blog', [ self::class, 'grantCapsForNewSite' ] );
		}
		// A root MU loader may require this ordinary-path file. Network plugins also
		// load before muplugins_loaded, so hook timing cannot identify MU mode.
		if ( self::loadedByMustUsePlugin() ) {
			add_action( 'carbon_fields_fields_registered', [ self::class, 'bootstrapMustUse' ], 20 );
		}
	}

	private static function loadedByMustUsePlugin(): bool {
		$mu_files = [];
		foreach ( wp_get_mu_plugins() as $file ) {
			$path = realpath( $file );
			if ( false !== $path ) {
				$mu_files[ $path ] = true;
			}
		}

		// Ignore arguments: this stack is used only to identify the including file.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( ! isset( $frame['file'] ) ) {
				continue;
			}
			$path = realpath( $frame['file'] );
			if ( false !== $path && isset( $mu_files[ $path ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function processActivationHook(): void {
		self::onMainSite( function () {
			delete_option( self::MU_COMPLETE_OPTION );
		} );
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
		self::onMainSite( function () {
			wp_clear_scheduled_hook( 'itron/safety-passwords/activate' );
			delete_option( self::MU_COMPLETE_OPTION );
		} );
	}

	/** Initialize an MU install only after Carbon Fields has registered its fields. */
	public static function bootstrapMustUse(): void {
		self::onMainSite( function () {
			if ( get_option( self::MU_COMPLETE_OPTION ) ) {
				self::ensureMainEvent();
				return;
			}

			$owner = self::claimMuLock();
			if ( null === $owner ) {
				return;
			}

			try {
				wp_cache_delete( self::MU_COMPLETE_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				if ( get_option( self::MU_COMPLETE_OPTION ) ) {
					self::ensureMainEvent();
					return;
				}
				// A deferred ordinary activation must not later reset the MU scheduler.
				wp_clear_scheduled_hook( 'itron/safety-passwords/activate' );
				self::grantCaps();
				Controller::putCurrentPasswordsToStopList();
				if ( ! self::currentPasswordsHaveHistory() || ! self::ownsMuLock( $owner ) ) {
					return;
				}
				Cron::ensureEvent();
				if ( ! wp_next_scheduled( Cron::EVENT_NAME ) || 'twicedaily' !== wp_get_schedule( Cron::EVENT_NAME ) ) {
					return;
				}
				if ( self::ownsMuLock( $owner ) ) {
					update_option( self::MU_COMPLETE_OPTION, 1, false );
				}
			} finally {
				self::releaseMuLock( $owner );
			}
		} );
	}

	private static function currentPasswordsHaveHistory(): bool {
		$users = get_users( [ 'fields' => 'ids', 'blog_id' => is_multisite() ? 0 : get_current_blog_id() ] );
		foreach ( $users as $user_id ) {
			$user = get_user_by( 'ID', $user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );
			if ( ! is_array( $history ) || ! in_array( $user->user_pass, $history, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function ensureMainEvent(): void {
		if ( ! wp_next_scheduled( Cron::EVENT_NAME ) || 'twicedaily' !== wp_get_schedule( Cron::EVENT_NAME ) ) {
			Cron::ensureEvent();
		}
	}

	/** Claim the private main-site option row atomically, or take over an expired lease. */
	private static function claimMuLock(): ?string {
		global $wpdb;
		$owner = (string) ( time() + self::MU_LOCK_SECONDS ) . ':' . wp_generate_uuid4();
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
			self::MU_LOCK_OPTION, $owner, 'no'
		) );
		if ( 1 === $inserted ) {
			self::flushMuLockCache();
			return $owner;
		}
		if ( false === $inserted ) {
			return null;
		}

		self::flushMuLockCache();
		$previous = get_option( self::MU_LOCK_OPTION, '' );
		$expiry = (int) strtok( (string) $previous, ':' );
		if ( ! is_string( $previous ) || ! $expiry || $expiry >= time() ) {
			return null;
		}
		$replaced = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => $owner ],
			[ 'option_name' => self::MU_LOCK_OPTION, 'option_value' => $previous ],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		self::flushMuLockCache();
		return 1 === $replaced ? $owner : null;
	}

	private static function ownsMuLock( string $owner ): bool {
		self::flushMuLockCache();
		return get_option( self::MU_LOCK_OPTION, '' ) === $owner;
	}

	private static function releaseMuLock( string $owner ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->options,
			[ 'option_name' => self::MU_LOCK_OPTION, 'option_value' => $owner ],
			[ '%s', '%s' ]
		);
		self::flushMuLockCache();
	}

	private static function flushMuLockCache(): void {
		wp_cache_delete( self::MU_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function onMainSite( callable $callback ): void {
		$main_site_id = is_multisite() ? (int) get_network()->site_id : get_current_blog_id();
		if ( get_current_blog_id() === $main_site_id ) {
			$callback();
			return;
		}
		switch_to_blog( $main_site_id );
		try {
			$callback();
		} finally {
			restore_current_blog();
		}
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
