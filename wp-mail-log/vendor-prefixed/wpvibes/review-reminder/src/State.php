<?php
/**
 * State storage and state machine.
 *
 * Each plugin's state lives in a single option to avoid wp_options bloat.
 * State machine: pending -> rated|dismissed (terminal) | snoozed -> pending.
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

final class State {

	const STATE_PENDING   = 'pending';
	const STATE_SNOOZED   = 'snoozed';
	const STATE_DISMISSED = 'dismissed';
	const STATE_RATED     = 'rated';

	const SNOOZE_DURATION = 30 * DAY_IN_SECONDS;

	const GLOBAL_COOLDOWN_KEY      = 'wpvibes_review_reminder_global_cooldown';
	const GLOBAL_COOLDOWN_DURATION = 14 * DAY_IN_SECONDS;

	/**
	 * Build the option key for a given plugin slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function option_key( $slug ) {
		return 'wpvibes_review_reminder_' . $slug;
	}

	/**
	 * Get state for a plugin, creating defaults if absent.
	 *
	 * @param string $slug Plugin slug.
	 * @return array
	 */
	public static function get( $slug ) {
		$stored = get_option( self::option_key( $slug ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args(
			$stored,
			array(
				'installed_at'  => null,
				'usage_count'   => 0,
				'state'         => self::STATE_PENDING,
				'snoozed_until' => null,
				'last_shown_at' => null,
			)
		);
	}

	/**
	 * Persist state for a plugin.
	 *
	 * @param string $slug  Plugin slug.
	 * @param array  $state State array.
	 * @return void
	 */
	public static function save( $slug, array $state ) {
		// `false` for autoload — reminder state is only read on admin pages.
		update_option( self::option_key( $slug ), $state, false );
	}

	/**
	 * Mark the plugin as just installed. Idempotent.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function mark_installed( $slug ) {
		$state = self::get( $slug );
		if ( null === $state['installed_at'] ) {
			$state['installed_at'] = time();
			self::save( $slug, $state );
		}
	}

	/**
	 * Lazily set installed_at if it was missing (e.g., plugin existed before
	 * the library was added). Uses current time, which is intentionally
	 * conservative — the user will see the reminder only after the trigger
	 * window from now, not from their actual install date.
	 *
	 * @param string $slug Plugin slug.
	 * @return array Updated state.
	 */
	public static function ensure_installed_at( $slug ) {
		$state = self::get( $slug );
		if ( null === $state['installed_at'] ) {
			$state['installed_at'] = time();
			self::save( $slug, $state );
		}
		return $state;
	}

	/**
	 * Increment a usage counter, but only if the plugin's state is still
	 * pending or snoozed. Skip writes once the state is terminal.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $key    Counter key (must match config).
	 * @param int    $amount Increment.
	 * @return void
	 */
	public static function increment_usage( $slug, $key, $amount = 1 ) {
		$state = self::get( $slug );

		if ( in_array( $state['state'], array( self::STATE_RATED, self::STATE_DISMISSED ), true ) ) {
			return;
		}

		// We store under the config-supplied key so multiple counters could
		// theoretically coexist, though in practice each plugin uses one.
		if ( ! isset( $state[ $key ] ) || ! is_int( $state[ $key ] ) ) {
			$state[ $key ] = 0;
		}
		$state[ $key ]    += max( 1, (int) $amount );
		$state['usage_count'] = $state[ $key ]; // Mirror to the canonical field.
		self::save( $slug, $state );
	}

	/**
	 * Transition to snoozed state.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function snooze( $slug ) {
		$state                  = self::get( $slug );
		$state['state']         = self::STATE_SNOOZED;
		$state['snoozed_until'] = time() + self::SNOOZE_DURATION;
		self::save( $slug, $state );
	}

	/**
	 * Transition to dismissed (terminal).
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function dismiss( $slug ) {
		$state          = self::get( $slug );
		$state['state'] = self::STATE_DISMISSED;
		self::save( $slug, $state );
	}

	/**
	 * Transition to rated (terminal).
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function mark_rated( $slug ) {
		$state          = self::get( $slug );
		$state['state'] = self::STATE_RATED;
		self::save( $slug, $state );
	}

	/**
	 * Mark that the reminder was shown (for the global cooldown lock and analytics).
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function mark_shown( $slug ) {
		$state                  = self::get( $slug );
		$state['last_shown_at'] = time();
		self::save( $slug, $state );

		set_transient( self::GLOBAL_COOLDOWN_KEY, $slug, self::GLOBAL_COOLDOWN_DURATION );
	}

	/**
	 * Whether some other WPVibes plugin recently showed its reminder.
	 *
	 * @param string $current_slug The slug asking. Same-slug cooldown does not block re-render
	 *                             within a single page load (the reminder renders once per page).
	 * @return bool
	 */
	public static function global_cooldown_active( $current_slug ) {
		$holder = get_transient( self::GLOBAL_COOLDOWN_KEY );
		if ( false === $holder ) {
			return false;
		}
		// If the lock is held by this same plugin, allow it (own cooldown is handled by state).
		return $holder !== $current_slug;
	}

	/**
	 * If a snooze has expired, transition back to pending.
	 *
	 * @param string $slug Plugin slug.
	 * @return array Updated state.
	 */
	public static function maybe_unsnooze( $slug ) {
		$state = self::get( $slug );
		if (
			self::STATE_SNOOZED === $state['state']
			&& null !== $state['snoozed_until']
			&& time() >= (int) $state['snoozed_until']
		) {
			$state['state']         = self::STATE_PENDING;
			$state['snoozed_until'] = null;
			self::save( $slug, $state );
		}
		return $state;
	}

	/**
	 * Reset all state for a plugin. Testing/admin tool use only.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function reset( $slug ) {
		delete_option( self::option_key( $slug ) );
	}
}
