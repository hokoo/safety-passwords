<?php

namespace iTRON\SafetyPasswords\Integrations\CLI;
use iTRON\SafetyPasswords\Activation;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\General;
use WP_CLI;
use WP_CLI_Command;

class Safety extends WP_CLI_Command {
	/**
	 * Initialize or repair the current site's or network's lifecycle state.
	 *
	 * Run with --url=<main-site-url> to select a network in multisite.
	 */
	public function init( $args, $assoc_args ) {
		if ( ! Activation::initialize( true ) ) {
			WP_CLI::error( 'Safety Passwords initialization did not complete.' );
		}
		WP_CLI::success( 'Safety Passwords initialization completed.' );
	}
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
