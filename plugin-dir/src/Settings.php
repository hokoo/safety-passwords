<?php

namespace iTRON\SafetyPasswords;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container;
use Carbon_Fields\Field;

class Settings {
	public static string $optionPrefix;
	const MANAGE_CAPS = 'safety_passwords_manage_options';
	private const INIT_ACTION = 'safety_passwords_initialize';
	private const SETTINGS_PAGE = 'crb_carbon_fields_container_safety_passwords.php';

	public static function init(): void {
		add_action( 'carbon_fields_register_fields', [ self::class, 'createOptions' ] );
		add_action( 'after_setup_theme', [ self::class, 'loadCarbon' ] );
		add_action( 'admin_post_' . self::INIT_ACTION, [ self::class, 'processInitialize' ] );
		add_action( 'admin_footer', [ self::class, 'renderInitializeForm' ] );
		add_action( 'admin_notices', [ self::class, 'renderInitializeNotice' ] );
		add_action( 'network_admin_notices', [ self::class, 'renderInitializeNotice' ] );
		add_action( 'admin_notices', [ self::class, 'renderInitializationStatus' ] );
		add_action( 'network_admin_notices', [ self::class, 'renderInitializationStatus' ] );

		self::$optionPrefix = PLUGIN_SLUG . '_';
	}

	public static function loadCarbon(): void {
		Carbon_Fields::boot();
	}

	public static function createOptions(): void {
		$option_page = Container::make( OPTIONS_MODE, 'Safety Passwords' );
		if ( is_multisite() ) {
			// Carbon defaults network containers to SITE_ID_CURRENT_SITE, which can
			// differ from the network handling this request in a multi-network install.
			$option_page->get_datastore()->set_object_id( get_current_network_id() );
		}
		$settings    = [];
		// Force Password Reset after registration
		if ( ! self::isOverloaded( 'rp_on_registration' ) ) {
			$settings[] = Field::make( 'checkbox', self::$optionPrefix . 'rp_on_registration', __( 'Change After Registration', 'safety-passwords' ) )
			                   ->set_option_value( 'yes' )
			                   ->set_help_text( __('Force users to change their password after registration.', 'safety-passwords') );
		} else {
			$value      = self::getOverloaded( 'rp_on_registration' ) ? __('Enabled', 'safety-passwords' ) : __( 'Disabled', 'safety-passwords' );
			$settings[] = Field::make( 'html', self::$optionPrefix . 'rp_on_registration_disabled' )
			                   ->set_html( '[' . esc_html( $value ) . ']' . __( "<b>Change After Registration</b> Overwritten by constant<br/><small><i>Force users to change their password after registration</i></small>", 'safety-passwords' ) );
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
			                   ->set_html( '[' . esc_html( (string) $value ) . ']' . __( "<b>Password's minimum length</b> Overwritten by constant<br/>", 'safety-passwords' ) );
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
			                   ->set_html( '[' . esc_html( (string) $value ) . ']' . __( "<b>Force Password Reset Interval (days)</b> Overwritten by constant<br/>", 'safety-passwords' ) );
		}
		$settings[] = Field::make( 'html', self::$optionPrefix . 'initialize_control' )
			->set_html( '<h2>' . esc_html__( 'Maintenance', 'safety-passwords' ) . '</h2>'
				. '<p>' . esc_html__( 'Initialization and schedule checks normally run automatically. Use this button to retry after an initialization error, repair a degraded schedule, or restore history after a same-version must-use installation returns.', 'safety-passwords' ) . '</p>'
				. '<button type="submit" class="button button-secondary" form="safety-passwords-initialize-form">'
				. esc_html__( 'Initialize / repair', 'safety-passwords' ) . '</button>'
				. '<p><a href="' . esc_url( 'https://wordpress.org/plugins/safety-passwords/#faq' ) . '">'
				. esc_html__( 'When should I use Initialize / repair? Read the plugin FAQ.', 'safety-passwords' ) . '</a></p>' );


		$option_page->add_fields( $settings )
		            ->set_icon( 'dashicons-superhero' )
		            ->where( 'current_user_capability', 'IN', [ self::MANAGE_CAPS, 'manage_options' ] );
		if ( is_multisite() ) {
			// Carbon evaluates these conditions for both page attachment and saving.
			$option_page->where( 'current_user_capability', '=', 'manage_network_options' );
		}
	}

	private static function canManage(): bool {
		return is_multisite()
			? current_user_can( 'manage_network_options' )
			: ( current_user_can( self::MANAGE_CAPS ) || current_user_can( 'manage_options' ) );
	}

	private static function onSettingsPage(): bool {
		return isset( $_GET['page'] ) && self::SETTINGS_PAGE === $_GET['page'];
	}

	private static function settingsUrl(): string {
		$base = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return add_query_arg( 'page', self::SETTINGS_PAGE, $base );
	}

	public static function renderInitializeForm(): void {
		if ( ! self::onSettingsPage() || ! self::canManage() ) {
			return;
		}
		echo '<form id="safety-passwords-initialize-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::INIT_ACTION ) . '">';
		wp_nonce_field( self::INIT_ACTION );
		echo '</form>';
	}

	public static function renderInitializeNotice(): void {
		if ( ! self::onSettingsPage() || ! self::canManage() || ! isset( $_GET['safety_passwords_init'] ) ) {
			return;
		}
		if ( 'success' === $_GET['safety_passwords_init'] ) {
			General::echoNotice( __( 'Safety Passwords initialization completed.', 'safety-passwords' ), 'success' );
		} elseif ( 'failed' === $_GET['safety_passwords_init'] ) {
			General::echoNotice( __( 'Safety Passwords initialization did not complete. Retry after checking the site state.', 'safety-passwords' ), 'error' );
		}
	}

	/** Display only fixed diagnostic text to users who can repair this network. */
	public static function renderInitializationStatus(): void {
		if ( ! self::onSettingsPage() || ! self::canManage() ) {
			return;
		}
		$status = Activation::initializationStatus();
		$schedule_health = Activation::scheduleHealth();
		if ( 'ready' === $status['state'] && in_array( $schedule_health, [ 'healthy', 'verification_due' ], true ) ) {
			return;
		}
		$labels = [
			'pending' => __( 'Pending. Initialization will run on the next suitable request.', 'safety-passwords' ),
			'running' => __( 'Running. Another request is initializing Safety Passwords; try again after it finishes.', 'safety-passwords' ),
			'ready' => __( 'Ready. Initialization completed for this version.', 'safety-passwords' ),
			'retryable_error' => __( 'Retryable error. Use Initialize / repair to retry now.', 'safety-passwords' ),
		];
		$categories = [
			'capability' => __( 'Capability repair failed.', 'safety-passwords' ),
			'history' => __( 'History verification failed.', 'safety-passwords' ),
			'schedule' => __( 'Schedule repair failed.', 'safety-passwords' ),
			'general' => __( 'Initialization failed.', 'safety-passwords' ),
		];
		$message = $labels[ $status['state'] ];
		if ( 'retryable_error' === $status['state'] ) {
			$message .= ' ' . $categories[ $status['category'] ];
			if ( $status['next_retry'] > time() ) {
				$message .= ' ' . sprintf(
					/* translators: %s: local date and time of the next automatic initialization attempt. */
					__( 'Next automatic retry: %s.', 'safety-passwords' ),
					get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $status['next_retry'] ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				);
			}
		}
		$schedule = [
			'healthy' => __( 'Healthy.', 'safety-passwords' ),
			'degraded' => __( 'Degraded. Use Initialize / repair to repair the schedule.', 'safety-passwords' ),
			'verification_due' => __( 'Verification due. The network schedule will be checked on an eligible request.', 'safety-passwords' ),
		];
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Initialization:', 'safety-passwords' ) . ' ' . esc_html( $message ) . '</p>';
		echo '<p>' . esc_html__( 'Schedule:', 'safety-passwords' ) . ' ' . esc_html( $schedule[ $schedule_health ] ) . '</p></div>';
	}

	public static function processInitialize(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Invalid request method.', 'safety-passwords' ), '', [ 'response' => 405 ] );
		}
		if ( ! self::canManage() ) {
			wp_die( esc_html__( 'You are not allowed to initialize Safety Passwords.', 'safety-passwords' ), '', [ 'response' => 403 ] );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::INIT_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request nonce.', 'safety-passwords' ), '', [ 'response' => 403 ] );
		}
		$result = Activation::initialize( true );
		$redirect = add_query_arg( 'safety_passwords_init', $result ? 'success' : 'failed', self::settingsUrl() );
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function isOverloaded( $optionSlug ): bool {
		return defined( 'SAFETY_PASSWORDS_' . strtoupper( $optionSlug ) );
	}

	private static function getOverloaded( $optionSlug ) {
		$value = constant( 'SAFETY_PASSWORDS_' . strtoupper( $optionSlug ) );
		if ( 'rp_on_registration' === $optionSlug ) {
			return wp_validate_boolean( $value );
		}

		if ( in_array( $optionSlug, [ 'min_len', 'reset_interval' ], true ) && is_string( $value )
			&& preg_match( '/^([+-]?)([0-9]+)$/D', $value, $matches ) ) {
			$digits = ltrim( $matches[2], '0' );
			$normalized = ( '-' === $matches[1] && '' !== $digits ? '-' : '' ) . ( '' === $digits ? '0' : $digits );
			$integer = filter_var( $normalized, FILTER_VALIDATE_INT );
			if ( false !== $integer ) {
				return $integer;
			}
		}

		return $value;
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
		if ( is_multisite() ) {
			// Network settings may be updated from any site context; avoid stale site-local cache entries.
			return carbon_get_network_option( get_current_network_id(), self::$optionPrefix . $optionSlug );
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
