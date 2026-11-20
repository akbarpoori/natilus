<?php
/**
 * Natilus — term & slug suggestions (user-approved).
 *
 * STRICT POLICY (same as the link suggester): the AI NEVER creates anything
 * and never writes to the post by itself.
 *
 *   kic_suggest_terms  the model may ONLY pick from the site's EXISTING
 *                      categories & tags (ids validated against the real
 *                      term tables). New terms are impossible; ids that do
 *                      not exist are dropped.
 *   kic_suggest_slug   the model proposes an English slug derived from the
 *                      CURRENT (user-approved) title; server-side it goes
 *                      through sanitize_title() and is de-duplicated, so the
 *                      applied value is always a legal WordPress slug.
 *   kic_apply_terms    the ONLY write endpoint — and it runs exclusively on
 *                      an explicit user click ("اعمال") for ids the user
 *                      checked in the list.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Term_Suggester {

        /**
         * Singleton instance.
         *
         * @var KIC_Term_Suggester|null
         */
        private static $instance = null;

        /**
         * Maximum terms handed to the model per taxonomy.
         */
        const MAX_TERMS = 60;

        /**
         * Maximum suggestions returned per call.
         */
        const MAX_SUGGESTIONS = 12;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Term_Suggester
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
         * @return KIC_Term_Suggester
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers the AJAX endpoints.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_suggest_terms', array( $this, 'ajax_suggest_terms' ) );
                add_action( 'wp_ajax_kic_suggest_slug', array( $this, 'ajax_suggest_slug' ) );
                add_action( 'wp_ajax_kic_apply_terms', array( $this, 'ajax_apply_terms' ) );
        }

        /* ---------------------------------------------------------------------
         * Guards & shared plumbing (mirrors KIC_Link_Suggester).
         * ------------------------------------------------------------------- */

        /**
         * Nonce + capability guard.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function guard() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        return kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' );
                }
                if ( ! ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) && KIC_Security::user_can_compose() ) ) {
                        return kic_t( 'دسترسی ندارید.' );
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
         * Light model args (same recipe as the link suggester).
         *
         * @param string $task       Router task slug.
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
         * Request parameters (form or JSON body).
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
         * Content digest for the model call.
         *
         * @param array  $params Request bag.
         * @param int    $limit  Character limit.
         * @return string
         */
        private function content_digest( $params, $limit = 5000 ) {
                $content = isset( $params['content'] ) ? (string) $params['content'] : '';
                $content = wp_kses_post( $content );
                $content = trim( $content );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $content ) > $limit ) {
                        $content = mb_substr( $content, 0, $limit ) . "\n…";
                } elseif ( ! function_exists( 'mb_strlen' ) && strlen( $content ) > $limit ) {
                        $content = substr( $content, 0, $limit ) . "\n…";
                }
                return $content;
        }

        /**
         * Extract the first JSON array from a model answer (defensive).
         *
         * @param string $content Model output.
         * @return array
         */
        private function parse_json_array( $content ) {
                $content = trim( (string) $content );
                $content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
                $content = preg_replace( '/\s*```$/i', '', $content );
                $content = trim( (string) $content );

                $start = strpos( $content, '[' );
                $end   = strrpos( $content, ']' );
                if ( false === $start || false === $end || $end <= $start ) {
                        return array();
                }

                $decoded = json_decode( substr( $content, $start, $end - $start + 1 ), true );
                return is_array( $decoded ) ? $decoded : array();
        }

        /**
         * Extract a plain string answer (slug mode).
         *
         * @param string $content Model output.
         * @return string
         */
        private function parse_plain( $content ) {
                $content = trim( (string) $content );
                $content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
                $content = preg_replace( '/\s*```$/i', '', $content );
                return trim( (string) $content );
        }

        /* ---------------------------------------------------------------------
         * Term candidates.
         * ------------------------------------------------------------------- */

        /**
         * Existing terms of a taxonomy, as id => name rows for the model + UI.
         *
         * @param string $taxonomy Taxonomy slug.
         * @param bool   $hierarchical Whether the taxonomy is hierarchical.
         * @return array[] [{id,name,taxonomy}]
         */
        private function existing_terms( $taxonomy, $hierarchical ) {
                if ( ! function_exists( 'get_terms' ) || ! taxonomy_exists( $taxonomy ) ) {
                        return array();
                }

                $terms = get_terms(
                        array(
                                'taxonomy'   => $taxonomy,
                                'hide_empty' => false,
                                'number'     => self::MAX_TERMS,
                                'orderby'    => 'count',
                                'order'      => 'DESC',
                        )
                );

                if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                        return array();
                }

                $out = array();
                foreach ( $terms as $term ) {
                        if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) {
                                continue;
                        }
                        $out[] = array(
                                'id'        => (int) $term->term_id,
                                'name'      => (string) $term->name,
                                'taxonomy'  => $taxonomy,
                                'hierarchical' => $hierarchical,
                        );
                }
                return $out;
        }

        /**
         * Taxonomies relevant for the post type being edited.
         *
         * @param string $post_type Post type slug.
         * @return array[] [{taxonomy, hierarchical}]
         */
        private function taxonomies_for( $post_type ) {
                $post_type = sanitize_key( (string) $post_type );

                if ( 'product' === $post_type ) {
                        return array(
                                array( 'taxonomy' => 'product_cat', 'hierarchical' => true ),
                        );
                }
                if ( 'page' === $post_type ) {
                        return array();
                }

                return array(
                        array( 'taxonomy' => 'category', 'hierarchical' => true ),
                        array( 'taxonomy' => 'post_tag', 'hierarchical' => false ),
                );
        }

        /* ---------------------------------------------------------------------
         * Endpoints.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_suggest_terms — AI picks ONLY from existing categories/tags.
         * Read-only; nothing is assigned to the post.
         *
         * @return void
         */
        public function ajax_suggest_terms() {
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

                $params  = $this->request_params();
                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $post    = $post_id ? get_post( $post_id ) : null;

                if ( ! $post ) {
                        wp_send_json_error( kic_t( 'نوشته‌ای برای تحلیل انتخاب نشده است — ابتدا محتوا را ذخیره کنید.' ) );
                }

                $digest = $this->content_digest( $params );
                if ( '' === $digest ) {
                        $digest = wp_strip_all_tags( (string) $post->post_content );
                        if ( function_exists( 'mb_strlen' ) && mb_strlen( $digest ) > 5000 ) {
                                $digest = mb_substr( $digest, 0, 5000 );
                        }
                }
                if ( '' === trim( $digest ) ) {
                        wp_send_json_error( kic_t( 'محتوایی برای تحلیل ارسال نشده است — ابتدا نوشته‌ای بنویسید یا انتخاب کنید.' ) );
                }

                $title = ( '' !== trim( (string) $post->post_title ) )
                        ? (string) $post->post_title
                        : ( isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '' );

                /* Already-assigned ids (marked in the UI so the user can spot them). */
                $assigned = $this->assigned_term_ids( $post );

                $taxonomies = $this->taxonomies_for( $post->post_type );
                $candidates = array();
                foreach ( $taxonomies as $spec ) {
                        foreach ( $this->existing_terms( $spec['taxonomy'], $spec['hierarchical'] ) as $term ) {
                                if ( isset( $assigned[ $term['id'] ] ) ) {
                                        continue; // Already on the post — nothing to suggest.
                                }
                                $candidates[] = $term;
                        }
                }

                if ( empty( $candidates ) ) {
                        wp_send_json_error( kic_t( 'دسته یا برچسب موجود دیگری برای پیشنهاد نیست.' ) );
                }

                $candidate_lines = array();
                foreach ( $candidates as $term ) {
                        $candidate_lines[] = '- ' . $term['id'] . ' | ' . $term['taxonomy'] . ' | ' . $term['name'];
                }

                $system = 'You are a taxonomy assistant for a WordPress site. You may ONLY choose from the EXISTING terms in the provided list (id | taxonomy | name). You can NEVER invent, rename or merge terms — unknown ids are discarded automatically. Pick the 2-8 most genuinely relevant terms for the article (fewer is better than a stretch). Answer ONLY with a JSON array of ids: [123, 456] — numbers, copied exactly from the list.';

                $user = 'ARTICLE TITLE: ' . ( '' !== $title ? $title : '(untitled)' ) . "\n\n"
                        . "ARTICLE TEXT DIGEST:\n" . $digest . "\n\n"
                        . 'EXISTING TERMS (id | taxonomy | name):' . "\n" . implode( "\n", $candidate_lines );

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
                        $this->light_args( 'short', 300 ),
                        array( 'action' => 'suggest_terms' )
                );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                /* Whitelist enforcement: only real candidate ids survive. */
                $allowed = array();
                foreach ( $candidates as $term ) {
                        $allowed[ (int) $term['id'] ] = $term;
                }

                $raw        = $this->parse_json_array( isset( $result['content'] ) ? $result['content'] : '' );
                $suggested  = array();
                $seen       = array();

                foreach ( $raw as $item ) {
                        $id = 0;
                        if ( is_numeric( $item ) ) {
                                $id = (int) $item;
                        } elseif ( is_array( $item ) && isset( $item['id'] ) ) {
                                $id = absint( $item['id'] );
                        }
                        if ( $id < 1 || ! isset( $allowed[ $id ] ) || isset( $seen[ $id ] ) ) {
                                continue; // Invented id — dropped.
                        }
                        $seen[ $id ]     = true;
                        $suggested[]     = array(
                                'id'        => $id,
                                'name'      => $allowed[ $id ]['name'],
                                'taxonomy'  => $allowed[ $id ]['taxonomy'],
                        );
                        if ( count( $suggested ) >= self::MAX_SUGGESTIONS ) {
                                break;
                        }
                }

                $this->finish();

                wp_send_json_success(
                        array(
                                'suggestions' => $suggested,
                                'candidates'  => count( $candidates ),
                                'usage'       => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(),
                                'model'       => isset( $result['model'] ) ? (string) $result['model'] : '',
                                'read_only'   => true,
                        )
                );
        }

        /**
         * AJAX: kic_suggest_slug — English slug from the CURRENT (user-approved)
         * title. Sanitized server-side; uniqueness is checked and reported.
         * Read-only — the slug is not written.
         *
         * @return void
         */
        public function ajax_suggest_slug() {
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

                $params  = $this->request_params();
                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $post    = $post_id ? get_post( $post_id ) : null;

                $title = '';
                if ( $post && '' !== trim( (string) $post->post_title ) ) {
                        $title = (string) $post->post_title;
                } elseif ( isset( $params['title'] ) ) {
                        $title = sanitize_text_field( (string) $params['title'] );
                }

                if ( '' === trim( $title ) ) {
                        wp_send_json_error( kic_t( 'ابتدا تیتر نوشته را تعیین کنید (نامک از تیتر ساخته می‌شود).' ) );
                }

                $system = 'You convert an article title into a clean English URL slug. Rules: 3-6 words, all lowercase, words separated by single hyphens, only a-z/0-9/hyphen, no stopwords (a/the/of/in/and), keep the title meaning. Answer ONLY with the slug — no quotes, no explanation.';

                $user = 'TITLE: ' . $title;

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
                        $this->light_args( 'short', 60 ),
                        array( 'action' => 'suggest_slug' )
                );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                /* Server-side legality: sanitize_title enforces a real WP slug. */
                $slug = sanitize_title( $this->parse_plain( isset( $result['content'] ) ? $result['content'] : '' ) );
                $slug = trim( trim( $slug, '-' ) );

                if ( '' === $slug ) {
                        wp_send_json_error( kic_t( 'مدل نامک معتبری برنگرداند — دوباره تلاش کنید.' ) );
                }

                /* Uniqueness check against other posts of the same type
                 * (the current post's own row is excluded below). */
                $taken   = false;
                $current = $post ? (string) $post->post_name : '';
                $dupes   = get_posts(
                        array(
                                'post_type'      => $post ? $post->post_type : 'post',
                                'post_status'    => array( 'publish', 'future', 'private', 'draft' ),
                                'name'           => $slug,
                                'posts_per_page' => 5,
                                'fields'         => 'ids',
                                'no_found_rows'  => true,
                        )
                );
                if ( is_array( $dupes ) ) {
                        foreach ( $dupes as $dupe_id ) {
                                if ( ! $post || (int) $dupe_id !== (int) $post->ID ) {
                                        $taken = true;
                                        break;
                                }
                        }
                }

                $this->finish();

                wp_send_json_success(
                        array(
                                'slug'      => $slug,
                                'taken'     => $taken,
                                'current'   => $current,
                                'usage'     => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(),
                                'model'     => isset( $result['model'] ) ? (string) $result['model'] : '',
                                'read_only' => true,
                        )
                );
        }

        /**
         * AJAX: kic_apply_terms — the ONLY write endpoint, executed strictly on a
         * user click. Assigns user-checked EXISTING term ids to the post (and/or
         * sets the slug when the user picked a suggested slug). Term ids are
         * validated against existing terms again — nothing new can be created.
         *
         * @return void
         */
        public function ajax_apply_terms() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $params  = $this->request_params();
                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $post    = $post_id ? get_post( $post_id ) : null;

                if ( ! $post ) {
                        wp_send_json_error( kic_t( 'نوشته‌ای برای اعمال انتخاب نشده است.' ) );
                }

                /* v2.1 — write endpoint: the acting user must be able to edit THIS post. */
                if ( get_current_user_id() && ! current_user_can( 'edit_post', $post_id ) ) {
                        wp_send_json_error( kic_t( 'اجازه ویرایش این نوشته را ندارید.' ) );
                }

                /* --- Terms (append mode: existing assignments stay). --- */
                $requested = array();
                if ( isset( $params['term_ids'] ) && is_array( $params['term_ids'] ) ) {
                        $requested = $params['term_ids'];
                }

                $applied = array();
                if ( ! empty( $requested ) ) {
                        $taxonomies = $this->taxonomies_for( $post->post_type );

                        /* Group validated ids per taxonomy (ids must exist right now). */
                        $per_taxonomy = array();
                        foreach ( $taxonomies as $spec ) {
                                $per_taxonomy[ $spec['taxonomy'] ] = array();
                        }

                        foreach ( $requested as $raw_id ) {
                                $id = absint( $raw_id );
                                if ( $id < 1 ) {
                                        continue;
                                }
                                $term = function_exists( 'get_term' ) ? get_term( $id ) : null;
                                if ( ! is_object( $term ) || is_wp_error( $term ) || ! isset( $term->taxonomy ) ) {
                                        continue; // Invented id — dropped.
                                }
                                if ( ! isset( $per_taxonomy[ $term->taxonomy ] ) ) {
                                        continue; // Not a taxonomy of this post type.
                                }
                                $per_taxonomy[ $term->taxonomy ][] = (int) $term->term_id;
                                $applied[] = array(
                                        'id'   => (int) $term->term_id,
                                        'name' => (string) $term->name,
                                        'taxonomy' => (string) $term->taxonomy,
                                );
                        }

                        foreach ( $per_taxonomy as $taxonomy => $ids ) {
                                if ( empty( $ids ) ) {
                                        continue;
                                }
                                $existing = wp_get_object_terms( $post_id, $taxonomy );
                                $existing_ids = array();
                                if ( ! is_wp_error( $existing ) && is_array( $existing ) ) {
                                        foreach ( $existing as $term ) {
                                                $existing_ids[] = (int) $term->term_id;
                                        }
                                }
                                wp_set_object_terms( $post_id, array_values( array_unique( array_merge( $existing_ids, $ids ) ) ), $taxonomy, false );
                        }
                }

                /* --- Slug (optional, user-clicked). --- */
                $slug_applied = '';
                if ( isset( $params['slug'] ) ) {
                        $slug = sanitize_title( (string) $params['slug'] );
                        $slug = trim( trim( $slug, '-' ) );
                        if ( '' !== $slug ) {
                                /* WP unique-ifies on save; we only seed the value. */
                                $updated = wp_update_post(
                                        array(
                                                'ID'        => $post_id,
                                                'post_name' => $slug,
                                        ),
                                        true
                                );
                                if ( ! is_wp_error( $updated ) && (int) $updated > 0 ) {
                                        $slug_applied = $slug;
                                }
                        }
                }

                if ( empty( $applied ) && '' === $slug_applied ) {
                        wp_send_json_error( kic_t( 'چیزی برای اعمال انتخاب نشده بود.' ) );
                }

                wp_send_json_success(
                        array(
                                'applied'     => $applied,
                                'slug'        => $slug_applied,
                                'user_action' => true, // This write happened ONLY because of an explicit click.
                        )
                );
        }

        /**
         * Term ids already assigned to the post (all relevant taxonomies).
         *
         * @param WP_Post $post Post.
         * @return array {id: true}
         */
        private function assigned_term_ids( $post ) {
                $assigned = array();
                foreach ( $this->taxonomies_for( $post->post_type ) as $spec ) {
                        $terms = wp_get_object_terms( $post->ID, $spec['taxonomy'] );
                        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                                continue;
                        }
                        foreach ( $terms as $term ) {
                                if ( is_object( $term ) && isset( $term->term_id ) ) {
                                        $assigned[ (int) $term->term_id ] = true;
                                }
                        }
                }
                return $assigned;
        }
}
