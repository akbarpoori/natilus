<?php
/**
 * Natilus — publishing queue calendar & operations.
 *
 * The drip scheduler turns bulk drafts into a queue of future slots. This
 * module gives the admin a calendar VIEW of that queue plus explicit queue
 * operations — pause/resume, drag-and-drop rescheduling (day granularity),
 * reorder, and "publish the next item now".
 *
 * Every operation is an explicit admin action: the plugin reschedules what
 * the admin moved; it never decides on its own what to publish when. The
 * queue itself keeps living in post meta (_kic_drip) + WP-Cron single
 * events, exactly as before.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Queue_Manager {

        /**
         * Singleton instance.
         *
         * @var KIC_Queue_Manager|null
         */
        private static $instance = null;

        /**
         * Lazy singleton bootstrap (called from KIC_Plugin::boot()).
         *
         * @return KIC_Queue_Manager
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
         * @return KIC_Queue_Manager
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

                add_action( 'wp_ajax_kic_queue_move', array( $this, 'ajax_queue_move' ) );
                add_action( 'wp_ajax_kic_queue_reorder', array( $this, 'ajax_queue_reorder' ) );
                add_action( 'wp_ajax_kic_queue_pause', array( $this, 'ajax_queue_pause' ) );
                add_action( 'wp_ajax_kic_queue_resume', array( $this, 'ajax_queue_resume' ) );
                add_action( 'wp_ajax_kic_queue_run_next', array( $this, 'ajax_queue_run_next' ) );
        }

        /* ---------------------------------------------------------------------
         * Admin page.
         * ------------------------------------------------------------------- */

        /**
         * «تقویم انتشار» submenu page.
         *
         * @return void
         */
        public static function register_menu() {
                add_submenu_page(
                        'ai-content-publishing-agent',
                        kic_t( 'تقویم انتشار' ),
                        kic_t( 'تقویم انتشار' ),
                        'manage_options',
                        'kic-calendar',
                        array( __CLASS__, 'render_page' )
                );
        }

        /**
         * Enqueue calendar assets on the calendar screen only.
         *
         * @param string $hook Current admin page hook suffix.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, 'kic-calendar' ) ) {
                        return;
                }

                wp_enqueue_style( 'kic-admin', KIC_URL . 'admin/css/kic-admin.css', array(), KIC_VERSION );
                wp_enqueue_script( 'kic-calendar', KIC_URL . 'admin/js/kic-calendar.js', array( 'jquery' ), KIC_VERSION, true );

                wp_localize_script(
                        'kic-calendar',
                        'KIC_CAL',
                        array(
                                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                                'nonce'   => wp_create_nonce( 'kic_ajax' ),
                                'i18n'    => KIC_I18n::js_package(),
                        )
                );
        }

        /**
         * Calendar render callback.
         *
         * @return void
         */
        public static function render_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html( kic_t( 'شما اجازهٔ دسترسی به این صفحه را ندارید.' ) ) );
                }

                $month = self::requested_month();
                $queue = self::queue_items( $month );
                $paused = self::paused();

                require KIC_PATH . 'admin/views/calendar-page.php';
        }

        /**
         * Requested month (Y-m) from the URL or the current month.
         *
         * @return string
         */
        private static function requested_month() {
                $month = isset( $_GET['month'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only calendar month param on a manage_options admin screen
                if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
                        $month = current_time( 'Y-m' );
                }
                return $month;
        }

        /* ---------------------------------------------------------------------
         * Queue data.
         * ------------------------------------------------------------------- */

        /**
         * Whether the queue is paused (admin toggle).
         *
         * @return bool
         */
        public static function paused() {
                return (bool) kic_get_setting( 'drip_paused', 0 );
        }

        /**
         * Drip-queued drafts for a month (Y-m): posts with _kic_drip=1 whose
         * post_date falls inside the month. Returns ALL queued items when the
         * month is 'all'.
         *
         * @param string $month 'Y-m' or 'all'.
         * @return array[] [{id,title,date,time,edit_url,url}]
         */
        public static function queue_items( $month = 'all' ) {
                $args = array(
                        'post_type'      => 'any',
                        'post_status'    => 'draft',
                        'posts_per_page' => 400,
                        'orderby'        => 'date',
                        'order'          => 'ASC',
                        'no_found_rows'  => true,
                        'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                                array(
                                        'key'   => '_kic_drip',
                                        'value' => '1',
                                        'compare' => '=',
                                ),
                        ),
                );

                $posts = get_posts( $args );
                if ( ! is_array( $posts ) ) {
                        return array();
                }

                $out = array();
                foreach ( $posts as $post ) {
                        $post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
                        if ( $post_id < 1 ) {
                                continue;
                        }

                        $date = is_object( $post ) && isset( $post->post_date ) ? (string) $post->post_date : '';
                        if ( '' === $date ) {
                                continue;
                        }

                        if ( 'all' !== $month && 0 !== strpos( $date, $month ) ) {
                                continue;
                        }

                        $parts = explode( ' ', $date );

                        $out[] = array(
                                'id'       => $post_id,
                                'title'    => ( '' !== trim( (string) $post->post_title ) ) ? (string) $post->post_title : kic_t( 'عنوان' ) . ' —',
                                'date'     => isset( $parts[0] ) ? $parts[0] : '',
                                'time'     => isset( $parts[1] ) ? substr( $parts[1], 0, 5 ) : '',
                                'edit_url' => function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
                                'url'      => function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '',
                        );
                }

                return $out;
        }

        /* ---------------------------------------------------------------------
         * Queue mutations (all require an explicit admin click).
         * ------------------------------------------------------------------- */

        /**
         * Move one queued item to another DAY (keeps the time of day). Reschedules
         * its cron event. Clamps into the future (never schedules into the past).
         *
         * @param int    $post_id Queued draft id.
         * @param string $date    New date 'Y-m-d'.
         * @return string|WP_Error New local datetime string, or error.
         */
        public static function move( $post_id, $date ) {
                $post_id = absint( $post_id );
                $post    = $post_id ? get_post( $post_id ) : null;

                if ( ! $post || 'draft' !== $post->post_status || '1' !== (string) get_post_meta( $post_id, '_kic_drip', true ) ) {
                        return new WP_Error( 'kic_queue', kic_t( 'این نوشته در صف انتشار نیست.' ) );
                }

                $date = trim( (string) $date );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                        return new WP_Error( 'kic_queue', kic_t( 'تاریخ جدید نامعتبر است.' ) );
                }

                /* Keep the time of day; default to the drip publish hour when empty. */
                $time = substr( (string) $post->post_date, 11, 8 );
                if ( '' === $time ) {
                        $time = sprintf( '%02d:00:00', absint( kic_get_setting( 'drip_publish_hour', 9 ) ) );
                }

                $when_str = $date . ' ' . $time;

                /* Never into the past: clamp to now + 2 minutes. */
                $gmt_offset = current_datetime()->getTimestamp() - time();
                $when       = strtotime( $when_str ) + $gmt_offset; // true epoch of the local wall-clock string
                $now        = time();
                if ( $when < $now + 120 ) {
                        $when     = $now + 120;
                        $when_str = date_i18n( 'Y-m-d H:i:s', $when );
                }

                self::reschedule( $post_id, $when_str );

                return $when_str;
        }

        /**
         * Swap two queued items' slots (reorder), keeping both cron events in
         * sync with their new dates.
         *
         * @param int  $post_id Item to move.
         * @param bool $up      true = one slot earlier, false = one slot later.
         * @return string|WP_Error Info message, or error.
         */
        public static function reorder( $post_id, $up ) {
                $items = self::queue_items( 'all' );
                if ( count( $items ) < 2 ) {
                        return new WP_Error( 'kic_queue', kic_t( 'برای جابه‌جایی حداقل دو آیتم لازم است.' ) );
                }

                /* PHP sorts queue_items by date ASC; find the target's neighbour. */
                $index = false;
                foreach ( $items as $i => $item ) {
                        if ( (int) $item['id'] === (int) $post_id ) {
                                $index = $i;
                                break;
                        }
                }
                if ( false === $index ) {
                        return new WP_Error( 'kic_queue', kic_t( 'این نوشته در صف انتشار نیست.' ) );
                }

                $swap = $up ? $index - 1 : $index + 1;
                if ( $swap < 0 || $swap >= count( $items ) ) {
                        return new WP_Error( 'kic_queue', kic_t( 'مرز صف است — جای دیگری برای جابه‌جایی نیست.' ) );
                }

                $a = get_post( $items[ $index ]['id'] );
                $b = get_post( $items[ $swap ]['id'] );
                if ( ! $a || ! $b ) {
                        return new WP_Error( 'kic_queue', kic_t( 'آیتم‌های صف خوانده نشد.' ) );
                }

                /* Swap post_date values. */
                $date_a = (string) $a->post_date;
                $date_b = (string) $b->post_date;

                self::reschedule( $a->ID, $date_b );
                self::reschedule( $b->ID, $date_a );

                return kic_t( 'زمان جدید ذخیره شد.' );
        }

        /**
         * Publish the earliest queued item immediately (explicit admin action —
         * works even while the queue is paused).
         *
         * @return array|WP_Error {id,title}, or error.
         */
        public static function run_next() {
                $items = self::queue_items( 'all' );
                if ( empty( $items ) ) {
                        return new WP_Error( 'kic_queue', kic_t( 'هیچ آیتمی برای انتشار در صف نیست.' ) );
                }

                $next = $items[0];
                $post_id = (int) $next['id'];

                wp_clear_scheduled_hook( 'kic_drip_publish', array( $post_id ) );

                $updated = wp_update_post(
                        array(
                                'ID'          => $post_id,
                                'post_status' => 'publish',
                                'post_date'   => current_time( 'mysql' ),
                                'post_date_gmt' => current_time( 'mysql', 1 ),
                        ),
                        true
                );

                if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
                        return new WP_Error( 'kic_queue', kic_t( 'انتشار این آیتم ناموفق بود.' ) );
                }

                update_post_meta( $post_id, '_kic_drip', 'published' );

                return array(
                        'id'    => $post_id,
                        'title' => $next['title'],
                        'url'   => $next['url'],
                );
        }

        /**
         * Reschedule one queued draft: new post_date + a fresh single cron event.
         *
         * @param int    $post_id   Draft id.
         * @param string $when_str  Local 'Y-m-d H:i:s'.
         * @return void
         */
        public static function reschedule( $post_id, $when_str ) {
                $post_id = absint( $post_id );

                wp_clear_scheduled_hook( 'kic_drip_publish', array( $post_id ) );

                wp_update_post(
                        array(
                                'ID'            => $post_id,
                                'post_date'     => $when_str,
                                'post_date_gmt' => get_gmt_from_date( $when_str ),
                        )
                );

                wp_schedule_single_event(
                        strtotime( get_gmt_from_date( $when_str ) ),
                        'kic_drip_publish',
                        array( $post_id )
                );
        }

        /* ---------------------------------------------------------------------
         * AJAX endpoints.
         * ------------------------------------------------------------------- */

        /**
         * AJAX guard (nonce + capability).
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function guard() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        return kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' );
                }
                if ( ! current_user_can( 'manage_options' ) ) {
                        return kic_t( 'دسترسی ندارید.' );
                }
                return null;
        }

        /**
         * Request parameters (form or JSON body).
         *
         * @return array
         */
        private function request_params() {
                $params = isset( $_POST ) && is_array( $_POST ) ? $_POST : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller runs check_ajax_referer() first; each field is sanitized at its point of use
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
         * AJAX: kic_queue_move — drag & drop reschedule (admin click/drop).
         *
         * @return void
         */
        public function ajax_queue_move() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $params  = $this->request_params();
                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $date    = isset( $params['date'] ) ? sanitize_text_field( (string) $params['date'] ) : '';

                $result = self::move( $post_id, $date );
                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                wp_send_json_success(
                        array(
                                'post_id'    => $post_id,
                                'new_date'   => $result,
                                'user_action' => true,
                        )
                );
        }

        /**
         * AJAX: kic_queue_reorder — move one item one slot up/down.
         *
         * @return void
         */
        public function ajax_queue_reorder() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $params  = $this->request_params();
                $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
                $up      = ! empty( $params['up'] );

                $result = self::reorder( $post_id, $up );
                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                wp_send_json_success( array( 'message' => $result, 'items' => self::queue_items( 'all' ) ) );
        }

        /**
         * AJAX: kic_queue_pause — stop the drip queue (explicit admin action).
         *
         * @return void
         */
        public function ajax_queue_pause() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                $settings['drip_paused'] = 1;
                update_option( 'kic_settings', $settings, false );

                wp_send_json_success( array( 'paused' => true ) );
        }

        /**
         * AJAX: kic_queue_resume — continue the drip queue.
         *
         * @return void
         */
        public function ajax_queue_resume() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $settings = wp_parse_args( (array) get_option( 'kic_settings', array() ), kic_default_settings() );
                $settings['drip_paused'] = 0;
                update_option( 'kic_settings', $settings, false );

                wp_send_json_success( array( 'paused' => false ) );
        }

        /**
         * AJAX: kic_queue_run_next — publish the earliest item right now.
         *
         * @return void
         */
        public function ajax_queue_run_next() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $result = self::run_next();
                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                wp_send_json_success( array_merge( $result, array( 'user_action' => true ) ) );
        }
}
