<?php
/**
 * Natilus — portable settings bundle (JSON).
 *
 * Free-distribution helper: move a working configuration (provider chain,
 * non-secret settings, prompt skills) from one site to another with one
 * export and one import — e.g. share a ready-made "pack" with other users.
 *
 * SECURITY: API keys are excluded from the bundle by default. The export
 * screen offers an explicit "include keys" checkbox (plain text, clearly
 * warned). Imported providers without keys work as soon as the admin pastes
 * their own key.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Bundle {

        /**
         * Singleton instance.
         *
         * @var KIC_Bundle|null
         */
        private static $instance = null;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Bundle
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
         * @return KIC_Bundle
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers the AJAX endpoints.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_bundle_export', array( $this, 'ajax_export' ) );
                add_action( 'wp_ajax_kic_bundle_import', array( $this, 'ajax_import' ) );
        }

        /**
         * Settings keys that are SAFE to carry across sites (no secrets).
         *
         * @return string[]
         */
        private static function portable_setting_keys() {
                return array(
                        'temperature',
                        'max_tokens',
                        'timeout',
                        'system_extra',
                        'auto_sideload',
                        'jsonld',
                        'internal_links',
                        'rate_limit',
                        'draft_default',
                        'keep_backups',
                        'routing_enabled',
                        'cheap_model',
                        'monthly_budget_usd',
                        'budget_alert_pct',
                        'drip_enabled',
                        'drip_posts_per_day',
                        'drip_publish_hour',
                        'drip_start_days',
                        'builder_mode',
                        'auto_extend_tokens',
                        'auto_continue_max',
                        'rss_feeds',
                        // v2.2 — headline set theme sync (site-structure preferences, no secrets).
                        'theme_sync_kicker_key',
                        'theme_sync_subtitle_key',
                        'theme_sync_auto_output',
                        'theme_sync_kicker_custom',
                        'theme_sync_subtitle_custom',
                );
        }

        /* ---------------------------------------------------------------------
         * Export.
         * ------------------------------------------------------------------- */

        /**
         * Build the bundle array.
         *
         * @param bool $include_keys Whether to embed API keys as plain text.
         * @return array
         */
        public static function build( $include_keys = false ) {
                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );

                $portable = array();
                foreach ( self::portable_setting_keys() as $key ) {
                        if ( isset( $settings[ $key ] ) ) {
                                $portable[ $key ] = $settings[ $key ];
                        }
                }

                /* Providers: strip ids (fresh ids on import) and keys (default). */
                $providers = array();
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'public_list' ) ) {
                        $public = KIC_Provider_Chain::public_list();
                        if ( is_array( $public ) ) {
                                foreach ( $public as $provider ) {
                                        if ( ! is_array( $provider ) ) {
                                                continue;
                                        }
                                        $row = array(
                                                'label'    => isset( $provider['label'] ) ? (string) $provider['label'] : '',
                                                'base_url' => isset( $provider['base_url'] ) ? (string) $provider['base_url'] : '',
                                                'models'   => isset( $provider['models'] ) && is_array( $provider['models'] ) ? array_values( (array) $provider['models'] ) : array(),
                                        );
                                        if ( $include_keys ) {
                                                /* Plain text key — the admin explicitly opted in. */
                                                $stored = KIC_Provider_Chain::find( isset( $provider['id'] ) ? (string) $provider['id'] : '' );
                                                $plain  = '';
                                                if ( is_array( $stored ) && ! empty( $stored['api_key'] ) && class_exists( 'KIC_Security' ) ) {
                                                        $plain = KIC_Security::decrypt( (string) $stored['api_key'] );
                                                }
                                                $row['api_key'] = $plain;
                                        }
                                        $providers[] = $row;
                                }
                        }
                }

                return array(
                        'plugin'      => 'ai-content-publishing-agent',
                        'version'     => defined( 'KIC_VERSION' ) ? KIC_VERSION : '',
                        'exported_at' => gmdate( 'Y-m-d H:i:s' ),
                        'settings'    => $portable,
                        'providers'   => $providers,
                );
        }

        /**
         * AJAX: kic_bundle_export — returns the JSON text for a client-side
         * download. Nothing is written by this endpoint.
         *
         * @return void
         */
        public function ajax_export() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        wp_send_json_error( kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' ) );
                }
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( kic_t( 'دسترسی ندارید.' ) );
                }

                $params        = $this->request_params();
                $include_keys  = ! empty( $params['include_keys'] );

                $bundle = self::build( $include_keys );

                wp_send_json_success(
                        array(
                                'filename' => 'kic-bundle-' . current_time( 'Ymd-His' ) . '.json',
                                'json'     => wp_json_encode( $bundle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
                                'includes_keys' => $include_keys,
                                'read_only' => true,
                        )
                );
        }

        /* ---------------------------------------------------------------------
         * Import.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_bundle_import — apply a bundle: providers append with fresh
         * ids (keys only when embedded), portable settings update, skills append.
         *
         * @return void
         */
        public function ajax_import() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        wp_send_json_error( kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' ) );
                }
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( kic_t( 'دسترسی ندارید.' ) );
                }

                $params = $this->request_params();
                $raw    = '';

                if ( isset( $params['bundle'] ) && is_string( $params['bundle'] ) ) {
                        $raw = trim( (string) $params['bundle'] );
                }
                if ( '' === $raw && isset( $_FILES['bundle_file'], $_FILES['bundle_file']['tmp_name'], $_FILES['bundle_file']['size'] ) && is_uploaded_file( $_FILES['bundle_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-side temp path from the PHP engine, validated via is_uploaded_file()
                        $uploaded = (int) $_FILES['bundle_file']['size']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- integer byte size from the PHP engine, cast immediately
                        if ( $uploaded > 0 && $uploaded < 512000 ) {
                                $raw = trim( (string) file_get_contents( (string) $_FILES['bundle_file']['tmp_name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-side temp path from the PHP engine, validated via is_uploaded_file()
                        }
                }

                if ( '' === $raw ) {
                        wp_send_json_error( kic_t( 'فایل JSON بسته را اینجا بچسبانید یا انتخاب کنید.' ) );
                }

                $bundle = json_decode( $raw, true );
                if ( ! is_array( $bundle ) || ! isset( $bundle['plugin'] ) || 'ai-content-publishing-agent' !== (string) $bundle['plugin'] ) {
                        wp_send_json_error( kic_t( 'بسته نامعتبر است — ساختار JSON خوانده نشد.' ) );
                }

                $imported_providers = 0;
                $imported_settings  = 0;

                /* Providers: append (fresh ids). */
                if ( isset( $bundle['providers'] ) && is_array( $bundle['providers'] ) && class_exists( 'KIC_Provider_Chain' ) ) {
                        foreach ( $bundle['providers'] as $provider ) {
                                if ( ! is_array( $provider ) ) {
                                        continue;
                                }
                                $row = $provider;
                                unset( $row['id'] ); // Always a fresh id — never overwrite by import.
                                $saved = KIC_Provider_Chain::save( $row );
                                if ( '' !== (string) $saved ) {
                                        $imported_providers++;
                                }
                        }
                }

                /* Portable settings: whitelisted keys only. */
                if ( isset( $bundle['settings'] ) && is_array( $bundle['settings'] ) ) {
                        $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );

                        foreach ( self::portable_setting_keys() as $key ) {
                                if ( ! array_key_exists( $key, $bundle['settings'] ) ) {
                                        continue;
                                }
                                $value = $bundle['settings'][ $key ];

                                /* Type-safe merge based on the default's type. */
                                $default = isset( $settings[ $key ] ) ? $settings[ $key ] : null;
                                if ( is_array( $default ) ) {
                                        $value = is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) ) ) : array();
                                } elseif ( is_int( $default ) || is_bool( $default ) ) {
                                        $value = absint( $value );
                                } elseif ( is_float( $default ) ) {
                                        $value = (float) $value;
                                } else {
                                        $value = sanitize_text_field( (string) $value );
                                }

                                $settings[ $key ] = $value;
                                $imported_settings++;
                        }

                        /* Bounds for the numeric ones (defence in depth). */
                        $settings['rate_limit']         = min( 120, max( 0, absint( $settings['rate_limit'] ) ) );
                        $settings['keep_backups']       = min( 10, max( 1, absint( $settings['keep_backups'] ) ) );
                        $settings['max_tokens']         = max( 1, absint( $settings['max_tokens'] ) );
                        $settings['timeout']            = min( 300, max( 10, absint( $settings['timeout'] ) ) );
                        $settings['drip_posts_per_day'] = min( 50, max( 1, absint( $settings['drip_posts_per_day'] ) ) );
                        $settings['drip_publish_hour']  = min( 23, max( 0, absint( $settings['drip_publish_hour'] ) ) );
                        $settings['drip_start_days']    = min( 30, max( 0, absint( $settings['drip_start_days'] ) ) );
                        $settings['auto_continue_max']  = min( 10, max( 0, absint( $settings['auto_continue_max'] ) ) );
                        $settings['monthly_budget_usd'] = max( 0, (float) $settings['monthly_budget_usd'] );
                        $settings['budget_alert_pct']   = min( 100, max( 10, absint( $settings['budget_alert_pct'] ) ) );
                        $settings['temperature']        = min( 2.0, max( 0.0, (float) $settings['temperature'] ) );

                        update_option( 'kic_settings', $settings, false );
                }

                wp_send_json_success(
                        array(
                                'providers' => $imported_providers,
                                'settings'  => $imported_settings,
                                'message'   => kic_t( 'بستهٔ تنظیمات با موفقیت وارد شد.' ),
                        )
                );
        }

        /**
         * Request parameters (form or JSON body).
         *
         * @return array
         */
        private function request_params() {
                $params = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every caller of this method verifies the kic_ajax nonce via check_ajax_referer() first; each field is sanitized at its point of use
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
}
