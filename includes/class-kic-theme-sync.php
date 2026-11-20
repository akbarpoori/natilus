<?php
/**
 * Theme structure synchronization for روتیتر (kicker) and زیرتیتر (subtitle).
 *
 * v2.2 — publishes the headline set (kicker above the title, subtitle below
 * the title) into whatever structure the ACTIVE THEME of the site uses:
 *
 *  1. Canonical storage — _kic_kicker / _kic_subtitle post meta (always
 *     written, plus REST fields kic_kicker / kic_subtitle so Gutenberg,
 *     mobile apps and page builders can read/write them too).
 *  2. Native theme fields — the meta keys the site's own theme/plugin uses
 *     are AUTO-DETECTED (registered post meta + the keys actually present in
 *     the postmeta table) and the same text is mirrored into them, so the
 *     theme renders it exactly the way it was designed to.
 *  3. Universal fallback — when the theme has no native kicker/subtitle
 *     field anywhere, the plugin outputs them itself on the single post
 *     view (the_content) with clean, theme-inheriting markup.
 *
 * Everything is auto-detected from the LIVE site structure (no hardcoded
 * theme guesses), developers can override the mapping at any level via
 * the kic_theme_sync_* filters, and the detection scan is cached (12 h)
 * so the publish pipeline stays fast.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Theme_Sync {

        /**
         * Singleton instance.
         *
         * @var KIC_Theme_Sync|null
         */
        private static $instance = null;

        /** Canonical meta key: روتیتر (kicker — the small headline above the title). */
        const KICKER_META = '_kic_kicker';

        /** Canonical meta key: زیرتیتر (subtitle — the explanatory line below the title). */
        const SUBTITLE_META = '_kic_subtitle';

        /** Transient holding the detected site meta-key scan. */
        const SCAN_TRANSIENT = 'kic_theme_sync_scan';

        /**
         * Boot the module (called from KIC_Plugin::boot()).
         *
         * @return KIC_Theme_Sync
         */
        public static function init() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Singleton accessor.
         *
         * @return KIC_Theme_Sync
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Register own hooks.
         */
        private function __construct() {
                add_action( 'init', array( $this, 'register_meta' ), 5 );
                add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
                add_filter( 'the_content', array( $this, 'frontend_output' ), 7 );
                add_action( 'wp_head', array( $this, 'print_inline_css' ), 99 );
                add_shortcode( 'kic_kicker', array( $this, 'shortcode_kicker' ) );
                add_shortcode( 'kic_subtitle', array( $this, 'shortcode_subtitle' ) );
        }

        /* ---------------------------------------------------------------------
         * Field plumbing (sanitize / store / read).
         * ------------------------------------------------------------------- */

        /**
         * Sanitize one headline value (plain single-line text, soft 300-char cap).
         *
         * @param mixed $value Raw input.
         * @return string
         */
        public static function sanitize_value( $value ) {
                $value = sanitize_text_field( (string) $value );
                if ( '' === $value ) {
                        return '';
                }
                return wp_html_excerpt( $value, 300, '' );
        }

        /**
         * Canonical meta key for a field ('kicker'|'subtitle').
         *
         * @param string $field Field name.
         * @return string
         */
        public static function meta_key( $field ) {
                return ( 'kicker' === $field ) ? self::KICKER_META : self::SUBTITLE_META;
        }

        /**
         * Publish the headline set: canonical meta + the theme's own native
         * fields, resolved from the live site structure. Empty value = keep the
         * current one (the same semantics as the title field).
         *
         * @param int   $post_id Target post id.
         * @param array $fields  {kicker: string, subtitle: string}.
         * @return array Map of field => applied value (only the non-empty ones).
         */
        public static function apply_fields( $post_id, $fields ) {
                $post_id = absint( $post_id );
                if ( ! $post_id || ! is_array( $fields ) ) {
                        return array();
                }

                $applied = array();
                foreach ( array( 'kicker', 'subtitle' ) as $field ) {
                        $value = isset( $fields[ $field ] ) ? self::sanitize_value( $fields[ $field ] ) : '';
                        if ( '' === $value ) {
                                continue;
                        }

                        /* 1) Canonical — always written. */
                        update_post_meta( $post_id, self::meta_key( $field ), wp_slash( $value ) );
                        $applied[ $field ] = $value;

                        /* 2) Mirror into the theme's native field when one exists. */
                        $theme_key = self::resolved_target_key( $field );
                        if ( '' !== $theme_key ) {
                                update_post_meta( $post_id, $theme_key, wp_slash( $value ) );
                        }
                }

                return $applied;
        }

        /**
         * Read the kicker for a post (canonical first, mapped theme key as
         * fallback so templates/helpers also see theme-native values).
         *
         * @param int|null $post_id Post id (defaults to current).
         * @return string
         */
        public static function get_kicker( $post_id = null ) {
                return self::read_field( 'kicker', $post_id );
        }

        /**
         * Read the subtitle for a post (canonical first, mapped theme key as
         * fallback).
         *
         * @param int|null $post_id Post id (defaults to current).
         * @return string
         */
        public static function get_subtitle( $post_id = null ) {
                return self::read_field( 'subtitle', $post_id );
        }

        /**
         * Shared read helper.
         *
         * @param string   $field   Field name.
         * @param int|null $post_id Post id.
         * @return string
         */
        private static function read_field( $field, $post_id = null ) {
                $post_id = absint( $post_id ? $post_id : get_the_ID() );
                if ( ! $post_id ) {
                        return '';
                }
                $value = (string) get_post_meta( $post_id, self::meta_key( $field ), true );
                if ( '' === $value ) {
                        $theme_key = self::resolved_target_key( $field );
                        if ( '' !== $theme_key ) {
                                $value = (string) get_post_meta( $post_id, $theme_key, true );
                        }
                }
                return trim( wp_strip_all_tags( $value ) );
        }

        /* ---------------------------------------------------------------------
         * Site-structure detection (the "sync" engine).
         * ------------------------------------------------------------------- */

        /**
         * Semantic patterns: keys that mean kicker-ish vs subtitle-ish things in
         * any theme (English + Persian transliterations both covered).
         *
         * @var array
         */
        private static $patterns = array(
                'any' => '#(kicker|eyebrow|overline|over_?line|rotitr|zirtitr|standfirst|super_?title|superhead|suphead|subtitle|sub_?title|sub_?headline|sub_?head|subhead|deck|teaser|lead_?in|hat_?note|article_?lead|post_?lead|excerpt_?lead)#i',
                'kicker' => '#(kicker|eyebrow|overline|over_?line|rotitr|standfirst|super_?title|superhead|suphead|hat_?note)#i',
        );

        /**
         * Detected candidate meta keys the site's theme/plugin actually uses
         * for headline fields. Merges: WP-registered post meta keys (modern
         * themes), the keys present in the postmeta table (classic themes) and
         * developer-provided ones, filtered by the semantic patterns above.
         *
         * @return array[] Each: {field: 'kicker'|'subtitle', key, source}.
         */
        public static function candidates() {
                $candidates = array();
                $seen       = array();

                /* WordPress-registered meta (block themes, builder plugins). */
                $registered = array();
                if ( function_exists( 'get_registered_meta_keys' ) ) {
                        $registered = get_registered_meta_keys( 'post' );
                        if ( ! is_array( $registered ) ) {
                                $registered = array();
                        }
                }
                $registered_keys = array_map( 'strtolower', array_keys( $registered ) );

                /* Keys actually used on real posts (cached 12 h). */
                $db_keys = self::db_meta_keys();

                $pool = array_merge( array_keys( $registered ), $db_keys );
                foreach ( $pool as $raw_key ) {
                        $key = strtolower( trim( (string) $raw_key ) );
                        if ( '' === $key || isset( $seen[ $key ] ) ) {
                                continue;
                        }
                        $seen[ $key ] = true;

                        /* Never offer our own canonical keys or foreign engine internals. */
                        if ( 0 === strpos( $key, '_kic_' ) ) {
                                continue;
                        }
                        if ( preg_match( '#^(_elementor|_wp_|_yoast|rank_math|_aioseo|_seopress|_smartcrawl|_wpseo)#', $key ) ) {
                                continue;
                        }
                        if ( ! preg_match( self::$patterns['any'], $key ) ) {
                                continue;
                        }

                        $field = preg_match( self::$patterns['kicker'], $key ) ? 'kicker' : 'subtitle';
                        $candidates[] = array(
                                'field'  => $field,
                                'key'    => $key,
                                'source' => in_array( $key, $registered_keys, true ) ? 'registered' : 'db',
                        );
                }

                /* Shortest (most exact) keys first, alphabetical tie-break. */
                usort(
                        $candidates,
                        function ( $a, $b ) {
                                $len = strlen( $a['key'] ) - strlen( $b['key'] );
                                return ( 0 !== $len ) ? $len : strcmp( $a['key'], $b['key'] );
                        }
                );

                /**
                 * Developers can extend/replace the detected candidates.
                 *
                 * @param array $candidates {field, key, source} items.
                 */
                $candidates = apply_filters( 'kic_theme_sync_candidates', $candidates );
                if ( ! is_array( $candidates ) ) {
                        return array();
                }

                $clean = array();
                foreach ( $candidates as $candidate ) {
                        if ( ! is_array( $candidate ) ) {
                                continue;
                        }
                        $field = isset( $candidate['field'] ) ? (string) $candidate['field'] : '';
                        $key   = isset( $candidate['key'] ) ? strtolower( sanitize_key( (string) $candidate['key'] ) ) : '';
                        if ( ! in_array( $field, array( 'kicker', 'subtitle' ), true ) ) {
                                continue;
                        }
                        if ( '' === $key || ! preg_match( '/^[a-z0-9_\-]{2,64}$/', $key ) || 0 === strpos( $key, '_kic_' ) ) {
                                continue;
                        }
                        $clean[] = array(
                                'field'  => $field,
                                'key'    => $key,
                                'source' => isset( $candidate['source'] ) ? (string) $candidate['source'] : 'custom',
                        );
                }
                return $clean;
        }

        /**
         * Best auto-detected candidate for one field.
         *
         * @param string $field 'kicker'|'subtitle'.
         * @return string|null Meta key or null when the theme has none.
         */
        public static function best_candidate( $field ) {
                foreach ( self::candidates() as $candidate ) {
                        if ( $field === $candidate['field'] ) {
                                return $candidate['key'];
                        }
                }
                return null;
        }

        /**
         * Meta keys present in the postmeta table (DISTINCT scan; meta_key is a
         * covering index in the WP schema, cached 12 h so publishes stay fast).
         *
         * @return string[]
         */
        private static function db_meta_keys() {
                $cached = get_transient( self::SCAN_TRANSIENT );
                if ( is_array( $cached ) ) {
                        return $cached;
                }

                $keys = array();
                global $wpdb;
                $rows = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} LIMIT 2000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- meta_key is indexed; result cached below.
                if ( is_array( $rows ) ) {
                        foreach ( $rows as $row ) {
                                if ( is_string( $row ) && '' !== trim( $row ) ) {
                                        $keys[] = $row;
                                }
                        }
                }

                set_transient( self::SCAN_TRANSIENT, $keys, 12 * HOUR_IN_SECONDS );
                return $keys;
        }

        /**
         * Drop the detection cache (settings save / explicit refresh).
         *
         * @return void
         */
        public static function flush_cache() {
                delete_transient( self::SCAN_TRANSIENT );
        }

        /* ---------------------------------------------------------------------
         * Target resolution.
         * ------------------------------------------------------------------- */

        /**
         * Resolve the ACTUAL meta key a field is mirrored into on this site.
         * '' = canonical only (no mirroring), 'auto' has no candidate either —
         * handled by should_frontend_output().
         *
         * @param string $field 'kicker'|'subtitle'.
         * @return string Meta key or '' when nothing native exists.
         */
        public static function resolved_target_key( $field ) {
                $setting = self::field_setting( $field );

                if ( 'none' === $setting ) {
                        return '';
                }

                if ( 'auto' === $setting ) {
                        $best = self::best_candidate( $field );
                        return ( null !== $best ) ? $best : '';
                }

                /* A specific (or custom) key — keep only sane names. */
                return preg_match( '/^[a-z0-9_\-]{2,64}$/', $setting ) ? $setting : '';
        }

        /**
         * Raw setting for a field: 'auto' | 'none' | a specific meta key.
         * The kic_theme_sync_custom_* settings (free-text) win over the select.
         *
         * @param string $field Field name.
         * @return string
         */
        private static function field_setting( $field ) {
                $custom = trim( (string) kic_get_setting( 'theme_sync_' . $field . '_custom', '' ) );
                if ( '' !== $custom && preg_match( '/^[a-z0-9_\-]{2,64}$/', strtolower( $custom ) ) ) {
                        return strtolower( sanitize_key( $custom ) );
                }

                $setting = strtolower( trim( (string) kic_get_setting( 'theme_sync_' . $field . '_key', 'auto' ) ) );
                if ( '' === $setting ) {
                        $setting = 'auto';
                }
                if ( ! in_array( $setting, array( 'auto', 'none' ), true ) && ! preg_match( '/^[a-z0-9_\-]{2,64}$/', $setting ) ) {
                        $setting = 'auto';
                }
                return $setting;
        }

        /**
         * Whether the plugin itself should print this field on the single post
         * view. ONLY the 'auto' mapping with NO detected native field may use
         * the fallback display: a theme-native mirror (auto WITH a candidate,
         * or a specific/custom key) is rendered by the theme itself, and
         * 'none' means canonical storage only — neither must ever be
         * auto-printed (no duplication, no output when opted out).
         *
         * @param string $field Field name.
         * @return bool
         */
        public static function should_frontend_output( $field ) {
                if ( ! kic_get_setting( 'theme_sync_auto_output', 1 ) ) {
                        return false;
                }
                if ( 'auto' !== self::field_setting( $field ) ) {
                        return false;
                }
                return null === self::best_candidate( $field );
        }

        /* ---------------------------------------------------------------------
         * Registration (meta typing + REST fields).
         * ------------------------------------------------------------------- */

        /**
         * Post types the headline fields live on.
         *
         * @return string[]
         */
        private static function post_types() {
                $types = array( 'post', 'page', 'product' );
                $extra = apply_filters( 'kic_theme_sync_post_types', array() );
                if ( is_array( $extra ) ) {
                        $types = array_values( array_unique( array_merge( $types, array_map( 'strval', $extra ) ) ) );
                }
                return $types;
        }

        /**
         * init: type the canonical meta (typed, single, not REST-exposed — the
         * dedicated REST fields below handle the API surface instead).
         *
         * @return void
         */
        public function register_meta() {
                if ( ! function_exists( 'register_post_meta' ) ) {
                        return;
                }
                foreach ( self::post_types() as $type ) {
                        register_post_meta(
                                $type,
                                self::KICKER_META,
                                array(
                                        'type'          => 'string',
                                        'single'        => true,
                                        'show_in_rest'  => false,
                                        'auth_callback' => function () {
                                                return current_user_can( 'edit_posts' );
                                        },
                                )
                        );
                        register_post_meta(
                                $type,
                                self::SUBTITLE_META,
                                array(
                                        'type'          => 'string',
                                        'single'        => true,
                                        'show_in_rest'  => false,
                                        'auth_callback' => function () {
                                                return current_user_can( 'edit_posts' );
                                        },
                                )
                        );
                }
        }

        /**
         * REST fields (kic_kicker / kic_subtitle) on every supported post type —
         * so Gutenberg, apps and integrations can read AND write the headline
         * set through the standard REST API.
         *
         * @return void
         */
        public function register_rest_fields() {
                if ( ! function_exists( 'register_rest_field' ) ) {
                        return;
                }
                foreach ( self::post_types() as $type ) {
                        register_rest_field(
                                $type,
                                'kic_kicker',
                                array(
                                        'get_callback'    => function ( $post_data ) {
                                                $post_arr = (array) $post_data;
                                                return self::get_kicker( isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0 );
                                        },
                                        'update_callback' => function ( $value, $post ) {
                                                if ( ! is_object( $post ) || ! property_exists( $post, 'ID' ) ) {
                                                        return new WP_Error( 'kic_post_missing', 'نوشتهٔ هدف یافت نشد.' );
                                                }
                                                if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
                                                        return new WP_Error( 'kic_forbidden', 'اجازهٔ ویرایش این نوشته را ندارید.', array( 'status' => rest_authorization_required_code() ) );
                                                }
                                                self::apply_fields( (int) $post->ID, array( 'kicker' => self::sanitize_value( (string) $value ) ) );
                                                return true;
                                        },
                                        'schema'          => array(
                                                'type'        => 'string',
                                                'description' => 'Kicker / روتیتر (small headline above the title).',
                                        ),
                                )
                        );
                        register_rest_field(
                                $type,
                                'kic_subtitle',
                                array(
                                        'get_callback'    => function ( $post_data ) {
                                                $post_arr = (array) $post_data;
                                                return self::get_subtitle( isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0 );
                                        },
                                        'update_callback' => function ( $value, $post ) {
                                                if ( ! is_object( $post ) || ! property_exists( $post, 'ID' ) ) {
                                                        return new WP_Error( 'kic_post_missing', 'نوشتهٔ هدف یافت نشد.' );
                                                }
                                                if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
                                                        return new WP_Error( 'kic_forbidden', 'اجازهٔ ویرایش این نوشته را ندارید.', array( 'status' => rest_authorization_required_code() ) );
                                                }
                                                self::apply_fields( (int) $post->ID, array( 'subtitle' => self::sanitize_value( (string) $value ) ) );
                                                return true;
                                        },
                                        'schema'          => array(
                                                'type'        => 'string',
                                                'description' => 'Subtitle / زیرتیتر (explanatory line below the title).',
                                        ),
                                )
                        );
                }
        }

        /* ---------------------------------------------------------------------
         * Frontend output (universal fallback when the theme has no field).
         * ------------------------------------------------------------------- */

        /**
         * Formatted kicker markup (filter: kic_kicker_html).
         *
         * @param string $text Kicker text.
         * @return string
         */
        public static function kicker_html( $text ) {
                $html = '<p class="kic-kicker">' . esc_html( $text ) . '</p>';
                return apply_filters( 'kic_kicker_html', $html, $text );
        }

        /**
         * Formatted subtitle markup (filter: kic_subtitle_html).
         *
         * @param string $text Subtitle text.
         * @return string
         */
        public static function subtitle_html( $text ) {
                $html = '<p class="kic-subtitle">' . esc_html( $text ) . '</p>';
                return apply_filters( 'kic_subtitle_html', $html, $text );
        }

        /**
         * the_content filter: prepend kicker + subtitle on the single post view
         * ONLY when the active theme has no native field for them (no double
         * rendering) and the site owner kept the universal fallback on.
         *
         * @param string $content Post content.
         * @return string
         */
        public function frontend_output( $content ) {
                if ( ! is_singular() || is_admin() || wp_doing_ajax() || is_feed() || wp_doing_cron() ) {
                        return $content;
                }
                $post_id = get_the_ID();
                if ( ! $post_id || ! in_the_loop() || ! is_main_query() ) {
                        return $content;
                }
                if ( post_password_required( $post_id ) ) {
                        return $content;
                }

                $prepend = '';
                if ( self::should_frontend_output( 'kicker' ) ) {
                        $kicker = self::get_kicker( $post_id );
                        if ( '' !== $kicker && apply_filters( 'kic_print_kicker', true, $post_id ) ) {
                                $prepend .= self::kicker_html( $kicker );
                        }
                }
                if ( self::should_frontend_output( 'subtitle' ) ) {
                        $subtitle = self::get_subtitle( $post_id );
                        if ( '' !== $subtitle && apply_filters( 'kic_print_subtitle', true, $post_id ) ) {
                                $prepend .= self::subtitle_html( $subtitle );
                        }
                }

                if ( '' === $prepend ) {
                        return $content;
                }
                return $prepend . $content;
        }

        /**
         * Minimal inline CSS for the fallback markup — printed ONLY on singular
         * pages that actually carry the fields (zero extra HTTP requests, fonts
         * and colors inherit from the active theme; letter-spacing stays 0 so
         * Persian cursive script keeps its joins).
         *
         * @return void
         */
        public function print_inline_css() {
                if ( ! is_singular() ) {
                        return;
                }
                $post_id = get_the_ID();
                if ( ! $post_id ) {
                        return;
                }
                if ( ! self::should_frontend_output( 'kicker' ) && ! self::should_frontend_output( 'subtitle' ) ) {
                        return;
                }
                if ( '' === self::get_kicker( $post_id ) && '' === self::get_subtitle( $post_id ) ) {
                        return;
                }
                echo '<style id="kic-theme-sync">.kic-kicker{font-size:.82em;font-weight:700;line-height:1.8;margin:0 0 6px;opacity:.72;}.kic-subtitle{font-size:1.12em;line-height:1.9;margin:0 0 18px;opacity:.85;}.kic-kicker+.kic-subtitle{margin-top:-4px;}</style>' . "\n";
        }

        /* ---------------------------------------------------------------------
         * Shortcodes + template helpers for theme developers.
         * ------------------------------------------------------------------- */

        /**
         * Shortcode: [kic_kicker] — exact placement anywhere in a template.
         *
         * @return string
         */
        public function shortcode_kicker() {
                $kicker = self::get_kicker();
                return ( '' === $kicker ) ? '' : self::kicker_html( $kicker );
        }

        /**
         * Shortcode: [kic_subtitle].
         *
         * @return string
         */
        public function shortcode_subtitle() {
                $subtitle = self::get_subtitle();
                return ( '' === $subtitle ) ? '' : self::subtitle_html( $subtitle );
        }

        /* ---------------------------------------------------------------------
         * Admin config payloads.
         * ------------------------------------------------------------------- */

        /**
         * Active theme identity (child themes resolve to the parent too).
         *
         * @return array {name, slug, parent}
         */
        public static function theme_info() {
                static $info = null;
                if ( null !== $info ) {
                        return $info;
                }
                $info = array(
                        'name'   => '',
                        'slug'   => '',
                        'parent' => '',
                );
                if ( function_exists( 'wp_get_theme' ) ) {
                        $theme = wp_get_theme();
                        if ( $theme && $theme->exists() ) {
                                $info['name'] = (string) $theme->get( 'Name' );
                                $info['slug'] = (string) $theme->get_stylesheet();
                                $parent       = $theme->parent();
                                if ( $parent && $parent->exists() ) {
                                        $info['parent'] = (string) $parent->get( 'Name' );
                                }
                        }
                }
                return $info;
        }

        /**
         * Localized config for the compose widget (a one-line sync hint under
         * the new headline fields + the resolved targets).
         *
         * @return array
         */
        public static function js_config() {
                $theme = self::theme_info();

                $config = array(
                        'theme'   => $theme,
                        'kicker'  => self::field_mode_info( 'kicker' ),
                        'subtitle' => self::field_mode_info( 'subtitle' ),
                        'hint'    => self::build_hint( $theme ),
                );
                return $config;
        }

        /**
         * Mode info for one field: native (theme key) | plugin (auto output) |
         * none (canonical only).
         *
         * @param string $field Field name.
         * @return array {mode, key}
         */
        private static function field_mode_info( $field ) {
                $key  = self::resolved_target_key( $field );
                $mode = '';
                if ( '' !== $key ) {
                        $mode = 'native';
                } elseif ( self::should_frontend_output( $field ) ) {
                        $mode = 'plugin';
                } else {
                        $mode = 'none';
                }
                return array(
                        'mode' => $mode,
                        'key'  => $key,
                );
        }

        /**
         * Human-readable one-line hint in the active UI language.
         *
         * @param array $theme theme_info() payload.
         * @return string
         */
        private static function build_hint( $theme ) {
                $en  = ( class_exists( 'KIC_I18n' ) && method_exists( 'KIC_I18n', 'lang' ) && 'en' === KIC_I18n::lang() );
                $who = ( '' !== $theme['parent'] )
                        /* translators: %1$s: theme name, %2$s: parent theme name. */
                        ? sprintf( $en ? '%1$s (child of %2$s)' : '%1$s (فرزندِ %2$s)', $theme['name'], $theme['parent'] )
                        : $theme['name'];

                $parts = array();
                foreach ( array( 'kicker', 'subtitle' ) as $field ) {
                        $info = self::field_mode_info( $field );
                        $label = ( 'kicker' === $field )
                                ? ( $en ? 'Kicker' : 'روتیتر' )
                                : ( $en ? 'Subtitle' : 'زیرتیتر' );
                        if ( 'native' === $info['mode'] ) {
                                /* translators: %s: meta key. */
                                $parts[] = sprintf( $en ? '%1$s → native theme field `%2$s`' : '%1$s ← فیلد بومی قالب (%2$s)', $label, $info['key'] );
                        } elseif ( 'plugin' === $info['mode'] ) {
                                $parts[] = sprintf( $en ? '%s → plugin fallback output' : '%s ← نمایش خودکار افزونه', $label );
                        } else {
                                $parts[] = sprintf( $en ? '%s → standard meta only' : '%s ← فقط متای استاندارد', $label );
                        }
                }

                /* translators: %1$s: theme name, %2$s: mapping detail. */
                return sprintf(
                        $en ? 'Theme sync (%1$s): %2$s' : 'همگام با قالب «%1$s»: %2$s',
                        $who,
                        implode( $en ? '; ' : '؛ ', $parts )
                );
        }

        /**
         * Data for the settings page section (candidates grouped per field).
         *
         * @return array {theme: array, kicker: array, subtitle: array}
         */
        public static function settings_view_data() {
                $data = array(
                        'theme'    => self::theme_info(),
                        'kicker'   => array(),
                        'subtitle' => array(),
                );
                foreach ( self::candidates() as $candidate ) {
                        $data[ $candidate['field'] ][] = $candidate;
                }
                return $data;
        }
}

/* ---------------------------------------------------------------------
 * Template helpers (for theme developers who want exact placement).
 * --------------------------------------------------------------------- */

if ( ! function_exists( 'kic_the_kicker' ) ) {
        /**
         * Print the kicker of the current post (template tag).
         *
         * @return void
         */
        function kic_the_kicker() {
                $kicker = KIC_Theme_Sync::get_kicker();
                if ( '' !== $kicker ) {
                        echo wp_kses_post( KIC_Theme_Sync::kicker_html( $kicker ) );
                }
        }
}

if ( ! function_exists( 'kic_the_subtitle' ) ) {
        /**
         * Print the subtitle of the current post (template tag).
         *
         * @return void
         */
        function kic_the_subtitle() {
                $subtitle = KIC_Theme_Sync::get_subtitle();
                if ( '' !== $subtitle ) {
                        echo wp_kses_post( KIC_Theme_Sync::subtitle_html( $subtitle ) );
                }
        }
}
