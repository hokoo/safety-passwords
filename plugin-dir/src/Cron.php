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

	public static function ensureEvent( bool $reset = false ): void {
		if ( is_multisite() ) {
			$main_site_id = (int) get_network()->site_id;
			self::forEachNetworkSite( function ( $site_id ) use ( $main_site_id, $reset ) {
				if ( $site_id !== $main_site_id ) {
					self::unscheduleCurrentSiteEvents();
					return;
				}
				self::ensureCurrentSiteEvent( $reset );
			} );
			return;
		}

		self::ensureCurrentSiteEvent( $reset );
	}

	private static function ensureCurrentSiteEvent( bool $reset ): void {
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ self::EVENT_NAME ] ) ) {
				$count += count( $events[ self::EVENT_NAME ] );
			}
		}

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

	private static function forEachNetworkSite( callable $callback ): void {
		$sites = get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] );
		foreach ( $sites as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );
			try {
				$callback( $site_id );
			} finally {
				restore_current_blog();
			}
		}
	}
}
