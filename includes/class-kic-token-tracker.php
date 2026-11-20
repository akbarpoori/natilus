<?php
/**
 * Token usage tracker + consumption dashboard.
 *
 * Every API interaction is persisted into {$wpdb->prefix}kic_usage and
 * aggregated into an admin dashboard ("داشبورد مصرف") nested under the
 * "ai-content-publishing-agent" parent menu. Aggregates are cached in transients
 * for 60 seconds and invalidated whenever a new row is logged.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Token_Tracker {

        /**
         * Dashboard range whitelist (days).
         */
        const RANGES = array( 7, 30, 90 );

        /**
         * Aggregate cache lifetime in seconds.
         */
        const CACHE_TTL = 60;

        /**
         * Singleton instance (bootstrap contract).
         *
         * @var KIC_Token_Tracker|null
         */
        private static $instance = null;

        /**
         * @return KIC_Token_Tracker
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {}

        /**
         * Hook registration. The parent menu slug "ai-content-publishing-agent" is
         * registered by the settings module at admin_menu priority 5, so the
         * dashboard submenu hooks in at priority 10.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 10 );
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        }

        /**
         * Register the dashboard submenu page.
         *
         * @return void
         */
        public static function register_menu() {
                add_submenu_page(
                        'ai-content-publishing-agent',
                        kic_t( 'داشبورد مصرف' ),
                        kic_t( 'داشبورد مصرف' ),
                        'manage_options',
                        'kic-dashboard',
                        array( __CLASS__, 'render_page' )
                );
        }

        /**
         * Enqueue the shared admin stylesheet on the dashboard screen.
         *
         * @param string $hook Current admin page hook suffix.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, 'kic-dashboard' ) ) {
                        return;
                }
                foreach ( array( 'admin/css/kic-admin.css', 'assets/kic-admin.css' ) as $relative ) {
                        if ( file_exists( KIC_PATH . $relative ) ) {
                                wp_enqueue_style( 'kic-admin', KIC_URL . $relative, array(), KIC_VERSION );
                                return;
                        }
                }
        }

        /**
         * Dashboard render callback (server-rendered view, no JS).
         *
         * @return void
         */
        public static function render_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'دسترسی غیرمجاز به داشبورد مصرف.', 'ai-content-publishing-agent' ) );
                }

                $days  = self::sanitize_days( absint( isset( $_GET['days'] ) ? wp_unslash( $_GET['days'] ) : 30 ) );
                $stats = self::get_stats( $days );

                require KIC_PATH . 'admin/views/dashboard-page.php';
        }

        /**
         * Persist one usage row into {$wpdb->prefix}kic_usage.
         *
         * Expected keys: user_id, post_id, action (<=32 chars), model (<=128),
         * provider (<=191), prompt_tokens, completion_tokens, total_tokens,
         * cost_estimate (float). Missing keys default to zero/empty.
         *
         * @param array $row Usage row.
         * @return int|false Inserted row count or false on failure.
         */
        public static function log( $row ) {
                global $wpdb;

                if ( ! is_array( $row ) ) {
                        return false;
                }

                $row = wp_parse_args( $row, array(
                        'user_id'           => 0,
                        'post_id'           => 0,
                        'action'            => '',
                        'model'             => '',
                        'provider'          => '',
                        'prompt_tokens'     => 0,
                        'completion_tokens' => 0,
                        'total_tokens'      => 0,
                        'cost_estimate'     => 0.0,
                ) );

                $data = array(
                        'user_id'           => absint( $row['user_id'] ),
                        'post_id'           => absint( $row['post_id'] ),
                        'action'            => substr( sanitize_key( self::scalar_string( $row['action'] ) ), 0, 32 ),
                        'model'             => substr( sanitize_text_field( self::scalar_string( $row['model'] ) ), 0, 128 ),
                        'provider'          => substr( sanitize_text_field( self::scalar_string( $row['provider'] ) ), 0, 191 ),
                        'prompt_tokens'     => absint( $row['prompt_tokens'] ),
                        'completion_tokens' => absint( $row['completion_tokens'] ),
                        'total_tokens'      => absint( $row['total_tokens'] ),
                        'cost_estimate'     => (float) $row['cost_estimate'],
                        'created_at'        => gmdate( 'Y-m-d H:i:s' ),
                );

                $format = array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s' );

                $inserted = $wpdb->insert( $wpdb->prefix . 'kic_usage', $data, $format );

                if ( false !== $inserted ) {
                        // Fresh data exists — drop the cached aggregates.
                        delete_transient( 'kic_stats_7' );
                        delete_transient( 'kic_stats_30' );
                        delete_transient( 'kic_stats_90' );
                }

                return $inserted;
        }

        /**
         * Aggregated usage statistics for the requested range.
         *
         * @param int $days Range in days (whitelisted to 7/30/90, default 30).
         * @return array {
         *     @type array $totals   requests, prompt_tokens, completion_tokens,
         *                           total_tokens, cost_estimate, today_tokens.
         *     @type array $by_day   Chronological list with date + total_tokens
         *                           (missing days filled with 0).
         *     @type array $by_user  Top 10 users (display_name, requests, total_tokens).
         *     @type array $by_action Per-action rows (action, requests, total_tokens).
         *     @type array $recent   Last 50 rows with display_name + human diff.
         * }
         */
        public static function get_stats( $days = 30 ) {
                global $wpdb;

                $days = self::sanitize_days( $days );

                $cache_key = 'kic_stats_' . $days;
                $cached    = get_transient( $cache_key );
                if ( false !== $cached && is_array( $cached ) ) {
                        return $cached;
                }

                $table = $wpdb->prefix . 'kic_usage';
                $now   = time();
                $since = gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
                $today = gmdate( 'Y-m-d 00:00:00', $now );

                // Totals for the window plus a conditional "today" token sum.
                $totals = $wpdb->get_row(
                        $wpdb->prepare(
                                "SELECT COUNT(*) AS requests,
                                        COALESCE( SUM( prompt_tokens ), 0 ) AS prompt_tokens,
                                        COALESCE( SUM( completion_tokens ), 0 ) AS completion_tokens,
                                        COALESCE( SUM( total_tokens ), 0 ) AS total_tokens,
                                        COALESCE( SUM( cost_estimate ), 0 ) AS cost_estimate,
                                        COALESCE( SUM( CASE WHEN created_at >= %s THEN total_tokens ELSE 0 END ), 0 ) AS today_tokens
                                FROM {$wpdb->prefix}kic_usage
                                WHERE created_at >= %s",
                                $today,
                                $since
                        ),
                        ARRAY_A
                );

                $totals_out = array(
                        'requests'          => 0,
                        'prompt_tokens'     => 0,
                        'completion_tokens' => 0,
                        'total_tokens'      => 0,
                        'cost_estimate'     => 0.0,
                        'today_tokens'      => 0,
                );

                if ( is_array( $totals ) ) {
                        $totals_out['requests']          = absint( isset( $totals['requests'] ) ? $totals['requests'] : 0 );
                        $totals_out['prompt_tokens']     = absint( isset( $totals['prompt_tokens'] ) ? $totals['prompt_tokens'] : 0 );
                        $totals_out['completion_tokens'] = absint( isset( $totals['completion_tokens'] ) ? $totals['completion_tokens'] : 0 );
                        $totals_out['total_tokens']      = absint( isset( $totals['total_tokens'] ) ? $totals['total_tokens'] : 0 );
                        $totals_out['cost_estimate']     = (float) ( isset( $totals['cost_estimate'] ) ? $totals['cost_estimate'] : 0 );
                        $totals_out['today_tokens']      = absint( isset( $totals['today_tokens'] ) ? $totals['today_tokens'] : 0 );
                }

                // Per-day totals keyed by Y-m-d.
                $day_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT DATE( created_at ) AS date, COALESCE( SUM( total_tokens ), 0 ) AS total_tokens
                                FROM {$wpdb->prefix}kic_usage
                                WHERE created_at >= %s
                                GROUP BY DATE( created_at )",
                                $since
                        ),
                        ARRAY_A
                );

                $day_map = array();
                if ( is_array( $day_rows ) ) {
                        foreach ( $day_rows as $day_row ) {
                                if ( is_array( $day_row ) && isset( $day_row['date'] ) ) {
                                        $day_map[ (string) $day_row['date'] ] = absint( isset( $day_row['total_tokens'] ) ? $day_row['total_tokens'] : 0 );
                                }
                        }
                }

                $by_day = array();
                for ( $i = $days - 1; $i >= 0; $i-- ) {
                        $d        = gmdate( 'Y-m-d', $now - ( $i * DAY_IN_SECONDS ) );
                        $by_day[] = array(
                                'date'         => $d,
                                'total_tokens' => isset( $day_map[ $d ] ) ? $day_map[ $d ] : 0,
                        );
                }

                // Top 10 users within the window (LEFT JOIN keeps user_id = 0 rows).
                $user_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT s.user_id, u.display_name, COUNT(*) AS requests,
                                        COALESCE( SUM( s.total_tokens ), 0 ) AS total_tokens
                                FROM {$wpdb->prefix}kic_usage s
                                LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
                                WHERE s.created_at >= %s
                                GROUP BY s.user_id, u.display_name
                                ORDER BY total_tokens DESC
                                LIMIT 10",
                                $since
                        ),
                        ARRAY_A
                );

                $by_user = array();
                if ( is_array( $user_rows ) ) {
                        foreach ( $user_rows as $user_row ) {
                                if ( ! is_array( $user_row ) ) {
                                        continue;
                                }
                                $uid  = absint( isset( $user_row['user_id'] ) ? $user_row['user_id'] : 0 );
                                $name = self::scalar_string( isset( $user_row['display_name'] ) ? $user_row['display_name'] : '' );
                                if ( $uid < 1 ) {
                                        $name = 'سیستم';
                                } elseif ( '' === $name ) {
                                        $name = 'کاربر #' . $uid;
                                }
                                $by_user[] = array(
                                        'display_name' => $name,
                                        'requests'     => absint( isset( $user_row['requests'] ) ? $user_row['requests'] : 0 ),
                                        'total_tokens' => absint( isset( $user_row['total_tokens'] ) ? $user_row['total_tokens'] : 0 ),
                                );
                        }
                }

                // Per-action totals within the window.
                $action_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT action, COUNT(*) AS requests,
                                        COALESCE( SUM( total_tokens ), 0 ) AS total_tokens
                                FROM {$wpdb->prefix}kic_usage
                                WHERE created_at >= %s
                                GROUP BY action
                                ORDER BY total_tokens DESC",
                                $since
                        ),
                        ARRAY_A
                );

                $by_action = array();
                if ( is_array( $action_rows ) ) {
                        foreach ( $action_rows as $action_row ) {
                                if ( ! is_array( $action_row ) ) {
                                        continue;
                                }
                                $by_action[] = array(
                                        'action'       => self::scalar_string( isset( $action_row['action'] ) ? $action_row['action'] : '' ),
                                        'requests'     => absint( isset( $action_row['requests'] ) ? $action_row['requests'] : 0 ),
                                        'total_tokens' => absint( isset( $action_row['total_tokens'] ) ? $action_row['total_tokens'] : 0 ),
                                );
                        }
                }

                // Last 50 rows with the author display name and a human readable age.
                $recent_rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT s.user_id, s.post_id, s.action, s.model, s.provider,
                                        s.prompt_tokens, s.completion_tokens, s.total_tokens, s.cost_estimate,
                                        s.created_at, u.display_name
                                FROM {$wpdb->prefix}kic_usage s
                                LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
                                WHERE s.created_at >= %s
                                ORDER BY s.created_at DESC, s.id DESC
                                LIMIT 50",
                                $since
                        ),
                        ARRAY_A
                );

                $recent = array();
                if ( is_array( $recent_rows ) ) {
                        foreach ( $recent_rows as $recent_row ) {
                                if ( ! is_array( $recent_row ) ) {
                                        continue;
                                }

                                $uid  = absint( isset( $recent_row['user_id'] ) ? $recent_row['user_id'] : 0 );
                                $name = self::scalar_string( isset( $recent_row['display_name'] ) ? $recent_row['display_name'] : '' );
                                if ( $uid < 1 ) {
                                        $display = 'سیستم';
                                } elseif ( '' === $name ) {
                                        $display = 'کاربر #' . $uid;
                                } else {
                                        $display = $name;
                                }

                                $created_at = self::scalar_string( isset( $recent_row['created_at'] ) ? $recent_row['created_at'] : '' );
                                $human      = '';
                                if ( '0000-00-00' !== substr( $created_at, 0, 10 ) ) {
                                        $timestamp = strtotime( $created_at );
                                        if ( false !== $timestamp && $timestamp > 0 ) {
                                                $age = time() - (int) $timestamp;
                                                if ( $age >= 0 ) {
                                                        $human = human_time_diff( (int) $timestamp, time() );
                                                }
                                        }
                                }

                                $recent[] = array(
                                        'user_id'           => $uid,
                                        'post_id'           => absint( isset( $recent_row['post_id'] ) ? $recent_row['post_id'] : 0 ),
                                        'action'            => self::scalar_string( isset( $recent_row['action'] ) ? $recent_row['action'] : '' ),
                                        'model'             => self::scalar_string( isset( $recent_row['model'] ) ? $recent_row['model'] : '' ),
                                        'provider'          => self::scalar_string( isset( $recent_row['provider'] ) ? $recent_row['provider'] : '' ),
                                        'prompt_tokens'     => absint( isset( $recent_row['prompt_tokens'] ) ? $recent_row['prompt_tokens'] : 0 ),
                                        'completion_tokens' => absint( isset( $recent_row['completion_tokens'] ) ? $recent_row['completion_tokens'] : 0 ),
                                        'total_tokens'      => absint( isset( $recent_row['total_tokens'] ) ? $recent_row['total_tokens'] : 0 ),
                                        'cost_estimate'     => (float) ( isset( $recent_row['cost_estimate'] ) ? $recent_row['cost_estimate'] : 0 ),
                                        'created_at'        => $created_at,
                                        'display_name'      => $display,
                                        'human'             => $human,
                                );
                        }
                }

                $stats = array(
                        'totals'    => $totals_out,
                        'by_day'    => $by_day,
                        'by_user'   => $by_user,
                        'by_action' => $by_action,
                        'recent'    => $recent,
                );

                set_transient( $cache_key, $stats, self::CACHE_TTL );

                return $stats;
        }

        /**
         * Whitelist a dashboard range.
         *
         * @param mixed $raw Requested days value.
         * @return int
         */
        private static function sanitize_days( $raw ) {
                $days = absint( $raw );
                if ( ! in_array( $days, self::RANGES, true ) ) {
                        $days = 30;
                }
                return $days;
        }

        /**
         * Cast a value to a string without emitting PHP notices.
         *
         * @param mixed $value Raw value.
         * @return string
         */
        private static function scalar_string( $value ) {
                return is_scalar( $value ) ? (string) $value : '';
        }
}
