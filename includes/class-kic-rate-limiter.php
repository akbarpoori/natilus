<?php
/**
 * Sliding-window per-user rate limiter.
 *
 * Keeps a transient list of request timestamps (float, microtime based)
 * per user id. check() tells whether another request is allowed inside
 * the 60 second window; hit() records one. Transients live slightly
 * longer (90s) than the window so pruning is self-healing.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KIC_Rate_Limiter {

	/**
	 * Sliding window length in seconds.
	 */
	const WINDOW = 60;

	/**
	 * Transient TTL in seconds (slightly longer than the window).
	 */
	const TTL = 90;

	/**
	 * Singleton instance (bootstrap contract).
	 *
	 * @var KIC_Rate_Limiter|null
	 */
	private static $instance = null;

	/**
	 * @return KIC_Rate_Limiter
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Bootstrap contract hook registration. Static utility — the limiter
	 * is invoked directly by the AJAX/API layers, no hooks needed.
	 *
	 * @return void
	 */
	public static function init() {}

	/**
	 * Whether the user may fire another request inside the sliding window.
	 *
	 * @param int $user_id User id (0 allowed for logged-out callers).
	 * @return true|WP_Error true when allowed, WP_Error('kic_rate_limit') otherwise.
	 */
	public static function check( $user_id ) {
		$user_id = absint( $user_id );

		$limit = absint( kic_get_setting( 'rate_limit', 6 ) );
		if ( $limit < 1 ) {
			$limit = 1;
		}

		$hits = get_transient( 'kic_rl_' . $user_id );
		if ( ! is_array( $hits ) ) {
			return true;
		}

		$recent = self::prune( $hits );

		if ( count( $recent ) >= $limit ) {
			return new WP_Error(
				'kic_rate_limit',
				'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً حدود یک دقیقه دیگر دوباره تلاش کنید.'
			);
		}

		return true;
	}

	/**
	 * Record one request for the sliding window.
	 *
	 * @param int $user_id User id (0 allowed for logged-out callers).
	 * @return true
	 */
	public static function hit( $user_id ) {
		$user_id = absint( $user_id );

		$hits   = get_transient( 'kic_rl_' . $user_id );
		$recent = is_array( $hits ) ? self::prune( $hits ) : array();

		$recent[] = microtime( true );

		set_transient( 'kic_rl_' . $user_id, $recent, self::TTL );

		return true;
	}

	/**
	 * Drop timestamps that fell outside the sliding window.
	 *
	 * @param array $hits Stored timestamp list (mixed).
	 * @return float[] Live timestamps, oldest first.
	 */
	private static function prune( $hits ) {
		$now    = microtime( true );
		$recent = array();

		foreach ( $hits as $timestamp ) {
			if ( is_numeric( $timestamp ) && ( $now - (float) $timestamp ) < self::WINDOW ) {
				$recent[] = (float) $timestamp;
			}
		}

		return $recent;
	}
}
