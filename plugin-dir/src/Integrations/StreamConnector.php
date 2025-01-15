<?php
/**
 * Logger integration for the Stream plugin.
 * @see https://wordpress.org/plugins/stream/
 * @see https://github.com/xwp/stream/wiki/Creating-a-Custom-Connector
 *
 * @package iTRON\SafetyPasswords\Integrations
 */

namespace iTRON\SafetyPasswords\Integrations;

use Psr\Log\LogLevel;
use WP_Stream\Connector;
use WP_Stream\Record;

class StreamConnector extends Connector {
	/**
	 * WP Stream Connector slug.
	 *
	 * @var string
	 */
	public $name = 'ctm-logger';

	/**
	 * Actions registered for this connector.
	 *
	 * These are actions that this connector has created, we are defining them here to
	 * tell Stream to run a callback each time this action is fired, so we can
	 * log information about what happened.
	 *
	 * @var array
	 */
	public $actions = [
		'safety_passwords_stream_logger_write',
	];

	/**
	 * Return translated connector label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Safety Passwords';
	}

	/**
	 * Return translated context labels
	 *
	 * @return array
	 */
	public function get_context_labels(): array {
		/* @TODO Create logic for adding list of used labels (modules). */
		return apply_filters(
			'itron/safety-passwords/wp_stream_connector/get_context_labels',
			[
				'general' => 'General',
			]
		);
	}

	/**
	 * Return translated action labels
	 *
	 * @return array
	 */
	public function get_action_labels(): array {
		return [
			LogLevel::ALERT     => ucfirst( LogLevel::ALERT ),
			LogLevel::CRITICAL  => ucfirst( LogLevel::CRITICAL ),
			LogLevel::DEBUG     => ucfirst( LogLevel::DEBUG ),
			LogLevel::EMERGENCY => ucfirst( LogLevel::EMERGENCY ),
			LogLevel::ERROR     => ucfirst( LogLevel::ERROR ),
			LogLevel::INFO      => ucfirst( LogLevel::INFO ),
			LogLevel::NOTICE    => ucfirst( LogLevel::NOTICE ),
			LogLevel::WARNING   => ucfirst( LogLevel::WARNING ),
		];
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * This method is optional.
	 *
	 * @param array  $links  Previous links registered.
	 * @param Record $record Stream record.
	 *
	 * @return array Action links.
	 */
	public function action_links( $links, $record ) {
		return $links;
	}

	/**
	 * Callback write function.
	 *
	 * @param string $level Level of record.
	 * @param string $message General message.
	 * @param array $data Additional data.
	 * @param string $module The module that caused the record.
	 *
	 * @return void
	 */
	public function callback_safety_passwords_stream_logger_write( $level, $message, $data, $module ) {
		// Getting $message sprintf-ready error message string.
		$message = str_replace('%', '%%', $message );

		$res = $this->log( $message, $data, 0, $module, $level );
	}
}