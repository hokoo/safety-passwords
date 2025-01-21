<?php

namespace iTRON\SafetyPasswords;

use WP_Error;
use WP_User;

class Controller {
	const PASSWORD_CHECK_FAILURE_CODE = 'pass';

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
		// User didn't manage to log in.
		if ( ! $user instanceof WP_User ) {
			// We can not perform nonce verification here, because the nonce is not passed as a request parameter.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$user = sanitize_user( $_REQUEST['log'] ?? '' );
			$user = get_user_by( 'login', $user ) ?: get_user_by( 'email', $user );

			if ( empty( $user ) ) {
				return $redirect;
			}

			if ( "1" === get_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited', true ) ) {
				// Oh, it seems we do know why the user has failed to log in.
				add_filter( 'wp_login_errors', function ( $errors ) {
					$errors->errors = [];
					$errors->add( 'pass', self::get_password_reset_message() );

					return $errors;
				} );
			}

			return $redirect;
		}

		// User is logged in.
		if ( get_user_meta( $user->ID, Settings::$optionPrefix . 'rp_pre_inited', true ) ) {
			// Okay, keep calm, kindly asking the user to change their password.
			// "Soft" password reset initiation. It's really seamless, without any reset link sendings.
			$reset_key = '';

			$reset = self::retrievePassword( $user, true, $reset_key );
			if ( true !== $reset ) {
				// Something went wrong when trying to init the password changing.
				// We really don't know what it was. But it seems it's better to allow the user to log in.

				/* Translators: %s - user login */
				$msg = __( 'Failed to retrieve password for user %s', 'safety-passwords' );
				if ( $reset instanceof WP_Error ) {
					$msg .= ': ' . implode( '; ', $reset->get_error_messages() );
				}

				General::getLogger()->error(
					sprintf( $msg, "{$user->user_login} [{$user->user_email}]" ),
					[ 'user' => $user, 'error' => $reset ]
				);

				return $redirect;
			}

			// The user just is being redirected to the password change form.
			// If the user doesn't change it now, he is still able to log in by using the old one until its expiration.
			return network_site_url( "wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode( $user->user_login ),
				'login' );
		}

		return $redirect;
	}

	public static function set_rp_pre_inited_on_registration( $user_id ): void {
		if ( ! Settings::getOption( 'rp_on_registration' ) ) {
			return;
		}

		// This fires for both whether the account is being created by another user or by the user itself.
		update_user_meta( $user_id, Settings::$optionPrefix . 'rp_pre_inited', true );

		// This fires for only the self-registration, cancel the password reset initiation.
		add_action( 'register_new_user', function ( $user_id ) {
			delete_user_meta( $user_id, Settings::$optionPrefix . 'rp_pre_inited' );
		}, 20, 1 );
	}

	public static function user_profile_update_errors( WP_Error $errors, $update, $user ): WP_Error {
		if ( ! empty( $user->user_pass ) ) {
			// This might be either password update or user creation as well.
			// We need to check if the password is secure in both cases.
			// But even if the password is secure, we need to force user to reset it after registration when
			// the account is being created by another user.

			if ( ! self::is_password_secure( $user->user_pass, $errors ) ) {
				return $errors;
			}

			if ( ! $update ) {
				// User is being created.
				// User's registration is handled by the callback of the 'user_register' hook firing a bit later.
				// Nothing to do here anymore.
				return $errors;
			}

			// At this point, the user is being updated: either by the user itself or by another user.
			// We believe that since the password is secure, we don't need to force the user to reset it again
			// even when the account is being updated by another user.
			// Password is secure at this point, remove the flag.
			// But we can't do it in the current context, because the password is not actually updated yet,
			// and some hook handlers may return errors a bit later even if no errors now.
			// So, the hook 'wp_update_user' is the best place to do it.
			add_action( 'wp_update_user', function ( $user_id, $userdata ) use ( $user ) {
				// Reassure that the user is the same.
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
	 * @param WP_Error $errors
	 * @param WP_User|WP_Error  $user
	 *
	 * @return WP_Error
	 */
	public static function validate_password_reset( WP_Error $errors, $user = null ): WP_Error {
		if ( ! $user instanceof WP_User ) {
			return $errors;
		}

		/**
		 * Notice for reviewers:
		 * We NEVER need to sanitize the PASSWORD.
		 * @see https://wordpress.org/support/topic/do-not-sanitize-the-password/
		 */

		/**
		 * Notice for reviewers:
		 * This function does not run in global scope. It is hooked on 'validate_password_reset' action,
		 * which is fired after the password reset form submission is validated by means of the user's cookie.
		 * So, it is safe to use the $_POST global variable here.
		 *
		 * Remember also, we are acting in the context of the password reset form submission.
		 * So, we can not perform nonce verification here, because the nonce is not passed as a request parameter.
		 * We can not verify the user by 'current_user_can' function, because the user is not logged in yet.
		 * Finally, the password is not passed as a parameter to the hook,
		 * but it is only available in the $_POST global variable.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST["pass1"] ) && self::is_password_secure( $_POST["pass1"], $errors ) ) {
			// Password is secure, remove the flag
			update_user_meta( $user->ID, Settings::$optionPrefix . 'last_reset', time() );
			delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_inited' );
			delete_user_meta( $user->ID, Settings::$optionPrefix . 'rp_pre_inited' );
		}

		return $errors;
	}

	public static function is_password_secure( $i, WP_Error &$errors = null ): bool {
		$length      = strlen( $i ) >= Settings::getOption( 'min_len' );
		$has_lower   = preg_match( '/[a-z]/', $i );
		$has_upper   = preg_match( '/[A-Z]/', $i );
		$has_number  = preg_match( '/[0-9]/', $i );
		$has_special = preg_match( '/[^a-zA-Z0-9]/', $i );

		if ( $errors ) {
			if ( ! $length ) {
				$errors->add( self::PASSWORD_CHECK_FAILURE_CODE,
					sprintf(
						/* Translators: %s - minimum password length */
						__( 'Password is too short. It must be at least %s characters long.', 'safety-passwords' ),
						Settings::getOption( 'min_len' )
					) );
			}

			if ( ! $has_lower ) {
				$errors->add( self::PASSWORD_CHECK_FAILURE_CODE,
					__( 'Password must contain at least one lowercase letter.', 'safety-passwords' ) );
			}

			if ( ! $has_upper ) {
				$errors->add( self::PASSWORD_CHECK_FAILURE_CODE,
					__( 'Password must contain at least one uppercase letter.', 'safety-passwords' ) );
			}

			if ( ! $has_number ) {
				$errors->add( self::PASSWORD_CHECK_FAILURE_CODE, __( 'Password must contain at least one number.', 'safety-passwords' ) );
			}

			if ( ! $has_special ) {
				$errors->add( self::PASSWORD_CHECK_FAILURE_CODE,
					__( 'Password must contain at least one special character.', 'safety-passwords' ) );
			}
		}

		// All the checks should be true
		return $length && $has_lower && $has_upper && $has_number && $has_special;
	}

	public static function findExpiringPasswords(): void {
		if ( ! Settings::getInterval() ) {
			return;
		}

		General::getLogger()->info( __( 'Checking users for password reset.', 'safety-passwords' ) );
		self::checkUsers( $resetUsers, $preInitedUsers );

		// Log the results.
		$string = sprintf(
			/* Translators: %1$s - number of users to reset password, %2$s - number of users to remind to reset password */
			__('Number of users to reset password immediately: %1$s. Number of users to remind to reset password soon: %2$s', 'safety-passwords' ),
			count( $resetUsers ),
			count( $preInitedUsers ),
		);
		General::getLogger()->info( $string, [ 'resetUsers' => $resetUsers, 'preInitedUsers' => $preInitedUsers ] );
	}

	public static function retrievePassword( WP_User $user, $skip_email = false, &$reset_key = '' ) {
		add_filter( 'retrieve_password_message', function ( $message, $key, $login, $user_data ) use ( $user, $skip_email, &$reset_key ) {
			// Reassure that the user is the same.
			if ( $login !== $user->user_login ) {
				return $message;
			}

			$reset_key = $key;

			if ( $skip_email ) {
				return '';
			}

			return sprintf(
				/* Translators: %1$s - user login, %2$s - password reset link, %3$s - new line */
				__( 'Hi there, %1$s!%3$sWe noticed you have not had a chance to reset your password yet, and that is totally okay!%3$sPlease proceed to do so now by visiting the following link: <%2$s>','safety-passwords' ),
				$user->user_login,
				network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $login ), 'login' ) . '&wp_lang=' . get_user_locale( $user_data ),
				PHP_EOL . PHP_EOL
			);
		}, 99, 4 );

		return retrieve_password( $user->user_login );
	}

	public static function checkUsers( &$resetUsers = [], &$preInitedUsers = [] ) {
		$users = get_users( ['fields' => 'ids'] );
		foreach ( $users as $user_id ) {
			if ( true === get_user_meta( $user_id, Settings::$optionPrefix . 'rp_inited', true ) ) {
				// The user has already reset the password, skip.
				continue;
			}

			$last_reset = (int) get_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', true );
			if ( ! $last_reset ) {
				update_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', time() );
				continue;
			}

			// Password has to be reset immediately.
			if ( ( time() - $last_reset ) > ( DAY_IN_SECONDS * Settings::getInterval() ) ) {
				$wp_user = get_user_by( 'ID', $user_id );
				if ( ! $wp_user instanceof WP_User ) {
					continue;
				}

				// Reset the password. Since this moment the user can not log in with the old password.
				update_user_meta( $user_id, Settings::$optionPrefix . 'rp_inited', true );
				wp_set_password( wp_generate_password( 24 ), $user_id );

				// We do not handle observing expiration of the password reset link.
				// When user uses expired link, WordPress itself handles it.
				$reset = Controller::retrievePassword( $wp_user );

				if ( true !== $reset ) {
					// Something went wrong when trying to reset the password.
					$msg = sprintf(
						/* Translators: %s - user login and email */
						__( "Failed to reset password for user %s", 'safety-passwords' ),
						"{$wp_user->user_login} [{$wp_user->user_email}]"
					);

					if ( $reset instanceof WP_Error ) {
						$msg .= ': ' . implode(  '; ', $reset->get_error_messages() );
					}

					General::getLogger()->error( $msg, [ 'user_id' => $user_id, 'error' => $reset ] );
				}

				$resetUsers[] = $user_id;

				continue;
			}

			// Password resetting is coming soon.
			if ( ( time() - $last_reset ) > ( DAY_IN_SECONDS * Settings::getInterval() - General::getPreInitInterval() ) ) {
				update_user_meta( $user_id, Settings::$optionPrefix . 'rp_pre_inited', true );
				$preInitedUsers[] = $user_id;
			}
		}
	}

	public static function get_password_reset_message(): string {
		return __( "Please <strong>reset your password</strong> to continue. Follow the instructions in the email that was sent to you.", 'safety-passwords' );
	}
}
