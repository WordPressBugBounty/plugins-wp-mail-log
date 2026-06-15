<?php
/**
 * Public facade for the review reminder library.
 *
 * Each plugin calls ReviewReminder::register() once with its config.
 * Everything else (state, triggers, rendering, ajax) is internal.
 *
 * @package WPVibes\ReviewReminder
 *
 * @license GPL-2.0-or-later
 * Modified by __root__ on 21-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WPVibes\WPMailLog\Vendor\WPVibes\ReviewReminder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReviewReminder {

	/**
	 * Registered plugin instances keyed by slug.
	 *
	 * @var array<string, Config>
	 */
	private static $instances = array();

	/**
	 * Whether shared hooks have been bound.
	 *
	 * @var bool
	 */
	private static $hooks_bound = false;

	/**
	 * Register a plugin with the review reminder system.
	 *
	 * Idempotent: calling twice with the same slug replaces the prior config.
	 *
	 * @param array $args Plugin configuration. See Config::from_array() for shape.
	 * @return void
	 */
	public static function register( array $args ) {
		try {
			$config = Config::from_array( $args );
		} catch ( \InvalidArgumentException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				trigger_error(
					sprintf(
						'wpvibes/review-reminder: invalid config for plugin "%s": %s',
						isset( $args['plugin_slug'] ) ? esc_html( $args['plugin_slug'] ) : 'unknown',
						esc_html( $e->getMessage() )
					),
					E_USER_WARNING
				);
			}
			return;
		}

		self::$instances[ $config->slug ] = $config;

		// Activation hook must be set up at registration time, not on admin_init.
		register_activation_hook(
			$config->plugin_file,
			static function () use ( $config ) {
				State::mark_installed( $config->slug );
			}
		);

		// Per-slug AJAX action so multiple WPVibes plugins never collide.
		add_action( 'wp_ajax_wpvibes_review_reminder_' . $config->slug, array( AjaxHandler::class, 'handle' ) );

		self::bind_hooks();
	}

	/**
	 * Increment a usage counter for a registered plugin.
	 *
	 * Plugins call this from their own value-moment events (form submitted,
	 * widget rendered, email captured, etc).
	 *
	 * @param string $slug    Plugin slug previously passed to register().
	 * @param string $key     Counter key from triggers.usage.option_key.
	 * @param int    $amount  Increment amount, default 1.
	 * @return void
	 */
	public static function increment( $slug, $key, $amount = 1 ) {
		if ( ! isset( self::$instances[ $slug ] ) ) {
			return;
		}
		State::increment_usage( $slug, $key, (int) $amount );
	}

	/**
	 * Reset state for a plugin. Useful for testing or admin tools.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function reset( $slug ) {
		if ( ! isset( self::$instances[ $slug ] ) ) {
			return;
		}
		State::reset( $slug );
	}

	/**
	 * Internal: get all registered configs.
	 *
	 * @return array<string, Config>
	 */
	public static function get_instances() {
		return self::$instances;
	}

	/**
	 * Bind admin hooks once, regardless of how many plugins register.
	 *
	 * @return void
	 */
	private static function bind_hooks() {
		if ( self::$hooks_bound ) {
			return;
		}
		self::$hooks_bound = true;

		add_action( 'admin_notices', array( ReminderRenderer::class, 'maybe_render' ) );
		add_action( 'admin_enqueue_scripts', array( ReminderRenderer::class, 'enqueue_assets' ) );
	}
}
