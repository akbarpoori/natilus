<?php
/**
 * Natilus — smart model routing.
 *
 * Light tasks (outline, translate, title, short) can be routed to a
 * cheaper model while heavy tasks (full compose, regenerate, images)
 * always stay on the primary model. Pure static helper — no hooks.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KIC_Router {

	/**
	 * Singleton instance (contract only — this class registers no hooks).
	 *
	 * @var KIC_Router|null
	 */
	private static $instance = null;

	/**
	 * Contract bootstrap (registers nothing by design).
	 *
	 * @return KIC_Router
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
	 * @return KIC_Router
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::init();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton contract, no hooks to register).
	 */
	private function __construct() {
	}

	/**
	 * Task slugs eligible for the cheap model.
	 *
	 * @return string[]
	 */
	private static function light_tasks() {
		return array( 'outline', 'translate', 'title', 'short' );
	}

	/**
	 * Resolve the model to use for a task slug.
	 *
	 * When routing is enabled, a cheap model is configured and the task is a
	 * light task, the cheap model wins; every other case keeps $default_model.
	 *
	 * @param string $task          Task slug (outline|full|translate|title|short|image...).
	 * @param string $default_model Model used when routing does not apply.
	 * @return string
	 */
	public static function model_for( $task, $default_model ) {
		$task          = sanitize_key( (string) $task );
		$default_model = (string) $default_model;

		if ( ! kic_get_setting( 'routing_enabled' ) ) {
			return $default_model;
		}

		$cheap = trim( (string) kic_get_setting( 'cheap_model', '' ) );
		if ( '' === $cheap ) {
			return $default_model;
		}

		if ( in_array( $task, self::light_tasks(), true ) ) {
			return $cheap;
		}

		return $default_model;
	}

	/**
	 * English one-liner describing which model class a task resolves to
	 * (debugging aid; currently unused by the UI).
	 *
	 * @param string $task Task slug.
	 * @return string
	 */
	public static function task_note( $task ) {
		$task = sanitize_key( (string) $task );

		if ( ! kic_get_setting( 'routing_enabled' ) ) {
			return 'routing disabled: primary model for task "' . $task . '"';
		}

		$cheap = trim( (string) kic_get_setting( 'cheap_model', '' ) );
		if ( '' === $cheap ) {
			return 'routing enabled but no cheap model configured: primary model for task "' . $task . '"';
		}

		if ( in_array( $task, self::light_tasks(), true ) ) {
			return 'light task "' . $task . '" routed to cheap model "' . $cheap . '"';
		}

		return 'heavy task "' . $task . '" stays on the primary model';
	}
}
