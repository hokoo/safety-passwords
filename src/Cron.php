<?php

namespace iTRON\SafetyPasswords;

class Cron {
	const EVENT_NAME = 'safety_passwords_periodically_reset';

	public static function stopEvent(): void {
		wp_unschedule_event( wp_next_scheduled( self::EVENT_NAME ), self::EVENT_NAME );
	}

	public static function ensureEvent( bool $reset = false ): void {
		if ( $reset ) {
			self::stopEvent();
		}

		if ( ! wp_next_scheduled( self::EVENT_NAME ) ) {
			// Avoid the first run to be immediate.
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'twicedaily', self::EVENT_NAME );
		}
	}
}
