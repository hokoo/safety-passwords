<?php

namespace iTRON\SafetyPasswords;

class Controller {
	public static function init() {
		add_action( 'user_register', [ self::class, "set_force_password_change_flag" ], 20, 1 );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 10, 3 );
		add_action( 'user_profile_update_errors', [ self::class, 'user_profile_update_errors' ], 99, 3 );
		add_action( "validate_password_reset", [ self::class, "validate_password_reset" ], 99, 2 );
	}

	public static function user_profile_update_errors( \WP_Error $errors, $update, $user ): \WP_Error {
		if ( ! $update ) {
			return $errors;
		}

		if ( ! empty( $_POST["pass1"] ) ) {
			if ( ! self::is_password_secure( $_POST["pass1"] ) ) {
				$errors->add( 'pass', self::get_weak_password_message() );
			}

			return $errors;
		}

		return $errors;
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
			if ( "1" === get_user_meta( $user->ID, 'safetypasswords_need_reset_password', true ) ) {
				// User needs to reset password
				add_filter( 'wp_login_errors', function ( $errors ) {
					$errors->errors = [];
					$errors->add( 'pass', self::get_password_reset_message() );

					return $errors;
				} );
			}

			return $redirect;
		}

		if ( "1" === get_user_meta( $user->ID, 'safetypasswords_force_password_change_after_registration', true ) ) {
			reset_password( $user, wp_generate_password( 24 ) );
			$key = get_password_reset_key( $user );

			// Send email with password reset link
			$subject = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) . ' - ' . __( 'Password Reset',
					'safety-passwords' );
			$message = sprintf(
				__( "Dear %s!\r\nYou were asked to reset your password.\r\nIf you didn't managed do it till now, please, visit the following address: <%s>",
					'safety-passwords' ),
				$user->user_login,
				network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ),
					'login' )
			);
			if ( $message && ! wp_mail( $user->user_email, wp_specialchars_decode( $subject ), $message ) ) {
				wp_die(
					__( 'The e-mail could not be sent. Possible reason: your host may have disabled the mail() function.',
						'safety-passwords' )
				);
			}
			delete_user_meta( $user->ID, 'safetypasswords_force_password_change_after_registration' );
			update_user_meta( $user->ID, 'safetypasswords_need_reset_password', 1 );

			return network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ),
				'login' );
		}

		return $redirect;
	}

	public static function set_force_password_change_flag( $user_id ): void {
		update_user_meta( $user_id, 'safetypasswords_force_password_change_after_registration', 1 );
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
				delete_user_meta( $user->ID, 'safetypasswords_need_reset_password' );
			}

			return $errors;
		}

		return $errors;
	}

	public static function is_password_secure( $i ): bool {
		$length      = strlen( $i ) > 10;
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
}
