<?php
/**
 * AJAX orchestration for the Natilus plugin (PURE PUBLISHER).
 *
 * kic_publish_ready publishes ready-made content end-to-end: media import,
 * gallery, featured image, SEO, snapshot, injection and builder sync — all
 * side effects in one request, with zero AI calls. kic_sideload imports
 * remote media into the library on demand.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Ajax_Handler {

        /**
         * Singleton instance.
         *
         * @var KIC_Ajax_Handler|null
         */
        private static $instance = null;

        /**
         * Get the singleton instance.
         *
         * @return KIC_Ajax_Handler
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Bootstrap: register the AJAX endpoints.
         *
         * @return void
         */
        public static function init() {
                self::instance();
        }

        /**
         * Private constructor (singleton) — registers hooks.
         *
         * v2.1 — PURE PUBLISHER: only kic_publish_ready and kic_sideload are
         * registered. Natilus only publishes ready-made content; no generation
         * endpoint exists anywhere in the plugin.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_sideload', array( $this, 'ajax_sideload' ) );
                /* v1.4 — publisher mode: direct publish of READY content (no AI, no provider). */
                add_action( 'wp_ajax_kic_publish_ready', array( $this, 'ajax_publish_ready' ) );
        }

        /* ---------------------------------------------------------------------
         * Guards & shared plumbing.
         * ------------------------------------------------------------------- */

        /**
         * Common guard: nonce, capability and rate limit. Sends a JSON error
         * (and exits) on failure.
         *
         * @return void
         */
        private function guard() {
                $error = $this->stream_guard();
                if ( null !== $error ) {
                        wp_send_json_error( $error );
                }
        }

        /**
         * Publish guard: nonce + core publishing capability. Publishing
         * ready-made content is NOT an AI action, so the AI rate limiter
         * must never throttle it and authors (edit_posts) may use it.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function publish_guard() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        return 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.';
                }

                if ( ! current_user_can( 'edit_posts' ) ) {
                        return 'دسترسی ندارید.';
                }

                return null;
        }

        /**
         * Non-output variant of guard() usable before SSE headers are sent.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function stream_guard() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        return 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.';
                }

                if ( ! KIC_Security::user_can_compose() ) {
                        return 'دسترسی ندارید.';
                }

                $rate_limit = KIC_Rate_Limiter::check( get_current_user_id() );
                if ( is_wp_error( $rate_limit ) ) {
                        return $rate_limit->get_error_message();
                }

                return null;
        }

        /**
         * Record a successful AJAX interaction against the rate limiter.
         *
         * @return void
         */
        private function finish() {
                KIC_Rate_Limiter::hit( get_current_user_id() );
        }

        /**
         * Collect request parameters (supports form and JSON bodies).
         *
         * @return array
         */
        private function request_params() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- every AJAX endpoint that reads these params verifies the 'kic_ajax' nonce in guard() first; each field is sanitized at its point of use.
                $merged = array_merge( $_GET, $_POST );
                $merged = wp_unslash( $merged );

                if ( empty( $merged ) ) {
                        // Tolerate application/json bodies posted by the compose widget.
                        $raw = file_get_contents( 'php://input' );
                        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                                $decoded = json_decode( $raw, true );
                                if ( is_array( $decoded ) ) {
                                        $merged = $decoded;
                                }
                        }
                }

                return is_array( $merged ) ? $merged : array();
        }

        /**
         * Sanitize a media URL list (array, JSON string or newline separated string).
         *
         * @param mixed $raw Raw value.
         * @return string[] Max 20 esc_url_raw validated http/https URLs.
         */
        private function sanitize_media_urls( $raw ) {
                if ( is_string( $raw ) ) {
                        $decoded = json_decode( $raw, true );
                        if ( is_array( $decoded ) ) {
                                $raw = $decoded;
                        } else {
                                $raw = preg_split( '/\r\n|\r|\n/', $raw );
                        }
                }

                if ( ! is_array( $raw ) ) {
                        return array();
                }

                $urls = array();
                foreach ( $raw as $url ) {
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
                        $urls[] = $url;
                }

                $urls = array_values( array_unique( $urls ) );
                if ( count( $urls ) > 20 ) {
                        $urls = array_slice( $urls, 0, 20 );
                }

                return $urls;
        }

        /**
         * Sanitize the SEO part of a confirm payload.
         *
         * @param array $raw Raw SEO payload.
         * @return array
         */
        private function sanitize_seo( $raw ) {
                $seo = array(
                        'seo_title'       => '',
                        'seo_description' => '',
                        'focus_keyword'   => '',
                        'og_image'        => '',
                        'faq'             => array(),
                        'alt_texts'       => array(),
                );

                if ( ! is_array( $raw ) ) {
                        return $seo;
                }

                if ( isset( $raw['seo_title'] ) ) {
                        $seo['seo_title'] = sanitize_text_field( (string) $raw['seo_title'] );
                }

                if ( isset( $raw['seo_description'] ) ) {
                        $seo['seo_description'] = sanitize_text_field( (string) $raw['seo_description'] );
                }

                if ( isset( $raw['focus_keyword'] ) ) {
                        $seo['focus_keyword'] = sanitize_text_field( (string) $raw['focus_keyword'] );
                }
                if ( isset( $raw['og_image'] ) ) {
                        $seo['og_image'] = esc_url_raw( (string) $raw['og_image'] );
                }

                if ( isset( $raw['faq'] ) && is_array( $raw['faq'] ) ) {
                        foreach ( $raw['faq'] as $qa ) {
                                if ( ! is_array( $qa ) ) {
                                        continue;
                                }
                                $question = isset( $qa['q'] ) ? sanitize_text_field( (string) $qa['q'] ) : '';
                                $answer   = isset( $qa['a'] ) ? sanitize_text_field( (string) $qa['a'] ) : '';
                                if ( '' !== $question && '' !== $answer ) {
                                        $seo['faq'][] = array(
                                                'q' => $question,
                                                'a' => $answer,
                                        );
                                }
                        }
                        $seo['faq'] = array_slice( $seo['faq'], 0, 6 );
                }

                if ( isset( $raw['alt_texts'] ) && is_array( $raw['alt_texts'] ) ) {
                        foreach ( $raw['alt_texts'] as $url => $alt ) {
                                $url = esc_url_raw( (string) $url );
                                $alt = sanitize_text_field( (string) $alt );
                                if ( '' !== $url && '' !== $alt ) {
                                        $seo['alt_texts'][ $url ] = $alt;
                                }
                        }
                }

                return $seo;
        }

        /**
         * Sanitize the options part of a confirm payload.
         *
         * @param array $raw Raw options payload.
         * @return array
         */
        private function sanitize_opts( $raw ) {
                $target = isset( $raw['target'] ) ? (string) $raw['target'] : 'append';
                if ( ! in_array( $target, array( 'append', 'replace', 'draft_new' ), true ) ) {
                        $target = 'append';
                }

                $editor = isset( $raw['editor'] ) ? (string) $raw['editor'] : 'classic';
                if ( ! in_array( $editor, array( 'gutenberg', 'classic', 'elementor' ), true ) ) {
                        $editor = 'classic';
                }

                $builder_mode = 'auto';
                if ( class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'sanitize_mode' ) ) {
                        $builder_mode = KIC_Builder_Modes::sanitize_mode( isset( $raw['builder_mode'] ) ? $raw['builder_mode'] : 'auto' );
                }

                $skill_id = '';
                if ( isset( $raw['skill_id'] ) ) {
                        $skill_id = sanitize_text_field( (string) $raw['skill_id'] );
                }

                return array(
                        'gallery'  => ! empty( $raw['gallery'] ),
                        'featured' => ! empty( $raw['featured'] ),
                        'target'   => $target,
                        'editor'   => $editor,
                        'builder_mode' => $builder_mode,
                        'skill_id' => $skill_id,
                        'featured_url' => isset( $raw['featured_url'] ) ? esc_url_raw( (string) $raw['featured_url'] ) : '',
                );
        }

        /* ---------------------------------------------------------------------
         * Confirm pipeline (all side effects).
         * ------------------------------------------------------------------- */

        /**
         * Shared confirm pipeline — performs every side effect.
         *
         * @param int   $post_id Target post id.
         * @param array $payload { html, seo, media_urls, opts, layout_prompt }.
         * @return array|WP_Error
         */
        private function do_confirm( $post_id, $payload ) {
                $post_id = absint( $post_id );
                $post    = $post_id ? get_post( $post_id ) : null;

                if ( ! $post ) {
                        return new WP_Error( 'kic_post_missing', 'نوشته هدف یافت نشد.' );
                }

                $acting_user = get_current_user_id();
                if ( $acting_user && ! current_user_can( 'edit_post', $post_id ) ) {
                        return new WP_Error( 'kic_forbidden', 'اجازه ویرایش این نوشته را ندارید.' );
                }

                // The full kses pass happens right before injection (inject() re-applies it).
                $html = isset( $payload['html'] ) ? wp_check_invalid_utf8( (string) $payload['html'], true ) : '';
                if ( '' === trim( (string) $html ) ) {
                        return new WP_Error( 'kic_confirm_empty', 'محتوای HTML برای ثبت خالی است.' );
                }

                $seo        = $this->sanitize_seo( isset( $payload['seo'] ) && is_array( $payload['seo'] ) ? $payload['seo'] : array() );
                $opts       = $this->sanitize_opts( isset( $payload['opts'] ) && is_array( $payload['opts'] ) ? $payload['opts'] : array() );
                $media_urls = $this->sanitize_media_urls( isset( $payload['media_urls'] ) ? $payload['media_urls'] : '' );

                // 1) Snapshot BEFORE any modification.
                $backup_key = KIC_Revision_Guard::snapshot( $post_id );

                $items       = array();
                $ids         = array();
                $sc          = '';
                $featured_id = 0;

                // 2) Media import + URL localization.
                if ( ! empty( $media_urls ) && kic_get_setting( 'auto_sideload' ) ) {
                        $items = KIC_Media_Handler::instance()->sideload_many( $media_urls, $post_id, $seo['alt_texts'] );

                        // Replace every imported original URL with its local counterpart.
                        foreach ( $items as $item ) {
                                $source = isset( $item['source'] ) ? (string) $item['source'] : '';
                                $local  = isset( $item['url'] ) ? (string) $item['url'] : '';
                                if ( '' !== $source && '' !== $local && $source !== $local ) {
                                        $html = str_replace( $source, $local, $html );
                                }
                        }

                        $ids = array_map( 'absint', wp_list_pluck( $items, 'id' ) );
                }

                // Apply alt texts to every remaining image (sideloaded or not).
                $html = KIC_Prompt_Builder::instance()->apply_alt_texts( $html, $seo['alt_texts'] );

                // 3) Gallery placement.
                if ( $opts['gallery'] && ! empty( $ids ) ) {
                        $sc = KIC_Media_Handler::instance()->create_gallery( $ids, $post_id );
                        if ( '' !== $sc ) {
                                if ( false !== strpos( $html, '<!--kic:gallery-->' ) ) {
                                        $html = str_replace( '<!--kic:gallery-->', $sc, $html );
                                } else {
                                        $html = rtrim( $html ) . "\n\n" . $sc;
                                }
                        }
                }

                // Remove leftover pipeline markers before injection.
                $html = KIC_Content_Injector::instance()->strip_markers( $html );

                // 4) Featured image — the user-picked URL wins; otherwise first item.
                if ( $opts['featured'] && ! empty( $ids ) ) {
                        $featured_id = (int) reset( $ids );
                        if ( '' !== $opts['featured_url'] ) {
                                foreach ( $items as $item ) {
                                        $source = isset( $item['source'] ) ? (string) $item['source'] : '';
                                        $local  = isset( $item['url'] ) ? (string) $item['url'] : '';
                                        if ( '' !== $source && ( $source === $opts['featured_url'] || $local === $opts['featured_url'] ) ) {
                                                $featured_id = (int) $item['id'];
                                                break;
                                        }
                                }
                        }
                        KIC_Media_Handler::instance()->set_featured( $post_id, $featured_id );
                }

                /* OG/Twitter image for the SEO meta — the featured attachment's
                 * URL, or the first imported image when no featured was set. */
                if ( empty( $seo['og_image'] ) ) {
                        if ( $featured_id ) {
                                $seo['og_image'] = (string) wp_get_attachment_url( $featured_id );
                        } elseif ( ! empty( $items ) ) {
                                $seo['og_image'] = isset( $items[0]['url'] ) ? (string) $items[0]['url'] : '';
                        }
                }

                // 5) JSON-LD schema.
                if ( kic_get_setting( 'jsonld' ) ) {
                        KIC_SEO_Schema::register_jsonld( $post_id, $seo );
                }

                // 6) SEO meta (Rank Math / Yoast / fallback).
                KIC_SEO_Integrator::instance()->apply( $post_id, $seo );

                /*
                 * 7) Content injection — SKIPPED for elementor/wpbakery modes:
                 * those modes write the content through their native pipeline
                 * (step 8a), so a parallel post_content write would duplicate
                 * the output on the front end.
                 */
                $effective_mode = 'gutenberg';
                try {
                        if ( class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'resolve_for_post' ) ) {
                                $effective_mode = KIC_Builder_Modes::resolve_for_post( $post_id, $opts['builder_mode'] );
                        }
                } catch ( Throwable $mode_resolve_error ) {
                        $effective_mode = 'gutenberg';
                }
                $mode_routing = in_array( $effective_mode, array( 'elementor', 'wpbakery' ), true );

                $result = null;
                if ( $mode_routing ) {
                        // draft_new still needs a target post created by the injector.
                        if ( 'draft_new' === $opts['target'] ) {
                                $draft_result = KIC_Content_Injector::instance()->inject( $post_id, wp_kses_post( $html ), $opts['target'], 'classic' );
                                if ( is_wp_error( $draft_result ) ) {
                                        return $draft_result;
                                }
                                $result = $draft_result;
                        } else {
                                $result = array( 'post_id' => $post_id );
                        }
                } else {
                        $result = KIC_Content_Injector::instance()->inject( $post_id, wp_kses_post( $html ), $opts['target'], $opts['editor'] );
                        if ( is_wp_error( $result ) ) {
                                return $result;
                        }
                }

                // 8) Builder sync (Elementor / WPBakery / Slider Revolution) — never fatal.
                $sync_post_id = $post_id;
                if ( isset( $result['post_id'] ) && absint( $result['post_id'] ) ) {
                        $sync_post_id = absint( $result['post_id'] );
                }

                /*
                 * 8a) Builder MODE routing (v1.2): elementor mode builds a native
                 * Elementor document; wpbakery mode writes native VC shortcodes.
                 * Classic/gutenberg keep the standard injection above.
                 */
                $mode_applied = null;
                try {
                        if ( $mode_routing && class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'apply_to_post' ) ) {
                                $mode_applied = KIC_Builder_Modes::apply_to_post( $sync_post_id, $html, $effective_mode, $opts['target'] );
                        }
                } catch ( Throwable $mode_error ) {
                        // Builder mode failures must never abort confirmation.
                }

                try {
                        if ( class_exists( 'KIC_Builder_Integration' ) && empty( $mode_applied ) ) {
                                KIC_Builder_Integration::instance()->sync( $sync_post_id, $html );
                        }
                } catch ( Exception $e ) {
                        // Builder sync failures must not abort confirmation.
                }

                // 9) Generation stamps.
                update_post_meta( $post_id, '_kic_generated', 1 );
                update_post_meta( $post_id, '_kic_generated_content', wp_slash( $html ) );

                /*
                 * Store the complete SEO payload. The 'title' / 'description' aliases
                 * keep KIC_SEO_Integrator's standalone meta fallback working (it reads
                 * the short key shape) while the full payload stays available to the
                 * compose UI.
                 */
                $seo_stored                = $seo;
                $seo_stored['title']       = $seo['seo_title'];
                $seo_stored['description'] = $seo['seo_description'];
                update_post_meta( $post_id, '_kic_seo', $seo_stored );

                // 10) Prompt history (max 20 entries per user, guarded).
                $layout_prompt = isset( $payload['layout_prompt'] ) ? sanitize_textarea_field( (string) $payload['layout_prompt'] ) : '';
                if ( $acting_user && '' !== $layout_prompt ) {
                        $history = get_user_meta( $acting_user, 'kic_prompt_history', true );
                        if ( ! is_array( $history ) ) {
                                $history = array();
                        }
                        $history[] = array(
                                'prompt' => $layout_prompt,
                                'time'   => time(),
                        );
                        $history = array_slice( $history, -20 );
                        update_user_meta( $acting_user, 'kic_prompt_history', $history );
                }

                // 11) Response payload.
                $edit_url = '';
                if ( 'draft_new' === $opts['target'] && isset( $result['post_id'] ) ) {
                        $link = get_edit_post_link( (int) $result['post_id'], 'raw' );
                        if ( ! empty( $link ) ) {
                                $edit_url = (string) $link;
                        }
                }

                return array(
                        'backup_key'        => $backup_key,
                        'result'            => $result,
                        'gallery_shortcode' => $sc,
                        'featured_id'       => $featured_id,
                        'edit_url'          => $edit_url,
                        'builder_mode'      => $effective_mode,
                        'builder_applied'   => ( null !== $mode_applied ) ? $mode_applied['mode'] : '',
                );
        }

        /* ---------------------------------------------------------------------
         * Publisher mode (v1.4): direct publish of READY content.
         * No AI, no provider, no word counts — the content is used EXACTLY
         * as the user pasted it (plain text only gets standard paragraph
         * formatting; it is never rewritten or regenerated).
         * ------------------------------------------------------------------- */

        /**
         * AJAX: publish a ready-made article into WordPress with the full
         * publishing plumbing (media import, gallery, featured image,
         * builder structures, SEO meta) — without any generation step.
         *
         * @return void
         */
        public function ajax_publish_ready() {
                $guard_error = $this->publish_guard();
                if ( null !== $guard_error ) {
                        wp_send_json_error( $guard_error );
                }

                $params = $this->request_params();
                $result = $this->do_publish_ready( $params );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                wp_send_json_success( $result );
        }

        /**
         * Shared direct-publish pipeline.
         *
         * @param array $params Request parameters (post_id, target, title,
         *                      kicker, subtitle, status, content, builder_mode,
         *                      editor, gallery, featured, media_urls, cats,
         *                      tags, seo).
         * @return array|WP_Error
         */
        /**
         * Parse an INI size value ("8M", "512K", "-1") into bytes.
         *
         * @param mixed $value Raw ini value.
         * @return int Bytes; -1 (unlimited) is returned as PHP_INT_MAX.
         */
        private function php_size_to_bytes( $value ) {
                $value = trim( (string) $value );
                if ( '' === $value || '-1' === $value ) {
                        return PHP_INT_MAX;
                }
                $unit  = strtolower( substr( $value, -1 ) );
                $num   = (float) $value;
                switch ( $unit ) {
                        case 'g':
                                $num *= 1024;
                                // no break — intentional fallthrough to M.
                        case 'm':
                                $num *= 1024;
                                // no break — intentional fallthrough to K.
                        case 'k':
                                $num *= 1024;
                }
                return (int) $num;
        }

        private function do_publish_ready( $params ) {
                /*
                 * A request body larger than PHP's post_max_size is dropped
                 * SILENTLY by PHP — $_POST arrives empty and a long pasted
                 * article would misleadingly report «no content». Detect the
                 * oversize body up front and say what actually happened.
                 */
                $content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
                $post_max       = $this->php_size_to_bytes( ini_get( 'post_max_size' ) );
                if ( $content_length > 0 && $post_max > 0 && $content_length > $post_max ) {
                        return new WP_Error(
                                'kic_publish_too_large',
                                sprintf(
                                        'حجم متن ارسالی (%1$s) از سقف مجاز سرور (%2$s) بزرگ‌تر است — متن کامل دریافت نشد. لطفاً متن را کوتاه‌تر کنید یا از پشتیبانی هاست بخواهید post_max_size را افزایش دهد.',
                                        size_format( $content_length ),
                                        size_format( $post_max )
                                )
                        );
                }

                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $target  = isset( $params['target'] ) ? (string) $params['target'] : 'append';
                if ( ! in_array( $target, array( 'append', 'replace', 'draft_new' ), true ) ) {
                        $target = 'append';
                }

                $html = isset( $params['content'] ) ? (string) $params['content'] : '';
                $html = wp_check_invalid_utf8( $html, true );
                if ( '' === trim( $html ) ) {
                        /* Browser sent text but it never arrived → a host
                         * WAF/ModSecurity filter stripped the field. */
                        if ( isset( $params['content_len'] ) && (int) $params['content_len'] > 0 ) {
                                return new WP_Error( 'kic_publish_stripped', 'مرورگر شما ' . (int) $params['content_len'] . ' کاراکتر فرستاد اما به سرور نرسید — احتمالاً فایروال یا فیلتر امنیتی هاست متن را حذف کرده است. از پشتیبانی هاست بخواهید ModSecurity/WAF را برای admin-ajax بررسی کند.' );
                        }
                        return new WP_Error( 'kic_publish_empty', 'محتوای نوشته خالی است — چیزی برای انتشار ارسال نشده است.' );
                }

                /*
                 * Plain text (no HTML tags at all) only gets standard WordPress
                 * paragraph formatting. This is formatting, NOT rewriting: every
                 * word stays exactly as pasted.
                 */
                if ( false === strpos( $html, '<' ) ) {
                        $html = wpautop( $html );
                }

                $title  = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '';

                /*
                 * v2.2 — the full headline set: روتیتر (kicker) and زیرتیتر
                 * (subtitle) travel with the title and get the same empty =
                 * keep-current semantics. They are stored canonically and
                 * mirrored into the active theme's own fields by
                 * KIC_Theme_Sync (never fatal for publishing).
                 */
                $kicker   = isset( $params['kicker'] ) ? sanitize_text_field( (string) $params['kicker'] ) : '';
                $subtitle = isset( $params['subtitle'] ) ? sanitize_text_field( (string) $params['subtitle'] ) : '';
                $status = ( isset( $params['status'] ) && 'publish' === (string) $params['status'] ) ? 'publish' : 'draft';
                $slug   = isset( $params['slug'] ) ? sanitize_title( (string) $params['slug'] ) : '';

                // Categories/tags only apply to a freshly created post; the
                // current post keeps whatever its own WordPress sidebar says.
                $cats_raw = isset( $params['cats'] ) ? (string) $params['cats'] : '';
                $tags_raw = isset( $params['tags'] ) ? sanitize_text_field( (string) $params['tags'] ) : '';
                $cats     = array();
                foreach ( explode( ',', $cats_raw ) as $cat_id ) {
                        $cat_id = absint( trim( $cat_id ) );
                        if ( $cat_id ) {
                                $cats[] = $cat_id;
                        }
                }

                $is_new = ( 'draft_new' === $target ) || ! $post_id;

                if ( $is_new ) {
                        if ( ! current_user_can( 'edit_posts' ) ) {
                                return new WP_Error( 'kic_forbidden', 'اجازه ایجاد نوشته را ندارید.' );
                        }
                        $new_id = wp_insert_post(
                                array(
                                        'post_title'   => wp_slash( '' !== $title ? $title : 'پیش‌نویس Natilus' ),
                                        'post_status'  => $status,
                                        'post_type'    => 'post',
                                        'post_author'  => get_current_user_id(),
                                        'post_name'    => $slug,
                                ),
                                true
                        );
                        if ( is_wp_error( $new_id ) ) {
                                return $new_id;
                        }
                        $post_id = (int) $new_id;
                        $target  = 'replace';
                } else {
                        $post = get_post( $post_id );
                        if ( ! $post ) {
                                return new WP_Error( 'kic_post_missing', 'نوشته هدف یافت نشد.' );
                        }
                        if ( get_current_user_id() && ! current_user_can( 'edit_post', $post_id ) ) {
                                return new WP_Error( 'kic_forbidden', 'اجازه ویرایش این نوشته را ندارید.' );
                        }
                }

                if ( $is_new && ! empty( $cats ) ) {
                        wp_set_object_terms( $post_id, $cats, 'category', false );
                }
                if ( $is_new && '' !== trim( $tags_raw ) ) {
                        $tag_names = array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) );
                        if ( ! empty( $tag_names ) ) {
                                wp_set_object_terms( $post_id, $tag_names, 'post_tag', true );
                        }
                }

                $seo = isset( $params['seo'] ) ? $params['seo'] : array();
                if ( is_string( $seo ) ) {
                        $seo = json_decode( $seo, true );
                }
                if ( ! is_array( $seo ) ) {
                        $seo = array();
                }

                $payload = array(
                        'html'       => $html,
                        'seo'        => $seo,
                        'opts'       => array(
                                'target'       => $target,
                                'editor'       => isset( $params['editor'] ) ? (string) $params['editor'] : 'classic',
                                'builder_mode' => isset( $params['builder_mode'] ) ? (string) $params['builder_mode'] : 'auto',
                                'gallery'      => ! empty( $params['gallery'] ),
                                'featured'     => ! empty( $params['featured'] ),
                                'featured_url' => isset( $params['featured_url'] ) ? esc_url_raw( (string) $params['featured_url'] ) : '',
                        ),
                        'media_urls' => isset( $params['media_urls'] ) ? $params['media_urls'] : '',
                );

                $result = $this->do_confirm( $post_id, $payload );
                if ( is_wp_error( $result ) ) {
                        if ( $is_new && $post_id ) {
                                /* Never leave an orphan empty draft behind a failed publish. */
                                wp_delete_post( $post_id, true );
                        }
                        return $result;
                }

                /*
                 * Existing-post fields: title (only when provided) and status
                 * (publish immediately or keep as draft). Applied AFTER the
                 * content pipeline so the response reports the REAL status.
                 */
                $applied_status = null;
                if ( ! $is_new ) {
                        $update = array( 'ID' => $post_id );
                        if ( '' !== $title ) {
                                $update['post_title'] = wp_slash( $title );
                        }
                        if ( '' !== $slug ) {
                                $update['post_name'] = $slug;
                        }
                        if ( 'publish' === $status ) {
                                $update['post_status'] = 'publish';
                                $applied_status       = 'publish';
                        }
                        if ( count( $update ) > 1 ) {
                                wp_update_post( $update, true );
                        }
                }

                /*
                 * v2.2 — headline set: روتیتر/زیرتیتر are stored canonically
                 * AND mirrored into whatever fields the active theme uses
                 * (auto-detected site-structure sync). Guarded so a missing
                 * module can never break publishing.
                 */
                $theme_sync_applied = array();
                if ( class_exists( 'KIC_Theme_Sync' ) && method_exists( 'KIC_Theme_Sync', 'apply_fields' ) ) {
                        $theme_sync_applied = KIC_Theme_Sync::apply_fields(
                                $post_id,
                                array(
                                        'kicker'   => $kicker,
                                        'subtitle' => $subtitle,
                                )
                        );
                }

                if ( is_array( $result ) ) {
                        $result['post_id']  = $post_id;
                        $result['edit_url'] = get_edit_post_link( $post_id, 'raw' );
                        $result['view_url'] = get_permalink( $post_id );
                        $result['status']   = ( null !== $applied_status ) ? 'publish' : get_post_status( $post_id );
                        $result['theme_sync'] = is_array( $theme_sync_applied ) ? $theme_sync_applied : array();
                }

                return $result;
        }

        /* ---------------------------------------------------------------------
         * Direct sideload endpoint.
         * ------------------------------------------------------------------- */

        /**
         * AJAX endpoint: kic_sideload.
         *
         * @return void
         */
        public function ajax_sideload() {
                $this->guard();

                $params  = $this->request_params();
                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

                if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
                        wp_send_json_error( 'دسترسی به این نوشته ندارید.' );
                }

                $urls = array();
                if ( isset( $params['urls'] ) ) {
                        $urls = $this->sanitize_media_urls( $params['urls'] );
                } elseif ( isset( $params['url'] ) ) {
                        // Convenience: a single URL parameter.
                        $urls = $this->sanitize_media_urls( (array) $params['url'] );
                }

                if ( empty( $urls ) ) {
                        wp_send_json_error( 'هیچ نشانی رسانه‌ای معتبری ارسال نشده است.' );
                }

                $items = KIC_Media_Handler::instance()->sideload_many( $urls, $post_id );

                $this->finish();
                wp_send_json_success( array( 'items' => $items ) );
        }

}
