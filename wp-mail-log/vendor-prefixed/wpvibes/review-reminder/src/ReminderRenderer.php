<?php
/**
 * @license GPL-2.0-or-later
 *
 * Modified by __root__ on 21-May-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
/**
 * Reminder renderer.
 *
 * Iterates registered plugins, picks the first that should show, and renders
 * a single reminder. Only one reminder ever renders per page load even if
 * multiple plugins technically qualify.
 *
 * @package WPVibes\ReviewReminder
 */

namespace WPVibes\WPMailLog\Vendor\WPVibes\ReviewReminder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReminderRenderer {

	/**
	 * Track whether a reminder has already rendered on this page.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Resolve which Config (if any) should render right now.
	 *
	 * @return ?Config
	 */
	private static function resolve_active_config() {
		if ( self::$rendered ) {
			return null;
		}

		foreach ( ReviewReminder::get_instances() as $config ) {
			if ( ! current_user_can( $config->capability ) ) {
				continue;
			}
			if ( ! ScreenMatcher::current_matches( $config->screens ) ) {
				continue;
			}
			if ( State::global_cooldown_active( $config->slug ) ) {
				continue;
			}

			$state = State::ensure_installed_at( $config->slug );
			$state = State::maybe_unsnooze( $config->slug );

			if ( ! TriggerEvaluator::should_show( $config, $state ) ) {
				continue;
			}

			return $config;
		}

		return null;
	}

	/**
	 * Render the reminder if conditions are met.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		$config = self::resolve_active_config();
		if ( ! $config ) {
			return;
		}

		self::$rendered = true;
		State::mark_shown( $config->slug );

		$nonce       = wp_create_nonce( 'wpvibes_review_reminder_' . $config->slug );
		$ajax_url    = admin_url( 'admin-ajax.php' );
		$ajax_action = 'wpvibes_review_reminder_' . $config->slug;
		$review_url  = $config->review_url();
		$plugin_name = $config->name;
		$icon_url    = $config->icon_url;

		if ( $config->message_body ) {
			$message = $config->message_body;
		} else {
			$message = sprintf(
				/* translators: %s: plugin display name. */
				__( 'Hey! It looks like you are enjoying %s. Could you take a moment to leave a 5-star review on WordPress.org? It really helps small teams like ours.', 'wpvibes-review-reminder' ),
				'<strong>' . esc_html( $plugin_name ) . '</strong>'
			);
		}

		?>
		<div
			class="wpvibes-review-reminder"
			data-slug="<?php echo esc_attr( $config->slug ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
			data-ajax-action="<?php echo esc_attr( $ajax_action ); ?>"
		>
			<div class="wpvibes-review-reminder__inner">
				<?php if ( $icon_url ) : ?>
					<img class="wpvibes-review-reminder__icon" src="<?php echo esc_url( $icon_url ); ?>" alt="" />
				<?php endif; ?>
				<div class="wpvibes-review-reminder__body">
					<?php if ( $config->message_heading ) : ?>
						<p class="wpvibes-review-reminder__heading" style="font-weight: 600; font-size: 1.1em; margin-bottom: 0.5em; margin-top: 0;"><?php echo wp_kses_post( $config->message_heading ); ?></p>
					<?php endif; ?>
					<p class="wpvibes-review-reminder__message">
						<?php echo wp_kses_post( $message ); ?>
					</p>
					<p class="wpvibes-review-reminder__actions">
						<a
							href="<?php echo esc_url( $review_url ); ?>"
							class="button button-primary wpvibes-review-reminder__btn"
							data-action="rate"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php esc_html_e( '★ Rate now', 'wpvibes-review-reminder' ); ?>
						</a>
						<button
							type="button"
							class="button button-secondary wpvibes-review-reminder__btn"
							data-action="rated"
						>
							<?php esc_html_e( 'I already did', 'wpvibes-review-reminder' ); ?>
						</button>
						<button
							type="button"
							class="button-link wpvibes-review-reminder__btn-link"
							data-action="snooze"
						>
							<?php esc_html_e( 'Remind me in 30 days', 'wpvibes-review-reminder' ); ?>
						</button>
						<button
							type="button"
							class="button-link wpvibes-review-reminder__btn-link"
							data-action="dismiss"
						>
							<?php esc_html_e( 'Never show again', 'wpvibes-review-reminder' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue reminder CSS and JS only on screens where a plugin is registered.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		// Cheap filter: any registered plugin's screens match? Avoids loading
		// assets on every admin page across the entire WP install.
		$relevant = false;
		foreach ( ReviewReminder::get_instances() as $config ) {
			if ( ScreenMatcher::current_matches( $config->screens ) ) {
				$relevant = true;
				break;
			}
		}
		if ( ! $relevant ) {
			return;
		}

		$base_url = self::library_url();
		if ( null === $base_url ) {
			// Fallback: inline assets if the library URL can't be resolved.
			add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_inline_assets' ) );
			return;
		}

		$handle = 'wpvibes-review-reminder-' . substr( md5( __DIR__ ), 0, 8 );
		wp_enqueue_style(
			$handle,
			$base_url . 'assets/reminder.css',
			array(),
			'1.0.0'
		);
		wp_enqueue_script(
			$handle,
			$base_url . 'assets/reminder.js',
			array(),
			'1.0.0',
			true
		);
	}

	/**
	 * Resolve the URL to this library's directory.
	 *
	 * Works whether the package is in the plugin's vendor/ directory or
	 * scoped/copied somewhere else, by mapping __DIR__ to a URL via
	 * plugins_url(). Returns null if the file is outside WP_PLUGIN_DIR
	 * (e.g., dropped into mu-plugins or wp-content/themes), in which case
	 * the caller falls back to inline assets.
	 *
	 * @return ?string
	 */
	private static function library_url() {
		$library_dir = dirname( __DIR__ ); // /path/to/.../wpvibes-review-reminder/
		$library_dir = wp_normalize_path( $library_dir );

		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
		if ( 0 !== strpos( $library_dir, $plugins_dir ) ) {
			return null;
		}

		$relative = ltrim( substr( $library_dir, strlen( $plugins_dir ) ), '/' );
		return plugins_url( $relative . '/' );
	}

	/**
	 * Inline asset fallback when library_url() can't resolve a URL.
	 *
	 * @return void
	 */
	public static function print_inline_assets() {
		$css_path = dirname( __DIR__ ) . '/assets/reminder.css';
		$js_path  = dirname( __DIR__ ) . '/assets/reminder.js';

		if ( file_exists( $css_path ) ) {
			echo "<style id='wpvibes-review-reminder-inline-css'>\n";
			echo file_get_contents( $css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			echo "\n</style>\n";
		}
		if ( file_exists( $js_path ) ) {
			echo "<script id='wpvibes-review-reminder-inline-js'>\n";
			echo file_get_contents( $js_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			echo "\n</script>\n";
		}
	}
}
