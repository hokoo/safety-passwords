<?php

use iTRON\SafetyPasswords\Activation;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_controls_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: lifecycle controls $label\n" );
		exit( 1 );
	}
}

sp_controls_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration' && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe target' );
sp_controls_assert( class_exists( Activation::class ) && did_action( 'carbon_fields_fields_registered' ) > 0, 'plugin unavailable' );
$stage = $args[0] ?? '';
$lock = 'safety_passwords_mu_initializing';
$version = 'safety_passwords_initialized_version';
$failure_key = 'safety_passwords_initialization_failure';

if ( 'cli-lock' === $stage ) {
	sp_controls_assert( add_option( $lock, ( time() + 900 ) . ':fixture', '', false ), 'lock setup' );
	return;
}
if ( 'cli-unlock' === $stage ) {
	delete_option( $lock );
	return;
}
if ( 'cli-verify' === $stage ) {
	sp_controls_assert( get_option( $version ) === \iTRON\SafetyPasswords\VERSION && Cron::isNormalized(), 'CLI completion' );
	return;
}
if ( 'network-capture' === $stage ) {
	sp_controls_assert( is_multisite() && get_current_blog_id() === (int) get_network()->site_id, 'network context' );
	update_option( 'safety_passwords_integration_controls_snapshot', [ get_option( $version ), get_option( 'cron' ) ] );
	return;
}
if ( 'network-verify' === $stage ) {
	sp_controls_assert( [ get_option( $version ), get_option( 'cron' ) ] === get_option( 'safety_passwords_integration_controls_snapshot' ), 'other network changed' );
	delete_option( 'safety_passwords_integration_controls_snapshot' );
	return;
}
if ( 'status' === $stage ) {
	sp_controls_assert( get_option( $version ) === \iTRON\SafetyPasswords\VERSION && Activation::scheduleHealth() === 'healthy', 'status fixture requires ready initialization' );
	$original_user = get_current_user_id();
	$_GET['page'] = 'crb_carbon_fields_container_safety_passwords.php';
	ob_start();
	Settings::renderInitializationStatus();
	$ready_notice = ob_get_clean();
	sp_controls_assert( Activation::initializationStatus()['state'] === 'ready' && false !== strpos( $ready_notice, 'Ready.' ) && false !== strpos( $ready_notice, 'Healthy.' ), 'ready status or schedule UI' );
	wp_set_current_user( 0 );
	ob_start();
	Settings::renderInitializationStatus();
	Settings::renderInitializeForm();
	sp_controls_assert( '' === ob_get_clean(), 'unauthorized status or repair form visible' );
	wp_set_current_user( $original_user );

	wp_unschedule_hook( Cron::EVENT_NAME );
	sp_controls_assert( Activation::initializationStatus()['state'] === 'ready' && Activation::scheduleHealth() === 'degraded', 'schedule health incorrectly changed initialization status' );
	delete_option( $version );
	sp_controls_assert( Activation::initializationStatus()['state'] === 'pending', 'pending status' );
	ob_start();
	Settings::renderInitializationStatus();
	sp_controls_assert( false !== strpos( ob_get_clean(), 'Pending.' ), 'pending status UI' );
	sp_controls_assert( add_option( $lock, ( time() + 900 ) . ':fixture', '', false ), 'running lock setup' );
	sp_controls_assert( Activation::initializationStatus()['state'] === 'running', 'running status' );
	ob_start();
	Settings::renderInitializationStatus();
	sp_controls_assert( false !== strpos( ob_get_clean(), 'Running.' ), 'running status UI' );
	delete_option( $lock );

	$frozen_cron = get_option( 'cron' );
	$block_cron = function () use ( $frozen_cron ) { return $frozen_cron; };
	add_filter( 'pre_update_option_cron', $block_cron );
	$traversals = 0;
	$count_traversal = function () use ( &$traversals ) { ++$traversals; };
	add_action( 'itron/safety-passwords/capabilities/set', $count_traversal );
	sp_controls_assert( ! Activation::initialize() && $traversals === 1, 'initial failure was not attempted once' );
	$status = Activation::initializationStatus();
	$failure = get_option( $failure_key );
	sp_controls_assert( $status['state'] === 'retryable_error' && $status['category'] === 'schedule' && $status['next_retry'] >= time() + 290 && $status['next_retry'] <= time() + 300, 'retryable status or first cooldown' );
	sp_controls_assert( is_array( $failure ) && array_keys( $failure ) === [ 'category', 'attempts', 'version', 'next_retry' ] && $failure['category'] === 'schedule' && $failure['attempts'] === 1 && $failure['version'] === \iTRON\SafetyPasswords\VERSION, 'failure record contains unexpected fields' );
	global $wpdb;
	$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $failure_key ) );
	sp_controls_assert( ! in_array( $autoload, [ 'yes', 'on', 'auto-on' ], true ), 'failure record autoloaded' );
	ob_start();
	Settings::renderInitializationStatus();
	$error_notice = ob_get_clean();
	sp_controls_assert( false !== strpos( $error_notice, 'Retryable error.' ) && false !== strpos( $error_notice, 'Schedule repair failed.' ) && false !== strpos( $error_notice, 'Next automatic retry:' ) && false === strpos( $error_notice, ':fixture' ), 'unsafe or incomplete failure UI' );
	$tainted = $failure;
	$tainted['category'] = 'untrusted-category';
	update_option( $failure_key, $tainted, false );
	ob_start();
	Settings::renderInitializationStatus();
	$sanitized_notice = ob_get_clean();
	sp_controls_assert( false !== strpos( $sanitized_notice, 'Initialization failed.' ) && false === strpos( $sanitized_notice, 'untrusted-category' ), 'untrusted failure category reached UI' );
	update_option( $failure_key, $failure, false );
	if ( false !== has_action( 'wp_loaded', [ Activation::class, 'bootstrapMustUse' ] ) ) {
		Activation::bootstrapMustUse();
	} else {
		Activation::bootstrapOrdinary();
	}
	sp_controls_assert( $traversals === 1 && get_option( $failure_key ) === $failure, 'automatic cooldown allowed heavy retry' );
	sp_controls_assert( add_option( $lock, ( time() + 900 ) . ':fixture', '', false ), 'manual lock setup' );
	sp_controls_assert( ! Activation::initialize( true ) && $traversals === 1 && get_option( $failure_key ) === $failure, 'manual repair ignored active lease' );
	delete_option( $lock );
	$failure['attempts'] = 4;
	$failure['next_retry'] = time() - 1;
	update_option( $failure_key, $failure, false );
	if ( false !== has_action( 'wp_loaded', [ Activation::class, 'bootstrapMustUse' ] ) ) {
		Activation::bootstrapMustUse();
	} else {
		Activation::bootstrapOrdinary();
	}
	$capped = get_option( $failure_key );
	sp_controls_assert( $traversals === 2 && is_array( $capped ) && $capped['attempts'] === 5 && $capped['next_retry'] >= time() + 3590 && $capped['next_retry'] <= time() + 3600, 'due automatic retry or one-hour cap' );
	remove_filter( 'pre_update_option_cron', $block_cron );
	sp_controls_assert( Activation::initialize( true ) && $traversals === 3 && Activation::initializationStatus()['state'] === 'ready' && ! get_option( $failure_key ), 'manual retry did not bypass cooldown or clear failure' );
	remove_action( 'itron/safety-passwords/capabilities/set', $count_traversal );
	sp_controls_assert( Activation::scheduleHealth() === 'healthy', 'schedule did not recover' );
	update_option( $failure_key, [ 'category' => 'general', 'attempts' => 5, 'version' => 'previous-version', 'next_retry' => time() + 3600 ], false );
	sp_controls_assert( Activation::initializationStatus()['state'] === 'ready' && false === get_option( $failure_key ), 'version change retained old failure' );
	echo "PASS: lifecycle initialization status\n";
	return;
}

sp_controls_assert( 'admin' === $stage, 'unknown stage' );
sp_controls_assert( false !== has_action( 'admin_post_safety_passwords_initialize', [ Settings::class, 'processInitialize' ] ), 'handler not registered' );
sp_controls_assert( false === has_action( 'toplevel_page_crb_carbon_fields_container_safety_passwords.php', [ Cron::class, 'ensureEvent' ] ), 'settings page registers unleased schedule repair' );
sp_controls_assert( false !== has_action( 'wp_loaded', [ Activation::class, 'bootstrapMustUse' ] ) || false !== has_action( 'wp_loaded', [ Activation::class, 'bootstrapOrdinary' ] ), 'scheduled health bootstrap not registered' );
$original_user = get_current_user_id();
sp_controls_assert( $original_user > 0, 'admin user missing' );
$_GET['page'] = 'crb_carbon_fields_container_safety_passwords.php';
ob_start();
Settings::renderInitializeForm();
$form = ob_get_clean();
sp_controls_assert( false !== strpos( $form, '<form id="safety-passwords-initialize-form" method="post"' ) && false !== strpos( $form, 'admin-post.php' ), 'separate POST form missing' );
$die_handler = function () { return function () { throw new RuntimeException( 'request rejected' ); }; };
add_filter( 'wp_die_handler', $die_handler );
$redirect_handler = function ( $location ) { throw new RuntimeException( $location ); };
add_filter( 'wp_redirect', $redirect_handler );
$old_method = $_SERVER['REQUEST_METHOD'] ?? null;
$old_post = $_POST;
$cron_before = get_option( 'cron' );
$version_before = get_option( $version );
function sp_controls_request( $method, $user_id, $valid_nonce, $expected ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	wp_set_current_user( $user_id );
	$_POST = [ '_wpnonce' => $valid_nonce ? wp_create_nonce( 'safety_passwords_initialize' ) : 'invalid' ];
	try {
		Settings::processInitialize();
		sp_controls_assert( false, 'handler returned without redirect or rejection' );
	} catch ( RuntimeException $error ) {
		$location = $error->getMessage();
		if ( 'rejected' === $expected ) {
			sp_controls_assert( 'request rejected' === $location, 'request was not rejected' );
		} else {
			sp_controls_assert( false !== strpos( $location, 'page=crb_carbon_fields_container_safety_passwords.php' ) && false !== strpos( $location, 'safety_passwords_init=' . $expected ), 'wrong fixed redirect' );
		}
	}
}

sp_controls_request( 'GET', $original_user, true, 'rejected' );
sp_controls_request( 'POST', 0, true, 'rejected' );
sp_controls_request( 'POST', $original_user, false, 'rejected' );
if ( is_multisite() ) {
	$site_admin_login = 'sp-controls-' . strtolower( wp_generate_password( 12, false, false ) );
	$site_admin_password = wp_generate_password( 32, true, false );
	$site_admin_id = wp_create_user( $site_admin_login, $site_admin_password, $site_admin_login . '@example.invalid' );
	unset( $site_admin_password );
	sp_controls_assert( is_int( $site_admin_id ) && $site_admin_id > 0, 'site administrator creation' );
	sp_controls_assert( ! is_wp_error( add_user_to_blog( get_current_blog_id(), $site_admin_id, 'administrator' ) ), 'site administrator role assignment' );
	wp_set_current_user( $site_admin_id );
	sp_controls_assert( current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ), 'site administrator permissions' );
	sp_controls_request( 'POST', $site_admin_id, true, 'rejected' );
	wp_set_current_user( $original_user );
	if ( ! function_exists( 'wpmu_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	sp_controls_assert( wpmu_delete_user( $site_admin_id ), 'site administrator cleanup' );
}
sp_controls_assert( get_option( 'cron' ) === $cron_before && get_option( $version ) === $version_before, 'denied request changed lifecycle state' );

sp_controls_assert( add_option( $lock, ( time() + 900 ) . ':fixture', '', false ), 'held lock setup' );
sp_controls_request( 'POST', $original_user, true, 'failed' );
delete_option( $lock );
wp_schedule_event( time() + 900, 'twicedaily', Cron::EVENT_NAME, [ 'control-fixture' ] );
sp_controls_assert( ! Cron::isNormalized(), 'repair fixture did not disturb schedule' );
sp_controls_request( 'POST', $original_user, true, 'success' );
sp_controls_assert( Cron::isNormalized() && get_option( $version ) === \iTRON\SafetyPasswords\VERSION, 'authorized repair did not complete' );
$scheduled = wp_next_scheduled( Cron::EVENT_NAME );
sp_controls_request( 'POST', $original_user, true, 'success' );
sp_controls_assert( $scheduled === wp_next_scheduled( Cron::EVENT_NAME ), 'repeat repair changed schedule' );

remove_filter( 'wp_redirect', $redirect_handler );
remove_filter( 'wp_die_handler', $die_handler );
wp_set_current_user( $original_user );
$_POST = $old_post;
if ( null === $old_method ) {
	unset( $_SERVER['REQUEST_METHOD'] );
} else {
	$_SERVER['REQUEST_METHOD'] = $old_method;
}
echo "PASS: lifecycle admin controls ($stage)\n";
