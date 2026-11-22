<?php
/**
 * Plugin Name:       Natilus
 * Description:       Natilus publishes your ready-made content to WordPress exactly as written — kicker/subtitle synced with your theme, media import, galleries, featured image, Elementor/WPBakery/Gutenberg/Classic layouts, SEO meta, drip queue, rollback. AI helpers optional. https://github.com/akbarpoori/natilus | انتشارگر محتوای آماده برای وردپرس
 * Version:           2.5.2
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            Meysam Akbarpoori
 * Author URI:        https://akbarpoori.ir
 * Plugin URI:        https://github.com/akbarpoori/natilus
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-content-publishing-agent
 * Domain Path:       /languages
 */

/*
 * phpcs:disable WordPress.Files.FileName
 * The main plugin file must be named after the plugin slug
 * (ai-content-publishing-agent.php) per the WordPress.org plugin
 * directory rules, so the class-name based file naming convention
 * (class-kic-plugin.php) cannot apply to this file. The slug itself is
 * already all-lowercase with hyphens, which is the convention the
 * generic part of this sniff enforces.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

define( 'KIC_VERSION', '2.5.2' );
define( 'KIC_FILE', __FILE__ );
define( 'KIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'KIC_URL', plugin_dir_url( __FILE__ ) );
define( 'KIC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Every module lives in /includes and follows the same contract:
 * final class KIC_<Name> with static init() / instance() singletons that
 * register their own WordPress hooks. The bootstrap only wires them together.
 */
$kic_includes = array(
        'class-kic-i18n.php',               // bilingual UI strings (FA default / EN).
        'class-kic-security.php',          // capability + API key encryption.
        'class-kic-settings.php',          // provider settings page (any OpenAI-compatible endpoint).
        'class-kic-provider-chain.php',    // provider & model priority chain + health + /models.
        'class-kic-api-client.php',        // chat (chain failover) for the OPTIONAL suggestion helpers only.
        'class-kic-router.php',            // cheap-model routing for light tasks.
        'class-kic-ai-editor.php',         // v2.3 — OPTIONAL prompt-based edit of the CURRENT post (preview → apply).
        'class-kic-generator.php',         // v2.4 — OPTIONAL prompt-based article generation + image generation (preview only).
        'class-kic-token-estimator.php',   // practical token estimates, guide data, auto-extend.
        'class-kic-budget-guard.php',       // monthly USD budget cap + email alerts.
        'class-kic-drip-scheduler.php',     // gradual WP-Cron publishing of bulk drafts.
        'class-kic-prompt-builder.php',    // system & user prompts for the OPTIONAL suggestion helpers only (categories / slug).
        'class-kic-media-handler.php',     // sideload, alt-text, galleries, featured images, b64 import.
        'class-kic-content-injector.php',  // append / replace / draft_new injection for both editors.
        'class-kic-ajax-handler.php',      // v2.0 — publisher endpoints: publish_ready + sideload (generation endpoints deregistered).
        'class-kic-seo-schema.php',        // Article + FAQ JSON-LD output.
        'class-kic-seo-integrator.php',    // Rank Math + Yoast (+ standalone meta fallback).
        'class-kic-theme-sync.php',        // v2.2 — kicker/subtitle synced with the ACTIVE theme's own fields (auto-detected) + REST fields + fallback display.
        'class-kic-builder-integration.php', // Elementor widget & data sync, WPBakery, Slider Revolution.
        'class-kic-builder-modes.php',     // elementor / wpbakery / classic / gutenberg modes + Elementor live panel.
        'class-kic-classic-editor.php',    // TinyMCE toolbar button scrolling to the widget.
        'class-kic-metabox.php',           // inline compose widget under the editor (both editors).
        'class-kic-rate-limiter.php',      // sliding-window per-user request limit.
        'class-kic-token-tracker.php',     // usage table + dashboard.
        'class-kic-revision-guard.php',    // snapshot before injection + rollback.
        'class-kic-link-suggester.php',    // suggestion-only internal/RSS links + headlines (user-approved).
        'class-kic-bulk-generator.php',    // bulk PUBLISH of ready-made articles + legacy queue processing.
        // v1.4.0 — publish quality, queue calendar, reports, distribution.
        'class-kic-prepublish.php',        // read-only pre-publish checklist + link opportunities.
        'class-kic-term-suggester.php',    // user-approved existing-term & slug suggestions.
        'class-kic-queue-manager.php',     // publish calendar + queue operations.
        'class-kic-chain-health.php',      // durable failover event log (read-only).
        'class-kic-reports.php',           // weekly email summary + usage spike alert + reports page.
        'class-kic-bundle.php',            // portable JSON settings bundle (export/import).
        'class-kic-wizard.php',            // 3-step first-run setup wizard.
        'class-kic-cli-commands.php',     // WP-CLI commands (queue / tokens).
);

/*
 * Fault-tolerant loading: a missing, corrupted or unparsable module records
 * a warning instead of triggering a fatal "require" error, so the plugin
 * can still be activated and the admin sees exactly which file needs
 * replacing. ParseError is catchable since PHP 7.
 */
$GLOBALS['kic_missing_includes'] = array();
foreach ( $kic_includes as $kic_include_file ) {
        $kic_include_path = KIC_PATH . 'includes/' . $kic_include_file;
        if ( ! is_readable( $kic_include_path ) ) {
                $GLOBALS['kic_missing_includes'][] = 'includes/' . $kic_include_file;
                continue;
        }
        try {
                require_once $kic_include_path;
        } catch ( Throwable $kic_load_error ) {
                $GLOBALS['kic_missing_includes'][] = 'includes/' . $kic_include_file
                        . ' (' . $kic_load_error->getMessage() . ')';
        }
}

if ( ! function_exists( 'kic_fatal_log_file' ) ) {
        /**
         * Best-effort path for the activation fatal log (uploads dir, always
         * writable on healthy WP installs; plugin dir as fallback).
         *
         * @return string Absolute path (may not be writable).
         */
        function kic_fatal_log_file() {
                $upload = wp_upload_dir();
                if ( is_array( $upload ) && ! empty( $upload['basedir'] ) ) {
                        return trailingslashit( $upload['basedir'] ) . 'kic-activation-error.log';
                }
                return KIC_PATH . 'kic-activation-error.log';
        }
}

if ( ! function_exists( 'kic_capture_activation_fatal' ) ) {
        /**
         * Shutdown handler armed only during the activation request: prints
         * the EXACT fatal error (even when display_errors is off) and stores
         * it as an option + file log so kic-diagnostic can read it later.
         *
         * @return void
         */
        function kic_capture_activation_fatal() {
                $error = error_get_last();
                if ( ! is_array( $error ) ) {
                        return;
                }
                $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
                if ( ! in_array( $error['type'], $fatal_types, true ) ) {
                        return;
                }

                $message = sprintf(
                        '%1$s | file: %2$s | line: %3$s',
                        $error['message'],
                        $error['file'],
                        $error['line']
                );

                // Persist for later reading (option + file), best-effort.
                update_option( 'kic_activation_fatal', $message, false );
                @file_put_contents( kic_fatal_log_file(), $message . "\n" );

                // Print live even with display_errors=Off: shutdown output still reaches the admin.
                echo '<div style="direction:rtl;text-align:right;margin:16px;padding:16px 20px;background:#fff0f0;border:1px solid #d32f2f;border-radius:8px;font:14px/1.9 Tahoma,sans-serif;color:#b00020;position:relative;z-index:99999;">'
                        . '<strong>خطای فعال‌سازی Natilus</strong><br>'
                        . 'متن دقیق خطا: <code style="direction:ltr;unicode-bidi:embed;">' . esc_html( $message ) . '</code><br>'
                        . 'این متن را برای پشتیبانی ارسال کنید. افزونه فعال نشده است.</div>';
        }
}

if ( ! function_exists( 'kic_default_settings' ) ) {
        /**
         * Default plugin settings. Stored encrypted where sensitive.
         *
         * @return array
         */
        function kic_default_settings() {
                return array(
                        'base_url'       => '', // No default provider — publishing needs none.
                        'api_key'        => '', // Encrypted at rest by KIC_Security.
                        'model'          => '',
                        'temperature'    => 0.7,
                        'max_tokens'     => 4096,
                        'timeout'        => 120,
                        'system_extra'   => '',
                        'auto_sideload'  => 1,
                        'jsonld'         => 1,
                        'internal_links' => 0,
                        'rate_limit'     => 6,
                        'draft_default'  => 0,
                        'keep_backups'   => 3,
                        // v1.1.0 — routing / failover / budget / drip.
                        'routing_enabled'     => 0,
                        'cheap_model'         => '',
                        'failover_enabled'    => 0,
                        'failover_base_url'   => '',
                        'failover_api_key'    => '', // Encrypted at rest by KIC_Security.
                        'failover_model'      => '',
                        'monthly_budget_usd'  => 0,
                        'budget_alert_pct'   => 80,
                        'drip_enabled'        => 0,
                        'drip_posts_per_day'  => 3,
                        'drip_publish_hour'   => 9,
                        'drip_start_days'     => 1,
                        // v1.2.0 — chain / tokens / skills / builder modes.
                        'builder_mode'        => 'auto',
                        'auto_extend_tokens'  => 1,
                        'auto_continue_max'   => 3,
                        // v1.3.0 — RSS feeds for external/product link suggestions.
                        'rss_feeds'           => array(),
                        // v1.4.0 — UI language, queue pause, report emails.
                        'ui_lang'             => 'fa',
                        'drip_paused'         => 0,
                        'weekly_report_enabled' => 1,
                        'spike_alert_enabled'   => 1,
                        // v2.2.0 — headline set (kicker/subtitle) theme sync.
                        'theme_sync_kicker_key'      => 'auto',
                        'theme_sync_subtitle_key'     => 'auto',
                        'theme_sync_auto_output'     => 1,
                        'theme_sync_kicker_custom'   => '',
                        'theme_sync_subtitle_custom' => '',
                        // v2.3.0 — OPTIONAL prompt-based edit of the current post.
                        'ai_edit_enabled'            => 1,
                        // v2.4.0 — OPTIONAL prompt-based article + image generation.
                        'generate_enabled'           => 1,
                        'image_model'                => '',
                );
        }
}

if ( ! function_exists( 'kic_get_setting' ) ) {
        /**
         * Settings accessor. Raw values; KIC_API_Client decrypts the key.
         *
         * @param string $key     Setting key.
         * @param mixed  $default Fallback value.
         * @return mixed
         */
        function kic_get_setting( $key, $default = null ) {
                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
        }
}

/**
 * Orchestration container.
 */
if ( ! class_exists( 'KIC_Plugin' ) ) {
        final class KIC_Plugin {

                /**
                 * @var KIC_Plugin|null
                 */
                private static $instance = null;

                /**
                 * @return KIC_Plugin
                 */
                public static function instance() {
                        if ( null === self::$instance ) {
                                self::$instance = new self();
                        }
                        return self::$instance;
                }

                private function __construct() {
                        add_action( 'admin_notices', array( $this, 'activation_notice' ) );
                        $this->boot();
                }

                /**
                 * Show activation-time diagnostics exactly once, then clear them.
                 * Covers both non-fatal warnings and a previously captured fatal
                 * (e.g. a fatal on an older upload that blocked activation).
                 *
                 * @return void
                 */
                public function activation_notice() {
                        if ( ! current_user_can( 'activate_plugins' ) ) {
                                return;
                        }

                        /* Fatal error captured during the last activation attempt. */
                        $fatal = get_option( 'kic_activation_fatal', '' );
                        $fatal = is_string( $fatal ) ? trim( $fatal ) : '';
                        if ( '' === $fatal ) {
                                $log = kic_fatal_log_file();
                                if ( is_readable( $log ) ) {
                                        $content = (string) file_get_contents( $log );
                                        if ( '' !== trim( $content ) ) {
                                                $fatal = trim( $content );
                                                // The file log is authoritative; drop the stale copy.
                                                delete_option( 'kic_activation_fatal' );
                                        }
                                }
                        }
                        if ( '' !== $fatal ) {
                                delete_option( 'kic_activation_fatal' );
                                $log = kic_fatal_log_file();
                                if ( is_readable( $log ) ) {
                                        wp_delete_file( $log );
                                }
                                echo '<div class="notice notice-error"><p><strong>'
                                        . esc_html__( 'Natilus — خطای فعال‌سازی:', 'ai-content-publishing-agent' )
                                        . '</strong></p><p><code style="direction:ltr;unicode-bidi:embed;">'
                                        . esc_html( $fatal )
                                        . '</code></p><p>'
                                        . esc_html__( 'این متن دقیقاً علت توقف فعال‌سازی است؛ آن را برای پشتیبانی ارسال کنید.', 'ai-content-publishing-agent' )
                                        . '</p></div>';
                        }

                        /* Non-fatal warnings. */
                        $warnings = get_option( 'kic_activation_notice', array() );
                        if ( empty( $warnings ) || ! is_array( $warnings ) ) {
                                return;
                        }

                        echo '<div class="notice notice-warning is-dismissible"><p><strong>'
                                . esc_html__( 'Natilus:', 'ai-content-publishing-agent' )
                                . '</strong> ';
                        /* translators: %s: count of warnings. */
                        echo esc_html( sprintf( __( 'فعال‌سازی انجام شد اما %s مورد نیاز بررسی دارد:', 'ai-content-publishing-agent' ), number_format_i18n( count( $warnings ) ) ) );
                        echo '</p><ul style="list-style:disc;margin:6px 0 6px 18px;">';
                        foreach ( $warnings as $warning ) {
                                echo '<li>' . esc_html( (string) $warning ) . '</li>';
                        }
                        echo '</ul><p style="margin-top:6px;">';
                        echo esc_html__( 'افزونه فعال است؛ پیشنهاد می‌شود بستهٔ ZIP را یک بار دیگر دانلود و جایگزین کنید. اگر پیام تکرار شد با مدیر هاست تماس بگیرید.', 'ai-content-publishing-agent' );
                        echo '</p></div>';

                        delete_option( 'kic_activation_notice' );
                }

                /**
                 * Component boot order respects runtime dependencies while every
                 * class registers its own hooks inside its constructor. Missing
                 * modules (incomplete upload) are skipped instead of crashing.
                 *
                 * @return void
                 */
                private function boot() {
                        $modules = array(
                                'KIC_Security',
                                'KIC_Settings',
                                'KIC_Provider_Chain',
                                'KIC_API_Client',
                                'KIC_Router',
                                'KIC_AI_Editor',
                                'KIC_Generator',
                                'KIC_Token_Estimator',
                                'KIC_Budget_Guard',
                                'KIC_Drip_Scheduler',
                                'KIC_Prompt_Builder',
                                'KIC_Rate_Limiter',
                                'KIC_Token_Tracker',
                                'KIC_Revision_Guard',
                                'KIC_Link_Suggester',
                                'KIC_Media_Handler',
                                'KIC_SEO_Schema',
                                'KIC_SEO_Integrator',
                                'KIC_Theme_Sync',
                                'KIC_Content_Injector',
                                'KIC_Builder_Integration',
                                'KIC_Builder_Modes',
                                'KIC_Classic_Editor',
                                'KIC_Metabox',
                                'KIC_Ajax_Handler',
                                'KIC_Bulk_Generator',
                                'KIC_Prepublish',
                                'KIC_Term_Suggester',
                                'KIC_Queue_Manager',
                                'KIC_Chain_Health',
                                'KIC_Reports',
                                'KIC_Bundle',
                                'KIC_Wizard',
                        );

                        foreach ( $modules as $module ) {
                                if ( class_exists( $module ) && method_exists( $module, 'init' ) ) {
                                        call_user_func( array( $module, 'init' ) );
                                }
                        }
                }
        }
}

/**
 * Activation: tables, capabilities, default settings, prompt seeds.
 * Every step is fault-tolerant: a DB hiccup records a warning instead of
 * aborting activation, so the plugin always activates successfully.
 *
 * @return void
 */
function kic_activate() {
        global $wpdb;

        // Clear stale diagnostics, then arm the fatal capture for THIS request
        // so any fatal during activation is printed + logged instead of a blank page.
        delete_option( 'kic_activation_fatal' );
        if ( file_exists( kic_fatal_log_file() ) ) {
                wp_delete_file( kic_fatal_log_file() );
        }
        register_shutdown_function( 'kic_capture_activation_fatal' );

        $kic_warnings = array();

        /* 1. Custom tables. The v1.x bulk-generation queue table is no
         * longer created (v2.1 publisher creates posts directly); it is
         * still dropped by uninstall for legacy installs. */
        try {
                if ( ! function_exists( 'dbDelta' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                }

                $charset_collate = $wpdb->get_charset_collate();

                $t_usage = $wpdb->prefix . 'kic_usage';
                dbDelta(
                        "CREATE TABLE {$t_usage} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                action VARCHAR(32) NOT NULL DEFAULT '',
                                model VARCHAR(128) NOT NULL DEFAULT '',
                                provider VARCHAR(191) NOT NULL DEFAULT '',
                                prompt_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                completion_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                cost_estimate DECIMAL(12,5) UNSIGNED NOT NULL DEFAULT 0,
                                created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
                                PRIMARY KEY  (id),
                                KEY user_id (user_id),
                                KEY created_at (created_at),
                                KEY action (action)
                        ) {$charset_collate};"
                );
        } catch ( Throwable $kic_activation_error ) {
                $kic_warnings[] = 'ایجاد جداول پایگاه‌داده ناموفق بود: ' . $kic_activation_error->getMessage();
        }

        /* 2. Capabilities. */
        try {
                foreach ( array( 'administrator', 'editor' ) as $role_name ) {
                        $role = get_role( $role_name );
                        if ( $role && ! $role->has_cap( 'kubit_ai_compose' ) ) {
                                $role->add_cap( 'kubit_ai_compose' );
                        }
                }
        } catch ( Throwable $kic_activation_error ) {
                $kic_warnings[] = 'افزودن دسترسی کاربران ناموفق بود: ' . $kic_activation_error->getMessage();
        }

        /* 3. Default settings (first activation only). */
        try {
                if ( false === get_option( 'kic_settings', false ) ) {
                        add_option( 'kic_settings', kic_default_settings(), '', false );
                }
        } catch ( Throwable $kic_activation_error ) {
                $kic_warnings[] = 'ذخیرهٔ تنظیمات پیش‌فرض ناموفق بود: ' . $kic_activation_error->getMessage();
        }

        /* 4. Provider chain: seed from the legacy primary/failover settings. */
        try {
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'maybe_migrate' ) ) {
                        KIC_Provider_Chain::instance()->maybe_migrate();
                }
        } catch ( Throwable $kic_activation_error ) {
                $kic_warnings[] = 'ساخت زنجیرهٔ ارائه‌دهنده‌ها ناموفق بود: ' . $kic_activation_error->getMessage();
        }

        /* 4c. v1.4 — recurring report crons (weekly summary + daily spike check). */
        try {
                if ( ! wp_next_scheduled( 'kic_weekly_summary' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'kic_weekly_summary' );
                }
                if ( ! wp_next_scheduled( 'kic_daily_usage_check' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kic_daily_usage_check' );
                }
        } catch ( Throwable $kic_activation_error ) {
                $kic_warnings[] = 'زمان‌بندی گزارش‌های دوره‌ای ناموفق بود: ' . $kic_activation_error->getMessage();
        }

        /* 5. Incomplete-installation report from the load-time file check. */
        if ( ! empty( $GLOBALS['kic_missing_includes'] ) && is_array( $GLOBALS['kic_missing_includes'] ) ) {
                $kic_warnings[] = 'این فایل‌ها در بستهٔ نصب‌شده یافت نشد: ' . implode( '، ', $GLOBALS['kic_missing_includes'] );
        }

        /* Persist diagnostics for the one-time admin notice. */
        if ( ! empty( $kic_warnings ) ) {
                update_option( 'kic_activation_notice', $kic_warnings, false );
        } else {
                delete_option( 'kic_activation_notice' );
        }
}
register_activation_hook( __FILE__, 'kic_activate' );

/**
 * Deactivation: drop capabilities, keep all data for re-activation.
 *
 * @return void
 */
function kic_deactivate() {
        foreach ( array( 'administrator', 'editor' ) as $role_name ) {
                $role = get_role( $role_name );
                if ( $role ) {
                        $role->remove_cap( 'kubit_ai_compose' );
                }
        }
}
register_deactivation_hook( __FILE__, 'kic_deactivate' );

KIC_Plugin::instance();
