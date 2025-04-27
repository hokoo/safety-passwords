<?php

namespace iTRON\SafetyPasswords;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;

class Settings {
	public static string $optionPrefix;
	const MANAGE_CAPS = 'safety_passwords_manage_options';

	public static function init(): void {
		add_action( 'carbon_fields_register_fields', [ self::class, 'createOptions' ] );
		add_action( 'after_setup_theme', [ self::class, 'loadCarbon' ] );

		// Ensure the cron event is scheduled when visiting the plugin settings page.
		add_action( 'toplevel_page_crb_carbon_fields_container_safety_passwords', [ Cron::class, 'ensureEvent' ] );

		self::$optionPrefix = PLUGIN_SLUG . '_';
	}

	public static function loadCarbon(): void {
		Carbon_Fields::boot();
	}

	public static function createOptions(): void {
		$option_page = Container::make( OPTIONS_MODE, 'Safety Passwords' );
		$settings    = [];
		// Force Password Reset after registration
		if ( ! self::isOverloaded( 'rp_on_registration' ) ) {
			$settings[] = Field::make( 'checkbox', self::$optionPrefix . 'rp_on_registration', __( 'Change After Registration', 'safety-passwords' ) )
			                   ->set_option_value( 'yes' )
			                   ->set_help_text( __('Force users to change their password after registration.', 'safety-passwords') );
		} else {
			$value      = self::getOverloaded( 'rp_on_registration' ) ? __('Enabled', 'safety-passwords' ) : __( 'Disabled', 'safety-passwords' );
			$settings[] = Field::make( 'html', self::$optionPrefix . 'rp_on_registration_disabled' )
			                   ->set_html( "[$value]". __( "<b>Change After Registration</b> Overwritten by constant<br/><small><i>Force users to change their password after registration</i></small>", 'safety-passwords' ) );
		}

		if ( ! self::isOverloaded( 'min_len' ) ) {
			$settings[] = Field::make( 'text', self::$optionPrefix . 'min_len', __("Password's minimum length", 'safety-passwords') )
			                   ->set_attribute( 'min', 1 )
			                   ->set_attribute( 'max', 24 )
			                   ->set_attribute( 'step', 1 )
			                   ->set_attribute( 'type', 'number' )
			                   ->set_default_value( 8 );
		} else {
			$value      = self::getOverloaded( 'min_len' ) ;
			$settings[] = Field::make( 'html', self::$optionPrefix . 'min_len_disabled' )
			                   ->set_html( "[$value]" . __( "<b>Password's minimum length</b> Overwritten by constant<br/>", 'safety-passwords' ) );
		}

		if ( ! self::isOverloaded( 'reset_interval' ) ) {
			$settings[] = Field::make( 'text', self::$optionPrefix . 'reset_interval', __( 'Force Password Reset Interval (days)', 'safety-passwords' ) )
			                   ->set_attribute( 'min', 0 )
			                   ->set_attribute( 'max', 999 )
			                   ->set_attribute( 'step', 1 )
			                   ->set_attribute( 'type', 'number' )
			                   ->set_default_value( 30 )
			                   ->set_help_text( __('Set 0 to disable forced periodical password reset', 'safety-passwords' ) );
		} else {
			$value      = self::getOverloaded( 'reset_interval' ) ;
			$settings[] = Field::make( 'html', self::$optionPrefix . 'reset_interval_disabled' )
			                   ->set_html( "[$value]" . __( "<b>Force Password Reset Interval (days)</b> Overwritten by constant<br/>", 'safety-passwords' ) );
		}


		$option_page->add_fields( $settings )
		            ->set_icon( 'dashicons-superhero' )
		            ->where( 'current_user_capability', 'IN', [ self::MANAGE_CAPS, 'manage_options' ] );
	}

	private static function isOverloaded( $optionSlug ): bool {
		return defined( 'SAFETY_PASSWORDS_' . strtoupper( $optionSlug ) );
	}

	private static function getOverloaded( $optionSlug ) {
		return constant( 'SAFETY_PASSWORDS_' . strtoupper( $optionSlug ) );
	}

	/**
	 * @todo Cache invalidation.
	 *
	 * @param string $optionSlug
	 *
	 * @return mixed|null
	 */
	public static function getOption( string $optionSlug ) {
		if ( self::isOverloaded( $optionSlug ) ) {
			return self::getOverloaded( $optionSlug );
		}

		// Carbon Fields does not have a built-in caching mechanism, lol.
		$cache = wp_cache_get( $optionSlug, PLUGIN_SLUG );
		if ( false !== $cache ) {
			return $cache;
		}

		$value = carbon_get_theme_option( self::$optionPrefix . $optionSlug );
		wp_cache_set( $optionSlug, $value, PLUGIN_SLUG );

		return $value;
	}

	public static function getInterval(): int {
		return (int) self::getOption( 'reset_interval' );
	}
}
