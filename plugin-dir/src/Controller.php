<?php

namespace iTRON\SafetyPasswords;

class Controller {
	public static function init(): void {
		add_action( 'user_register', [ self::class, "set_force_password_change_flag" ], 20, 1 );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 10, 3 );
		add_action( 'user_profile_update_errors', [ self::class, 'user_profile_update_errors' ], 99, 3 );
		add_action( "validate_password_reset", [ self::class, "validate_password_reset" ], 99, 2 );
	}

	public static function login_redirect( $redirect, $requested_redirect_to, $user ) {
		if ( ! $user instanceof \WP_User ) {
			if ( ! isset( $_REQUEST['log'] ) ) {
				return $redirect;
			}

			// Retrieve user object from user log
			$user = get_user_by( 'login', $_REQUEST['log'] ) ?: get_user_by( 'email', $_REQUEST['log'] );

			if ( ! $user ) {
				return $redirect;
			}

			// Check if user needs to reset password
			if ( "1" === get_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited', true ) ) {
				// User needs to reset password
				add_filter( 'wp_login_errors', function ( $errors ) {
					$errors->errors = [];
					$errors->add( 'pass', self::get_password_reset_message() );

					return $errors;
				} );
			}

			return $redirect;
		}

		if ( "1" === get_user_meta( $user->ID, Settings::$optionPrefix . 'fpr_registration', true ) ) {
			$reset_key = '';

			if ( true !== self::retrievePassword( $user, true, $reset_key ) ) {
				wp_die(
					__( 'Something went wrong when trying to reset your password. Please, try again later.',
						'safety-passwords' )
				);
			}

			delete_user_meta( $user->ID, Settings::$optionPrefix . 'fpr_registration' );

			return network_site_url( "wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode( $user->user_login ),
				'login' );
		}

		return $redirect;
	}

	public static function set_force_password_change_flag( $user_id ): void {
		if ( ! Settings::getOption( 'fpr_on_registration' ) ) {
			return;
		}

		update_user_meta( $user_id, Settings::$optionPrefix . 'fpr_registration', 1 );
	}

	public static function user_profile_update_errors( \WP_Error $errors, $update, $user ): \WP_Error {
		if ( ! empty( $_POST["pass1"] ) ) {
			if ( ! self::is_password_secure( $_POST["pass1"] ) ) {
				$errors->add( 'pass', self::get_weak_password_message() );
			}

			update_user_meta( $user->ID, Settings::$optionPrefix . 'last_reset', time() );

			return $errors;
		}

		return $errors;
	}

	public static function validate_password_reset( $errors, $user = null ) {
		if ( ! $user instanceof \WP_User ) {
			return $errors;
		}

		if ( isset( $_POST["pass1"] ) ) {
			if ( ! self::is_password_secure( $_POST["pass1"] ) ) {
				$errors->add( 'pass', self::get_weak_password_message() );
			}

			if ( ! $errors->get_error_data( "pass" ) ) {
				// Password is secure, remove the flag
				delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited' );
				update_user_meta( $user->ID, Settings::$optionPrefix . 'last_reset', time() );
			}

			return $errors;
		}

		return $errors;
	}

	public static function is_password_secure( $i ): bool {
		$length      = strlen( $i ) >= Settings::getOption( 'min_len' );
		$has_lower   = preg_match( '/[a-z]/', $i );
		$has_upper   = preg_match( '/[A-Z]/', $i );
		$has_number  = preg_match( '/[0-9]/', $i );
		$has_special = preg_match( '/[^a-zA-Z0-9]/', $i );

		// All the checks should be true
		return $length && $has_lower && $has_upper && $has_number && $has_special;
	}

	public static function display_notice( $msg ) {
		add_action( 'admin_notices', function () use ( $msg ) {
			echo "<div class='notice notice-success is-dismissible'><p>$msg</p></div>";
		} );
	}

	public static function get_weak_password_message(): string {
		return sprintf(
			__( 'Please enter a %sstrong%s password to comply this site\'s security measures.', 'safety-passwords' ),
			"<strong>",
			"</strong>"
		);
	}

	public static function get_password_reset_message(): string {
		return sprintf(
			__( 'Please %sreset your password%s to continue. Follow the instructions in the email that was sent to you.',
				'safety-passwords' ),
			"<strong>",
			"</strong>"
		);
	}

	public static function findExpiringPasswords(): void {
		if ( ! Settings::getInterval() ) {
			return;
		}

		shell_exec( "wp safety check-users" );

	}

	public static function retrievePassword( $user, $skip_email = false, &$reset_key = '' ) {
		add_filter( 'retrieve_password_message', function ( $message, $key, $login, $user_data ) use ( $user, $skip_email, &$reset_key ) {
			if ( $login !== $user->user_login ) {
				return $message;
			}

			$reset_key = $key;

			if ( $skip_email ) {
				return '';
			}

			return sprintf(
				__( "Dear %s!\r\nYou were asked to reset your password.\r\nIf you didn't managed do it till now, please, visit the following address: <%s>",
					'safety-passwords' ),
				$user->user_login,
				network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $login ), 'login' ) . '&wp_lang=' . get_user_locale( $user_data )
			);
		}, 99, 4 );

		$result = retrieve_password( $user->user_login );
		if ( true === $result ) {
			update_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited', 1 );
		}

		return $result;
	}
}
