<?php
/**
 * Trigger evaluator.
 *
 * Pure decision logic: given a Config and the current state, should the
 * reminder render? No side effects.
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

final class TriggerEvaluator {

	/**
	 * Decide whether the reminder should render.
	 *
	 * @param Config $config Plugin config.
	 * @param array  $state  Current state.
	 * @return bool
	 */
	public static function should_show( Config $config, array $state ) {
		// Terminal states never show again.
		if ( in_array( $state['state'], array( State::STATE_RATED, State::STATE_DISMISSED ), true ) ) {
			return false;
		}

		// Snoozed and not yet expired.
		if ( State::STATE_SNOOZED === $state['state'] ) {
			if ( null !== $state['snoozed_until'] && time() < (int) $state['snoozed_until'] ) {
				return false;
			}
		}

		$time_ok  = self::evaluate_time( $config, $state );
		$usage_ok = self::evaluate_usage( $config, $state );

		switch ( $config->trigger_logic ) {
			case 'TIME_ONLY':
				return $time_ok;
			case 'USAGE_ONLY':
				return $usage_ok;
			case 'OR':
				return $time_ok || $usage_ok;
			case 'AND':
			default:
				return $time_ok && $usage_ok;
		}
	}

	/**
	 * Has enough time elapsed since installation?
	 *
	 * @param Config $config Plugin config.
	 * @param array  $state  Current state.
	 * @return bool True if there is no time trigger (so it doesn't block) OR enough time has passed.
	 */
	private static function evaluate_time( Config $config, array $state ) {
		if ( null === $config->triggers['time'] ) {
			// No time trigger configured. Return true so AND logic can pass on usage alone.
			return true;
		}
		if ( null === $state['installed_at'] ) {
			return false;
		}
		return ( time() - (int) $state['installed_at'] ) >= (int) $config->triggers['time'];
	}

	/**
	 * Has the usage counter reached threshold?
	 *
	 * @param Config $config Plugin config.
	 * @param array  $state  Current state.
	 * @return bool True if there is no usage trigger OR threshold reached.
	 */
	private static function evaluate_usage( Config $config, array $state ) {
		if ( null === $config->triggers['usage'] ) {
			return true;
		}
		$usage     = $config->triggers['usage'];
		$key       = $usage['option_key'];
		$threshold = (int) $usage['threshold'];
		$count     = isset( $state[ $key ] ) ? (int) $state[ $key ] : (int) $state['usage_count'];

		return $count >= $threshold;
	}
}
