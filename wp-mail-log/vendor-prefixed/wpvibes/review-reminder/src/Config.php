<?php
/**
 * Config value object.
 *
 * Validates and normalizes the array passed to ReviewReminder::register().
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

final class Config {

	/** @var string Plugin wp.org slug. */
	public $slug;

	/** @var string Display name. */
	public $name;

	/** @var string Absolute path to the plugin's main file. */
	public $plugin_file;

	/** @var string Text domain for translatable strings. */
	public $text_domain;

	/**
	 * Trigger config.
	 *
	 * @var array{time: ?int, usage: ?array{option_key: string, threshold: int}}
	 */
	public $triggers;

	/** @var string One of TIME_ONLY, USAGE_ONLY, AND, OR. */
	public $trigger_logic;

	/**
	 * Screen IDs where the reminder may appear. Supports trailing wildcard '*'.
	 *
	 * @var string[]
	 */
	public $screens;

	/** @var string Capability required to see the reminder. */
	public $capability;

	/** @var ?string Optional icon URL. */
	public $icon_url;

	/** @var ?string Optional custom review message heading. */
	public $message_heading;

	/** @var ?string Optional custom review message body. */
	public $message_body;

	/**
	 * Build from an arbitrary array, applying defaults and validation.
	 *
	 * @param array $args Raw input.
	 * @return self
	 * @throws \InvalidArgumentException When required fields are missing or invalid.
	 */
	public static function from_array( array $args ) {
		$config = new self();

		// Required.
		foreach ( array( 'plugin_slug', 'plugin_name', 'plugin_file' ) as $required ) {
			if ( empty( $args[ $required ] ) || ! is_string( $args[ $required ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Missing or invalid "%s"', $required ) );
			}
		}

		$config->slug        = sanitize_key( $args['plugin_slug'] );
		$config->name        = sanitize_text_field( $args['plugin_name'] );
		$config->plugin_file = $args['plugin_file'];
		$config->text_domain = isset( $args['text_domain'] ) ? sanitize_key( $args['text_domain'] ) : $config->slug;

		// Triggers with defaults.
		$triggers = isset( $args['triggers'] ) && is_array( $args['triggers'] ) ? $args['triggers'] : array();

		$time_trigger = isset( $triggers['time'] ) ? (int) $triggers['time'] : null;
		if ( null !== $time_trigger && $time_trigger <= 0 ) {
			$time_trigger = null;
		}

		$usage_trigger = null;
		if ( isset( $triggers['usage'] ) && is_array( $triggers['usage'] ) ) {
			$usage = $triggers['usage'];
			if ( ! empty( $usage['option_key'] ) && isset( $usage['threshold'] ) && (int) $usage['threshold'] > 0 ) {
				$usage_trigger = array(
					'option_key' => sanitize_key( $usage['option_key'] ),
					'threshold'  => (int) $usage['threshold'],
				);
			}
		}

		if ( null === $time_trigger && null === $usage_trigger ) {
			throw new \InvalidArgumentException( 'At least one trigger (time or usage) must be configured' );
		}

		$config->triggers = array(
			'time'  => $time_trigger,
			'usage' => $usage_trigger,
		);

		// Trigger logic.
		$logic = isset( $args['trigger_logic'] ) ? strtoupper( (string) $args['trigger_logic'] ) : 'AND';
		if ( ! in_array( $logic, array( 'AND', 'OR', 'TIME_ONLY', 'USAGE_ONLY' ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid trigger_logic "%s"', $logic ) );
		}
		// If a logic mode references a missing trigger, fall back gracefully.
		if ( 'TIME_ONLY' === $logic && null === $time_trigger ) {
			throw new \InvalidArgumentException( 'trigger_logic=TIME_ONLY but no time trigger configured' );
		}
		if ( 'USAGE_ONLY' === $logic && null === $usage_trigger ) {
			throw new \InvalidArgumentException( 'trigger_logic=USAGE_ONLY but no usage trigger configured' );
		}
		if ( 'AND' === $logic && ( null === $time_trigger || null === $usage_trigger ) ) {
			// Degrade AND to whichever trigger is present rather than erroring;
			// the common case is a plugin with only one trigger configured.
			$logic = null !== $time_trigger ? 'TIME_ONLY' : 'USAGE_ONLY';
		}
		$config->trigger_logic = $logic;

		// Screens.
		$screens = isset( $args['screens'] ) && is_array( $args['screens'] ) ? $args['screens'] : array( 'dashboard', 'plugins' );
		$config->screens = array_values( array_filter( array_map( 'strval', $screens ) ) );

		// Capability.
		$config->capability = isset( $args['capability'] ) ? (string) $args['capability'] : 'manage_options';

		// Icon (optional).
		$config->icon_url = isset( $args['icon_url'] ) ? esc_url_raw( (string) $args['icon_url'] ) : null;

		// Custom message (optional).
		$config->message_heading = ! empty( $args['message_heading'] ) ? wp_kses_post( $args['message_heading'] ) : null;
		$config->message_body    = ! empty( $args['message_body'] ) ? wp_kses_post( $args['message_body'] ) : null;

		return $config;
	}

	/**
	 * Build the wp.org review URL for this plugin.
	 *
	 * @return string
	 */
	public function review_url() {
		return sprintf(
			'https://wordpress.org/support/plugin/%s/reviews/?filter=5#new-post',
			rawurlencode( $this->slug )
		);
	}
}
