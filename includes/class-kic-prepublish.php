<?php
/**
 * Natilus — pre-publish checklist & link opportunities.
 *
 * STRICT POLICY: everything here is READ-ONLY diagnostics. The checks look
 * at the current post (featured image, category, slug collisions, SEO meta,
 * image alt texts, internal links) and REPORT. Nothing is modified, nothing
 * is auto-fixed — the user fixes items in the normal editor UI. This is a
 * publishing-quality gate, not a content generator.
 *
 * The same module computes the "internal-link opportunity report": published
 * posts whose content contains no internal links at all (read-only list for
 * the Reports screen — suggestions still require the user-approved flow in
 * the compose widget).
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Prepublish {

        /**
         * Singleton instance.
         *
         * @var KIC_Prepublish|null
         */
        private static $instance = null;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Prepublish
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
         * @return KIC_Prepublish
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers the read-only AJAX endpoint.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_prepublish_check', array( $this, 'ajax_run_checks' ) );
        }

        /* ---------------------------------------------------------------------
         * Checklist.
         * ------------------------------------------------------------------- */

        /**
         * Run every technical pre-publish check for one post.
         *
         * @param int $post_id Post id (0 = nothing to check).
         * @return array[] [{id, label, status: ok|warn|na, detail}]
         */
        public static function run_checks( $post_id ) {
                $post_id = absint( $post_id );
                $post    = $post_id ? get_post( $post_id ) : null;

                $out = array();

                if ( ! $post ) {
                        return $out;
                }

                /* 1. Featured image. */
                $out[] = self::check_featured( $post_id );

                /* 2. Category (hierarchical public post types only). */
                $out[] = self::check_category( $post );

                /* 3. Slug sanity + duplicates. */
                $out[] = self::check_slug( $post );

                /* 4. SEO meta description (Rank Math / Yoast / plugin fallback). */
                $out[] = self::check_seo_description( $post_id );

                /* 5. Image alt texts. */
                $out[] = self::check_alt_texts( (string) $post->post_content );

                /* 6. Internal links. */
                $out[] = self::check_internal_links( $post );

                return $out;
        }

        /**
         * Featured image check.
         *
         * @param int $post_id Post id.
         * @return array
         */
        private static function check_featured( $post_id ) {
                $ok = function_exists( 'has_post_thumbnail' ) && has_post_thumbnail( $post_id );
                return array(
                        'id'     => 'featured',
                        'label'  => kic_t( 'تصویر شاخص' ),
                        'status' => $ok ? 'ok' : 'warn',
                        'detail' => $ok ? '' : kic_t( 'تصویر شاخص ندارد.' ),
                );
        }

        /**
         * Category check (post/product and other hierarchical types).
         *
         * @param WP_Post $post Post.
         * @return array
         */
        private static function check_category( $post ) {
                $type = $post->post_type;

                if ( 'page' === $type ) {
                        return array(
                                'id'     => 'category',
                                'label'  => kic_t( 'دسته‌بندی' ),
                                'status' => 'na',
                                'detail' => kic_t( 'این مورد بررسی نشد (نوع نوشته).' ),
                        );
                }

                $taxonomy = 'category';
                if ( 'product' === $type && function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_cat' ) ) {
                        $taxonomy = 'product_cat';
                }

                $terms = function_exists( 'get_the_terms' ) ? get_the_terms( $post->ID, $taxonomy ) : false;
                $ok    = is_array( $terms ) && ! empty( $terms );

                return array(
                        'id'     => 'category',
                        'label'  => ( 'product_cat' === $taxonomy ) ? kic_t( 'دسته‌بندی' ) : kic_t( 'دسته‌بندی' ),
                        'status' => $ok ? 'ok' : 'warn',
                        'detail' => $ok ? '' : kic_t( 'دسته‌بندی انتخاب نشده است.' ),
                );
        }

        /**
         * Slug check: non-empty + not duplicated by another published post.
         *
         * @param WP_Post $post Post.
         * @return array
         */
        private static function check_slug( $post ) {
                $slug = trim( (string) $post->post_name );

                $ok = ( '' !== $slug );

                if ( $ok ) {
                        $dupes = get_posts(
                                array(
                                        'post_type'      => $post->post_type,
                                        'post_status'    => array( 'publish', 'future', 'private' ),
                                        'name'           => $slug,
                                        'posts_per_page' => 5,
                                        'fields'         => 'ids',
                                        'no_found_rows'  => true,
                                )
                        );
                        if ( is_array( $dupes ) ) {
                                foreach ( $dupes as $dupe_id ) {
                                        if ( (int) $dupe_id !== (int) $post->ID ) {
                                                $ok = false;
                                                break;
                                        }
                                }
                        }
                }

                return array(
                        'id'     => 'slug',
                        'label'  => kic_t( 'نامک (slug)' ),
                        'status' => $ok ? 'ok' : 'warn',
                        'detail' => $ok ? $slug : kic_t( 'نامک تکراری یا خالی است.' ),
                );
        }

        /**
         * SEO meta description check (Rank Math → Yoast → plugin fallback).
         *
         * @param int $post_id Post id.
         * @return array
         */
        private static function check_seo_description( $post_id ) {
                $description = '';

                foreach ( array( 'rank_math_description', '_yoast_wpseo_metadesc' ) as $key ) {
                        $value = (string) get_post_meta( $post_id, $key, true );
                        if ( '' !== trim( $value ) ) {
                                $description = $value;
                                break;
                        }
                }

                if ( '' === trim( $description ) ) {
                        $stored = get_post_meta( $post_id, '_kic_seo', true );
                        if ( is_array( $stored ) && isset( $stored['description'] ) ) {
                                $description = (string) $stored['description'];
                        }
                }

                $ok = '' !== trim( $description );

                return array(
                        'id'     => 'seo_description',
                        'label'  => kic_t( 'توضیح متای سئو' ),
                        'status' => $ok ? 'ok' : 'warn',
                        'detail' => $ok ? '' : kic_t( 'توضیح متای سئو خالی است.' ),
                );
        }

        /**
         * Alt-text check: count <img> tags with an empty/missing alt attribute.
         *
         * @param string $content Post content (HTML).
         * @return array
         */
        private static function check_alt_texts( $content ) {
                $images = self::img_tags( $content );
                $missing = 0;

                foreach ( $images as $tag ) {
                        if ( ! preg_match( '/alt\s*=\s*(["\'])(.*?)\1/is', $tag, $match ) ) {
                                $missing++;
                                continue;
                        }
                        if ( '' === trim( $match[2] ) ) {
                                $missing++;
                        }
                }

                if ( empty( $images ) ) {
                        return array(
                                'id'     => 'alt_texts',
                                'label'  => kic_t( 'متن جایگزین تصاویر' ),
                                'status' => 'ok',
                                'detail' => '',
                        );
                }

                return array(
                        'id'     => 'alt_texts',
                        'label'  => kic_t( 'متن جایگزین تصاویر' ),
                        'status' => ( 0 === $missing ) ? 'ok' : 'warn',
                        'detail' => ( 0 === $missing )
                                ? ''
                                : kic_t( 'برخی تصاویر متن جایگزین ندارند.' ) . ' (' . number_format_i18n( $missing ) . '/' . number_format_i18n( count( $images ) ) . ')',
                );
        }

        /**
         * Internal-link check: at least one <a> pointing at this site (not the
         * post's own permalink or a pure anchor).
         *
         * @param WP_Post $post Post.
         * @return array
         */
        private static function check_internal_links( $post ) {
                $home     = function_exists( 'home_url' ) ? home_url() : '';
                $own      = function_exists( 'get_permalink' ) ? (string) get_permalink( $post->ID ) : '';
                $content  = (string) $post->post_content;
                $internal = 0;

                if ( '' !== $home && preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/is', $content, $matches ) ) {
                        foreach ( $matches[2] as $href ) {
                                $href = trim( (string) $href );
                                if ( '' === $href || '#' === $href[0] ) {
                                        continue;
                                }
                                if ( 0 === strpos( $href, $home ) || 0 === strpos( $href, '/' ) ) {
                                        if ( '' !== $own && rtrim( $href, '/' ) === rtrim( $own, '/' ) ) {
                                                continue; // Self link.
                                        }
                                        $internal++;
                                }
                        }
                }

                return array(
                        'id'     => 'internal_links',
                        'label'  => kic_t( 'لینک داخلی' ),
                        'status' => ( $internal > 0 ) ? 'ok' : 'warn',
                        'detail' => ( $internal > 0 )
                                ? number_format_i18n( $internal )
                                : kic_t( 'هیچ لینک داخلی به مقالات دیگر نیست.' ),
                );
        }

        /**
         * All <img ...> tags of an HTML string.
         *
         * @param string $content HTML.
         * @return string[]
         */
        private static function img_tags( $content ) {
                if ( ! is_string( $content ) || '' === $content ) {
                        return array();
                }
                if ( preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
                        return $matches[0];
                }
                return array();
        }

        /* ---------------------------------------------------------------------
         * Link-opportunity report (read-only).
         * ------------------------------------------------------------------- */

        /**
         * Published posts whose content contains no internal link at all.
         * Read-only list for the Reports screen; suggestions themselves still go
         * through the user-approved compose-widget flow.
         *
         * @param int $limit Maximum rows (default 25).
         * @return array[] [{id,title,edit_url,date,type}]
         */
        public static function link_opportunities( $limit = 25 ) {
                $limit = max( 1, min( 200, (int) $limit ) );

                $posts = get_posts(
                        array(
                                'post_type'      => array( 'post', 'page' ),
                                'post_status'    => 'publish',
                                'posts_per_page' => 200,
                                'orderby'        => 'date',
                                'order'          => 'DESC',
                                'no_found_rows'  => true,
                        )
                );

                if ( ! is_array( $posts ) ) {
                        return array();
                }

                $home = function_exists( 'home_url' ) ? home_url() : '';
                $out  = array();

                foreach ( $posts as $post ) {
                        if ( count( $out ) >= $limit ) {
                                break;
                        }

                        $post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
                        if ( $post_id < 1 ) {
                                continue;
                        }

                        $content = (string) $post->post_content;

                        /* Quick reject: no <a> at all. */
                        if ( false === stripos( $content, '<a ' ) && false === stripos( $content, '<a>' ) ) {
                                $out[] = self::opportunity_row( $post, $home );
                                continue;
                        }

                        /* Otherwise: look for at least one site link. */
                        $has_internal = self::content_has_internal_link( $content, $home, $post_id );
                        if ( ! $has_internal ) {
                                $out[] = self::opportunity_row( $post, $home );
                        }
                }

                return $out;
        }

        /**
         * Whether the HTML content links to this site (self links excluded).
         *
         * @param string $content HTML.
         * @param string $home    Home URL.
         * @param int    $post_id Current post id (self permalink excluded).
         * @return bool
         */
        private static function content_has_internal_link( $content, $home, $post_id ) {
                if ( ! is_string( $content ) || '' === $content ) {
                        return false;
                }
                $own = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';

                if ( ! preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/is', $content, $matches ) ) {
                        return false;
                }

                foreach ( $matches[2] as $href ) {
                        $href = trim( (string) $href );
                        if ( '' === $href || '#' === $href[0] ) {
                                continue;
                        }
                        $internal = ( '' !== $home && 0 === strpos( $href, $home ) ) || '/' === $href[0];
                        if ( ! $internal ) {
                                continue;
                        }
                        if ( '' !== $own && rtrim( $href, '/' ) === rtrim( $own, '/' ) ) {
                                continue; // Self link.
                        }
                        return true;
                }
                return false;
        }

        /**
         * Row shape for the opportunity report.
         *
         * @param WP_Post $post Post.
         * @param string  $home Home URL (unused, kept for shape symmetry).
         * @return array
         */
        private static function opportunity_row( $post, $home = '' ) {
                $post_id = (int) $post->ID;
                return array(
                        'id'       => $post_id,
                        'title'    => ( '' !== trim( (string) $post->post_title ) ) ? (string) $post->post_title : kic_t( 'عنوان' ) . ' —',
                        'edit_url' => function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
                        'date'     => (string) $post->post_date,
                        'type'     => (string) $post->post_type,
                );
        }

        /* ---------------------------------------------------------------------
         * AJAX.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_prepublish_check — run the read-only checks for one post.
         * Nothing is written; the response is a diagnostics list.
         *
         * @return void
         */
        public function ajax_run_checks() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        wp_send_json_error( kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' ) );
                }
                if ( ! ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) && KIC_Security::user_can_compose() ) ) {
                        wp_send_json_error( kic_t( 'دسترسی ندارید.' ) );
                }

                $params = $_POST;
                if ( empty( $params ) ) {
                        $raw = file_get_contents( 'php://input' );
                        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                                $decoded = json_decode( $raw, true );
                                if ( is_array( $decoded ) ) {
                                        $params = $decoded;
                                }
                        }
                }

                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                if ( $post_id < 1 ) {
                        wp_send_json_error( kic_t( 'نوشته‌ای برای بررسی انتخاب نشده است — ابتدا محتوا را ذخیره کنید.' ) );
                }
                if ( ! current_user_can( 'edit_post', $post_id ) ) {
                        wp_send_json_error( kic_t( 'دسترسی به این نوشته ندارید.' ) );
                }

                /* Client-sent content overrides the stored copy for the checks that
                 * only read HTML (alt texts / links), so unsaved edits count too. */
                $checks = self::run_checks( $post_id );

                $content = '';
                if ( isset( $params['content'] ) ) {
                        $content = (string) $params['content'];
                }
                if ( '' !== trim( $content ) ) {
                        $alt = self::check_alt_texts( $content );
                        foreach ( $checks as $index => $check ) {
                                if ( 'alt_texts' === $check['id'] ) {
                                        $checks[ $index ] = $alt;
                                }
                        }
                }

                $warnings = 0;
                foreach ( $checks as $check ) {
                        if ( 'warn' === $check['status'] ) {
                                $warnings++;
                        }
                }

                wp_send_json_success(
                        array(
                                'checks'   => $checks,
                                'warnings' => $warnings,
                                'read_only' => true,
                        )
                );
        }
}
