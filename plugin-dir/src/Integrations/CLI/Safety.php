<?php

namespace iTRON\SafetyPasswords\Integrations\CLI;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\General;
use iTRON\SafetyPasswords\Settings;
use WP_CLI;
use WP_CLI_Command;

class Safety extends WP_CLI_Command {
	/**
	 * Walk-through the users and check if they have to reset their password.
	 *
	 * @alias check-users
	 */
	public function check_users( $args, $assoc_args ) {
		WP_CLI::line( Settings::getInterval() );
		$users = get_users( ['fields' => 'ids'] );
		foreach ( $users as $user_id ) {
			$last_reset = (int) get_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', true );
			if ( ! $last_reset ) {
				update_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', time() );
				continue;
			}

			// Password has to be reset immediately.
			if ( ( time() - $last_reset ) > ( DAY_IN_SECONDS * Settings::getInterval() ) ) {
				if ( true === Controller::retrievePassword( get_user_by( 'ID', $user_id ) ) ) {
					WP_CLI::success( "Password has been retrieved for user : $user_id" );
				} else {
					WP_CLI::warning( "Password has not been retrieved for user : $user_id" );
				}

				continue;
			}

			// Password resetting is coming soon.
			if ( ( time() - $last_reset ) > ( DAY_IN_SECONDS * Settings::getInterval() - General::getPreInitInterval() ) ) {
				update_user_meta( $user_id, Settings::$optionPrefix . 'rp_pre_inited', true );
			}
		}

		WP_CLI::success( 'Done.' );
	}
}