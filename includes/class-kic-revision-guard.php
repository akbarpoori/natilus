<?php
/**
 * Content snapshot + rollback guard.
 *
 * snapshot() is called BEFORE any AI-generated content is injected into
 * a post: it prefers a real WordPress revision (when revisions are
 * enabled) and falls back to a rolling list of "_kic_backups" post meta
 * entries. rollback() restores either kind; list_backups() merges both
 * sources for UI display; wp_ajax_kic_rollback exposes the rollback to
 * the compose widget.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KIC_Revision_Guard {

	/**
	 * Singleton instance (bootstrap contract).
	 *
	 * @var KIC_Revision_Guard|null
	 */
	private static $instance = null;

	/**
	 * @return KIC_Revision_Guard
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_kic_rollback', array( __CLASS__, 'ajax_rollback' ) );
	}

	/**
	 * Take a snapshot of the post content BEFORE modification.
	 *
	 * @param int $post_id Post id.
	 * @return string Backup key ('rev:<id>' or 'bak:<uniqid>') or '' on failure.
	 */
	public static function snapshot( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post || ! isset( $post->post_type ) ) {
			return '';
		}

		// Preferred path: a native WordPress revision of the stored content.
		if ( function_exists( 'wp_revisions_enabled' ) && wp_revisions_enabled( $post ) ) {
			require_once ABSPATH . 'wp-admin/includes/revision.php';

			$revision_id = function_exists( 'wp_save_post_revision' ) ? wp_save_post_revision( $post_id ) : null;

			if ( is_numeric( $revision_id ) && (int) $revision_id > 0 ) {
				return 'rev:' . (int) $revision_id;
			}
		}

		// Fallback path: rolling post-meta backups.
		$backups = get_post_meta( $post_id, '_kic_backups', true );
		if ( ! is_array( $backups ) ) {
			$backups = array();
		}

		$key = 'bak:' . uniqid();

		array_unshift( $backups, array(
			'key'     => $key,
			'content' => (string) get_post_field( 'post_content', $post_id ),
			'time'    => time(),
		) );

		$keep = absint( kic_get_setting( 'keep_backups', 3 ) );
		if ( $keep < 1 ) {
			$keep = 1;
		}
		if ( $keep > 10 ) {
			$keep = 10;
		}

		$backups = array_slice( $backups, 0, $keep );

		update_post_meta( $post_id, '_kic_backups', $backups );

		return $key;
	}

	/**
	 * Restore a snapshot taken by snapshot().
	 *
	 * Revision content is already stored, sanitized HTML — it is passed
	 * through untouched (wp_kses_post is not re-applied on raw stored HTML).
	 * 'bak:' entries are removed after use, the others are kept.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Backup key ('rev:<id>' or 'bak:<uniqid>').
	 * @return bool
	 */
	public static function rollback( $post_id, $key ) {
		$post_id = absint( $post_id );
		$key     = is_string( $key ) ? trim( $key ) : '';

		if ( $post_id < 1 || '' === $key ) {
			return false;
		}
		if ( 1 !== preg_match( '~^[a-z]+:[a-z0-9]+$~', $key ) ) {
			return false;
		}
		if ( ! get_post( $post_id ) ) {
			return false;
		}

		if ( 0 === strpos( $key, 'rev:' ) ) {
			$revision = get_post( absint( substr( $key, 4 ) ) );
			if ( ! $revision || 'revision' !== $revision->post_type ) {
				return false;
			}
			/* The revision must belong to THIS post — never restore a
			 * revision of a different post into the target. */
			if ( (int) $revision->post_parent !== $post_id ) {
				return false;
			}

			$result = wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $revision->post_content,
			), true );

			return ( ! is_wp_error( $result ) && ! empty( $result ) );
		}

		if ( 0 === strpos( $key, 'bak:' ) ) {
			$backups = get_post_meta( $post_id, '_kic_backups', true );
			if ( ! is_array( $backups ) ) {
				return false;
			}

			$content = null;
			$kept    = array();

			foreach ( $backups as $backup ) {
				if ( is_array( $backup )
					&& isset( $backup['key'], $backup['content'] )
					&& is_string( $backup['content'] )
					&& $backup['key'] === $key ) {
					$content = $backup['content'];
				} else {
					$kept[] = $backup;
				}
			}

			if ( null === $content ) {
				return false;
			}

			update_post_meta( $post_id, '_kic_backups', $kept );

			$result = wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $content,
			), true );

			return ( ! is_wp_error( $result ) && ! empty( $result ) );
		}

		return false;
	}

	/**
	 * Merged list of available backups (revisions + meta entries), newest
	 * first, capped at 10 entries.
	 *
	 * @param int $post_id Post id.
	 * @return array[] Each item: array( 'key' => string, 'time' => int ).
	 */
	public static function list_backups( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return array();
		}

		$items = array();

		$revisions = get_children( array(
			'post_parent' => $post_id,
			'post_type'   => 'revision',
			'numberposts' => 5,
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );

		if ( is_array( $revisions ) ) {
			foreach ( $revisions as $revision ) {
				$timestamp = isset( $revision->post_date_gmt ) ? strtotime( $revision->post_date_gmt ) : false;
				$items[]   = array(
					'key'  => 'rev:' . (int) $revision->ID,
					'time' => ( false !== $timestamp && $timestamp > 0 ) ? (int) $timestamp : 0,
				);
			}
		}

		$backups = get_post_meta( $post_id, '_kic_backups', true );
		if ( is_array( $backups ) ) {
			foreach ( $backups as $backup ) {
				if ( is_array( $backup ) && isset( $backup['key'] ) ) {
					$items[] = array(
						'key'  => (string) $backup['key'],
						'time' => ( isset( $backup['time'] ) && is_numeric( $backup['time'] ) ) ? (int) $backup['time'] : 0,
					);
				}
			}
		}

		usort( $items, function ( $a, $b ) {
			$a_time = isset( $a['time'] ) ? (int) $a['time'] : 0;
			$b_time = isset( $b['time'] ) ? (int) $b['time'] : 0;
			if ( $a_time === $b_time ) {
				return 0;
			}
			return ( $a_time > $b_time ) ? -1 : 1;
		} );

		return array_slice( $items, 0, 10 );
	}

	/**
	 * AJAX rollback endpoint used by the compose widget.
	 *
	 * @return void
	 */
	public static function ajax_rollback() {
		check_ajax_referer( 'kic_ajax', 'nonce' );

		if ( ! class_exists( 'KIC_Security' )
			|| ! method_exists( 'KIC_Security', 'user_can_compose' )
			|| ! KIC_Security::user_can_compose() ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		if ( $post_id < 1 || '' === $key ) {
			wp_send_json_error( array( 'message' => 'پارامترهای نامعتبر.' ), 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'اجازه ویرایش این نوشته را ندارید.' ), 403 );
		}

		if ( self::rollback( $post_id, $key ) ) {
			wp_send_json_success( array( 'ok' => true ) );
		}

		wp_send_json_error( array( 'message' => 'نسخه پیدا نشد.' ) );
	}
}
