<?php
/**
 * Natilus — operational transparency (reports).
 *
 * READ-ONLY reporting, three pieces:
 *
 *   1. Weekly email summary (WP-Cron) — posts published this week, token
 *      usage, cost estimate and chain failures. Uses the same mail path as
 *      the budget alerts.
 *   2. Unusual usage alert (daily cron) — one email + one admin notice the
 *      FIRST time a day's token usage exceeds 3x the trailing 30-day daily
 *      average (with a floor so tiny sites don't fire).
 *   3. «گزارش‌ها و سلامت» admin screen — link-opportunity report (posts
 *      without internal links), chain health history, and a preview of the
 *      weekly summary email.
 *
 * Nothing here writes content or changes behaviour: pure visibility.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Reports {

        /**
         * Singleton instance.
         *
         * @var KIC_Reports|null
         */
        private static $instance = null;

        /**
         * Spike multiplier over the trailing daily average.
         */
        const SPIKE_MULTIPLIER = 3;

        /**
         * Spike floor (tokens) so quiet sites don't alert on noise.
         */
        const SPIKE_FLOOR = 5000;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Reports
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
         * @return KIC_Reports
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
                add_action( 'admin_notices', array( __CLASS__, 'spike_notice' ) );

                add_action( 'kic_weekly_summary', array( __CLASS__, 'send_weekly_summary' ) );
                add_action( 'kic_daily_usage_check', array( __CLASS__, 'daily_usage_check' ) );

                /* Self-heal the schedules (covers installs upgrading from 1.3). */
                add_action( 'admin_init', array( __CLASS__, 'ensure_schedules' ) );
        }

        /* ---------------------------------------------------------------------
         * Schedules.
         * ------------------------------------------------------------------- */

        /**
         * Arm both recurring cron events when missing (idempotent).
         *
         * @return void
         */
        public static function ensure_schedules() {
                if ( ! function_exists( 'wp_next_scheduled' ) ) {
                        return;
                }

                if ( ! wp_next_scheduled( 'kic_weekly_summary' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'kic_weekly_summary' );
                }
                if ( ! wp_next_scheduled( 'kic_daily_usage_check' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kic_daily_usage_check' );
                }
        }

        /* ---------------------------------------------------------------------
         * Data (direct, light SQL on the usage table).
         * ------------------------------------------------------------------- */

        /**
         * Token totals inside a window.
         *
         * @param string $since_mysql GMT 'Y-m-d H:i:s' lower bound.
         * @param string $until_mysql GMT 'Y-m-d H:i:s' upper bound ('' = now).
         * @return array {requests, prompt_tokens, completion_tokens, total_tokens, cost}
         */
        private static function usage_between( $since_mysql, $until_mysql = '' ) {
                global $wpdb;

                if ( '' !== (string) $until_mysql ) {
                        $row = $wpdb->get_row(
                                $wpdb->prepare(
                                        "SELECT COUNT(*) AS requests,
                                                COALESCE( SUM( prompt_tokens ), 0 ) AS prompt_tokens,
                                                COALESCE( SUM( completion_tokens ), 0 ) AS completion_tokens,
                                                COALESCE( SUM( total_tokens ), 0 ) AS total_tokens,
                                                COALESCE( SUM( cost_estimate ), 0 ) AS cost
                                        FROM {$wpdb->prefix}kic_usage
                                        WHERE created_at >= %s AND created_at < %s",
                                        $since_mysql,
                                        $until_mysql
                                ),
                                ARRAY_A
                        );
                } else {
                        $row = $wpdb->get_row(
                                $wpdb->prepare(
                                        "SELECT COUNT(*) AS requests,
                                                COALESCE( SUM( prompt_tokens ), 0 ) AS prompt_tokens,
                                                COALESCE( SUM( completion_tokens ), 0 ) AS completion_tokens,
                                                COALESCE( SUM( total_tokens ), 0 ) AS total_tokens,
                                                COALESCE( SUM( cost_estimate ), 0 ) AS cost
                                        FROM {$wpdb->prefix}kic_usage
                                        WHERE created_at >= %s",
                                        $since_mysql
                                ),
                                ARRAY_A
                        );
                }

                return array(
                        'requests'          => (int) ( is_array( $row ) ? $row['requests'] : 0 ),
                        'prompt_tokens'     => (int) ( is_array( $row ) ? $row['prompt_tokens'] : 0 ),
                        'completion_tokens' => (int) ( is_array( $row ) ? $row['completion_tokens'] : 0 ),
                        'total_tokens'      => (int) ( is_array( $row ) ? $row['total_tokens'] : 0 ),
                        'cost'              => (float) ( is_array( $row ) ? $row['cost'] : 0 ),
                );
        }

        /**
         * Today's token total so far.
         *
         * @return int
         */
        public static function usage_today() {
                $since = gmdate( 'Y-m-d' ) . ' 00:00:00';
                $usage = self::usage_between( $since );
                return $usage['total_tokens'];
        }

        /**
         * Trailing 30-day DAILY average (excluding today).
         *
         * @return float
         */
        public static function daily_average_30d() {
                global $wpdb;

                $since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

                $row = $wpdb->get_row(
                        $wpdb->prepare(
                                "SELECT COALESCE( SUM( total_tokens ), 0 ) AS total
                                FROM {$wpdb->prefix}kic_usage
                                WHERE created_at >= %s AND created_at < %s",
                                $since,
                                gmdate( 'Y-m-d' ) . ' 00:00:00'
                        ),
                        ARRAY_A
                );

                $total = (int) ( is_array( $row ) ? $row['total'] : 0 );
                return $total / 30.0;
        }

        /* ---------------------------------------------------------------------
         * Weekly summary email.
         * ------------------------------------------------------------------- */

        /**
         * Build the weekly summary (numbers + post list) — also used for the
         * admin preview on the Reports screen.
         *
         * @return array {usage, failures, posts: [{title,edit_url,date}]}
         */
        public static function weekly_data() {
                $since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

                $usage = self::usage_between( $since );

                $failures = 0;
                if ( class_exists( 'KIC_Chain_Health' ) && method_exists( 'KIC_Chain_Health', 'failures_since' ) ) {
                        $failures = KIC_Chain_Health::failures_since( time() - 7 * DAY_IN_SECONDS );
                }

                $posts = get_posts(
                        array(
                                'post_type'      => array( 'post', 'page' ),
                                'post_status'    => 'publish',
                                'posts_per_page' => 50,
                                'orderby'        => 'date',
                                'order'          => 'DESC',
                                'no_found_rows'  => true,
                                'date_query'     => array(
                                        array(
                                                'after' => date_i18n( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
                                        ),
                                ),
                        )
                );

                $post_rows = array();
                if ( is_array( $posts ) ) {
                        foreach ( $posts as $post ) {
                                $post_rows[] = array(
                                        'title'    => ( '' !== trim( (string) $post->post_title ) ) ? (string) $post->post_title : kic_t( 'عنوان' ) . ' —',
                                        'edit_url' => function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
                                        'date'     => (string) $post->post_date,
                                );
                        }
                }

                return array(
                        'usage'    => $usage,
                        'failures' => $failures,
                        'posts'    => $post_rows,
                );
        }

        /**
         * Render the weekly summary as plain text (email body).
         *
         * @param array $data weekly_data() output.
         * @return string
         */
        public static function render_weekly_text( $data ) {
                $usage    = is_array( $data ) && isset( $data['usage'] ) ? $data['usage'] : array();
                $failures = is_array( $data ) && isset( $data['failures'] ) ? (int) $data['failures'] : 0;
                $posts    = is_array( $data ) && isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array();

                $lines = array();
                $lines[] = '=== Natilus — ' . kic_t( 'خلاصهٔ هفتگی ایمیلی' ) . ' ===';
                $lines[] = '';
                $lines[] = kic_t( 'این هفته' ) . ':';
                $lines[] = '- ' . kic_t( 'وضعیت' ) . ': ' . number_format_i18n( isset( $usage['requests'] ) ? $usage['requests'] : 0 ) . ' request(s)';
                $lines[] = '- tokens: ' . number_format_i18n( isset( $usage['total_tokens'] ) ? $usage['total_tokens'] : 0 );
                $lines[] = '- cost: $' . number_format( (float) ( isset( $usage['cost'] ) ? $usage['cost'] : 0 ), 2 );
                $lines[] = '- ' . kic_t( 'تاریخچهٔ سلامت زنجیره' ) . ': ' . number_format_i18n( $failures ) . ' failure(s)';
                $lines[] = '';
                $lines[] = kic_t( 'منتشرشده' ) . ' (' . number_format_i18n( count( $posts ) ) . '):';

                foreach ( $posts as $post ) {
                        $lines[] = ' - ' . $post['title'] . ' (' . substr( (string) $post['date'], 0, 16 ) . ')';
                }

                if ( empty( $posts ) ) {
                        $lines[] = ' -';
                }

                $lines[] = '';
                $lines[] = kic_t( 'گزارش‌ها و سلامت' ) . ': ' . admin_url( 'admin.php?page=kic-reports' );

                return implode( "\n", $lines );
        }

        /**
         * Cron handler: send the weekly summary email (when enabled).
         *
         * @return void
         */
        public static function send_weekly_summary() {
                if ( ! kic_get_setting( 'weekly_report_enabled', 1 ) ) {
                        return;
                }

                $data = self::weekly_data();
                $body = self::render_weekly_text( $data );

                wp_mail(
                        get_option( 'admin_email' ),
                        'Natilus — ' . kic_t( 'خلاصهٔ هفتگی ایمیلی' ) . ' (' . date_i18n( 'Y-m-d', time() ) . ')',
                        $body,
                        array( 'Content-Type: text/plain; charset=UTF-8' )
                );
        }

        /* ---------------------------------------------------------------------
         * Unusual usage alert.
         * ------------------------------------------------------------------- */

        /**
         * Cron handler (daily): compare today's usage with the trailing average.
         * One notice + at most one email per day.
         *
         * @return void
         */
        public static function daily_usage_check() {
                if ( ! kic_get_setting( 'spike_alert_enabled', 1 ) ) {
                        return;
                }

                $today   = self::usage_today();
                $average = self::daily_average_30d();

                if ( $today < self::SPIKE_FLOOR || $average <= 0 ) {
                        return;
                }

                if ( $today < $average * self::SPIKE_MULTIPLIER ) {
                        return;
                }

                $today_key = gmdate( 'Y-m-d' );

                /* Persistent admin notice until dismissed. */
                $notice = array(
                        'date'    => $today_key,
                        'today'   => $today,
                        'average' => round( $average ),
                );
                update_option( 'kic_spike_notice', $notice, false );

                /* One email per day, best-effort. */
                $last_mail = (string) get_option( 'kic_spike_last_mail', '' );
                if ( $today_key !== $last_mail ) {
                        update_option( 'kic_spike_last_mail', $today_key, false );
                        wp_mail(
                                get_option( 'admin_email' ),
                                'Natilus — ' . kic_t( 'هشدار مصرف غیرعادی' ),
                                kic_t( 'هشدار مصرف غیرعادی' ) . "\n\n"
                                        . date_i18n( 'Y-m-d', time() ) . "\n"
                                        . 'today: ' . number_format_i18n( $today ) . " tokens\n"
                                        . '30-day daily average: ' . number_format_i18n( round( $average ) ) . " tokens\n\n"
                                        . admin_url( 'admin.php?page=kic-dashboard' ),
                                array( 'Content-Type: text/plain; charset=UTF-8' )
                        );
                }
        }

        /**
         * Show the spike notice in wp-admin (managers only, dismissible).
         *
         * @return void
         */
        public static function spike_notice() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                /* Dismiss link handling. */
                if ( isset( $_GET['kic_dismiss_spike'] ) && check_admin_referer( 'kic_dismiss_spike' ) ) {
                        delete_option( 'kic_spike_notice' );
                        return;
                }

                $notice = get_option( 'kic_spike_notice', array() );
                if ( ! is_array( $notice ) || empty( $notice['today'] ) ) {
                        return;
                }
                if ( (string) $notice['date'] !== gmdate( 'Y-m-d' ) ) {
                        delete_option( 'kic_spike_notice' );
                        return;
                }

                $dismiss = wp_nonce_url( add_query_arg( 'kic_dismiss_spike', 1 ), 'kic_dismiss_spike' );

                echo '<div class="notice notice-warning is-dismissible"><p><strong>'
                        . esc_html( 'Natilus — ' . kic_t( 'هشدار مصرف غیرعادی' ) )
                        . '</strong><br>'
                        . esc_html( kic_t( 'هشدار مصرف غیرعادی' ) . ': ' . number_format_i18n( (int) $notice['today'] ) . ' / ' . kic_t( 'امروز' ) )
                        . ' — '
                        . esc_html( '30-day avg: ' . number_format_i18n( (int) $notice['average'] ) )
                        . ' &nbsp;<a href="' . esc_url( $dismiss ) . '">' . esc_html( kic_t( 'بستن' ) ) . '</a>'
                        . '</p></div>';
        }

        /* ---------------------------------------------------------------------
         * Admin page.
         * ------------------------------------------------------------------- */

        /**
         * «گزارش‌ها و سلامت» submenu page.
         *
         * @return void
         */
        public static function register_menu() {
                add_submenu_page(
                        'ai-content-publishing-agent',
                        kic_t( 'گزارش‌ها و سلامت' ),
                        kic_t( 'گزارش‌ها و سلامت' ),
                        'manage_options',
                        'kic-reports',
                        array( __CLASS__, 'render_page' )
                );
        }

        /**
         * Enqueue the shared admin stylesheet on the reports screen.
         *
         * @param string $hook Current admin page hook suffix.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, 'kic-reports' ) ) {
                        return;
                }
                wp_enqueue_style( 'kic-admin', KIC_URL . 'admin/css/kic-admin.css', array(), KIC_VERSION );
        }

        /**
         * Reports render callback.
         *
         * @return void
         */
        public static function render_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html( kic_t( 'شما اجازهٔ دسترسی به این صفحه را ندارید.' ) ) );
                }

                $opportunities = array();
                if ( class_exists( 'KIC_Prepublish' ) && method_exists( 'KIC_Prepublish', 'link_opportunities' ) ) {
                        $opportunities = KIC_Prepublish::link_opportunities( 25 );
                }

                $chain_events = array();
                if ( class_exists( 'KIC_Chain_Health' ) && method_exists( 'KIC_Chain_Health', 'events' ) ) {
                        $chain_events = KIC_Chain_Health::events( 30 );
                }

                $weekly = self::weekly_data();
                $weekly_text = self::render_weekly_text( $weekly );
                $weekly_enabled = (bool) kic_get_setting( 'weekly_report_enabled', 1 );
                $spike_enabled = (bool) kic_get_setting( 'spike_alert_enabled', 1 );

                require KIC_PATH . 'admin/views/reports-page.php';
        }
}
