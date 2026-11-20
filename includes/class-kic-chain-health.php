<?php
/**
 * Natilus — chain health event history.
 *
 * The provider chain already tracks failures in short-lived transients
 * (10-minute cooldown, self-healing). This module keeps a durable,
 * READ-ONLY event log of the same signals so the admin can see:
 *
 *   - which provider / model failed and when,
 *   - the brief error classification (provider-wide vs model-level),
 *   - when a previously failing entry healed (a later success).
 *
 * Pure diagnostics: nothing here influences requests, and nothing here
 * writes post content. Stored as one option (ring buffer, newest first,
 * capped) so no extra DB table is needed on small installs.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KIC_Chain_Health {

	/**
	 * Singleton instance.
	 *
	 * @var KIC_Chain_Health|null
	 */
	private static $instance = null;

	/**
	 * Option key for the ring buffer.
	 */
	const OPTION = 'kic_chain_events';

	/**
	 * Maximum events kept (newest first).
	 */
	const MAX_EVENTS = 120;

	/**
	 * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
	 *
	 * @return KIC_Chain_Health
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Singleton accessor (lazily bootstrapped).
	 *
	 * @return KIC_Chain_Health
	 */
	public static function instance() {
		return self::init();
	}

	/**
	 * Private constructor (singleton; read-only helper registers no hooks).
	 */
	private function __construct() {
	}

	/**
	 * Record a chain event (best-effort; never interrupts a request).
	 *
	 * @param string $action     'failed' | 'healed'.
	 * @param string $provider_id Provider chain id ('' allowed).
	 * @param string $model       Model name.
	 * @param string $kind        'provider' | 'model'.
	 * @param string $note        Short error/detail text.
	 * @return void
	 */
	public static function record( $action, $provider_id, $model, $kind = 'model', $note = '' ) {
		if ( ! function_exists( 'update_option' ) ) {
			return; // Too early (activation edge) — skip silently.
		}

		$provider_id = trim( (string) $provider_id );
		$model       = trim( (string) $model );
		$action      = ( 'healed' === $action ) ? 'healed' : 'failed';
		$kind        = ( 'provider' === $kind ) ? 'provider' : 'model';
		$note        = mb_substr( wp_strip_all_tags( (string) $note ), 0, 200 );

		$label = self::provider_label( $provider_id );

		$events = get_option( self::OPTION, array() );
		if ( ! is_array( $events ) ) {
			$events = array();
		}

		// Collapse rapid duplicates: same signature within 5 minutes updates the last row.
		$signature = $action . '|' . $provider_id . '|' . $model . '|' . $kind;
		if ( ! empty( $events ) && is_array( $events[0] )
			&& isset( $events[0]['sig'], $events[0]['ts'] )
			&& (string) $events[0]['sig'] === $signature
			&& ( time() - (int) $events[0]['ts'] ) < 300 ) {
			$events[0]['ts']    = time();
			$events[0]['count'] = ( isset( $events[0]['count'] ) ? (int) $events[0]['count'] : 1 ) + 1;
			$events[0]['note']  = ( '' !== $note ) ? $note : (string) $events[0]['note'];
			update_option( self::OPTION, array_slice( $events, 0, self::MAX_EVENTS ), false );
			return;
		}

		$event = array(
			'ts'       => time(),
			'action'   => $action,
			'provider' => $label,
			'model'    => $model,
			'kind'     => $kind,
			'note'     => $note,
			'sig'      => $signature,
			'count'    => 1,
		);

		array_unshift( $events, $event );
		update_option( self::OPTION, array_slice( $events, 0, self::MAX_EVENTS ), false );
	}

	/**
	 * Record a "healed" event only when the entry was actually marked failing.
	 * Called BEFORE KIC_Provider_Chain::report_success() clears the marker.
	 *
	 * @param string $provider_id Provider chain id.
	 * @param string $model       Model name.
	 * @return void
	 */
	public static function maybe_record_healed( $provider_id, $model ) {
		if ( ! class_exists( 'KIC_Provider_Chain' ) ) {
			return;
		}
		$provider_id = trim( (string) $provider_id );
		if ( '' === $provider_id ) {
			return;
		}
		$failed = KIC_Provider_Chain::model_failed_recently( $provider_id, $model )
			|| KIC_Provider_Chain::provider_failed_recently( $provider_id );
		if ( $failed ) {
			self::record( 'healed', $provider_id, $model, 'model', '' );
		}
	}

	/**
	 * Recent events, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array[]
	 */
	public static function events( $limit = 40 ) {
		$events = get_option( self::OPTION, array() );
		if ( ! is_array( $events ) ) {
			return array();
		}
		$limit = max( 1, min( 200, (int) $limit ) );
		return array_slice( $events, 0, $limit );
	}

	/**
	 * Count of failure events inside a window (for the weekly summary).
	 *
	 * @param int $since Unix timestamp lower bound.
	 * @return int
	 */
	public static function failures_since( $since ) {
		$since  = (int) $since;
		$count  = 0;
		$events = self::events( self::MAX_EVENTS );
		foreach ( $events as $event ) {
			if ( isset( $event['ts'] ) && (int) $event['ts'] >= $since && 'failed' === (string) $event['action'] ) {
				$count += isset( $event['count'] ) ? max( 1, (int) $event['count'] ) : 1;
			}
		}
		return $count;
	}

	/**
	 * Human-friendly provider label for the log (falls back to the raw id).
	 *
	 * @param string $provider_id Provider chain id.
	 * @return string
	 */
	private static function provider_label( $provider_id ) {
		if ( '' === $provider_id ) {
			return '';
		}
		if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'find' ) ) {
			$provider = KIC_Provider_Chain::find( $provider_id );
			if ( is_array( $provider ) && ! empty( $provider['label'] ) ) {
				return (string) $provider['label'];
			}
			if ( is_array( $provider ) && ! empty( $provider['base_url'] ) ) {
				if ( method_exists( 'KIC_Provider_Chain', 'host_label' ) ) {
					return (string) KIC_Provider_Chain::host_label( (string) $provider['base_url'] );
				}
				return (string) $provider['base_url'];
			}
		}
		return $provider_id;
	}
}
