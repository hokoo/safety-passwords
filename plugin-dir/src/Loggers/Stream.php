<?php
/**
 * Logger integration for the Stream plugin.
 * @see https://wordpress.org/plugins/stream/
 * @see https://github.com/xwp/stream/wiki/Creating-a-Custom-Connector
 *
 * @package iTRON\SafetyPasswords\Loggers
 */

namespace iTRON\SafetyPasswords\Loggers;

use Psr\Log\AbstractLogger;

class Stream extends AbstractLogger {

	public function log( $level, $message, array $context = [], string $module = 'general' ) : void {
		$calling = function () use ( $level, $message, $context, $module ) {
			$meta = [];
			if ( ! empty( $context ) ) {
				$meta = array_map( function ( $value ) {
					return is_scalar( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
				}, $context );
			}
			// Notice for reviewers: this hook has to be exactly like this, because of integration with the Stream plugin.
			do_action( 'safety_passwords_stream_logger_write', $level, $message, $meta, $module );
		};

		if ( ! did_action( 'wp_stream_after_connectors_registration' ) ) {
			add_action(
				'wp_stream_after_connectors_registration',
				$calling
			);

			return;
		};

		$calling();
	}
}
