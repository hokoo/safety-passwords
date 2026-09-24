<?php

namespace iTRON\SafetyPasswords\Integrations\CLI;

use iTRON\SafetyPasswords\Controller;
use WP_CLI;
use WP_User;

/**
 * Applies password bookkeeping only while WP-CLI invokes its standard user commands.
 */
class PasswordChanges {
	private static bool $warned = false;

	public static function init(): void {
		foreach ( [ 'user update', 'user reset-password' ] as $command ) {
			WP_CLI::add_hook( 'before_invoke:' . $command, [ self::class, 'begin' ] );
			WP_CLI::add_hook( 'after_invoke:' . $command, [ self::class, 'end' ] );
		}
	}

	public static function begin(): void {
		self::$warned = false;
		add_action( 'profile_update', [ self::class, 'record' ], 10, 2 );
	}

	public static function end(): void {
		remove_action( 'profile_update', [ self::class, 'record' ], 10 );
	}

	public static function record( $user_id, $old_user_data ): void {
		if ( ! $old_user_data instanceof WP_User ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! is_string( $user->user_pass ) || '' === $user->user_pass ||
			$user->user_pass === $old_user_data->user_pass ) {
			return;
		}

		Controller::completeCliPasswordChange( $user, (string) $old_user_data->user_pass, $user->user_pass );
		if ( ! self::$warned ) {
			WP_CLI::warning( 'Safety Passwords: WP-CLI password changes bypass strength and reuse checks; password history and expiry were updated.' );
			self::$warned = true;
		}
	}
}
