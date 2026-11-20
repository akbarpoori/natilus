<?php
/**
 * Natilus — WP-CLI commands.
 *
 * Free-distribution helper for hosts where WP-Cron is unreliable or for
 * admins who prefer the terminal:
 *
 *   wp kic queue                     — show the drip publishing queue.
 *   wp kic queue --action=run        — publish the next queued item NOW.
 *   wp kic queue --action=pause      — pause the queue (slots kept).
 *   wp kic queue --action=resume     — resume the queue.
 *   wp kic tokens [--days=30]        — usage report.
 *
 * Read-only except run/pause/resume, which do exactly what their
 * admin-screen counterparts do (explicit operator actions).
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

        /**
         * WP-CLI command set: wp kic ...
         */
        final class KIC_CLI_Commands {

                /**
                 * Manage the drip publishing queue.
                 *
                 * ## OPTIONS
                 *
                 * [--action=<action>]
                 * : list (default) | run | pause | resume.
                 *
                 * [--month=<Y-m>]
                 * : Only items of that month (list action only).
                 *
                 * ## EXAMPLES
                 *
                 *     wp kic queue
                 *     wp kic queue --action=run
                 *     wp kic queue --action=pause
                 *     wp kic queue --action=list --month=2026-11
                 *
                 * @param array $args       Positional args (unused).
                 * @param array $assoc_args Flags.
                 * @return void
                 */
                public function queue( $args, $assoc_args ) {

                        if ( ! class_exists( 'KIC_Queue_Manager' ) ) {
                                \WP_CLI::error( 'Queue manager module missing.' );
                        }

                        $action = 'list';
                        if ( isset( $assoc_args['action'] ) ) {
                                $action = strtolower( sanitize_key( (string) $assoc_args['action'] ) );
                        }

                        if ( 'run' === $action ) {
                                $result = KIC_Queue_Manager::run_next();
                                if ( is_wp_error( $result ) ) {
                                        \WP_CLI::error( $result->get_error_message() );
                                }
                                \WP_CLI::success( sprintf( 'Published #%d — %s', $result['id'], $result['title'] ) );
                                return;
                        }

                        if ( 'pause' === $action ) {
                                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                                $settings['drip_paused'] = 1;
                                update_option( 'kic_settings', $settings, false );
                                \WP_CLI::success( 'Queue paused. Scheduled slots are kept and will fire after resume.' );
                                return;
                        }

                        if ( 'resume' === $action ) {
                                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                                $settings['drip_paused'] = 0;
                                update_option( 'kic_settings', $settings, false );
                                \WP_CLI::success( 'Queue resumed.' );
                                return;
                        }

                        /* Default: list. */
                        $month = 'all';
                        if ( isset( $assoc_args['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $assoc_args['month'] ) ) {
                                $month = (string) $assoc_args['month'];
                        }

                        $items  = KIC_Queue_Manager::queue_items( $month );
                        $paused = KIC_Queue_Manager::paused();

                        \WP_CLI::line( 'KIC publish queue' );
                        \WP_CLI::line( $paused ? 'Status: PAUSED' : 'Status: running' );
                        \WP_CLI::line( 'Items: ' . count( $items ) );

                        if ( empty( $items ) ) {
                                \WP_CLI::line( '(empty)' );
                                return;
                        }

                        \WP_CLI::line( '' );
                        foreach ( $items as $item ) {
                                \WP_CLI::line(
                                        sprintf(
                                                '  %s %s  #%d  %s',
                                                $item['date'],
                                                $item['time'],
                                                $item['id'],
                                                $item['title']
                                        )
                                );
                        }
                }

                /**
                 * Token usage report.
                 *
                 * ## OPTIONS
                 *
                 * [--days=<days>]
                 * : Look-back window in days (default 30).
                 *
                 * ## EXAMPLES
                 *
                 *     wp kic tokens
                 *     wp kic tokens --days=7
                 *
                 * @param array $args       Positional args (unused).
                 * @param array $assoc_args Flags.
                 * @return void
                 */
                public function tokens( $args, $assoc_args ) {
                        \WP_CLI::line( 'KIC token usage (optional suggestions only — publishing spends nothing)' );

                        if ( ! class_exists( 'KIC_Token_Tracker' ) || ! method_exists( 'KIC_Token_Tracker', 'get_stats' ) ) {
                                \WP_CLI::error( 'Token tracker module missing.' );
                        }

                        $days = 30;
                        if ( isset( $assoc_args['days'] ) ) {
                                $days = max( 1, min( 365, (int) $assoc_args['days'] ) );
                        }

                        $stats = KIC_Token_Tracker::get_stats( $days );
                        if ( ! is_array( $stats ) ) {
                                \WP_CLI::error( 'No usage data available.' );
                        }

                        /* v2.1 — get_stats() returns { totals, by_day, by_user, by_action, recent }. */
                        $totals = isset( $stats['totals'] ) && is_array( $stats['totals'] ) ? $stats['totals'] : array();

                        \WP_CLI::line( sprintf( 'Window: last %d days', $days ) );
                        \WP_CLI::line( sprintf( 'Requests: %s', number_format( (int) ( isset( $totals['requests'] ) ? $totals['requests'] : 0 ) ) ) );
                        \WP_CLI::line( sprintf( 'Prompt tokens: %s', number_format( (int) ( isset( $totals['prompt_tokens'] ) ? $totals['prompt_tokens'] : 0 ) ) ) );
                        \WP_CLI::line( sprintf( 'Completion tokens: %s', number_format( (int) ( isset( $totals['completion_tokens'] ) ? $totals['completion_tokens'] : 0 ) ) ) );
                        \WP_CLI::line( sprintf( 'Total tokens: %s', number_format( (int) ( isset( $totals['total_tokens'] ) ? $totals['total_tokens'] : 0 ) ) ) );
                        \WP_CLI::line( sprintf( 'Cost estimate: $%s', number_format( (float) ( isset( $totals['cost_estimate'] ) ? $totals['cost_estimate'] : 0 ), 2 ) ) );

                        if ( isset( $stats['by_action'] ) && is_array( $stats['by_action'] ) ) {
                                \WP_CLI::line( '' );
                                \WP_CLI::line( 'By action:' );
                                foreach ( $stats['by_action'] as $row ) {
                                        \WP_CLI::line( sprintf( '  %-24s %s tokens', $row['action'], number_format( (int) $row['total_tokens'] ) ) );
                                }
                        }
                }
        }

        try {
                WP_CLI::add_command( 'kic', 'KIC_CLI_Commands' );
        } catch ( Exception $kic_cli_error ) {
                // WP-CLI present but the command API misbehaves — never break the site.
        }
}
