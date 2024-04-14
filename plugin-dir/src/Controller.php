<?php

namespace iTRON\SafetyPasswords;

class Controller {
	public static function init(): void {
		// @todo Handling pre_inited when user is already logged in.

		add_action( 'user_register', [ self::class, "set_rp_pre_inited_on_registration" ], 20, 1 );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 10, 3 );
		add_action( 'user_profile_update_errors', [ self::class, 'user_profile_update_errors' ], 99, 3 );
		add_action( "validate_password_reset", [ self::class, "validate_password_reset" ], 99, 2 );
	}

	/**
	 * Fires on usual login attempting, but we don't actually know was it successful or not.
	 * 
	 * @param $redirect
	 * @param $requested_redirect_to
	 * @param $user
	 *
	 * @return mixed|string
	 */
	public static function login_redirect( $redirect, $requested_redirect_to, $user ) {
		// If user didn't manage to log in.
		if ( ! $user instanceof \WP_User ) {
			if ( ! isset( $_REQUEST['log'] ) ) {
				return $redirect;
			}

			// Retrieve user object from user log
			$user = get_user_by( 'login', $_REQUEST['log'] ) ?: get_user_by( 'email', $_REQUEST['log'] );

			if ( ! $user ) {
				return $redirect;
			}
			
			// Check if user's password has been forcefully reset.
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

		// User is logged in.
		if ( true === get_user_meta( $user->ID, Settings::$optionPrefix . 'rp_pre_inited', true ) ) {
			// Ok, keep calm, kindly asking user to reset their password.
			$reset_key = '';

			if ( true !== self::retrievePassword( $user, true, $reset_key ) ) {
				wp_die(
					__( 'Something went wrong when trying to reset your password. Please, try again later.',
						'safety-passwords' )
				);
			}

			// If the user doesn't reset the password now, he keeps possibility to log in by old password.
			return network_site_url( "wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode( $user->user_login ),
				'login' );
		}

		return $redirect;
	}

	public static function set_rp_pre_inited_on_registration( $user_id ): void {
		if ( ! Settings::getOption( 'rp_on_registration' ) ) {
			return;
		}

		// @todo: check if the account is being created by another user.
		// If not, skip the password reset initiation.
		update_user_meta( $user_id, Settings::$optionPrefix . 'rp_pre_inited', true );
	}

	public static function user_profile_update_errors( \WP_Error $errors, $update, $user ): \WP_Error {
		if ( ! empty( $_POST["pass1"] ) ) {
			// This might be either password update or user creation as well.
			// We need to check if the password is secure in both cases.
			// But even if the password is secure, we need to force user to reset it after registration when
			// the account is being created by another user.
			if ( ! self::is_password_secure( $_POST["pass1"] ) ) {
				$errors->add( 'pass', self::get_weak_password_message() );
				return $errors;
			}

			if ( ! $update ) {
				// User is being created. Nothing to do anymore here.
				return $errors;
			}

			// Password is secure, remove the flag.
			// But we can't do it in current context, because the password is not actually updated yet
			// and some handlers may return errors even if no errors now.
			add_action( 'wp_update_user', function ( $user_id, $userdata ) use ( $user ) {
				if ( $user_id !== $user->ID ) {
					return;
				}

				update_user_meta( $user->ID, Settings::$optionPrefix . 'last_reset', time() );
				delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited' );
				delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_pre_inited' );
			}, 10, 2 );
		}

		return $errors;
	}

	/**
	 * Fires exactly after password reset form submission.
	 * 
	 * @param $errors
	 * @param $user
	 *
	 * @return mixed
	 */
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
				update_user_meta( $user->ID, Settings::$optionPrefix . 'last_reset', time() );
				delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited' );
				delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_pre_inited' );
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
				__( "Hi there!<br/>We noticed you haven't had a chance to reset your password yet, and that's totally okay!<br/>Please proceed to do so now by visiting the following link: <%s>",
					'safety-passwords' ),
				$user->user_login,
				network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $login ), 'login' ) . '&wp_lang=' . get_user_locale( $user_data )
			);
		}, 99, 4 );

		return retrieve_password( $user->user_login );
	}
}
