<?php

use Carbon_Fields\Carbon_Fields;
use iTRON\SafetyPasswords\Activation;
use iTRON\SafetyPasswords\Controller;
use iTRON\SafetyPasswords\Cron;
use iTRON\SafetyPasswords\Settings;

function sp_network_assert( $condition, $label ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: network policy $label\n" );
		exit( 1 );
	}
}

function sp_network_events() {
	$count = 0;
	foreach ( (array) _get_cron_array() as $events ) {
		if ( isset( $events[ Cron::EVENT_NAME ] ) ) {
			$count += count( $events[ Cron::EVENT_NAME ] );
		}
	}
	return $count;
}

function sp_network_user( $suffix ) {
	$login = 'sp-network-' . strtolower( wp_generate_password( 12, false, false ) );
	$password = wp_generate_password( 32, true, false );
	$id = wp_create_user( $login, $password, $login . '@example.invalid' );
	unset( $password );
	sp_network_assert( is_int( $id ) && $id > 0, "synthetic $suffix account creation" );
	return $id;
}

function sp_network_history_count( $id ) {
	$history = get_user_meta( $id, Controller::USER_STOP_LIST_META_KEY, true );
	return is_array( $history ) ? count( $history ) : 0;
}

sp_network_assert( DB_HOST === 'db' && DB_NAME === 'safety_passwords_integration', 'unsafe database target' );
sp_network_assert( is_multisite() && get_option( 'safety_passwords_integration_target' ) === 'isolated', 'unsafe network target' );
sp_network_assert( get_current_blog_id() === (int) get_network()->site_id, 'main site context missing' );
$stage = $args[0] ?? '';
sp_network_assert( in_array( $stage, [ 'active', 'inactive' ], true ), 'unknown stage' );
$sites = array_map( 'intval', get_sites( [ 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 0 ] ) );
$main = (int) get_network()->site_id;
$subsites = array_values( array_diff( $sites, [ $main ] ) );
sp_network_assert( count( $subsites ) >= 1, 'subsite missing' );
$subsite = $subsites[0];

if ( $stage === 'inactive' ) {
	sp_network_assert( ! wp_next_scheduled( 'safety_passwords_periodically_reset' ), 'deactivation retained main event' );
	foreach ( $subsites as $site_id ) {
		switch_to_blog( $site_id );
		sp_network_assert( ! wp_next_scheduled( 'safety_passwords_periodically_reset' ), 'deactivation retained subsite event' );
		restore_current_blog();
	}
	echo "PASS: network deactivation removed main and subsite events\n";
	return;
}

sp_network_assert( class_exists( Activation::class ) && class_exists( Controller::class ), 'network plugin unavailable' );
sp_network_assert( sp_network_events() === 0, 'periodic event started before deferred phase' );
sp_network_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'main administrator capability missing' );
switch_to_blog( $subsite );
sp_network_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'existing subsite administrator capability missing' );
restore_current_blog();

$admin_id = get_current_user_id();
$site_admin = sp_network_user( 'site admin' );
add_user_to_blog( $main, $site_admin, 'administrator' );
sp_network_assert( ! user_can( $site_admin, 'manage_network_options' ), 'site admin unexpectedly manages network' );
$unassigned = sp_network_user( 'unassigned' );
remove_user_from_blog( $unassigned, $main );
$sub_user = sp_network_user( 'subsite' );
remove_user_from_blog( $sub_user, $main );
add_user_to_blog( $subsite, $sub_user, 'subscriber' );
sp_network_assert( get_blogs_of_user( $unassigned ) === [], 'account was not left unassigned' );
$sub_memberships = get_blogs_of_user( $sub_user );
sp_network_assert( isset( $sub_memberships[ $subsite ] ) && ! isset( $sub_memberships[ $main ] ), 'subsite account membership incorrect' );
$network_ids = array_map( 'intval', get_users( [ 'fields' => 'ids', 'blog_id' => 0 ] ) );
sp_network_assert( in_array( $unassigned, $network_ids, true ) && in_array( $sub_user, $network_ids, true ), 'all-account query omitted test accounts' );

do_action( 'itron/safety-passwords/activate' );
wp_clear_scheduled_hook( 'itron/safety-passwords/activate' );
sp_network_assert( sp_network_events() === 1 && wp_get_schedule( Cron::EVENT_NAME ) === 'twicedaily', 'deferred phase did not schedule main event' );
sp_network_assert( sp_network_history_count( $unassigned ) === 1 && sp_network_history_count( $sub_user ) === 1, 'deferred history omitted network account' );
switch_to_blog( $subsite );
Activation::processActivationHook();
sp_network_assert( get_current_blog_id() === $subsite && ! wp_next_scheduled( 'itron/safety-passwords/activate' ), 'activation from subsite changed context or left deferred event there' );
restore_current_blog();
sp_network_assert( wp_next_scheduled( 'itron/safety-passwords/activate' ), 'activation from subsite did not route deferred event to main' );
wp_clear_scheduled_hook( 'itron/safety-passwords/activate' );

$new_site = wpmu_create_blog( get_network()->domain, '/new/', 'New', $admin_id, [ 'public' => 0 ], get_current_network_id() );
sp_network_assert( is_int( $new_site ) && $new_site > 0, 'new site creation failed' );
switch_to_blog( $new_site );
sp_network_assert( get_role( 'administrator' )->has_cap( Settings::MANAGE_CAPS ), 'new site administrator capability missing' );
sp_network_assert( sp_network_events() === 0, 'new site received periodic event' );
restore_current_blog();

$prefix = Settings::$optionPrefix;
carbon_set_network_option( get_current_network_id(), $prefix . 'reset_interval', 1 );
carbon_set_network_option( get_current_network_id(), $prefix . 'rp_on_registration', false );
// Carbon's theme getter is unavailable without a theme container on multisite;
// these are the same persisted option keys from an earlier site-scoped install.
update_option( '_' . $prefix . 'reset_interval', 0 );
update_option( '_' . $prefix . 'rp_on_registration', 'yes' );
sp_network_assert( (int) get_option( '_' . $prefix . 'reset_interval' ) === 0, 'main site conflict value missing' );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
wp_cache_delete( 'rp_on_registration', 'safety-passwords' );
sp_network_assert( Settings::getInterval() === 1 && ! Settings::getOption( 'rp_on_registration' ), 'main policy did not use network container' );
switch_to_blog( $subsite );
update_option( '_' . $prefix . 'reset_interval', 9 );
update_option( '_' . $prefix . 'rp_on_registration', 'yes' );
sp_network_assert( (int) get_option( '_' . $prefix . 'reset_interval' ) === 9, 'subsite conflict value missing' );
wp_cache_delete( 'reset_interval', 'safety-passwords' );
wp_cache_delete( 'rp_on_registration', 'safety-passwords' );
sp_network_assert( Settings::getInterval() === 1 && ! Settings::getOption( 'rp_on_registration' ), 'subsite policy did not use network container' );
restore_current_blog();
wp_schedule_event( time() + 1200, 'twicedaily', Cron::EVENT_NAME );
sp_network_assert( sp_network_events() === 2, 'duplicate main event not seeded' );

$repository = Carbon_Fields::resolve( 'container_repository' );
$containers = $repository->get_containers( 'network' );
$settings = null;
foreach ( $containers as $container ) {
	if ( $container->get_id() === 'carbon_fields_container_safety_passwords' ) {
		$settings = $container;
		break;
	}
}
sp_network_assert( $settings !== null, 'Carbon network settings container missing' );
sp_network_assert( $settings->is_valid_attach_for_object( null ), 'network admin denied settings or save condition' );
$request_method = $_SERVER['REQUEST_METHOD'] ?? null;
$request_post = $_POST;
$_SERVER['REQUEST_METHOD'] = 'POST';
$nonce_name = $settings->get_id() . '_nonce';
$_POST = [ $nonce_name => wp_create_nonce( $nonce_name ) ];
sp_network_assert( $settings->is_valid_save(), 'network admin denied Carbon save validation' );
wp_set_current_user( $site_admin );
sp_network_assert( ! $settings->is_valid_attach_for_object( null ), 'site admin passed settings save condition' );
sp_network_assert( ! $settings->is_valid_attach(), 'site admin passed settings menu condition' );
$_POST = [ $nonce_name => wp_create_nonce( $nonce_name ) ];
sp_network_assert( ! $settings->is_valid_save(), 'site admin passed Carbon save validation' );
$_POST = $request_post;
if ( $request_method === null ) {
	unset( $_SERVER['REQUEST_METHOD'] );
} else {
	$_SERVER['REQUEST_METHOD'] = $request_method;
}
wp_set_current_user( $admin_id );

switch_to_blog( $subsite );
wp_schedule_event( time() + 600, 'twicedaily', Cron::EVENT_NAME );
wp_schedule_event( time() + 900, 'twicedaily', Cron::EVENT_NAME );
sp_network_assert( sp_network_events() === 2, 'legacy subsite events not seeded' );
Cron::ensureEvent();
sp_network_assert( get_current_blog_id() === $subsite && sp_network_events() === 0, 'ensure left subsite events or changed context' );
restore_current_blog();
sp_network_assert( sp_network_events() === 1, 'ensure did not leave exactly one main event' );
$scheduled = wp_next_scheduled( Cron::EVENT_NAME );
switch_to_blog( $subsite );
Cron::ensureEvent();
restore_current_blog();
sp_network_assert( wp_next_scheduled( Cron::EVENT_NAME ) === $scheduled && sp_network_events() === 1, 'repeat ensure changed main event' );

update_user_meta( $admin_id, $prefix . 'last_reset', time() );
update_user_meta( $site_admin, $prefix . 'last_reset', time() );
update_user_meta( $unassigned, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
update_user_meta( $sub_user, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
$mail_before = $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0;
switch_to_blog( $subsite );
do_action( Cron::EVENT_NAME );
sp_network_assert( ! get_user_meta( $unassigned, $prefix . 'rp_inited', true ) && ! get_user_meta( $sub_user, $prefix . 'rp_inited', true ), 'subsite callback reset network accounts' );
sp_network_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before, 'subsite callback attempted mail' );
restore_current_blog();
do_action( Cron::EVENT_NAME );
sp_network_assert( get_user_meta( $unassigned, $prefix . 'rp_inited', true ) === '1', 'main callback omitted unassigned account' );
sp_network_assert( get_user_meta( $sub_user, $prefix . 'rp_inited', true ) === '1', 'main callback omitted subsite account' );
sp_network_assert( ( $GLOBALS['safety_passwords_test_mail_attempts'] ?? 0 ) === $mail_before + 2, 'main callback attempted unexpected mail count' );

$manual = sp_network_user( 'manual CLI' );
remove_user_from_blog( $manual, $main );
update_user_meta( $manual, $prefix . 'last_reset', time() - 2 * DAY_IN_SECONDS );
$reset = [];
$reminded = [];
switch_to_blog( $subsite );
Controller::checkUsers( $reset, $reminded );
sp_network_assert( in_array( $manual, array_map( 'intval', $reset ), true ), 'manual check omitted unassigned account' );
restore_current_blog();

switch_to_blog( $subsite );
Cron::stopEvent();
sp_network_assert( get_current_blog_id() === $subsite && sp_network_events() === 0, 'stop changed subsite context or retained event' );
restore_current_blog();
sp_network_assert( sp_network_events() === 0, 'stop retained main event' );
Cron::ensureEvent();
switch_to_blog( $subsite );
wp_schedule_event( time() + 600, 'twicedaily', Cron::EVENT_NAME );
Activation::processDeactivationHook();
sp_network_assert( get_current_blog_id() === $subsite && sp_network_events() === 0, 'deactivation from subsite changed context or retained event' );
restore_current_blog();
sp_network_assert( sp_network_events() === 0, 'deactivation from subsite retained main event' );
Cron::ensureEvent();
switch_to_blog( $subsite );
wp_schedule_event( time() + 600, 'twicedaily', Cron::EVENT_NAME );
restore_current_blog();
echo "PASS: network policy, accounts, capabilities, authorization, cron and context\n";
