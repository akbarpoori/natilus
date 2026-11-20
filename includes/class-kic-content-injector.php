<?php
/**
 * Content injection for the Natilus plugin.
 *
 * Performs the final content side effects of publishing: append /
 * replace / draft-copy injection, gallery shortcodes, featured images
 * and marker cleanup.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Content_Injector {

        /**
         * Singleton instance.
         *
         * @var KIC_Content_Injector|null
         */
        private static $instance = null;

        /**
         * Get the singleton instance.
         *
         * @return KIC_Content_Injector
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Bootstrap (module contract symmetry — the injector runs on demand).
         *
         * @return void
         */
        public static function init() {
                self::instance();
        }

        /**
         * Private constructor (singleton).
         */
        private function __construct() {
        }

        /* ---------------------------------------------------------------------
         * Injection.
         * ------------------------------------------------------------------- */

        /**
         * Inject ready-made HTML into a post.
         *
         * The HTML always passes wp_kses_post before it touches the database.
         * v2.1 — the Gutenberg append handshake (client_insert) was removed:
         * the server writes post_content directly for every editor, which
         * guarantees pasted content is never lost. Gutenberg renders plain
         * HTML as a Classic block, still exactly as pasted.
         *
         * @param int    $post_id Target post id.
         * @param string $html    Ready-made HTML payload.
         * @param string $target  'append' | 'replace' | 'draft_new'.
         * @param string $editor  'gutenberg' | 'classic'.
         *
         * @return array|WP_Error { 'post_id' => int } or error.
         */
        public function inject( $post_id, $html, $target, $editor ) {
                $post_id = absint( $post_id );
                $post    = $post_id ? get_post( $post_id ) : null;

                if ( ! $post ) {
                        return new WP_Error( 'kic_post_missing', 'نوشته هدف یافت نشد.' );
                }

                $html = wp_kses_post( (string) $html );

                $target = is_string( $target ) ? $target : '';
                if ( ! in_array( $target, array( 'append', 'replace', 'draft_new' ), true ) ) {
                        $target = 'append';
                }

                $editor = is_string( $editor ) ? $editor : '';
                if ( ! in_array( $editor, array( 'gutenberg', 'classic' ), true ) ) {
                        $editor = 'classic';
                }

                // Draft copy: create a new draft, leave the original untouched.
                if ( 'draft_new' === $target ) {
                        $author = get_current_user_id();
                        if ( ! $author ) {
                                $author = (int) $post->post_author;
                        }

                        $title = (string) $post->post_title;
                        if ( '' === trim( $title ) ) {
                                $title = 'پیش‌نویس Natilus';
                        } else {
                                $title = $title . ' — پیش‌نویس Natilus';
                        }

                        $new_id = wp_insert_post(
                                array(
                                        'post_title'   => wp_slash( $title ),
                                        'post_status'  => 'draft',
                                        'post_type'    => $post->post_type,
                                        'post_content' => wp_slash( $html ),
                                        'post_author'  => $author,
                                ),
                                true
                        );

                        if ( is_wp_error( $new_id ) ) {
                                return $new_id;
                        }

                        return array(
                                'post_id' => (int) $new_id,
                        );
                }

                // Replace the whole post content.
                if ( 'replace' === $target ) {
                        $updated = wp_update_post(
                                array(
                                        'ID'           => $post_id,
                                        'post_content' => wp_slash( $html ),
                                ),
                                true
                        );

                        if ( is_wp_error( $updated ) ) {
                                return $updated;
                        }

                        return array(
                                'post_id' => $post_id,
                        );
                }

                /* Append — server-side for BOTH editors (content is never
                 * entrusted to a client-side handshake). */
                $existing = (string) $post->post_content;
                if ( '' === trim( $existing ) ) {
                        $merged = $html;
                } else {
                        $merged = rtrim( $existing ) . "\n\n" . $html;
                }

                $updated = wp_update_post(
                        array(
                                'ID'           => $post_id,
                                'post_content' => wp_slash( $merged ),
                        ),
                        true
                );

                if ( is_wp_error( $updated ) ) {
                        return $updated;
                }

                return array(
                        'post_id' => $post_id,
                );
        }

        /* ---------------------------------------------------------------------
         * Helpers.
         * ------------------------------------------------------------------- */

        /**
         * Build a [gallery] shortcode for the given attachment ids.
         *
         * @param int[] $ids Attachment ids.
         * @return string
         */
        public function create_gallery_shortcode( $ids ) {
                return KIC_Media_Handler::instance()->create_gallery( $ids );
        }

        /**
         * Assign the featured image of a post.
         *
         * @param int $post_id Post id.
         * @param int $att_id  Attachment id.
         * @return bool
         */
        public function set_featured( $post_id, $att_id ) {
                return KIC_Media_Handler::instance()->set_featured( $post_id, $att_id );
        }

        /**
         * Remove leftover pipeline markers from an HTML payload.
         *
         * @param string $html HTML payload.
         * @return string
         */
        public function strip_markers( $html ) {
                $html = (string) $html;

                // Leftover gallery marker.
                $cleaned = preg_replace( '/<!--\s*kic:gallery\s*-->/i', '', $html );
                if ( is_string( $cleaned ) ) {
                        $html = $cleaned;
                }

                // SEO block remnants.
                $cleaned = preg_replace( '/<!--\s*kic:seo[\s\S]*?-->/i', '', $html );
                if ( is_string( $cleaned ) ) {
                        $html = $cleaned;
                }

                return trim( $html );
        }
}
