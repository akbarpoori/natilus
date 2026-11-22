<?php
/**
 * Natilus — settings screen & provider presets.
 *
 * Registers the top-level "Natilus" admin page whose first submenu entry
 * («تنظیمات») renders admin/views/settings-page.php, sanitises and
 * persists the `kic_settings` option (autoload off) and exposes the
 * `kic_test_connection` AJAX endpoint used by the connection tester.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Settings {

        /**
         * Singleton instance.
         *
         * @var KIC_Settings|null
         */
        private static $instance = null;

        /**
         * Lazy singleton bootstrap.
         *
         * @return KIC_Settings
         */
        public static function init() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Access the singleton (lazily bootstrapped).
         *
         * @return KIC_Settings
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::init();
                }
                return self::$instance;
        }

        /**
         * Register hooks.
         */
        private function __construct() {
                add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
                add_action( 'admin_init', array( $this, 'save_settings' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
                add_action( 'wp_ajax_kic_test_connection', array( $this, 'ajax_test_connection' ) );
        }

        /**
         * Provider presets shared by the settings select box and kic-settings.js.
         *
         * @return array Map of preset id => {label, base_url, model}.
         */
        public static function presets() {
                return array(
                        'kubit'      => array(
                                'label'    => 'کوبیت',
                                'base_url' => 'https://api.kubit.ai/v1',
                                'model'    => 'kubit-ai',
                        ),
                        'openai'     => array(
                                'label'    => 'OpenAI',
                                'base_url' => 'https://api.openai.com/v1',
                                'model'    => 'gpt-4o-mini',
                        ),
                        'openrouter' => array(
                                'label'    => 'OpenRouter',
                                'base_url' => 'https://openrouter.ai/api/v1',
                                'model'    => 'openai/gpt-4o-mini',
                        ),
                        'groq'       => array(
                                'label'    => 'Groq',
                                'base_url' => 'https://api.groq.com/openai/v1',
                                'model'    => 'llama-3.3-70b-versatile',
                        ),
                        'deepseek'   => array(
                                'label'    => 'DeepSeek',
                                'base_url' => 'https://api.deepseek.com/v1',
                                'model'    => 'deepseek-chat',
                        ),
                        'together'   => array(
                                'label'    => 'Together',
                                'base_url' => 'https://api.together.xyz/v1',
                                'model'    => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
                        ),
                        'ollama'     => array(
                                'label'    => 'Ollama (لوکال)',
                                'base_url' => 'http://localhost:11434/v1',
                                'model'    => 'llama3.1',
                        ),
                        'lmstudio'   => array(
                                'label'    => 'LM Studio (لوکال)',
                                'base_url' => 'http://localhost:1234/v1',
                                'model'    => '',
                        ),
                        'custom'     => array(
                                'label'    => 'سایر / سفارشی',
                                'base_url' => '',
                                'model'    => '',
                        ),
                );
        }

        /**
         * Top-level menu + first submenu entry («تنظیمات») pointing at the same page.
         *
         * @return void
         */
        public function register_menu() {
                add_menu_page(
                        'Natilus',
                        'Natilus',
                        'manage_options',
                        'ai-content-publishing-agent',
                        array( $this, 'render_settings_page' ),
                        'dashicons-welcome-write-blog',
                        27
                );

                add_submenu_page(
                        'ai-content-publishing-agent',
                        'Natilus',
                        kic_t( 'تنظیمات' ),
                        'manage_options',
                        'ai-content-publishing-agent',
                        array( $this, 'render_settings_page' )
                );
        }

        /**
         * Render the settings screen. Hands the template everything except the
         * raw secret (the API key never reaches templates).
         *
         * @return void
         */
        public function render_settings_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html( 'شما اجازهٔ دسترسی به این صفحه را ندارید.' ) );
                }

                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                $has_key         = ( '' !== KIC_Security::decrypt( (string) $settings['api_key'] ) );
                $has_failover_key = ( '' !== KIC_Security::decrypt( (string) $settings['failover_api_key'] ) );

                unset( $settings['api_key'], $settings['failover_api_key'] );

                $template_vars = array(
                        'settings'         => $settings,
                        'has_key'          => $has_key,
                        'has_failover_key' => $has_failover_key,
                        'presets'          => self::presets(),
                        'capability'       => KIC_Security::capability(),
                        'providers'        => ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'public_list' ) )
                                ? KIC_Provider_Chain::public_list()
                                : array(),
                        'token_guide'      => ( class_exists( 'KIC_Token_Estimator' ) && method_exists( 'KIC_Token_Estimator', 'guide' ) )
                                ? KIC_Token_Estimator::guide()
                                : array(),
                );

                require KIC_PATH . 'admin/views/settings-page.php';
        }

        /**
         * Enqueue the shared admin stylesheet + settings script (with presets
         * and nonce) on the plugin's settings screen only.
         *
         * The settings screen re-uses the top-level menu slug, so its admin
         * page hook suffix is exactly "toplevel_page_{slug}".
         *
         * @param string $hook Current admin page hook suffix.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                $hook = (string) $hook;
                if ( 'toplevel_page_ai-content-publishing-agent' !== $hook
                        && false === strpos( $hook, '_page_ai-content-publishing-agent' ) ) {
                        return;
                }

                wp_enqueue_style( 'kic-admin', KIC_URL . 'admin/css/kic-admin.css', array(), KIC_VERSION );
                wp_enqueue_script( 'kic-settings', KIC_URL . 'admin/js/kic-settings.js', array( 'jquery' ), KIC_VERSION, true );

                $has_key = ( '' !== KIC_Security::decrypt( (string) kic_get_setting( 'api_key' ) ) );

                $providers = array();
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'public_list' ) ) {
                        $providers = KIC_Provider_Chain::public_list();
                }

                wp_localize_script(
                        'kic-settings',
                        'KIC_SETTINGS',
                        array(
                                'ajaxurl'  => admin_url( 'admin-ajax.php' ),
                                'nonce'    => wp_create_nonce( 'kic_ajax' ),
                                'has_key'  => (bool) $has_key,
                                'presets'  => self::presets(),
                                'providers' => $providers,
                        )
                );
        }

        /**
         * admin_init: persist the settings form (nonce + manage_options checked).
         * A blank api_key keeps the stored one; a submitted one is encrypted.
         *
         * @return void
         */
        public function save_settings() {
                if ( ! isset( $_POST['kic_settings'] ) || ! is_array( $_POST['kic_settings'] ) ) {
                        return;
                }

                check_admin_referer( 'kic_settings_save', 'kic_settings_nonce' );

                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html( 'شما اجازهٔ ذخیرهٔ این تنظیمات را ندارید.' ) );
                }

                $incoming = isset( $_POST['kic_settings'] ) && is_array( $_POST['kic_settings'] ) ? (array) wp_unslash( $_POST['kic_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- settings array: every field is whitelist-checked and sanitized individually below

                $current = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                $clean   = array();

                /* Connection tab ------------------------------------------------- */

                $clean['base_url'] = isset( $incoming['base_url'] )
                        ? esc_url_raw( trim( (string) $incoming['base_url'] ) )
                        : (string) $current['base_url'];

                $clean['model'] = isset( $incoming['model'] )
                        ? sanitize_text_field( (string) $incoming['model'] )
                        : (string) $current['model'];

                // Special case: an empty key means "keep the stored one".
                $submitted_key = isset( $incoming['api_key'] ) ? trim( (string) $incoming['api_key'] ) : '';
                if ( '' !== $submitted_key ) {
                        $clean['api_key'] = KIC_Security::encrypt( $submitted_key );
                } else {
                        $clean['api_key'] = isset( $current['api_key'] ) ? (string) $current['api_key'] : '';
                }

                if ( isset( $incoming['temperature'] ) && '' !== trim( (string) $incoming['temperature'] ) ) {
                        $clean['temperature'] = min( 2.0, max( 0.0, (float) $incoming['temperature'] ) );
                } else {
                        $clean['temperature'] = (float) $current['temperature'];
                }

                if ( isset( $incoming['max_tokens'] ) && '' !== trim( (string) $incoming['max_tokens'] ) ) {
                        $clean['max_tokens'] = max( 1, absint( $incoming['max_tokens'] ) );
                } else {
                        $clean['max_tokens'] = max( 1, absint( $current['max_tokens'] ) );
                }

                if ( isset( $incoming['timeout'] ) && '' !== trim( (string) $incoming['timeout'] ) ) {
                        $clean['timeout'] = min( 300, max( 10, absint( $incoming['timeout'] ) ) );
                } else {
                        $clean['timeout'] = min( 300, max( 10, absint( $current['timeout'] ) ) );
                }

                $clean['system_extra'] = isset( $incoming['system_extra'] )
                        ? sanitize_textarea_field( (string) $incoming['system_extra'] )
                        : (string) $current['system_extra'];

                /* Features tab ---------------------------------------------------- */

                foreach ( array( 'auto_sideload', 'jsonld', 'internal_links', 'draft_default', 'ai_edit_enabled', 'generate_enabled', 'related_posts', 'smart_links', 'og_card' ) as $flag ) {
                        $clean[ $flag ] = isset( $incoming[ $flag ] ) ? 1 : 0;
                }

                /* v2.8 — TTF font path for the generated OG card: filesystem
                 * path, not a URL — sanitize_text_field only. */
                $clean['og_font_path'] = isset( $incoming['og_font_path'] )
                        ? sanitize_text_field( (string) $incoming['og_font_path'] )
                        : (string) ( isset( $current['og_font_path'] ) ? $current['og_font_path'] : '' );

                $clean['smart_links_max'] = isset( $incoming['smart_links_max'] )
                        ? max( 1, min( 10, absint( $incoming['smart_links_max'] ) ) )
                        : 3;

                /* v2.4 — image-generation model override ('' = reuse the
                 * provider's own model slot). Sanitized like any model name. */
                $clean['image_model'] = isset( $incoming['image_model'] )
                        ? sanitize_text_field( (string) $incoming['image_model'] )
                        : (string) $current['image_model'];

                if ( isset( $incoming['rate_limit'] ) && '' !== trim( (string) $incoming['rate_limit'] ) ) {
                        $clean['rate_limit'] = min( 120, max( 0, absint( $incoming['rate_limit'] ) ) );
                } else {
                        $clean['rate_limit'] = min( 120, max( 0, absint( $current['rate_limit'] ) ) );
                }

                if ( isset( $incoming['keep_backups'] ) && '' !== trim( (string) $incoming['keep_backups'] ) ) {
                        $clean['keep_backups'] = min( 10, max( 1, absint( $incoming['keep_backups'] ) ) );
                } else {
                        $clean['keep_backups'] = min( 10, max( 1, absint( $current['keep_backups'] ) ) );
                }

                /* Routing / failover / budget / drip tab ------------------------- */

                foreach ( array( 'routing_enabled', 'failover_enabled', 'drip_enabled' ) as $flag ) {
                        $clean[ $flag ] = isset( $incoming[ $flag ] ) ? 1 : 0;
                }

                $clean['cheap_model'] = isset( $incoming['cheap_model'] )
                        ? sanitize_text_field( (string) $incoming['cheap_model'] )
                        : (string) $current['cheap_model'];

                $clean['failover_base_url'] = isset( $incoming['failover_base_url'] )
                        ? esc_url_raw( trim( (string) $incoming['failover_base_url'] ) )
                        : (string) $current['failover_base_url'];

                // Special case: an empty key means "keep the stored one" (like api_key).
                $submitted_failover_key = isset( $incoming['failover_api_key'] ) ? trim( (string) $incoming['failover_api_key'] ) : '';
                if ( '' !== $submitted_failover_key ) {
                        $clean['failover_api_key'] = KIC_Security::encrypt( $submitted_failover_key );
                } else {
                        $clean['failover_api_key'] = isset( $current['failover_api_key'] ) ? (string) $current['failover_api_key'] : '';
                }

                $clean['failover_model'] = isset( $incoming['failover_model'] )
                        ? sanitize_text_field( (string) $incoming['failover_model'] )
                        : (string) $current['failover_model'];

                if ( isset( $incoming['monthly_budget_usd'] ) && '' !== trim( (string) $incoming['monthly_budget_usd'] ) ) {
                        $clean['monthly_budget_usd'] = min( 999999.0, max( 0.0, (float) $incoming['monthly_budget_usd'] ) );
                } else {
                        $clean['monthly_budget_usd'] = min( 999999.0, max( 0.0, (float) $current['monthly_budget_usd'] ) );
                }

                if ( isset( $incoming['budget_alert_pct'] ) && '' !== trim( (string) $incoming['budget_alert_pct'] ) ) {
                        $clean['budget_alert_pct'] = min( 99, max( 50, absint( $incoming['budget_alert_pct'] ) ) );
                } else {
                        $clean['budget_alert_pct'] = min( 99, max( 50, absint( $current['budget_alert_pct'] ) ) );
                }

                if ( isset( $incoming['drip_posts_per_day'] ) && '' !== trim( (string) $incoming['drip_posts_per_day'] ) ) {
                        $clean['drip_posts_per_day'] = min( 50, max( 1, absint( $incoming['drip_posts_per_day'] ) ) );
                } else {
                        $clean['drip_posts_per_day'] = min( 50, max( 1, absint( $current['drip_posts_per_day'] ) ) );
                }

                if ( isset( $incoming['drip_publish_hour'] ) && '' !== trim( (string) $incoming['drip_publish_hour'] ) ) {
                        $clean['drip_publish_hour'] = min( 23, max( 0, absint( $incoming['drip_publish_hour'] ) ) );
                } else {
                        $clean['drip_publish_hour'] = min( 23, max( 0, absint( $current['drip_publish_hour'] ) ) );
                }

                if ( isset( $incoming['drip_start_days'] ) && '' !== trim( (string) $incoming['drip_start_days'] ) ) {
                        $clean['drip_start_days'] = min( 30, max( 0, absint( $incoming['drip_start_days'] ) ) );
                } else {
                        $clean['drip_start_days'] = min( 30, max( 0, absint( $current['drip_start_days'] ) ) );
                }

                /* v1.2 — builder modes / token guidance --------------------- */

                $sanitize_mode = 'auto';
                if ( class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'sanitize_mode' ) ) {
                        $sanitize_mode = 'KIC_Builder_Modes::sanitize_mode';
                }

                if ( isset( $incoming['builder_mode'] ) ) {
                        $clean['builder_mode'] = is_callable( $sanitize_mode ) ? call_user_func( $sanitize_mode, (string) $incoming['builder_mode'] ) : 'auto';
                } else {
                        $clean['builder_mode'] = is_callable( $sanitize_mode ) ? call_user_func( $sanitize_mode, (string) $current['builder_mode'] ) : 'auto';
                }

                $clean['auto_extend_tokens'] = isset( $incoming['auto_extend_tokens'] ) ? 1 : 0;

                if ( isset( $incoming['auto_continue_max'] ) && '' !== trim( (string) $incoming['auto_continue_max'] ) ) {
                        $clean['auto_continue_max'] = min( 8, max( 0, absint( $incoming['auto_continue_max'] ) ) );
                } else {
                        $clean['auto_continue_max'] = min( 8, max( 0, absint( $current['auto_continue_max'] ) ) );
                }

                /* v1.3 — RSS feeds for external/product link suggestions.
                 * Textarea with one feed URL per line -> validated unique URL list. */

                if ( isset( $incoming['rss_feeds_text'] ) ) {
                        $clean['rss_feeds'] = array();
                        $lines = preg_split( '/\r\n|\r|\n/', (string) $incoming['rss_feeds_text'] );
                        if ( is_array( $lines ) ) {
                                foreach ( $lines as $line ) {
                                        $url = esc_url_raw( trim( (string) $line ) );
                                        if ( '' !== $url && ! in_array( $url, $clean['rss_feeds'], true ) ) {
                                                $clean['rss_feeds'][] = $url;
                                        }
                                        if ( count( $clean['rss_feeds'] ) >= 10 ) {
                                                break;
                                        }
                                }
                        }
                } else {
                        $clean['rss_feeds'] = isset( $current['rss_feeds'] ) && is_array( $current['rss_feeds'] )
                                ? array_values( $current['rss_feeds'] )
                                : array();
                }

                /* v1.4 — UI language + operational report toggles. */

                $clean['ui_lang'] = isset( $incoming['ui_lang'] ) && 'en' === strtolower( trim( (string) $incoming['ui_lang'] ) )
                        ? 'en'
                        : 'fa';

                foreach ( array( 'weekly_report_enabled', 'spike_alert_enabled' ) as $report_flag ) {
                        $clean[ $report_flag ] = isset( $incoming[ $report_flag ] ) ? 1 : 0;
                }

                /* Queue pause stays server-managed (calendar/CLI toggle); never
                 * overwritten by the settings form. */
                $clean['drip_paused'] = isset( $current['drip_paused'] ) ? (int) $current['drip_paused'] : 0;

                /* v2.2 — headline set (kicker/subtitle) theme sync. Values are
                 * whitelisted: 'auto', 'none' or one of the DETECTED site meta
                 * keys, so the form can never start writing into arbitrary
                 * foreign meta. Custom free-text keys are pattern-validated. */

                $candidate_keys = array();
                if ( class_exists( 'KIC_Theme_Sync' ) && method_exists( 'KIC_Theme_Sync', 'candidates' ) ) {
                        foreach ( KIC_Theme_Sync::candidates() as $candidate ) {
                                if ( is_array( $candidate ) && isset( $candidate['key'] ) ) {
                                        $candidate_keys[] = (string) $candidate['key'];
                                }
                        }
                }

                foreach ( array( 'kicker', 'subtitle' ) as $sync_field ) {
                        $select_key = 'theme_sync_' . $sync_field . '_key';
                        $custom_key = 'theme_sync_' . $sync_field . '_custom';

                        $value = isset( $incoming[ $select_key ] ) ? strtolower( sanitize_key( (string) $incoming[ $select_key ] ) ) : 'auto';
                        if ( ! in_array( $value, array( 'auto', 'none' ), true ) && ! in_array( $value, $candidate_keys, true ) ) {
                                $value = 'auto';
                        }
                        $clean[ $select_key ] = $value;

                        $custom = isset( $incoming[ $custom_key ] ) ? strtolower( sanitize_key( trim( (string) $incoming[ $custom_key ] ) ) ) : '';
                        if ( '' !== $custom && ! preg_match( '/^[a-z0-9_\-]{2,64}$/', $custom ) ) {
                                $custom = '';
                        }
                        $clean[ $custom_key ] = $custom;
                }

                $clean['theme_sync_auto_output'] = isset( $incoming['theme_sync_auto_output'] ) ? 1 : 0;

                /* Refresh the site-structure scan so the dropdown reflects
                 * any theme/plugin change after saving. */
                if ( class_exists( 'KIC_Theme_Sync' ) && method_exists( 'KIC_Theme_Sync', 'flush_cache' ) ) {
                        KIC_Theme_Sync::flush_cache();
                }

                /* Persist + redirect with a success flag --------------------------- */

                $settings = array_merge( $current, $clean );
                update_option( 'kic_settings', $settings, false );

                wp_safe_redirect( add_query_arg( 'updated', '1', menu_page_url( 'ai-content-publishing-agent', false ) ) );
                exit;
        }

        /**
         * AJAX: minimal chat completion verifying provider connectivity.
         * Accepts optional posted overrides (base_url, api_key_plain, model) for
         * pre-save testing, otherwise falls back to the stored settings. The API
         * key is never echoed back.
         *
         * v2.1 — an 'id' parameter selects a chain provider row (its stored key
         * and URL are used), so every provider in the chain gets its own
         * "test connection" button. Local providers (Ollama/LM Studio) work
         * without a key.
         *
         * @return void
         */
        public function ajax_test_connection() {
                check_ajax_referer( 'kic_ajax', 'nonce' );

                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error(
                                array(
                                        'connected' => false,
                                        'message'  => 'شما اجازهٔ انجام این عملیات را ندارید.',
                                )
                        );
                }

                $base_url      = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
                $api_key_plain = isset( $_POST['api_key_plain'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key_plain'] ) ) : '';
                $model         = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
                $provider_id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

                /* Chain provider pick: the row's stored URL/key win unless the
                 * caller explicitly typed replacements. */
                if ( '' !== $provider_id && class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'find' ) ) {
                        $provider = KIC_Provider_Chain::find( $provider_id );
                        if ( null !== $provider ) {
                                if ( '' === $base_url ) {
                                        $base_url = (string) $provider['base_url'];
                                }
                                if ( '' === $api_key_plain ) {
                                        $decrypted = KIC_Security::decrypt( (string) $provider['api_key'] );
                                        if ( '' !== $decrypted ) {
                                                $api_key_plain = $decrypted;
                                        }
                                }
                                if ( '' === $model && ! empty( $provider['models'][0] ) ) {
                                        $model = (string) $provider['models'][0];
                                }
                        }
                }

                $client    = KIC_API_Client::instance();
                $overrides = array();
                if ( '' !== $base_url ) {
                        $overrides['base_url'] = $base_url;
                }
                if ( '' !== $api_key_plain ) {
                        $overrides['api_key'] = $api_key_plain;
                }
                if ( '' !== $model ) {
                        $overrides['model'] = $model;
                }
                if ( ! empty( $overrides ) ) {
                        $client->configure( $overrides );
                }

                $attempted_model = ( '' !== $model ) ? $model : (string) kic_get_setting( 'model' );
                $zero_usage      = array(
                        'prompt_tokens'     => 0,
                        'completion_tokens' => 0,
                        'total_tokens'      => 0,
                );

                $messages = array(
                        array(
                                'role'    => 'user',
                                'content' => 'Reply with the single word: pong',
                        ),
                );

                $start   = microtime( true );
                $result  = $client->chat( $messages, array( 'max_tokens' => 20 ), array( 'action' => 'test' ) );
                $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_success(
                                array(
                                        'connected'  => false,
                                        'message'    => (string) $result->get_error_message(),
                                        'model'      => $attempted_model,
                                        'latency_ms' => $latency,
                                        'usage'      => $zero_usage,
                                )
                        );
                }

                $reply = trim( (string) $result['content'] );
                if ( 80 < strlen( $reply ) ) {
                        $reply = substr( $reply, 0, 80 ) . '…';
                }

                wp_send_json_success(
                        array(
                                'connected'  => true,
                                'message'    => 'اتصال موفق بود؛ پاسخ مدل: «' . $reply . '»',
                                'model'      => (string) $result['model'],
                                'latency_ms' => $latency,
                                'usage'      => $result['usage'],
                        )
                );
        }
}
