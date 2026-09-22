<?php

// Loaded by WordPress before pluggable.php, on every integration request.
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		$GLOBALS['safety_passwords_test_mail_attempts'] = ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) + 1;
		return false;
	}
}
