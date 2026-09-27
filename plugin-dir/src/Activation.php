<?php

namespace iTRON\SafetyPasswords;

class Activation {
	private const MU_COMPLETE_OPTION = 'safety_passwords_mu_initialized';
	private const MU_LOCK_OPTION = 'safety_passwords_mu_initializing';
	private const VERSION_OPTION = 'safety_passwords_initialized_version';
	private const SCHEDULE_VERIFIED_OPTION = 'safety_passwords_schedule_verified_at';
	private const SCHEDULE_FAILURE_OPTION = 'safety_passwords_schedule_failure';
	private const MU_LOCK_SECONDS = 900;
	private const SCHEDULE_VERIFICATION_SECONDS = 86400;
	private static bool $mustUse = false;

	public static function init(): void {
		add_action( 'itron/safety-passwords/activate', [ self::class, 'processSecondPhaseActivation' ] );
		if ( is_multisite() ) {
			// Fired after the new site's roles are initialized on supported WordPress versions.
			add_action( 'wpmu_new_blog', [ self::class, 'grantCapsForNewSite' ] );
		}
		// A root MU loader may require this ordinary-path file. Network plugins also
		// load before muplugins_loaded, so hook timing cannot identify MU mode.
		self::$mustUse = self::loadedByMustUsePlugin();
		add_action( 'wp_loaded', [ self::class, self::$mustUse ? 'bootstrapMustUse' : 'bootstrapOrdinary' ] );
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
			delete_option( self::VERSION_OPTION );
			delete_option( self::SCHEDULE_VERIFIED_OPTION );
			delete_option( self::SCHEDULE_FAILURE_OPTION );
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
		// A concurrent bootstrap may have completed while WP-Cron waited to run.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$complete = false;
			self::onMainSite( function () use ( &$complete ) {
				$complete = self::isCurrentVersionComplete();
			} );
			if ( $complete ) {
				return;
			}
		}
		self::initialize( true );
	}

	public static function processDeactivationHook(): void {
		Cron::stopEvent();
		self::onMainSite( function () {
			wp_clear_scheduled_hook( 'itron/safety-passwords/activate' );
			delete_option( self::MU_COMPLETE_OPTION );
			delete_option( self::VERSION_OPTION );
			delete_option( self::SCHEDULE_VERIFIED_OPTION );
			delete_option( self::SCHEDULE_FAILURE_OPTION );
		} );
	}

	/** Complete pending ordinary activation or migration on the next full load. */
	public static function bootstrapOrdinary(): void {
		self::onMainSite( function () {
			if ( get_option( self::VERSION_OPTION ) !== VERSION ) {
				self::initialize();
				return;
			}
			self::repairScheduleIfDue();
		} );
	}

	/** MU bootstrap shares the ready-state schedule check with ordinary loading. */
	public static function bootstrapMustUse(): void {
		self::onMainSite( function () {
			if ( self::isCurrentVersionComplete() ) {
				self::repairScheduleIfDue();
				return;
			}
			self::initialize();
		} );
	}

	/** Repair lifecycle state for the current network; false means retry is required. */
	public static function initialize( bool $force = false ): bool {
		$main_site_id = is_multisite() ? (int) get_network()->site_id : get_current_blog_id();
		if ( get_current_blog_id() !== $main_site_id ) {
			switch_to_blog( $main_site_id );
			try {
				return self::initializeOnMainSite( $force );
			} finally {
				restore_current_blog();
			}
		}
		return self::initializeOnMainSite( $force );
	}

	private static function initializeOnMainSite( bool $force ): bool {
		if ( ! $force && self::isCurrentVersionComplete() ) {
			return true;
		}
		$owner = self::claimMuLock();
		if ( null === $owner ) {
			return false;
		}
		$progress = function () use ( &$owner ) {
			if ( ! self::renewMuLock( $owner ) ) {
				throw new \RuntimeException();
			}
		};
		try {
			$progress();
			wp_cache_delete( self::VERSION_OPTION, 'options' );
			wp_cache_delete( self::MU_COMPLETE_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( ! $force && self::isCurrentVersionComplete() ) {
				return true;
			}
			// A failed forced repair must be retried by the next normal request.
			if ( $force && get_option( self::VERSION_OPTION ) === VERSION ) {
				delete_option( self::VERSION_OPTION );
				wp_cache_delete( self::VERSION_OPTION, 'options' );
				if ( get_option( self::VERSION_OPTION ) === VERSION ) {
					return false;
				}
			}
			// Completed bootstrap supersedes the deferred activation phase.
			wp_clear_scheduled_hook( 'itron/safety-passwords/activate' );
			if ( wp_next_scheduled( 'itron/safety-passwords/activate' ) ) {
				return false;
			}
			self::grantCaps( $progress );
			Controller::putCurrentPasswordsToStopList( $progress );
			if ( ! self::currentPasswordsHaveHistory( $progress ) || ! self::capabilitiesAreGranted( $progress ) || ! self::renewMuLock( $owner ) ) {
				return false;
			}
			Cron::ensureEvent( false, $progress );
			if ( ! Cron::isNormalized( $progress ) || ! self::renewMuLock( $owner ) ) {
				return false;
			}
			if ( ! self::recordScheduleVerified() ) {
				return false;
			}
			if ( self::$mustUse && ! get_option( self::MU_COMPLETE_OPTION ) ) {
				if ( ! update_option( self::MU_COMPLETE_OPTION, 1, false ) && ! get_option( self::MU_COMPLETE_OPTION ) ) {
					return false;
				}
				wp_cache_delete( self::MU_COMPLETE_OPTION, 'options' );
				if ( ! get_option( self::MU_COMPLETE_OPTION ) ) {
					return false;
				}
			}
			if ( ! self::renewMuLock( $owner ) || ! self::ownsMuLock( $owner ) || ( ! update_option( self::VERSION_OPTION, VERSION, false ) && get_option( self::VERSION_OPTION ) !== VERSION ) ) {
				return false;
			}
			wp_cache_delete( self::VERSION_OPTION, 'options' );
			$complete = get_option( self::VERSION_OPTION ) === VERSION && self::ownsMuLock( $owner );
			return $complete;
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			self::releaseMuLock( $owner );
		}
	}

	private static function isCurrentVersionComplete(): bool {
		return get_option( self::VERSION_OPTION ) === VERSION
			&& ( ! self::$mustUse || get_option( self::MU_COMPLETE_OPTION ) );
	}

	private static function capabilitiesAreGranted( ?callable $progress = null ): bool {
		if ( $progress ) {
			$progress();
		}
		if ( ! is_multisite() ) {
			$granted = self::currentSiteCapabilityIsGranted();
			if ( $progress ) {
				$progress();
			}
			return $granted;
		}
		$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
		if ( $progress ) {
			$progress();
		}
		foreach ( $sites as $site_id ) {
			if ( $progress ) {
				$progress();
			}
			switch_to_blog( (int) $site_id );
			try {
				if ( ! self::currentSiteCapabilityIsGranted() ) {
					return false;
				}
			} finally {
				restore_current_blog();
			}
			if ( $progress ) {
				$progress();
			}
		}
		return true;
	}

	private static function currentSiteCapabilityIsGranted(): bool {
		$role = get_role( 'administrator' );
		if ( ! $role || ! $role->has_cap( Settings::MANAGE_CAPS ) ) {
			return false;
		}
		$role_option = wp_roles()->role_key;
		$stored_roles = get_option( $role_option );
		return is_array( $stored_roles )
			&& ! empty( $stored_roles['administrator']['capabilities'][ Settings::MANAGE_CAPS ] );
	}

	private static function currentPasswordsHaveHistory( ?callable $progress = null ): bool {
		$users = UserScope::userIds( null, $progress );
		foreach ( $users as $user_id ) {
			if ( $progress ) {
				$progress();
			}
			$user = get_user_by( 'ID', $user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$history = get_user_meta( $user_id, Controller::USER_STOP_LIST_META_KEY, true );
			if ( ! is_array( $history ) || ! in_array( $user->user_pass, $history, true ) ) {
				return false;
			}
			if ( $progress ) {
				$progress();
			}
		}
		return true;
	}

	/** Healthy requires a canonical main event and a recent successful network pass. */
	public static function scheduleHealth(): string {
		$health = 'verification_due';
		self::onMainSite( function () use ( &$health ) {
			if ( ! Cron::currentSiteEventIsCanonical() || get_option( self::SCHEDULE_FAILURE_OPTION ) ) {
				$health = 'degraded';
			} elseif ( ! self::scheduleVerificationDue() ) {
				$health = 'healthy';
			}
		} );
		return $health;
	}

	/** Ready-state repair never traverses users and uses the initialization lease. */
	private static function repairScheduleIfDue(): void {
		if ( Cron::currentSiteEventIsCanonical() && ! self::scheduleVerificationDue() && ! get_option( self::SCHEDULE_FAILURE_OPTION ) ) {
			return;
		}
		$failure = get_option( self::SCHEDULE_FAILURE_OPTION );
		if ( is_array( $failure ) && isset( $failure['next_retry'] ) && (int) $failure['next_retry'] > time() ) {
			return;
		}
		$owner = self::claimMuLock();
		if ( null === $owner ) {
			return;
		}
		$progress = function () use ( &$owner ) {
			if ( ! self::renewMuLock( $owner ) ) {
				throw new \RuntimeException();
			}
		};
		try {
			$progress();
			if ( ! self::isCurrentVersionComplete() ) {
				return;
			}
			if ( Cron::currentSiteEventIsCanonical() && ! self::scheduleVerificationDue() && ! get_option( self::SCHEDULE_FAILURE_OPTION ) ) {
				return;
			}
			// A repaired main event is not proof that a full network pass finished.
			delete_option( self::SCHEDULE_VERIFIED_OPTION );
			Cron::ensureEvent( false, $progress );
			if ( ! Cron::isNormalized( $progress ) || ! self::renewMuLock( $owner ) || ! self::recordScheduleVerified() ) {
				self::recordScheduleFailure( $owner );
			}
		} catch ( \Throwable $error ) {
			self::recordScheduleFailure( $owner );
		} finally {
			self::releaseMuLock( $owner );
		}
	}

	private static function scheduleVerificationDue(): bool {
		$verified = get_option( self::SCHEDULE_VERIFIED_OPTION );
		return ! is_numeric( $verified ) || (int) $verified > time() || (int) $verified <= time() - self::SCHEDULE_VERIFICATION_SECONDS;
	}

	private static function recordScheduleVerified(): bool {
		$now = time();
		if ( ! update_option( self::SCHEDULE_VERIFIED_OPTION, $now, false ) && (int) get_option( self::SCHEDULE_VERIFIED_OPTION ) !== $now ) {
			return false;
		}
		return delete_option( self::SCHEDULE_FAILURE_OPTION ) || false === get_option( self::SCHEDULE_FAILURE_OPTION );
	}

	private static function recordScheduleFailure( string $owner ): void {
		if ( ! self::ownsMuLock( $owner ) ) {
			return;
		}
		$failure = get_option( self::SCHEDULE_FAILURE_OPTION );
		$previous = is_array( $failure ) && isset( $failure['attempts'] ) ? (int) $failure['attempts'] : 0;
		$attempts = min( 5, $previous + 1 );
		update_option( self::SCHEDULE_FAILURE_OPTION, [
			'attempts' => $attempts,
			'next_retry' => time() + min( 300 * ( 2 ** ( $attempts - 1 ) ), HOUR_IN_SECONDS ),
		], false );
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
		if ( ! is_string( $previous ) || ! $expiry || $expiry > time() ) {
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
		if ( (int) strtok( $owner, ':' ) <= time() ) {
			return false;
		}
		self::flushMuLockCache();
		return get_option( self::MU_LOCK_OPTION, '' ) === $owner;
	}

	/** Extend only our unexpired lease using the complete old value as a CAS token. */
	private static function renewMuLock( string &$owner ): bool {
		global $wpdb;
		$expiry = (int) strtok( $owner, ':' );
		$now = time();
		if ( $expiry <= $now ) {
			return false;
		}
		if ( $expiry - $now > 300 ) {
			$owned = false;
			self::onMainSite( function () use ( $owner, &$owned ) {
				$owned = self::ownsMuLock( $owner );
			} );
			return $owned;
		}

		$next = (string) ( $now + self::MU_LOCK_SECONDS ) . ':' . substr( $owner, strpos( $owner, ':' ) + 1 );
		$updated = null;
		self::onMainSite( function () use ( $wpdb, $owner, $next, &$updated ) {
			$updated = $wpdb->update(
				$wpdb->options,
				[ 'option_value' => $next ],
				[ 'option_name' => self::MU_LOCK_OPTION, 'option_value' => $owner ],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			self::flushMuLockCache();
		} );
		if ( 1 !== $updated ) {
			return false;
		}
		$owner = $next;
		return true;
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

	public static function grantCaps( ?callable $progress = null ): void {
		if ( $progress ) {
			$progress();
		}
		if ( is_multisite() ) {
			$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
			if ( $progress ) {
				$progress();
			}
			foreach ( $sites as $site_id ) {
				if ( $progress ) {
					$progress();
				}
				switch_to_blog( (int) $site_id );
				try {
					self::grantCurrentSiteCap();
				} finally {
					restore_current_blog();
				}
				if ( $progress ) {
					$progress();
				}
			}
		} else {
			self::grantCurrentSiteCap();
		}

		do_action( 'itron/safety-passwords/capabilities/set' );
		if ( $progress ) {
			$progress();
		}
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
