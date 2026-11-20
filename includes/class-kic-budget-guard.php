<?php
/**
 * Natilus — monthly USD budget guard.
 *
 * Aggregates cost_estimate over the current month from the usage table,
 * hard-stops generation once the cap is reached and mails the site admin
 * once per month at the warning threshold and once at the hard stop.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Budget_Guard {

        /**
         * Singleton instance (contract only — this class registers no hooks).
         *
         * @var KIC_Budget_Guard|null
         */
        private static $instance = null;

        /**
         * Contract bootstrap (registers nothing by design).
         *
         * @return KIC_Budget_Guard
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
         * @return KIC_Budget_Guard
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::init();
                }
                return self::$instance;
        }

        /**
         * Private constructor (singleton contract, no hooks to register).
         */
        private function __construct() {
        }

        /**
         * Spend of the current calendar month in USD (SUM of cost_estimate).
         *
         * @return float
         */
        public static function month_spend() {
                global $wpdb;

                $month_start = gmdate( 'Y-m-01 00:00:00' );

                $spend = $wpdb->get_var(
                        $wpdb->prepare( "SELECT SUM(cost_estimate) FROM {$wpdb->prefix}kic_usage WHERE created_at >= %s", $month_start )
                );

                if ( null === $spend || '' === $spend ) {
                        return 0.0;
                }

                return (float) $spend;
        }

        /**
         * Active monthly budget in USD; 0 means the cap is disabled.
         *
         * @return float
         */
        public static function budget() {
                return (float) kic_get_setting( 'monthly_budget_usd' );
        }

        /**
         * Warning threshold percentage, clamped to 50..99.
         *
         * @return int
         */
        public static function alert_pct() {
                $pct = absint( kic_get_setting( 'budget_alert_pct', 80 ) );
                return min( 99, max( 50, $pct ) );
        }

        /**
         * Whether the caller may proceed with a billable request.
         *
         * @return true|WP_Error true when allowed; WP_Error('kic_budget') over the cap.
         */
        public static function check() {
                $budget = self::budget();
                if ( $budget <= 0 ) {
                        // Budget disabled: never block, never alert.
                        return true;
                }

                $spend = self::month_spend();

                if ( $spend >= $budget ) {
                        self::maybe_alert( true );
                        return new WP_Error(
                                'kic_budget',
                                'سقف بودجه ماهانهٔ هوش مصنوعی (' . number_format( $budget, 2 ) . '$) پر شده است. برای ادامه، بودجه را در تنظیمات افزایش دهید یا منتظر ماه بعد بمانید.'
                        );
                }

                if ( $spend >= ( $budget * self::alert_pct() / 100 ) ) {
                        self::maybe_alert( false );
                }

                return true;
        }

        /**
         * Mail the site admin ONCE per month per threshold (transient-guarded).
         *
         * @param bool $blocked true = cap crossed (hard stop), false = warning threshold.
         * @return void
         */
        public static function maybe_alert( $blocked ) {
                if ( ! function_exists( 'wp_mail' ) ) {
                        return;
                }

                $month = gmdate( 'Ym' );
                $key   = $blocked ? ( 'kic_bg_block_' . $month ) : ( 'kic_bg_warn_' . $month );

                if ( false !== get_transient( $key ) ) {
                        return; // Already alerted for this threshold this month.
                }

                $spend  = self::month_spend();
                $budget = self::budget();

                $subject = '[Natilus] هشدار بودجه';
                if ( $blocked ) {
                        $subject .= ' — عبور از سقف';
                }

                $body  = 'افزونهٔ «Natilus» گزارش وضعیت بودجهٔ ماهانه را ثبت کرد:' . "\n\n";
                $body .= 'مصرف این ماه: ' . number_format( $spend, 2 ) . ' دلار' . "\n";
                $body .= 'سقف بودجهٔ ماهانه: ' . number_format( $budget, 2 ) . ' دلار' . "\n\n";
                if ( $blocked ) {
                        $body .= 'سقف بودجه پر شده و پیشنهادهای اختیاری (دسته/نامک/لینک) تا ماه بعد متوقف شده‌اند — انتشار محتوا همچنان کامل کار می‌کند. برای فعال‌سازی دوبارهٔ پیشنهادها، سقف بودجه را در تنظیمات افزایش دهید.' . "\n";
                } else {
                        $body .= 'مصرف از آستانهٔ هشدار عبور کرده است. برای جلوگیری از توقف پیشنهادهای اختیاری، وضعیت بودجه را بررسی کنید.' . "\n";
                }
                $body .= "\n" . 'داشبورد مصرف: ' . esc_url( admin_url( 'admin.php?page=kic-dashboard' ) ) . "\n";

                wp_mail( get_option( 'admin_email' ), $subject, $body );

                // ~32 days: comfortably longer than any month.
                set_transient( $key, 1, (int) round( MONTH_IN_SECONDS * 1.05 ) );
        }
}
