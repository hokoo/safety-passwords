<?php

namespace iTRON\SafetyPasswords\Integrations\CLI;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\General;
use WP_CLI;
use WP_CLI_Command;

class Safety extends WP_CLI_Command {
	/**
	 * Walk-through the users and check if they have to reset their password.
	 *
	 * @alias check-users
	 */
	public function check_users( $args, $assoc_args ) {
		General::getLogger()->info( 'Checking users for password reset.' );
		$resetUsers = [];
		$preInitedUsers = [];
		Controller::checkUsers( $resetUsers, $preInitedUsers );
		// Log the results.
		General::getLogger()->info( 'Users to reset.', [ 'count' => count( $resetUsers ) ] );
		General::getLogger()->info( 'Users to pre-init.', [ 'count' => count( $preInitedUsers ) ] );

		WP_CLI::success( 'Done.' );
	}
}
