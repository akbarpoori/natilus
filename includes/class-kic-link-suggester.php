<?php
/**
 * Natilus — user-approved link & title suggestions.
 *
 * STRICT POLICY (mirrors the plugin philosophy): the AI never generates or
 * inserts content on its own. The single endpoint here is SUGGESTION-ONLY:
 *
 *   kic_suggest_links     AI proposes internal links to EXISTING site posts;
 *                         URLs are whitelisted against the real candidate list,
 *                         so the model can never invent a URL.
 *
 * The endpoint never touches post content: the compose widget shows the
 * suggestions and only an explicit user click inserts a link (client-side,
 * into the editor, unsaved until the user saves).
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Link_Suggester {

        /**
         * Singleton instance.
         *
         * @var KIC_Link_Suggester|null
         */
        private static $instance = null;

        /**
         * Maximum internal candidates handed to the model.
         */
        const MAX_CANDIDATES = 40;

        /**
         * Maximum suggestions returned per call.
         */
        const MAX_SUGGESTIONS = 8;

        /**
         * RSS feeds kept in settings (admin-managed).
         */
        const MAX_FEEDS = 10;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Link_Suggester
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
         * @return KIC_Link_Suggester
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers the AJAX endpoints.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_suggest_links', array( $this, 'ajax_suggest_links' ) );
        }

        /* ---------------------------------------------------------------------
         * Guards & shared plumbing.
         * ------------------------------------------------------------------- */

        /**
         * Nonce + capability guard (shared by every endpoint here).
         *
         * @return string|null Persian error message, or null when the caller may proceed.
         */
        private function guard() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        return 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.';
                }
                if ( ! ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) && KIC_Security::user_can_compose() ) ) {
                        return 'دسترسی ندارید.';
                }
                return null;
        }

        /**
         * Rate-limit guard for the billable (AI) endpoints.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function rate_guard() {
                if ( class_exists( 'KIC_Rate_Limiter' ) && method_exists( 'KIC_Rate_Limiter', 'check' ) ) {
                        $limit = KIC_Rate_Limiter::check( get_current_user_id() );
                        if ( is_wp_error( $limit ) ) {
                                return (string) $limit->get_error_message();
                        }
                }
                return null;
        }

        /**
         * Count a successful AI interaction against the rate limiter.
         *
         * @return void
         */
        private function finish() {
                if ( class_exists( 'KIC_Rate_Limiter' ) && method_exists( 'KIC_Rate_Limiter', 'hit' ) ) {
                        KIC_Rate_Limiter::hit( get_current_user_id() );
                }
        }

        /**
         * Budget gate for the AI endpoints.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function budget_error() {
                if ( class_exists( 'KIC_Budget_Guard' ) && method_exists( 'KIC_Budget_Guard', 'check' ) ) {
                        $budget = KIC_Budget_Guard::check();
                        if ( is_wp_error( $budget ) ) {
                                return (string) $budget->get_error_message();
                        }
                }
                return null;
        }

        /**
         * Model args for a light suggestion call: cheap-model routing when
         * enabled, otherwise the stored default; a small output cap.
         *
         * @param string $task       Router task slug ('short' family).
         * @param int    $max_tokens Output cap.
         * @return array
         */
        private function light_args( $task, $max_tokens ) {
                $args = array( 'max_tokens' => max( 120, min( 2000, (int) $max_tokens ) ) );

                $model = '';
                if ( class_exists( 'KIC_Router' ) && method_exists( 'KIC_Router', 'model_for' ) ) {
                        $model = KIC_Router::model_for( $task, (string) kic_get_setting( 'model' ) );
                } else {
                        $model = (string) kic_get_setting( 'model' );
                }
                if ( '' !== $model ) {
                        $args['model'] = $model;
                }

                return $args;
        }

        /**
         * Request parameters (form or JSON body), mirroring the ajax handler.
         *
         * @return array
         */
        private function request_params() {
                $params = isset( $_POST ) && is_array( $_POST ) ? $_POST : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every caller runs verify_ajax()/check_ajax_referer() first; each field is sanitized at its point of use
                if ( empty( $params ) ) {
                        $raw = file_get_contents( 'php://input' );
                        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                                $decoded = json_decode( $raw, true );
                                if ( is_array( $decoded ) ) {
                                        $params = $decoded;
                                }
                        }
                }
                return is_array( $params ) ? $params : array();
        }

        /**
         * Editor content sent by the client, sanitised and trimmed to a digest.
         *
         * @param array  $params Request bag.
         * @param int    $limit  Character limit.
         * @return string
         */
        private function content_digest( $params, $limit = 6000 ) {
                $content = '';
                if ( isset( $params['content'] ) ) {
                        $content = (string) $params['content'];
                }
                if ( function_exists( 'wp_check_invalid_utf8' ) ) {
                        $content = wp_check_invalid_utf8( $content, true );
                }
                $content = wp_kses_post( $content );
                $content = trim( $content );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $content ) > $limit ) {
                        $content = mb_substr( $content, 0, $limit ) . "\n…";
                } elseif ( ! function_exists( 'mb_strlen' ) && strlen( $content ) > $limit ) {
                        $content = substr( $content, 0, $limit ) . "\n…";
                }
                return $content;
        }

        /* ---------------------------------------------------------------------
         * JSON answer parsing (defensive: fences, prose wrappers, partial JSON).
         * ------------------------------------------------------------------- */

        /**
         * Extract the first JSON array from a model answer.
         *
         * @param string $content Model output.
         * @return array Decoded array (possibly empty).
         */
        private function parse_json_array( $content ) {
                $content = trim( (string) $content );

                // Strip code fences.
                $content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
                $content = preg_replace( '/\s*```$/i', '', $content );
                $content = trim( (string) $content );

                $start = strpos( $content, '[' );
                $end   = strrpos( $content, ']' );
                if ( false === $start || false === $end || $end <= $start ) {
                        return array();
                }

                $slice   = substr( $content, $start, $end - $start + 1 );
                $decoded = json_decode( $slice, true );
                if ( is_array( $decoded ) ) {
                        return $decoded;
                }

                // Fallback: salvage individual {...} objects.
                $items = array();
                if ( preg_match_all( '/\{[^{}]*\}/s', $slice, $matches ) ) {
                        foreach ( $matches[0] as $candidate ) {
                                $item = json_decode( $candidate, true );
                                if ( is_array( $item ) ) {
                                        $items[] = $item;
                                }
                        }
                }
                return $items;
        }

        /* ---------------------------------------------------------------------
         * Internal candidates.
         * ------------------------------------------------------------------- */

        /**
         * Public post types worth suggesting links to.
         *
         * @return string[]
         */
        private function candidate_post_types() {
                $types = array( 'post', 'page' );
                if ( function_exists( 'post_type_exists' ) && post_type_exists( 'product' ) ) {
                        $types[] = 'product';
                }
                return $types;
        }

        /**
         * Internal link candidates: newest published posts (optionally narrowed
         * by a search term), excluding the current post.
         *
         * @param string $search  Optional title/content search term.
         * @param int    $exclude Post id to exclude (the current post).
         * @return array[] [{id,title,url,excerpt,type}]
         */
        private function internal_candidates( $search = '', $exclude = 0 ) {
                $args = array(
                        'post_type'      => $this->candidate_post_types(),
                        'post_status'    => 'publish',
                        'posts_per_page' => self::MAX_CANDIDATES,
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                        'no_found_rows'  => true,
                );
                if ( '' !== trim( (string) $search ) ) {
                        $args['s'] = trim( (string) $search );
                }
                if ( (int) $exclude > 0 ) {
                        $args['post__not_in'] = array( (int) $exclude );
                }

                $posts = function_exists( 'get_posts' ) ? get_posts( $args ) : array();
                if ( ! is_array( $posts ) ) {
                        return array();
                }

                $out = array();
                foreach ( $posts as $post ) {
                        $id = 0;
                        if ( is_object( $post ) ) {
                                $id = isset( $post->ID ) ? (int) $post->ID : 0;
                        } elseif ( is_numeric( $post ) ) {
                                $id = (int) $post;
                        }
                        if ( $id < 1 ) {
                                continue;
                        }

                        $title = '';
                        if ( is_object( $post ) && isset( $post->post_title ) ) {
                                $title = (string) $post->post_title;
                        } elseif ( function_exists( 'get_the_title' ) ) {
                                $title = (string) get_the_title( $id );
                        }

                        $excerpt = '';
                        if ( is_object( $post ) && isset( $post->post_excerpt ) && '' !== trim( (string) $post->post_excerpt ) ) {
                                $excerpt = (string) $post->post_excerpt;
                        } elseif ( is_object( $post ) && isset( $post->post_content ) ) {
                                $excerpt = (string) $post->post_content;
                        }
                        $excerpt = wp_strip_all_tags( $excerpt );
                        if ( function_exists( 'mb_strlen' ) && mb_strlen( $excerpt ) > 180 ) {
                                $excerpt = mb_substr( $excerpt, 0, 180 ) . '…';
                        }

                        $type = 'post';
                        if ( is_object( $post ) && isset( $post->post_type ) ) {
                                $type = (string) $post->post_type;
                        }

                        $out[] = array(
                                'id'      => $id,
                                'title'   => $title,
                                'url'     => (string) get_permalink( $id ),
                                'excerpt' => $excerpt,
                                'type'    => $type,
                        );
                }
                return $out;
        }

        /* ---------------------------------------------------------------------
         * RSS feeds.
         * ------------------------------------------------------------------- */

        /**
         * Configured feed URLs (settings, one per line, admin-managed).
         *
         * @return string[] Validated feed URLs (max self::MAX_FEEDS).
         */
        public static function feed_urls() {
                $raw = kic_get_setting( 'rss_feeds', array() );
                $raw = is_array( $raw ) ? $raw : array();
                $urls = array();
                foreach ( $raw as $url ) {
                        $url = esc_url_raw( trim( (string) $url ) );
                        if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
                                $urls[] = $url;
                        }
                        if ( count( $urls ) >= self::MAX_FEEDS ) {
                                break;
                        }
                }
                return $urls;
        }

        /* ---------------------------------------------------------------------
         * Suggestion shaping (whitelist enforcement).
         * ------------------------------------------------------------------- */

        /**
         * Normalise + whitelist model suggestions against real candidate URLs.
         *
         * @param string   $content    Model output.
         * @param array[]  $candidates [{url,title,...}] the only allowed URLs.
         * @param string[] $extra_keys Extra per-item keys to keep (e.g. 'source').
         * @return array[] Validated suggestions.
         */
        private function shape_suggestions( $content, $candidates, $extra_keys = array() ) {
                $allowed = array();
                foreach ( $candidates as $candidate ) {
                        if ( ! empty( $candidate['url'] ) ) {
                                $allowed[ (string) $candidate['url'] ] = $candidate;
                        }
                }

                $raw = $this->parse_json_array( $content );
                $out = array();
                $seen_urls = array();
                $seen_anchors = array();

                foreach ( $raw as $item ) {
                        if ( is_string( $item ) ) {
                                // Shorthand form: plain string anchors are meaningless without a URL.
                                continue;
                        }
                        if ( ! is_array( $item ) ) {
                                continue;
                        }

                        $url = isset( $item['url'] ) ? esc_url_raw( trim( (string) $item['url'] ) ) : '';
                        if ( '' === $url || ! isset( $allowed[ $url ] ) ) {
                                continue; // URL not in the whitelist — the model invented it; drop it.
                        }
                        if ( isset( $seen_urls[ $url ] ) ) {
                                continue;
                        }

                        $anchor = isset( $item['anchor'] ) ? trim( (string) $item['anchor'] ) : '';
                        if ( '' === $anchor ) {
                                $anchor = (string) $allowed[ $url ]['title'];
                        }
                        if ( function_exists( 'mb_strlen' ) && mb_strlen( $anchor ) > 120 ) {
                                $anchor = mb_substr( $anchor, 0, 120 );
                        }
                        $anchor = wp_strip_all_tags( $anchor );
                        if ( '' === trim( $anchor ) ) {
                                continue;
                        }
                        $anchor_key = mb_strtolower( $anchor );
                        if ( isset( $seen_anchors[ $anchor_key ] ) ) {
                                continue;
                        }

                        $reason = isset( $item['reason'] ) ? wp_strip_all_tags( trim( (string) $item['reason'] ) ) : '';
                        if ( function_exists( 'mb_strlen' ) && mb_strlen( $reason ) > 200 ) {
                                $reason = mb_substr( $reason, 0, 200 );
                        }

                        $entry = array(
                                'anchor' => $anchor,
                                'url'    => $url,
                                'title'  => isset( $allowed[ $url ]['title'] ) ? (string) $allowed[ $url ]['title'] : '',
                                'reason' => $reason,
                        );
                        foreach ( $extra_keys as $key ) {
                                if ( isset( $item[ $key ] ) ) {
                                        $entry[ $key ] = wp_strip_all_tags( trim( (string) $item[ $key ] ) );
                                }
                        }

                        $out[] = $entry;
                        $seen_urls[ $url ] = true;
                        $seen_anchors[ $anchor_key ] = true;

                        if ( count( $out ) >= self::MAX_SUGGESTIONS ) {
                                break;
                        }
                }

                return $out;
        }

        /* ---------------------------------------------------------------------
         * Endpoints.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_suggest_links — AI internal-link suggestions (read-only).
         *
         * @return void
         */
        public function ajax_suggest_links() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }
                $rate = $this->rate_guard();
                if ( null !== $rate ) {
                        wp_send_json_error( $rate );
                }
                $budget = $this->budget_error();
                if ( null !== $budget ) {
                        wp_send_json_error( $budget );
                }

                $params = $this->request_params();

                $post_id  = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $focus    = isset( $params['focus'] ) ? sanitize_text_field( (string) $params['focus'] ) : '';
                $digest   = $this->content_digest( $params );

                if ( '' === $digest && $post_id < 1 ) {
                        wp_send_json_error( 'محتوایی برای تحلیل ارسال نشده است — ابتدا نوشته‌ای بنویسید یا انتخاب کنید.' );
                }

                // Fall back to the stored post content when the client sends none.
                if ( '' === $digest && $post_id > 0 && function_exists( 'get_post_field' ) ) {
                        $digest = wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) );
                        if ( function_exists( 'mb_strlen' ) && mb_strlen( $digest ) > 6000 ) {
                                $digest = mb_substr( $digest, 0, 6000 );
                        }
                }

                $title = '';
                if ( $post_id > 0 && function_exists( 'get_the_title' ) ) {
                        $title = (string) get_the_title( $post_id );
                } elseif ( isset( $params['title'] ) ) {
                        $title = sanitize_text_field( (string) $params['title'] );
                }

                $candidates = $this->internal_candidates( $focus, $post_id );
                if ( empty( $candidates ) ) {
                        wp_send_json_error( 'پست منتشرشده‌ای برای پیشنهاد لینک داخلی وجود ندارد.' );
                }

                $candidate_lines = array();
                foreach ( $candidates as $candidate ) {
                        $candidate_lines[] = '- ' . $candidate['title'] . ' | ' . $candidate['url'] . ' | ' . $candidate['excerpt'];
                }

                $system = 'You are an internal-linking assistant for a WordPress site. You ONLY suggest links to pages from the provided candidate list; you never invent URLs. Choose the 3-8 most genuinely relevant candidates for the article, pick natural anchor text (2-6 words) that ALREADY appears (or could naturally appear) inside the article, and avoid anchors that are already inside an existing <a> link in the article. Answer ONLY with a JSON array of objects: {"anchor": string, "url": string, "reason": string} where every url is copied EXACTLY from the candidate list.';

                $user = 'ARTICLE TITLE: ' . ( '' !== $title ? $title : '(untitled)' ) . "\n\n"
                        . "ARTICLE HTML DIGEST:\n" . $digest . "\n\n"
                        . 'CANDIDATE PAGES (title | url | excerpt):' . "\n" . implode( "\n", $candidate_lines );

                $result = KIC_API_Client::instance()->chat(
                        array(
                                array(
                                        'role'    => 'system',
                                        'content' => $system,
                                ),
                                array(
                                        'role'    => 'user',
                                        'content' => $user,
                                ),
                        ),
                        $this->light_args( 'short', 900 ),
                        array( 'action' => 'suggest_links' )
                );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                $suggestions = $this->shape_suggestions( isset( $result['content'] ) ? $result['content'] : '', $candidates );

                $this->finish();

                wp_send_json_success(
                        array(
                                'suggestions' => $suggestions,
                                'candidates'  => count( $candidates ),
                                'usage'       => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(),
                                'model'       => isset( $result['model'] ) ? (string) $result['model'] : '',
                                'read_only'   => true,
                        )
                );
        }
}
