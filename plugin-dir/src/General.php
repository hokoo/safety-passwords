<?php

namespace iTRON\SafetyPasswords;

use Exception;
use iTRON\SafetyPasswords\Integrations\CLI\Safety;
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

		defined( 'WP_CLI' ) && WP_CLI && WP_CLI::add_command( 'safety', Safety::class );

		add_action( Cron::EVENT_NAME, [ Controller::class, 'findExpiringPasswords' ] );
		add_action( 'itron/safety-passwords/activate', [ self::class, 'processSecondPhaseActivation' ] );
		add_action( 'admin_bar_menu', [ self::class, 'addAdminBarMenu' ], 60,1 );
		add_action( 'personal_options', [ self::class, 'addUserProfileNotice' ], 20, 1 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'addAdminStyles' ] );
		add_action( 'plugins_loaded', [ self::class, 'loadTranslations' ] );
		add_action( 'init', function () {
			self::$logger = $this->initLogger();
		}, 5 );
	}

	public function processActivationHook(): void {
		$role = get_role( 'administrator' );
		$role->add_cap( Settings::MANAGE_CAPS, true );

		do_action( 'itron/safety-passwords/capabilities/set' );

		// Carbon fields can not be loaded during activation hook,
		// and the plugin can not be properly activated during the activation hook
		// because activation hook runs too late. See wp-admin/plugins.php:do_action( 'activate_' . $plugin );
		// So, we just need to schedule the second phase of activation for the next normal request.
		wp_schedule_single_event( time(), 'itron/safety-passwords/activate' );
	}

	public static function processSecondPhaseActivation(): void {
		Cron::ensureEvent( true );
	}

	public function processDeactivationHook(): void {
		Cron::stopEvent();
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

	public static function addAdminBarMenu( $wp_admin_bar ) {
		if ( ! Settings::getInterval() ) {
			return;
		}

		$user_id = get_current_user_id();
		$last_reset = get_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', true ) ?: time();
		if ( ( time() - $last_reset ) < ( DAY_IN_SECONDS * Settings::getInterval() - self::$reminderInterval ) ) {
			return;
		}

		/* @var WP_Admin_Bar $wp_admin_bar */
		$wp_admin_bar->add_node( array(
			'id'    => 'safety-passwords',
			'title' => sprintf(
				/* translators: %s: days */
				__( 'Change password in %s days', 'safety-passwords' ),
				floor( Settings::getInterval() - (int) ( ( time() - $last_reset ) / DAY_IN_SECONDS ) )
			),
			'meta'  => [
				'class' => 'safety-passwords-reminder',
			],
		) );
	}

	public static function addUserProfileNotice( WP_User $user ) {
		if ( ! Settings::getInterval() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id != $user->ID ) {
			return;
		}

		$last_reset = (int) get_user_meta( $user_id, Settings::$optionPrefix . 'last_reset', true ) ?: time();
		if ( ( time() - $last_reset ) < ( DAY_IN_SECONDS * Settings::getInterval() - self::$reminderInterval ) ) {
			$notice = sprintf(
				/* translators: %s: days */
				__( 'Next password change in %s days.', 'safety-passwords' ),
				floor( Settings::getInterval() - (int) ( ( time() - $last_reset ) / DAY_IN_SECONDS ) )
			);
			$type = 'info';
		} else {
			$notice = sprintf(
				/* translators: %s: days */
				__( 'Please, change your password in %s days.', 'safety-passwords' ),
				floor( Settings::getInterval() - (int) ( ( time() - $last_reset ) / DAY_IN_SECONDS ) )
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
