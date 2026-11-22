<?php
/**
 * Media pipeline for the Natilus plugin.
 *
 * Remote sideloading (max 20 URLs per call, http/https only), base64
 * image imports, alt text storage, gallery shortcodes and featured image
 * assignment.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Media_Handler {

        /**
         * Singleton instance.
         *
         * @var KIC_Media_Handler|null
         */
        private static $instance = null;

        /**
         * Whether the wp-admin file/media/image includes were loaded once.
         *
         * @var bool
         */
        private static $includes_loaded = false;

        /**
         * Failures collected by the most recent sideload_many() run.
         *
         * @var int
         */
        private $last_failures = 0;

        /**
         * Get the singleton instance.
         *
         * @return KIC_Media_Handler
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Bootstrap: register the image quality filter.
         *
         * @return void
         */
        public static function init() {
                self::instance();
        }

        /**
         * Private constructor (singleton). The JPEG quality filter is armed
         * ONLY around our own sideload/import calls (see sideload_many / import_b64)
         * so the site-wide theme image compression is never altered.
         */
        private function __construct() {
        }

        /**
         * Arm the scoped quality filter for one sideload/import run.
         *
         * @return void
         */
        private function arm_quality_filter() {
                add_filter( 'jpeg_quality', array( $this, 'jpeg_quality' ) );
        }

        /**
         * Disarm the scoped quality filter after a run.
         *
         * @return void
         */
        private function disarm_quality_filter() {
                remove_filter( 'jpeg_quality', array( $this, 'jpeg_quality' ) );
        }

        /**
         * Slightly sharper default JPEG compression for generated media.
         *
         * @param int $quality Current quality (10-100).
         * @return int
         */
        public function jpeg_quality( $quality ) {
                return 82;
        }

        /**
         * Number of failures collected by the most recent sideload_many() run.
         *
         * @return int
         */
        public function get_last_failures() {
                return (int) $this->last_failures;
        }

        /* ---------------------------------------------------------------------
         * Sideload / import.
         * ------------------------------------------------------------------- */

        /**
         * Sideload a batch of remote URLs into the WordPress media library.
         *
         * Failures are skipped silently and counted; per call at most 20 URLs
         * are processed and only http/https URLs are accepted.
         *
         * @param string[] $urls    Remote URLs (array, or newline separated string).
         * @param int      $post_id Attach the media to this post (0 = unattached).
         * @param array    $alt_map url => alt text.
         * @return array Items: array( 'id' => int, 'url' => string, 'source' => string ).
         */
        public function sideload_many( $urls, $post_id = 0, $alt_map = array() ) {
                $items             = array();
                $this->last_failures = 0;

                if ( is_string( $urls ) ) {
                        $urls = preg_split( '/\r\n|\r|\n/', $urls );
                }
                if ( ! is_array( $urls ) ) {
                        $urls = array();
                }

                $post_id = absint( $post_id );

                // Validate URLs, cap at 20 per call.
                $clean = array();
                foreach ( $urls as $url ) {
                        if ( ! is_string( $url ) ) {
                                continue;
                        }
                        $url = esc_url_raw( trim( $url ) );
                        if ( '' === $url ) {
                                continue;
                        }
                        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
                        if ( 'http' !== $scheme && 'https' !== $scheme ) {
                                continue;
                        }
                        $clean[] = $url;
                        if ( count( $clean ) >= 20 ) {
                                break;
                        }
                }

                if ( empty( $clean ) ) {
                        return $items;
                }

                /* Scoped quality filter: applies ONLY to this batch.
                 * v2.6 — hard time budget: 20 URLs × up-to-120s downloads can
                 * outlive max_execution_time and die mid-batch, leaving the
                 * post half-imported. Stop starting new downloads once the
                 * shared deadline is reached and report partials. */
                $deadline = microtime( true ) + $this->batch_budget_seconds();

                $this->arm_quality_filter();
                try {
                        foreach ( $clean as $url ) {
                                if ( microtime( true ) >= $deadline ) {
                                        $this->last_failures += count( $clean ) - count( $items ) - $this->last_failures;
                                        break;
                                }
                                if ( ! $this->looks_remote_image( $url ) ) {
                                        $this->last_failures++;
                                        continue;
                                }
                                $attachment_id = $this->sideload_single( $url, $post_id );
                                if ( is_wp_error( $attachment_id ) ) {
                                        $this->last_failures++;
                                        continue;
                                }

                                // Apply the alt text when the map has an entry for this URL.
                                if ( is_array( $alt_map ) ) {
                                        $alt = '';
                                        if ( isset( $alt_map[ $url ] ) ) {
                                                $alt = $alt_map[ $url ];
                                        } elseif ( isset( $alt_map[ urldecode( $url ) ] ) ) {
                                                $alt = $alt_map[ urldecode( $url ) ];
                                        }
                                        if ( is_string( $alt ) && '' !== trim( $alt ) ) {
                                                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
                                        }
                                }

                                $items[] = array(
                                        'id'     => (int) $attachment_id,
                                        'url'    => (string) wp_get_attachment_url( $attachment_id ),
                                        'source' => $url,
                                );
                        }
                } finally {
                        $this->disarm_quality_filter();
                }

                return $items;
        }

        /**
         * Per-batch download budget in seconds — the lower of 60s and
         * 80% of max_execution_time so the loop always finishes cleanly.
         *
         * @return int
         */
        private function batch_budget_seconds() {
                $max = (int) ini_get( 'max_execution_time' );
                if ( $max <= 0 ) {
                        return 60; // CLI / unlimited: keep a sane bound anyway.
                }
                return max( 10, min( 60, (int) floor( $max * 0.8 ) ) );
        }

        /**
         * Cheap HEAD pre-check: confirm the URL really is an image before
         * spending download time on it. Skips when the server does not
         * answer HEAD (older CDNs) rather than breaking the import.
         *
         * @param string $url Validated http/https URL.
         * @return bool True when the remote looks like an image or HEAD is unavailable.
         */
        private function looks_remote_image( $url ) {
                $path = (string) wp_parse_url( $url, PHP_URL_PATH );
                $ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
                if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg' ), true ) ) {
                        return true;
                }
                $head = wp_safe_remote_head( $url, array( 'timeout' => 8, 'redirection' => 3 ) );
                if ( is_wp_error( $head ) ) {
                        return true; // HEAD unsupported — let the download decide.
                }
                $type = strtolower( (string) wp_remote_retrieve_header( $head, 'content-type' ) );
                if ( '' !== $type && 0 !== strpos( $type, 'image/' ) ) {
                        return false;
                }
                $length = (int) wp_remote_retrieve_header( $head, 'content-length' );
                return $length <= 25 * 1024 * 1024; // 25MB sanity cap.
        }

        /**
         * Sideload one remote file into the media library.
         *
         * @param string $url     Remote URL (validated).
         * @param int    $post_id Parent post id.
         * @return int|WP_Error Attachment id or error.
         */
        private function sideload_single( $url, $post_id ) {
                $this->load_admin_includes();

                $tmp = download_url( $url, 120 );
                if ( is_wp_error( $tmp ) ) {
                        return $tmp;
                }

                // Derive a safe filename from the URL path.
                $raw_name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
                $name     = sanitize_file_name( $raw_name );
                if ( '' === $name || '.' === $name ) {
                        $name = 'natilus-media-' . uniqid() . '.jpg';
                }

                $file = array(
                        'name'     => $name,
                        'tmp_name' => $tmp,
                );

                $dest = wp_handle_sideload( $file, array( 'test_form' => false ) );
                if ( is_wp_error( $dest ) || ! is_array( $dest ) || empty( $dest['file'] ) ) {
                        wp_delete_file( $tmp );
                        return is_wp_error( $dest ) ? $dest : new WP_Error( 'kic_sideload_failed', 'ذخیره فایل دریافتی ناموفق بود.' );
                }

                $attachment_id = $this->insert_attachment_from_file( $dest['file'], $post_id );
                if ( is_wp_error( $attachment_id ) ) {
                        wp_delete_file( $dest['file'] );
                }

                return $attachment_id;
        }

        /**
         * Import a base64 encoded image into the media library.
         *
         * @param string $b64      Raw base64 payload (a data URI prefix is tolerated).
         * @param int    $post_id  Parent post id.
         * @param string $filename Suggested filename.
         * @return int|WP_Error Attachment id or error.
         */
        public function import_b64( $b64, $post_id = 0, $filename = '' ) {
                $this->load_admin_includes();

                $b64 = (string) $b64;

                // Tolerate data URIs: data:image/png;base64,....
                if ( 0 === strpos( $b64, 'data:' ) ) {
                        $comma = strpos( $b64, ',' );
                        if ( false !== $comma ) {
                                $b64 = substr( $b64, $comma + 1 );
                        }
                }

                $b64   = preg_replace( '/\s+/', '', $b64 );
                $clean = base64_decode( (string) $b64, true );
                if ( false === $clean || '' === $clean ) {
                        return new WP_Error( 'kic_b64_invalid', 'داده تصویر (base64) نامعتبر است.' );
                }

                $filename = (string) $filename;
                if ( '' === trim( $filename ) ) {
                        $filename = 'kubit-image-' . uniqid() . '.png';
                }
                $filename = sanitize_file_name( $filename );
                if ( '' === $filename ) {
                        $filename = 'kubit-image-' . uniqid() . '.png';
                }
                /* Whitelist raster image extensions — wp_upload_bits() performs no
                 * mime validation of its own, so an attacker-controlled filename
                 * must never reach the uploads dir with an executable/svg type. */
                $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
                if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp' ), true ) ) {
                        $filename = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ) . '.png';
                        if ( '.png' === $filename ) {
                                $filename = 'kubit-image-' . uniqid() . '.png';
                        }
                }

                $upload = wp_upload_bits( $filename, null, $clean );
                if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
                        $message = ( is_array( $upload ) && isset( $upload['error'] ) ) ? $upload['error'] : 'ذخیره فایل تصویر ناموفق بود.';
                        return new WP_Error( 'kic_upload_failed', $message );
                }

                $this->arm_quality_filter();
                try {
                        $attachment_id = $this->insert_attachment_from_file( $upload['file'], absint( $post_id ) );
                } finally {
                        $this->disarm_quality_filter();
                }
                if ( is_wp_error( $attachment_id ) ) {
                        wp_delete_file( $upload['file'] );
                }

                return $attachment_id;
        }

        /**
         * Register an already-uploaded file in the media library and generate
         * its attachment metadata.
         *
         * @param string $dest    Absolute file path inside the uploads dir.
         * @param int    $post_id Parent post id.
         * @return int|WP_Error
         */
        private function insert_attachment_from_file( $dest, $post_id ) {
                $this->load_admin_includes();

                $filename = wp_basename( $dest );
                $check    = wp_check_filetype( $filename, wp_get_mime_types() );
                $mime     = ( is_array( $check ) && ! empty( $check['type'] ) ) ? $check['type'] : '';

                if ( '' === $mime && function_exists( 'wp_get_image_mime' ) ) {
                        $sniffed = wp_get_image_mime( $dest );
                        if ( is_string( $sniffed ) && '' !== $sniffed ) {
                                $mime = $sniffed;
                        }
                }
                if ( '' === $mime ) {
                        $mime = 'image/jpeg';
                }

                $title = sanitize_title( (string) pathinfo( $filename, PATHINFO_FILENAME ) );
                if ( '' === $title ) {
                        $title = 'kubit-media';
                }

                $attachment_id = wp_insert_attachment(
                        array(
                                'post_mime_type' => $mime,
                                'post_title'     => $title,
                                'post_status'    => 'inherit',
                        ),
                        $dest,
                        $post_id
                );

                if ( is_wp_error( $attachment_id ) ) {
                        return $attachment_id;
                }
                if ( ! $attachment_id ) {
                        return new WP_Error( 'kic_attachment_failed', 'ثبت رسانه در کتابخانه انجام نشد.' );
                }

                // image.php is required for metadata generation — loaded by load_admin_includes().
                $metadata = wp_generate_attachment_metadata( $attachment_id, $dest );
                wp_update_attachment_metadata( $attachment_id, $metadata );

                return (int) $attachment_id;
        }

        /**
         * Load the wp-admin file / media / image includes exactly once.
         *
         * @return void
         */
        private function load_admin_includes() {
                if ( self::$includes_loaded ) {
                        return;
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                self::$includes_loaded = true;
        }

        /* ---------------------------------------------------------------------
         * Galleries / featured image / alt texts.
         * ------------------------------------------------------------------- */

        /**
         * Build a core [gallery] shortcode for the given attachment ids.
         *
         * @param int[] $attachment_ids Attachment ids.
         * @param int   $post_id        Parent post id (kept for signature symmetry).
         * @return string Shortcode (empty string when no valid ids).
         */
        public function create_gallery( $attachment_ids, $post_id = 0 ) {
                $ids = array();

                if ( is_array( $attachment_ids ) ) {
                        foreach ( $attachment_ids as $id ) {
                                $id = absint( $id );
                                if ( $id && get_post( $id ) ) {
                                        $ids[] = $id;
                                }
                        }
                }

                $ids = array_values( array_unique( $ids ) );
                if ( empty( $ids ) ) {
                        return '';
                }

                $ids_csv = implode( ',', $ids );

                return '[gallery ids="' . esc_attr( $ids_csv ) . '" columns="3" link="file" size="medium"]';
        }

        /**
         * Assign the featured image of a post.
         *
         * @param int $post_id       Post id.
         * @param int $attachment_id Attachment id.
         * @return bool
         */
        public function set_featured( $post_id, $attachment_id ) {
                $post_id       = absint( $post_id );
                $attachment_id  = absint( $attachment_id );

                if ( ! $post_id || ! $attachment_id ) {
                        return false;
                }

                return (bool) set_post_thumbnail( $post_id, $attachment_id );
        }

        /**
         * Persist alt texts for a set of attachments.
         *
         * @param array $map attachment_id => alt text.
         * @return void
         */
        public function set_alts( $map ) {
                if ( ! is_array( $map ) ) {
                        return;
                }

                foreach ( $map as $attachment_id => $alt ) {
                        $attachment_id = absint( $attachment_id );
                        if ( ! $attachment_id ) {
                                continue;
                        }
                        update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
                }
        }
}
