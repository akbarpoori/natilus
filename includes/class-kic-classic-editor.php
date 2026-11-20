<?php
/**
 * Natilus — Classic Editor (TinyMCE) wiring.
 *
 * Adds a "Natilus" button to the second TinyMCE toolbar row. The button
 * scrolls the author to the compose widget under the editor and focuses it
 * via the "kic:focus" event dispatched by admin/js/kic-tinymce.js.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KIC_Classic_Editor {

	/**
	 * Singleton instance.
	 *
	 * @var KIC_Classic_Editor|null
	 */
	private static $instance = null;

	/**
	 * @return KIC_Classic_Editor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap the module (called from the plugin bootstrap).
	 *
	 * @return KIC_Classic_Editor
	 */
	public static function init() {
		return self::instance();
	}

	/**
	 * Register TinyMCE filters.
	 */
	private function __construct() {
		add_filter( 'mce_external_plugins', array( $this, 'tinymce_plugin' ) );
		add_filter( 'mce_buttons_2', array( $this, 'tinymce_button' ) );
	}

	/**
	 * Register the external TinyMCE plugin script.
	 *
	 * Only added when the current screen is the classic (non-block) editor
	 * for the post — otherwise the Gutenberg screen would carry a dead asset.
	 *
	 * @param array $plugin_array Existing external plugins (name => URL).
	 * @return array
	 */
	public function tinymce_plugin( $plugin_array ) {
		if ( ! $this->is_classic_post_screen() ) {
			return $plugin_array;
		}

		if ( ! is_array( $plugin_array ) ) {
			$plugin_array = array();
		}

		$plugin_array['kic'] = KIC_URL . 'admin/js/kic-tinymce.js';

		return $plugin_array;
	}

	/**
	 * Append the toolbar button (after a separator) to TinyMCE row 2.
	 *
	 * @param array $buttons Existing row-2 button slugs.
	 * @return array
	 */
	public function tinymce_button( $buttons ) {
		if ( ! $this->is_classic_post_screen() ) {
			return $buttons;
		}

		if ( ! is_array( $buttons ) ) {
			$buttons = array();
		}

		$buttons[] = '|';
		$buttons[] = 'kic_ai';

		return $buttons;
	}

	/**
	 * Cheap check: admin context and the current global post is not authored
	 * in the block editor (i.e. the classic TinyMCE toolbar is in play).
	 *
	 * @return bool
	 */
	private function is_classic_post_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		global $post;
		if ( ! $post instanceof WP_Post ) {
			return true; // No post context (secondary editors): assume classic.
		}

		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return false; // Block editor post — TinyMCE row is not in play.
		}

		return true;
	}
}
