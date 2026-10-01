<?php

namespace iTRON\SafetyPasswords;

class Cron {
	const EVENT_NAME = 'safety_passwords_periodically_reset';

	public static function stopEvent(): void {
		if ( is_multisite() ) {
			self::forEachNetworkSite( function () {
				self::unscheduleCurrentSiteEvents();
			} );
			return;
		}

		self::unscheduleCurrentSiteEvents();
	}

	public static function ensureEvent( bool $reset = false, ?callable $progress = null ): void {
		if ( is_multisite() ) {
			$main_site_id = (int) get_network()->site_id;
			self::forEachNetworkSite( function ( $site_id ) use ( $main_site_id, $reset ) {
				if ( $site_id !== $main_site_id ) {
					self::unscheduleCurrentSiteEvents();
					return;
				}
				self::ensureCurrentSiteEvent( $reset );
			}, $progress );
			return;
		}

		if ( $progress ) {
			$progress();
		}
		self::ensureCurrentSiteEvent( $reset );
		if ( $progress ) {
			$progress();
		}
	}

	/** Confirm the canonical event across only the selected network. */
	public static function isNormalized( ?callable $progress = null ): bool {
		if ( ! is_multisite() ) {
			if ( $progress ) {
				$progress();
			}
			$normalized = self::currentSiteEventIsCanonical();
			if ( $progress ) {
				$progress();
			}
			return $normalized;
		}
		$main_site_id = (int) get_network()->site_id;
		$normalized = true;
		$main_site_seen = false;
		self::forEachNetworkSite( function ( $site_id ) use ( $main_site_id, &$normalized, &$main_site_seen ) {
			if ( $site_id === $main_site_id ) {
				$main_site_seen = true;
				$normalized = self::currentSiteEventIsCanonical() && $normalized;
			} elseif ( self::currentSiteEventCount() ) {
				$normalized = false;
			}
		}, $progress );
		return $main_site_seen && $normalized;
	}

	/** Cheap check for the current site's one canonical event; callers select the main site. */
	public static function currentSiteEventIsCanonical(): bool {
		return 1 === self::currentSiteEventCount()
			&& false !== wp_next_scheduled( self::EVENT_NAME )
			&& 'twicedaily' === wp_get_schedule( self::EVENT_NAME );
	}

	private static function currentSiteEventCount(): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ self::EVENT_NAME ] ) ) {
				$count += count( $events[ self::EVENT_NAME ] );
			}
		}
		return $count;
	}

	private static function ensureCurrentSiteEvent( bool $reset ): void {
		$count = self::currentSiteEventCount();

		if ( $reset || $count > 1 || ( $count && 'twicedaily' !== wp_get_schedule( self::EVENT_NAME ) ) ) {
			// Clear every argument variant, including legacy events with arguments.
			self::unscheduleCurrentSiteEvents();
			$count = 0;
		}

		if ( ! $count ) {
			// Avoid the first run to be immediate.
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'twicedaily', self::EVENT_NAME );
		}
	}

	private static function unscheduleCurrentSiteEvents(): void {
		// WordPress 5.0 warns when wp_unschedule_hook() receives an empty cron array.
		if ( ! _get_cron_array() ) {
			return;
		}

		wp_unschedule_hook( self::EVENT_NAME );
	}

	private static function forEachNetworkSite( callable $callback, ?callable $progress = null ): void {
		if ( $progress ) {
			$progress();
		}
		$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
		if ( $progress ) {
			$progress();
		}
		foreach ( $sites as $site_id ) {
			if ( $progress ) {
				$progress();
			}
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );
			try {
				$callback( $site_id );
			} finally {
				restore_current_blog();
			}
			if ( $progress ) {
				$progress();
			}
		}
	}
}
