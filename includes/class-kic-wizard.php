<?php
/**
 * Natilus — 3-step first-run setup wizard.
 *
 * Free-distribution helper: a brand-new install shows one admin notice with
 * a «راه‌اندازی سریع» link. Three steps, two minutes:
 *
 *   1. pick a provider preset (Kubit / OpenAI / OpenRouter / Groq / ...),
 *   2. enter base URL + API key + model,
 *   3. test the connection → finish (the provider lands in the chain and
 *      becomes the default connection).
 *
 * The wizard only ever SAVES configuration the admin typed. It generates no
 * content and touches no posts.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Wizard {

        /**
         * Singleton instance.
         *
         * @var KIC_Wizard|null
         */
        private static $instance = null;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Wizard
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
         * @return KIC_Wizard
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers hooks.
         */
        private function __construct() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
                add_action( 'wp_ajax_kic_wizard_finish', array( $this, 'ajax_finish' ) );
        }

        /**
         * Whether the wizard has been completed (or skipped) on this site.
         *
         * @return bool
         */
        public static function done() {
                return (bool) get_option( 'kic_wizard_done', false );
        }

        /**
         * Whether ANY usable provider exists yet (chain row or legacy settings).
         *
         * @return bool
         */
        public static function has_provider() {
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'public_list' ) ) {
                        $providers = KIC_Provider_Chain::public_list();
                        if ( is_array( $providers ) ) {
                                foreach ( $providers as $provider ) {
                                        if ( is_array( $provider ) && ! empty( $provider['base_url'] ) ) {
                                                return true;
                                        }
                                }
                        }
                }
                return ( '' !== trim( (string) kic_get_setting( 'base_url' ) ) )
                        && ( '' !== KIC_Security::decrypt( (string) kic_get_setting( 'api_key' ) ) );
        }

        /* ---------------------------------------------------------------------
         * Notice + page.
         * ------------------------------------------------------------------- */

        /**
         * v1.4 publisher mode: no first-run notice is pushed at the admin.
         * Publishing needs NO provider; provider setup is an optional helper
         * for the suggestion features only (kept for anyone who opts in).
         *
         * @return void
         */
        public static function first_run_notice() {
                /* purposely empty — see note above. */
        }

        /**
         * Wizard submenu page.
         *
         * @return void
         */
        public static function register_menu() {
                add_submenu_page(
                        'ai-content-publishing-agent',
                        kic_t( 'راه‌اندازی سریع' ),
                        kic_t( 'راه‌اندازی سریع' ),
                        'manage_options',
                        'kic-wizard',
                        array( __CLASS__, 'render_page' )
                );
        }

        /**
         * Enqueue wizard assets on the wizard screen only.
         *
         * @param string $hook Current admin page hook suffix.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, 'kic-wizard' ) ) {
                        return;
                }

                wp_enqueue_style( 'kic-admin', KIC_URL . 'admin/css/kic-admin.css', array(), KIC_VERSION );
                wp_enqueue_script( 'kic-wizard', KIC_URL . 'admin/js/kic-wizard.js', array( 'jquery' ), KIC_VERSION, true );

                wp_localize_script(
                        'kic-wizard',
                        'KIC_WIZ',
                        array(
                                'ajaxurl'  => admin_url( 'admin-ajax.php' ),
                                'nonce'    => wp_create_nonce( 'kic_ajax' ),
                                'presets'  => ( class_exists( 'KIC_Settings' ) && method_exists( 'KIC_Settings', 'presets' ) )
                                        ? KIC_Settings::presets()
                                        : array(),
                                'i18n'     => KIC_I18n::js_package(),
                        )
                );
        }

        /**
         * Wizard render callback.
         *
         * @return void
         */
        public static function render_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html( kic_t( 'شما اجازهٔ دسترسی به این صفحه را ندارید.' ) ) );
                }

                $presets = ( class_exists( 'KIC_Settings' ) && method_exists( 'KIC_Settings', 'presets' ) )
                        ? KIC_Settings::presets()
                        : array();

                require KIC_PATH . 'admin/views/wizard-page.php';
        }

        /* ---------------------------------------------------------------------
         * Finish.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_wizard_finish — persist the wizard configuration.
         *
         * Saves (a) the provider into the chain (encrypted key) and (b) the
         * legacy default connection fields, then marks the wizard done. When the
         * admin ticked "test passed", the connection was already verified by the
         * standard kic_test_connection endpoint with the same values.
         *
         * v2.1 — a `skip=1` finish marks the wizard done WITHOUT saving any
         * provider (publishing needs none), and local providers (Ollama /
         * LM Studio) may finish with an empty API key.
         *
         * @return void
         */
        public function ajax_finish() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        wp_send_json_error( kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' ) );
                }
                if ( ! current_user_can( 'manage_options' ) ) {
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

                /* Skip: publishing works without any provider. */
                if ( ! empty( $params['skip'] ) ) {
                        update_option( 'kic_wizard_done', 1, false );
                        wp_send_json_success(
                                array(
                                        'skipped'      => true,
                                        'message'      => kic_t( 'راه‌اندازی رد شد — انتشار محتوا بدون ارائه‌دهنده کامل کار می‌کند.' ),
                                        'settings_url' => admin_url( 'admin.php?page=ai-content-publishing-agent' ),
                                )
                        );
                }

                $base_url = isset( $params['base_url'] ) ? esc_url_raw( trim( (string) $params['base_url'] ) ) : '';
                $base_url = rtrim( $base_url, '/' );
                $api_key  = isset( $params['api_key'] ) ? trim( (string) $params['api_key'] ) : '';
                $model    = isset( $params['model'] ) ? sanitize_text_field( (string) $params['model'] ) : '';
                $label    = isset( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : '';

                $is_local = false;
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'is_local_provider' ) ) {
                        $is_local = KIC_Provider_Chain::is_local_provider( $base_url );
                }

                if ( '' === $base_url || '' === $model || ( '' === $api_key && ! $is_local ) ) {
                        wp_send_json_error( kic_t( 'آدرس پایه و مدل الزامی‌اند؛ کلید API هم برای ارائه‌دهنده‌های غیرمحلی لازم است (سرور محلی می‌تواند بدون کلید باشد).' ) );
                }

                /* 1. Chain row (fresh id → append). */
                $provider_id = '';
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'save' ) ) {
                        $provider_id = KIC_Provider_Chain::save(
                                array(
                                        'label'    => ( '' !== $label ? $label : $base_url ),
                                        'base_url' => $base_url,
                                        'api_key'  => $api_key,
                                        'models'   => array( $model ),
                                )
                        );
                }

                /* 2. Legacy default connection (keeps the workspace chip + API client happy). */
                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                $settings['base_url'] = $base_url;
                $settings['model']    = $model;
                if ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'encrypt' ) ) {
                        $settings['api_key'] = KIC_Security::encrypt( $api_key );
                }
                update_option( 'kic_settings', $settings, false );

                update_option( 'kic_wizard_done', 1, false );

                wp_send_json_success(
                        array(
                                'provider_id' => $provider_id,
                                'message'     => kic_t( 'راه‌اندازی کامل شد.' ),
                                'settings_url' => admin_url( 'admin.php?page=ai-content-publishing-agent' ),
                        )
                );
        }
}
