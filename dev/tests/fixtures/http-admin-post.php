<?php

use iTRON\SafetyPasswords\Activation;

class SP_Http_Fixture_Failure extends RuntimeException {}

function sp_http_assert( $condition, $case ) {
	if ( ! $condition ) {
		throw new SP_Http_Fixture_Failure( 'HTTP admin-post fixture failed: ' . $case );
	}
}

function sp_http_endpoint_class( $path ) {
	$pathname = explode( '?', $path, 2 )[0];
	switch ( $pathname ) {
		case '/wp-login.php':
			return 'login';
		case '/wp-admin/profile.php':
			return 'profile';
		case '/wp-admin/admin.php':
		case '/wp-admin/network/admin.php':
			return 'settings';
		case '/wp-admin/admin-post.php':
			return 'admin-post';
	}
	return 'other';
}

function sp_http_response_code( $response ) {
	return $response['status'];
}

function sp_http_response_location( $response ) {
	return $response['location'];
}

function sp_http_response_body( $response ) {
	return $response['body'];
}

function sp_http_request( $base, $path, $method, $body, &$cookies ) {
	sp_http_assert( 1 === preg_match( '/^http:\/\/127\.0\.0\.1:[0-9]+$/', $base ) && 0 === strpos( $path, '/' ), 'loopback target' );
	sp_http_assert( 'GET' === $method || 'POST' === $method, 'request method' );
	$headers = [ 'Host: integration.invalid', 'Connection: close' ];
	if ( $cookies ) {
		$headers[] = 'Cookie: ' . implode( '; ', $cookies );
	}
	$content = '';
	if ( 'POST' === $method ) {
		sp_http_assert( is_array( $body ), 'request body' );
		$content = http_build_query( $body, '', '&', PHP_QUERY_RFC1738 );
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		$headers[] = 'Content-Length: ' . strlen( $content );
	}
	$options = [
		'method'           => $method,
		'header'           => implode( "\r\n", $headers ),
		'protocol_version' => 1.1,
		'follow_location'  => 0,
		'ignore_errors'    => true,
		'timeout'          => 15,
	];
	if ( 'POST' === $method ) {
		$options['content'] = $content;
	}
	$context = stream_context_create( [ 'http' => $options ] );
	$response_body = @file_get_contents( $base . $path, false, $context );
	if ( false === $response_body || ! isset( $http_response_header ) || ! is_array( $http_response_header ) ) {
		sp_http_assert( false, 'loopback request (' . sp_http_endpoint_class( $path ) . '; transport)' );
	}
	$status = 0;
	$location = false;
	foreach ( $http_response_header as $line ) {
		if ( preg_match( '/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $line, $match ) ) {
			$status = (int) $match[1];
			$location = false;
			continue;
		}
		if ( 0 === stripos( $line, 'Location:' ) ) {
			$location = trim( substr( $line, strlen( 'Location:' ) ) );
			continue;
		}
		if ( preg_match( '/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/i', $line, $match ) ) {
			$cookies[ $match[1] ] = $match[1] . '=' . $match[2];
		}
	}
	sp_http_assert( $status > 0, 'loopback response status' );
	return [ 'status' => $status, 'location' => $location, 'body' => $response_body ];
}

function sp_http_login( $base, $login, $password ) {
	$cookies = [];
	$response = sp_http_request( $base, '/wp-login.php', 'GET', null, $cookies );
	$status = sp_http_response_code( $response );
	$has_test_cookie = isset( $cookies[ TEST_COOKIE ] );
	if ( 200 !== $status || ! $has_test_cookie ) {
		sp_http_assert( false, 'login form (HTTP ' . (int) $status
			. '; test cookie ' . ( $has_test_cookie ? 'yes' : 'no' )
			. '; redirect ' . sp_http_redirect_class( $response )
			. '; response ' . sp_http_response_class( $response ) . ')' );
	}
	$response = sp_http_request( $base, '/wp-login.php', 'POST', [
		'log'        => $login,
		'pwd'        => $password,
		'testcookie' => '1',
		'wp-submit'  => 'Log In',
	], $cookies );
	sp_http_assert( 302 === sp_http_response_code( $response ), 'login response' );
	$logged_in = isset( $cookies[ LOGGED_IN_COOKIE ] );
	$admin_auth = isset( $cookies[ AUTH_COOKIE ] ) || isset( $cookies[ SECURE_AUTH_COOKIE ] );
	sp_http_assert( $logged_in && $admin_auth, 'login cookie names (logged-in ' . ( $logged_in ? 'yes' : 'no' ) . '; admin-auth ' . ( $admin_auth ? 'yes' : 'no' ) . ')' );
	return $cookies;
}

function sp_http_response_class( $response ) {
	$body = sp_http_response_body( $response );
	if ( false !== strpos( $body, 'id="loginform"' ) ) {
		return 'login-form';
	}
	if ( false !== strpos( $body, 'Sorry, you are not allowed to access this page.' ) ) {
		return 'admin-page-access-denied';
	}
	if ( false !== strpos( $body, 'Invalid plugin page.' ) || false !== strpos( $body, 'Cannot load ' ) ) {
		return 'plugin-page-missing';
	}
	return 'other';
}

function sp_http_redirect_class( $response ) {
	$location = sp_http_response_location( $response );
	$redirect_path = is_string( $location ) ? wp_parse_url( $location, PHP_URL_PATH ) : false;
	if ( '/wp-login.php' === $redirect_path ) {
		return 'login';
	}
	if ( is_string( $redirect_path ) && 0 === strpos( $redirect_path, '/wp-admin/network/' ) ) {
		return 'network';
	}
	if ( is_string( $redirect_path ) && 0 === strpos( $redirect_path, '/wp-admin/' ) ) {
		return 'admin';
	}
	return 'other';
}

function sp_http_nonce_from_settings( $base, $path, &$cookies ) {
	$response = sp_http_request( $base, $path, 'GET', null, $cookies );
	$status = sp_http_response_code( $response );
	if ( 200 !== $status ) {
		sp_http_assert( false, 'settings page (HTTP ' . (int) $status . '; redirect ' . sp_http_redirect_class( $response ) . '; response ' . sp_http_response_class( $response ) . ')' );
	}
	$html = sp_http_response_body( $response );
	sp_http_assert( 1 === preg_match( '/<form\b[^>]*id="safety-passwords-initialize-form"[^>]*>(.*?)<\/form>/s', $html, $form ), 'repair form on settings page' );
	sp_http_assert( false === strpos( $html, 'Initialization:' ) && false === strpos( $html, 'Schedule:' ), 'repair status hidden on healthy settings page' );
	sp_http_assert( 1 === preg_match( '/name="_wpnonce"\s+value="([^"]+)"/', $form[1], $match ), 'repair nonce on settings page' );
	return $match[1];
}

function sp_http_create_user( $role ) {
	$login = 'sp-http-' . strtolower( wp_generate_password( 16, false, false ) );
	$password = wp_generate_password( 40, true, true );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	sp_http_assert( is_int( $id ) && $id > 0, 'synthetic user creation' );
	$user = new WP_User( $id );
	$user->set_role( $role );
	return [ $id, $login, $password ];
}

sp_http_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
sp_http_assert( parse_url( home_url(), PHP_URL_HOST ) === 'integration.invalid', 'site host' );
sp_http_assert( ! is_multisite() || get_current_blog_id() === (int) get_network()->site_id, 'network main site' );
sp_http_assert( class_exists( Activation::class ), 'plugin unavailable' );
$stage = $args[0] ?? '';
sp_http_assert( ( 'single' === $stage && ! is_multisite() ) || ( 'network' === $stage && is_multisite() ), 'stage' );

$socket = stream_socket_server( 'tcp://127.0.0.1:0', $socket_error, $socket_message );
sp_http_assert( false !== $socket, 'loopback socket' );
$address = stream_socket_get_name( $socket, false );
fclose( $socket );
$port = (int) substr( $address, strrpos( $address, ':' ) + 1 );
sp_http_assert( $port > 0, 'loopback port' );
$base = 'http://127.0.0.1:' . $port;
$server = proc_open( [ PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', ABSPATH ], [
	0 => [ 'file', '/dev/null', 'r' ],
	1 => [ 'file', '/dev/null', 'w' ],
	2 => [ 'file', '/dev/null', 'w' ],
], $pipes );
sp_http_assert( is_resource( $server ), 'loopback server start' );
$user_ids = [];
$super_admin_id = 0;
$lock_added = false;
$cleanup_failed = false;
$failure = null;
try {
	$ready = false;
	for ( $attempt = 0; $attempt < 40; ++$attempt ) {
		$status = proc_get_status( $server );
		sp_http_assert( $status['running'], 'loopback server exited' );
		$connection = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $socket_error, $socket_message, 0.1 );
		if ( false !== $connection ) {
			fclose( $connection );
			$ready = true;
			break;
		}
		usleep( 50000 );
	}
	sp_http_assert( $ready, 'loopback server readiness' );

	$admin = sp_http_create_user( 'administrator' );
	$user_ids[] = $admin[0];
	if ( 'network' === $stage ) {
		sp_http_assert( grant_super_admin( $admin[0] ), 'network administrator grant' );
		$super_admin_id = $admin[0];
	}
	$manage_options = user_can( $admin[0], 'manage_options' );
	$plugin_capability = user_can( $admin[0], 'safety_passwords_manage_options' );
	sp_http_assert( $manage_options && $plugin_capability, 'synthetic administrator capabilities (manage_options ' . ( $manage_options ? 'yes' : 'no' ) . '; plugin ' . ( $plugin_capability ? 'yes' : 'no' ) . ')' );
	$cookies = sp_http_login( $base, $admin[1], $admin[2] );
	unset( $admin[2] );
	$settings_path = 'network' === $stage
		? '/wp-admin/network/admin.php?page=crb_carbon_fields_container_safety_passwords.php'
		: '/wp-admin/admin.php?page=crb_carbon_fields_container_safety_passwords.php';
	$nonce = sp_http_nonce_from_settings( $base, $settings_path, $cookies );
	$action_path = '/wp-admin/admin-post.php';

	$response = sp_http_request( $base, $action_path, 'POST', [ 'action' => 'safety_passwords_initialize', '_wpnonce' => 'invalid' ], $cookies );
	sp_http_assert( 403 === sp_http_response_code( $response ) && ! sp_http_response_location( $response ), 'invalid nonce rejected' );
	$response = sp_http_request( $base, $action_path . '?action=safety_passwords_initialize', 'GET', null, $cookies );
	sp_http_assert( 405 === sp_http_response_code( $response ) && ! sp_http_response_location( $response ), 'GET rejected' );

	$unauthorized = sp_http_create_user( 'network' === $stage ? 'administrator' : 'subscriber' );
	$user_ids[] = $unauthorized[0];
	sp_http_assert( 'network' === $stage
		? user_can( $unauthorized[0], 'manage_options' ) && ! user_can( $unauthorized[0], 'manage_network_options' )
		: ! user_can( $unauthorized[0], 'manage_options' ), 'unauthorized role permissions' );
	$denied_cookies = sp_http_login( $base, $unauthorized[1], $unauthorized[2] );
	unset( $unauthorized[2] );
	$response = sp_http_request( $base, $settings_path, 'GET', null, $denied_cookies );
	sp_http_assert( false === strpos( sp_http_response_body( $response ), 'safety-passwords-initialize-form' ), 'unauthorized role cannot see repair form' );
	$response = sp_http_request( $base, $action_path, 'POST', [ 'action' => 'safety_passwords_initialize', '_wpnonce' => $nonce ], $denied_cookies );
	sp_http_assert( 403 === sp_http_response_code( $response ) && ! sp_http_response_location( $response ), 'authenticated unauthorized role rejected' );

	sp_http_assert( add_option( 'safety_passwords_mu_initializing', ( time() + 900 ) . ':fixture', '', false ), 'held lease setup' );
	$lock_added = true;
	$response = sp_http_request( $base, $action_path, 'POST', [ 'action' => 'safety_passwords_initialize', '_wpnonce' => $nonce ], $cookies );
	sp_http_assert( 302 === sp_http_response_code( $response ), 'held lease response' );
	$location = sp_http_response_location( $response );
	$expected_base = 'network' === $stage ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	sp_http_assert( is_string( $location ) && 0 === strpos( $location, $expected_base ), 'held lease redirect base' );
	sp_http_assert( false !== strpos( $location, 'page=crb_carbon_fields_container_safety_passwords.php' ) && false !== strpos( $location, 'safety_passwords_init=failed' ), 'held lease failure redirect' );
	delete_option( 'safety_passwords_mu_initializing' );
	$lock_added = false;

	$response = sp_http_request( $base, $action_path, 'POST', [ 'action' => 'safety_passwords_initialize', '_wpnonce' => $nonce ], $cookies );
	sp_http_assert( 302 === sp_http_response_code( $response ), 'authorized repair response' );
	$location = sp_http_response_location( $response );
	sp_http_assert( is_string( $location ) && 0 === strpos( $location, $expected_base ) && false !== strpos( $location, 'page=crb_carbon_fields_container_safety_passwords.php' ) && false !== strpos( $location, 'safety_passwords_init=success' ), 'authorized repair redirect' );
	wp_cache_flush();
	sp_http_assert( Activation::initializationStatus()['state'] === 'ready', 'authorized repair state' );
} catch ( Throwable $error ) {
	$failure = $error instanceof SP_Http_Fixture_Failure ? $error->getMessage() : 'HTTP admin-post fixture failed: unexpected runtime error';
} finally {
	try {
		if ( $lock_added ) {
			delete_option( 'safety_passwords_mu_initializing' );
		}
		if ( $super_admin_id ) {
			$cleanup_failed = ! revoke_super_admin( $super_admin_id ) || $cleanup_failed;
		}
		if ( is_multisite() && ! function_exists( 'wpmu_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}
		if ( ! is_multisite() && ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $user_ids as $id ) {
			$removed = is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
			$cleanup_failed = ! $removed || $cleanup_failed;
		}
	} catch ( Throwable $error ) {
		$cleanup_failed = true;
	}
	proc_terminate( $server );
	proc_close( $server );
}
if ( null !== $failure || $cleanup_failed ) {
	fwrite( STDERR, ( null !== $failure ? $failure : 'HTTP admin-post fixture failed: cleanup' ) . "\n" );
	exit( 1 );
}
echo "PASS: loopback HTTP admin-post ($stage)\n";
