<?php
/**
 * Screen matcher.
 *
 * Supports literal screen IDs and trailing wildcards like 'form-vibes_page_*'.
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

final class ScreenMatcher {

	/**
	 * Does the current screen match any of the configured patterns?
	 *
	 * @param string[] $patterns Allowed screen IDs/patterns.
	 * @return bool
	 */
	public static function current_matches( array $patterns ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		$current = (string) $screen->id;

		foreach ( $patterns as $pattern ) {
			if ( self::matches( $current, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a single pattern. Trailing '*' is a wildcard.
	 *
	 * @param string $screen_id Current screen ID.
	 * @param string $pattern   Configured pattern.
	 * @return bool
	 */
	private static function matches( $screen_id, $pattern ) {
		if ( '*' === substr( $pattern, -1 ) ) {
			$prefix = substr( $pattern, 0, -1 );
			return 0 === strpos( $screen_id, $prefix );
		}
		return $screen_id === $pattern;
	}
}
