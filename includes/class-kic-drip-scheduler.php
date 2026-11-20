<?php
/**
 * Natilus — drip publishing for queued bulk drafts.
 *
 * Instead of leaving dozens of finished drafts sitting unpublished, every
 * draft added to the drip queue receives a future slot (start offset +
 * N posts per day, spread over 12 hours from the publish hour) and a single
 * WP-Cron event flips it to "publish" at that time.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Drip_Scheduler {

        /**
         * Singleton instance.
         *
         * @var KIC_Drip_Scheduler|null
         */
        private static $instance = null;

        /**
         * Register the WP-Cron handler (idempotent).
         *
         * @return KIC_Drip_Scheduler
         */
        public static function init() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                add_action( 'kic_drip_publish', array( __CLASS__, 'publish' ), 10, 1 );
                return self::$instance;
        }

        /**
         * Singleton accessor (lazily bootstrapped).
         *
         * @return KIC_Drip_Scheduler
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::init();
                }
                return self::$instance;
        }

        /**
         * Private constructor (singleton contract; the cron hook lives in init()).
         */
        private function __construct() {
        }

        /**
         * Whether drip publishing is enabled.
         *
         * @return bool
         */
        public static function enabled() {
                return (bool) kic_get_setting( 'drip_enabled' );
        }

        /**
         * Whether the queue is paused by the admin (v1.4 queue operations).
         * A paused queue keeps every slot; cron events are re-armed +6h until
         * the admin resumes, so nothing is lost and nothing publishes.
         *
         * @return bool
         */
        public static function paused() {
                return (bool) kic_get_setting( 'drip_paused', 0 );
        }

        /**
         * Assign the next free drip slot to a draft and arm its cron event.
         *
         * Slot math: existing scheduled drafts (draft + _kic_drip=1) define the
         * queue depth; day_index = floor(depth / per_day), slot_in_day =
         * depth % per_day spread over 12 hours starting at drip_publish_hour.
         * Never schedules into the past (pushes a full day forward, 5-min grace).
         *
         * @param int $post_id Draft post id.
         * @return string Local 'Y-m-d H:i:s' slot datetime, or '' when disabled/failed.
         */
        public static function schedule( $post_id ) {
                if ( ! self::enabled() ) {
                        return '';
                }

                $post_id = absint( $post_id );
                $post    = $post_id ? get_post( $post_id ) : null;
                if ( ! $post ) {
                        return '';
                }

                // Idempotence: never hand out a second slot for the same post.
                $existing = (string) get_post_meta( $post_id, '_kic_drip', true );
                if ( '' !== trim( $existing ) ) {
                        return '';
                }

                $per_day = absint( kic_get_setting( 'drip_posts_per_day', 3 ) );
                $per_day = min( 50, max( 1, $per_day ) );

                $hour = absint( kic_get_setting( 'drip_publish_hour', 9 ) );
                $hour = min( 23, max( 0, $hour ) );

                $offset_days = absint( kic_get_setting( 'drip_start_days', 1 ) );
                $offset_days = min( 30, max( 0, $offset_days ) );

                // Queue depth: drip slots already handed out to still-draft posts.
                $already = get_posts(
                        array(
                                'post_type'      => 'any',
                                'post_status'    => 'draft',
                                'fields'         => 'ids',
                                'posts_per_page' => -1,
                                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                                        array(
                                                'key'     => '_kic_drip',
                                                'value'   => '1',
                                                'compare' => '=',
                                        ),
                                ),
                        )
                );
                $already = is_array( $already ) ? count( $already ) : 0;

                $day_index   = intdiv( $already, $per_day );
                $slot_in_day = $already % $per_day;

                // Spread the day's slots evenly across 12 hours (e.g. 09:00 -> 21:00).
                $minutes = intdiv( 60 * 12, max( $per_day, 1 ) ) * $slot_in_day;

                $now        = time();
                $now_local  = current_datetime()->getTimestamp();
                $day_anchor = $now - ( $now_local % DAY_IN_SECONDS ); // local midnight as a true epoch
                $when       = $day_anchor
                        + ( $offset_days + $day_index ) * DAY_IN_SECONDS
                        + $hour * HOUR_IN_SECONDS
                        + $minutes * 60;

                // Never in the past: nudge one full day forward (5-minute grace).
                if ( $when < $now + 300 ) {
                        $when += DAY_IN_SECONDS;
                }

                $when_str = date_i18n( 'Y-m-d H:i:s', $when );

                update_post_meta( $post_id, '_kic_drip', 1 );

                $updated = wp_update_post(
                        array(
                                'ID'            => $post_id,
                                'post_date'     => $when_str,
                                'post_date_gmt' => get_gmt_from_date( $when_str ),
                        ),
                        true
                );
                if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
                        delete_post_meta( $post_id, '_kic_drip' );
                        return '';
                }

                $event_args = array( $post_id );
                if ( ! wp_next_scheduled( 'kic_drip_publish', $event_args ) ) {
                        wp_schedule_single_event(
                                strtotime( get_gmt_from_date( $when_str ) ),
                                'kic_drip_publish',
                                $event_args
                        );
                }

                return $when_str;
        }

        /**
         * Cron handler: publish exactly one scheduled draft (meta keeps a trail).
         *
         * @param int $post_id Post id passed by the cron event.
         * @return void
         */
        public static function publish( $post_id ) {
                $post_id = absint( $post_id );
                $post    = $post_id ? get_post( $post_id ) : null;
                if ( ! $post ) {
                        return;
                }
                if ( 'draft' !== $post->post_status ) {
                        return;
                }
                if ( '1' !== (string) get_post_meta( $post_id, '_kic_drip', true ) ) {
                        return;
                }

                /* v1.4 — paused queue: keep the slot, re-arm the event later. */
                if ( self::paused() ) {
                        wp_clear_scheduled_hook( 'kic_drip_publish', array( $post_id ) );
                        wp_schedule_single_event(
                                time() + 6 * HOUR_IN_SECONDS,
                                'kic_drip_publish',
                                array( $post_id )
                        );
                        return;
                }

                /* Explicit post_date keeps wp_insert_post() from flipping the
                 * status back to 'future' when the cron fires a few seconds
                 * early (the queued date may still be marginally ahead). */
                wp_update_post(
                        array(
                                'ID'            => $post_id,
                                'post_status'   => 'publish',
                                'post_date'     => current_time( 'mysql' ),
                                'post_date_gmt' => current_time( 'mysql', 1 ),
                        )
                );

                update_post_meta( $post_id, '_kic_drip', 'published' );
        }
}
