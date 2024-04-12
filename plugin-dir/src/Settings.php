<?php

namespace iTRON\SafetyPasswords;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;

class Settings {
	/**
	 * Fields that are overloaded by the constants.
	 * @var array
	 */
	const OVERLOADED = [];
	const DEFAULT_INTERVAL = 'hourly';
	const INTERVALS = [
		'twicehourly' => [ 'display' => 'Twice Hourly', 'value' => MINUTE_IN_SECONDS * 30 ],
		'hourly'      => [ 'display' => 'Hourly', 'value' => HOUR_IN_SECONDS ],
		'3hours'      => [ 'display' => 'Every 3 Hours', 'value' => HOUR_IN_SECONDS * 3 ],
		'twicedaily'  => [ 'display' => 'Twice Daily', 'value' => HOUR_IN_SECONDS * 12 ],
		'daily'       => [ 'display' => 'Once Daily', 'value' => DAY_IN_SECONDS ],
	];
	const MANAGE_CAPS = 'safety_passwords_manage_options';

	public function init(): void {
		add_action( 'carbon_fields_register_fields', [ $this, 'createOptions' ] );
		add_action( 'after_setup_theme', [ $this, 'loadCarbon' ] );
//		add_filter( 'carbon_fields_should_delete_field_value_on_save', [ $this, 'handleUpdateInterval' ], 10, 2 );
//		add_action( 'wp_ajax_ctm_settings_force_update', [ $this, 'redirectForceUpdate' ], 20 );

		// Ensure the cron event is scheduled when visiting the plugin settings page.
//		add_action( 'toplevel_page_crb_carbon_fields_container_ct_menus', [ Cron::class, 'ensureEvent' ] );
	}

	/**
	 * Reschedule the cron event when the interval is changed.
	 *
	 * @param $should_delete
	 * @param $field
	 *
	 * @return mixed
	 */
	public function handleUpdateInterval( $should_delete, $field ) {
		if ( $field->get_name() !== '_ctm_update_interval' ) {
			return $should_delete;
		}

		if ( self::getInterval() != $field->get_value() ) {
			add_action( 'carbon_fields_theme_options_container_saved',
				function ( $user_data, $container ) {
					Cron::setIntervals();
					Cron::ensureEvent( true );
				},
				2,
				50 );
		}

		return $should_delete;
	}

	public function loadCarbon(): void {
		Carbon_Fields::boot();
	}

	public function createOptions(): void {

		Container::make( 'theme_options', 'Safety Passwords' )
					->add_fields( [
						Field::make( 'checkbox',
							'ctm_display_header',
							'Display Header' )
						     ->set_default_value( true )
		         ] )
		         ->set_icon( 'dashicons-editor-kitchensink' )
		         ->where( 'current_user_capability', 'IN', [ self::MANAGE_CAPS, 'manage_options' ] );
	}

	public static function getOption( string $options_slug ) {
		// Carbon Fields does not have a built-in caching mechanism, lol.
		$group = 'safety-passwords';
		$cache = wp_cache_get( $options_slug, $group );
		if ( false !== $cache ) {
			return $cache;
		}

		$value = carbon_get_theme_option( $options_slug );
		wp_cache_set( $options_slug, $value, $group );
		return $value;
	}

	/**
	 * @param string $context 'display' or 'value'
	 *
	 * @return array
	 */
	public static function getIntervals( string $context = 'display' ): array {
		return array_map(
			function ( $item ) use ( $context ) {
				return $item[ $context ];
			},
			self::INTERVALS );
	}

	public static function getInterval() {
		return self::getOption( 'ctm_update_interval' );
	}

	public static function getMenus(): array {
		$data = get_option( '_ctm_menu_data', [] );
		return empty( $data ) ? CTM_MENU_TYPES : $data;
	}

	public static function setMenus( array $menus ): void {
		update_option( '_ctm_menu_data', $menus['data']['locale'], false );
	}

	public static function getLastUpdate(): int {
		return get_option( 'ctm_last_update' );
	}

	public static function setLastUpdate( int $time ): void {
		update_option( 'ctm_last_update', $time, false );
	}

	public static function getLocale() {
		return self::getOption( 'ctm_locale' );
	}

	private static function getForceButton(): string {
		$admin_url   = get_admin_url();
		$prefix      = 'ctm_settings_';
		$redirect_to = urlencode( $admin_url . 'admin.php?page=crb_carbon_fields_container_ct_menus.php' );

		return
			<<<BUTTON
		<a class='button button-primary ctm_force_update' href='{$admin_url}admin-ajax.php?action={$prefix}force_update&redirect_to={$redirect_to}'>Update now</a>
BUTTON;
	}

	/**
	 * Redirect to the settings page after the force update.
	 *
	 * @return void
	 */
	public function redirectForceUpdate(): void {
		$redirect_to = isset( $_GET['redirect_to'] ) ? urldecode( $_GET['redirect_to'] ) : admin_url();
		wp_redirect( $redirect_to );
		exit;
	}
}
