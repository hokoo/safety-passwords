<?php

namespace iTRON\SafetyPasswords;

use Exception;
use iTRON\SafetyPasswords\Integrations\CLI\Safety;
use iTRON\SafetyPasswords\Integrations\StreamConnector;
use iTRON\SafetyPasswords\Loggers\Stream;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_CLI;

class General {

	/**
	 * Period between asking to change password and actually hard resetting it. Seconds.
	 *
	 * @var int
	 */
	private static int $preInitInterval = 48 * HOUR_IN_SECONDS;

	/**
	 * @throws Exception
	 */
	public function init(): void {
		Settings::init();
		Controller::init();

		defined( 'WP_CLI' ) && WP_CLI && WP_CLI::add_command( 'safety', Safety::class );

		add_action( 'init', function () {
			$logger = $this->getLogger();
		}, 5 );
		add_action( 'itron/safety-passwords/activate', [ $this, 'processSecondPhaseActivation' ] );
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

	public function processSecondPhaseActivation(): void {
		Cron::ensureEvent( true );
	}

	public function processDeactivationHook(): void {
		Cron::stopEvent();
	}

	public function addStreamConnector( array $connectors ): array {
		$connectors[] = new StreamConnector();

		return $connectors;
	}

	private function getLogger(): LoggerInterface {
		$logger = apply_filters( 'itron/safety-passwords/logger', new NullLogger() );
		if ( ! is_a( $logger, LoggerInterface::class ) ) {
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
}
