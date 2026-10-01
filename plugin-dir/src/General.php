<?php

namespace iTRON\SafetyPasswords;

use Exception;
use iTRON\SafetyPasswords\Integrations\CLI\Safety;
use iTRON\SafetyPasswords\Integrations\CLI\PasswordChanges;
use iTRON\SafetyPasswords\Integrations\StreamConnector;
use iTRON\SafetyPasswords\Loggers\Stream;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Admin_Bar;
use WP_CLI;
use WP_User;

class General {

	/**
	 * Period between asking to change password and actually hard resetting it. Seconds.
	 *
	 * @var int
	 */
	private static int $preInitInterval = 48 * HOUR_IN_SECONDS;

	/**
	 * Period between hard resetting password and reminding that resetting is coming. Seconds.
	 *
	 * @var int|float
	 */
	private static int $reminderInterval = 7 * DAY_IN_SECONDS;

	private static LoggerInterface $logger;

	public function init(): void {
		self::$logger = new NullLogger();
		Settings::init();
		Controller::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'safety', Safety::class );
			PasswordChanges::init();
		}

		add_action( Cron::EVENT_NAME, [ Controller::class, 'findExpiringPasswords' ] );
		add_action( 'admin_bar_menu', [ self::class, 'addAdminBarMenu' ], 60,1 );
		add_action( 'personal_options', [ self::class, 'addUserProfileNotice' ], 20, 1 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'addAdminStyles' ] );
		add_action( 'plugins_loaded', [ self::class, 'loadTranslations' ] );
		add_action( 'init', function () {
			self::$logger = $this->initLogger();
		}, 5 );
	}

	public static function processSecondPhaseActivation(): void {
		Activation::processSecondPhaseActivation();
	}

	public function processDeactivationHook(): void {
		Activation::processDeactivationHook();
	}

	public function grantCaps(): void {
		Activation::grantCaps();
	}

	public function processActivationHook(): void {
		Activation::processActivationHook();
	}

	public function addStreamConnector( array $connectors ): array {
		$connectors[] = new StreamConnector();

		return $connectors;
	}

	private function initLogger(): LoggerInterface {
		$logger = apply_filters( 'itron/safety-passwords/logger', self::$logger );
		if ( ! is_a( $logger, LoggerInterface::class ) || $logger instanceof NullLogger ) {
			if ( class_exists( 'WP_Stream\Connector' ) ) {
				$logger = new Stream();

				// This filter fires at the init hook with priority = 9.
				add_filter( 'wp_stream_connectors', [ $this, 'addStreamConnector' ] );
			}
		}

		return $logger;
	}

	public static function getPreInitInterval(): int {
		return self::$preInitInterval;
	}

	/**
	 * Calculate the current user's reminder without changing password metadata.
	 *
	 * @return array{state: string, days: int}
	 */
	private static function getExpiryReminder(): array {
		$interval = Settings::getInterval();
		if ( ! $interval ) {
			return [ 'state' => 'disabled', 'days' => 0 ];
		}

		$user_id = get_current_user_id();
		if ( '1' === get_user_meta( $user_id, Settings::$optionPrefix . 'rp_inited', true ) ) {
			return [ 'state' => 'reset_required', 'days' => 0 ];
		}

		$now = time();
		$last_reset = (int) get_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', true ) ?: $now;
		$age = $now - $last_reset;
		$duration = DAY_IN_SECONDS * $interval;
		if ( $age >= $duration ) {
			return [ 'state' => 'due', 'days' => 0 ];
		}

		$days = floor( $interval - (int) ( $age / DAY_IN_SECONDS ) );
		$state = $age < ( $duration - self::$reminderInterval ) ? 'early' : 'reminder';
		return [ 'state' => $state, 'days' => (int) $days ];
	}

	public static function addAdminBarMenu( $wp_admin_bar ) {
		$reminder = self::getExpiryReminder();
		if ( 'disabled' === $reminder['state'] || 'early' === $reminder['state'] ) {
			return;
		}

		if ( 'reset_required' === $reminder['state'] ) {
			$title = __( 'Password reset is required. Use the password recovery form.', 'safety-passwords' );
		} elseif ( 'due' === $reminder['state'] ) {
			$title = __( 'Password change is due. Change your password.', 'safety-passwords' );
		} else {
			$title = sprintf(
				/* translators: %s: days */
				__( 'Change password in %s days', 'safety-passwords' ),
				$reminder['days']
			);
		}

		/* @var WP_Admin_Bar $wp_admin_bar */
		$wp_admin_bar->add_node( array(
			'id'    => 'safety-passwords',
			'title' => $title,
			'meta'  => [
				'class' => 'safety-passwords-reminder',
			],
		) );
	}

	public static function addUserProfileNotice( WP_User $user ) {
		$user_id = get_current_user_id();
		if ( $user_id != $user->ID ) {
			return;
		}

		$reminder = self::getExpiryReminder();
		if ( 'disabled' === $reminder['state'] ) {
			return;
		}

		if ( 'reset_required' === $reminder['state'] ) {
			$notice = __( 'Password reset is required. Use the password recovery form.', 'safety-passwords' );
			$type = 'warning';
		} elseif ( 'due' === $reminder['state'] ) {
			$notice = __( 'Password change is due. Change your password.', 'safety-passwords' );
			$type = 'warning';
		} elseif ( 'early' === $reminder['state'] ) {
			$notice = sprintf(
				/* translators: %s: days */
				__( 'Next password change in %s days.', 'safety-passwords' ),
				$reminder['days']
			);
			$type = 'info';
		} else {
			$notice = sprintf(
				/* translators: %s: days */
				__( 'Please, change your password in %s days.', 'safety-passwords' ),
				$reminder['days']
			);
			$type = 'warning';
		}

		self::echoNotice( $notice, $type );
	}

	public static function getLogger(): LoggerInterface {
		return self::$logger;
	}

	public static function addAdminStyles() {
		wp_enqueue_style( 'safety-passwords', PLUGIN_URL . 'assets/css/admin/style.css', [], VERSION );
	}

	public static function echoNotice( string $message, string $type = 'info' ) {
		echo '<div class="notice notice-' . esc_attr( $type ). '"><p>' . wp_kses( $message, wp_kses_allowed_html() ) . '</p></div>';
	}

	public static function loadTranslations(): void {
		load_plugin_textdomain( 'safety-passwords', false, dirname( PLUGIN_DIR ) . '/languages' );
	}
}
