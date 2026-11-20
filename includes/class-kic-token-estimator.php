<?php
/**
 * Natilus — practical token estimator & guide.
 *
 * Makes the token budget tangible instead of an abstract number:
 *
 * 1. ESTIMATE — before generation, estimates how many tokens the current
 *    task needs (prompt + completion) based on the task type ("length
 *    hint"), the raw notes and the layout prompt.
 * 2. HISTORY — averages and last-run usage per action and per model from
 *    the kic_usage table, so the UI can say "last time this consumed X".
 * 3. AUTO-EXTEND — when the configured max_tokens is too small for the
 *    requested task (and auto-extend is enabled), the effective
 *    max_tokens is raised to the estimate instead of failing.
 * 4. AUTO-CONTINUE — mid-work cut-offs are finished by KIC_API_Client
 *    (finish_reason=length triggers continuation requests); this module
 *    exposes the configured continuation cap for the guide + UI.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Token_Estimator {

        /**
         * Content types ("length hints") with word targets, Persian labels and
         * completion-token ranges (markup overhead included, Persian density).
         *
         * @return array key => {label, words, min, max}.
         */
        public static function types() {
                return array(
                        'short'    => array(
                                'label' => 'کوتاه (~۶۰۰ کلمه)',
                                'words' => 600,
                                'min'   => 1100,
                                'max'   => 1600,
                        ),
                        'standard' => array(
                                'label' => 'استاندارد (~۱۲۰۰ کلمه)',
                                'words' => 1200,
                                'min'   => 2200,
                                'max'   => 3200,
                        ),
                        'long'     => array(
                                'label' => 'بلند (~۲۰۰۰ کلمه)',
                                'words' => 2000,
                                'min'   => 3800,
                                'max'   => 5200,
                        ),
                        'xlong'    => array(
                                'label' => 'بسیار بلند (~۳۰۰۰ کلمه)',
                                'words' => 3000,
                                'min'   => 5600,
                                'max'   => 7600,
                        ),
                );
        }

        /**
         * Singleton instance (bootstrap contract).
         *
         * @var KIC_Token_Estimator|null
         */
        private static $instance = null;

        /**
         * @return KIC_Token_Estimator
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
         * @return KIC_Token_Estimator
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
        }

        /* --------------------------------------------------------------------- */
        /* Estimation                                                            */
        /* --------------------------------------------------------------------- */

        /**
         * Rough token estimate for a compose input bag.
         *
         * Persian/RTL text is denser than English: roughly 3 characters per
         * token; the system prompt adds a fixed overhead.
         *
         * @param array $input Compose input bag (content, layout_prompt, length_hint, media count).
         * @return array {
         *     @type int    $prompt_min     Lower prompt estimate.
         *     @type int    $prompt_max     Upper prompt estimate.
         *     @type int    $completion_min Lower completion estimate.
         *     @type int    $completion_max Upper completion estimate.
         *     @type int    $total_max      Worst-case total.
         *     @type string $type_key       Normalised length hint.
         *     @type array  $type           Matched type row.
         * }
         */
        public static function estimate( $input = array() ) {
                $input = is_array( $input ) ? $input : array();

                $notes  = isset( $input['content'] ) ? (string) $input['content'] : '';
                $layout = isset( $input['layout_prompt'] ) ? (string) $input['layout_prompt'] : '';

                $notes_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $notes, 'UTF-8' ) : strlen( $notes );
                $layout_len = function_exists( 'mb_strlen' ) ? mb_strlen( $layout, 'UTF-8' ) : strlen( $layout );

                $user_tokens_min = (int) ceil( ( $notes_len + $layout_len ) / 3.4 );
                $user_tokens_max = (int) ceil( ( $notes_len + $layout_len ) / 2.6 );

                // System prompt + scaffolding overhead.
                $overhead_min = 900;
                $overhead_max = 1400;

                // Media URLs inflate the prompt as numbered list lines.
                $media_count = 0;
                if ( isset( $input['media_urls'] ) && is_array( $input['media_urls'] ) ) {
                        $media_count = count( $input['media_urls'] );
                } elseif ( isset( $input['media_urls'] ) && is_string( $input['media_urls'] ) ) {
                        $media_count = count( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $input['media_urls'] ) ) ) );
                }
                $media_tokens = $media_count * 90;

                $prompt_min = $overhead_min + $user_tokens_min + $media_tokens;
                $prompt_max = $overhead_max + $user_tokens_max + $media_tokens;

                $type_key = self::normalize_type( isset( $input['length_hint'] ) ? $input['length_hint'] : '' );
                $type     = self::types();
                $type_row = $type[ $type_key ];

                // Long layout prompts inflate the completion as well (more sections).
                $sections_bonus = (int) ceil( min( 2000, $layout_len ) / 40 ) * 60;
                if ( isset( $input['pipeline'] ) && $input['pipeline'] ) {
                        $sections_bonus += 500; // Outline stage consumes extra tokens.
                }

                $completion_min = $type_row['min'] + $sections_bonus;
                $completion_max = $type_row['max'] + $sections_bonus;

                return array(
                        'prompt_min'     => $prompt_min,
                        'prompt_max'     => $prompt_max,
                        'completion_min' => $completion_min,
                        'completion_max' => $completion_max,
                        'total_max'      => $prompt_max + $completion_max,
                        'type_key'       => $type_key,
                        'type'           => $type_row,
                );
        }

        /**
         * Normalise a length hint onto the type whitelist.
         *
         * @param mixed $raw Raw hint.
         * @return string
         */
        public static function normalize_type( $raw ) {
                $key = sanitize_key( (string) $raw );
                $types = self::types();
                return isset( $types[ $key ] ) ? $key : 'standard';
        }

        /**
         * Effective max_tokens for a compose task.
         *
         * With auto-extend ON (default) the estimate wins over the stored
         * max_tokens whenever the task needs more room — rounded up to the next
         * thousand. With auto-extend OFF the stored setting is returned as-is.
         *
         * @param array $input Compose input bag.
         * @return int 0 = "omit max_tokens from the request".
         */
        public static function effective_max_tokens( $input = array() ) {
                $setting = (int) kic_get_setting( 'max_tokens', 4096 );

                if ( ! kic_get_setting( 'auto_extend_tokens', 1 ) ) {
                        return max( 0, $setting );
                }

                $estimate = self::estimate( $input );
                $needed   = (int) $estimate['completion_max'];

                if ( $needed > $setting ) {
                        $effective = (int) ( ceil( $needed / 1000 ) * 1000 );
                        /**
                         * Cap the automatic extension so a runaway estimate cannot ask
                         * for an absurd completion window.
                         *
                         * @param int $effective Auto-computed max_tokens.
                         * @param int $setting   Stored max_tokens setting.
                         * @param int $needed    Estimated completion tokens.
                         */
                        $effective = (int) apply_filters( 'kic_auto_extend_max_tokens', min( 16000, $effective ), $setting, $needed );
                        return max( $setting, $effective );
                }

                return max( 0, $setting );
        }

        /**
         * Configured continuation cap for mid-work cut-offs.
         *
         * @return int
         */
        public static function auto_continue_max() {
                $max = absint( kic_get_setting( 'auto_continue_max', 3 ) );
                return min( 8, max( 0, $max ) );
        }

        /* --------------------------------------------------------------------- */
        /* Usage history                                                        */
        /* --------------------------------------------------------------------- */

        /**
         * Averages + last usage per (action, model) from the usage table.
         *
         * @param int $days Window in days (default 30).
         * @return array rows {action, model, runs, avg_total, last_total, last_at}.
         */
        public static function history( $days = 30 ) {
                global $wpdb;

                $table = $wpdb->prefix . 'kic_usage';
                if ( self::table_missing( $table ) ) {
                        return array();
                }

                $days = max( 1, min( 365, absint( $days ) ) );
                $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT action, model, COUNT(*) AS runs,
                                                AVG(total_tokens) AS avg_total,
                                                MAX(total_tokens) AS max_total
                                FROM {$wpdb->prefix}kic_usage
                                WHERE created_at >= %s AND total_tokens > 0
                                GROUP BY action, model
                                ORDER BY runs DESC, avg_total DESC",
                                $since
                        ),
                        ARRAY_A
                );

                $out = array();
                if ( is_array( $rows ) ) {
                        foreach ( $rows as $row ) {
                                if ( ! is_array( $row ) ) {
                                        continue;
                                }
                                $action = is_scalar( $row['action'] ) ? (string) $row['action'] : '';
                                $model  = is_scalar( $row['model'] ) ? (string) $row['model'] : '';
                                $out[]  = array(
                                        'action'    => $action,
                                        'model'     => $model,
                                        'runs'      => absint( $row['runs'] ),
                                        'avg_total' => (int) round( (float) $row['avg_total'] ),
                                        'max_total' => absint( $row['max_total'] ),
                                );
                        }
                }

                return $out;
        }

        /**
         * Guard against a missing usage table (fresh install, failed dbDelta).
         *
         * @param string $table Table name.
         * @return bool
         */
        private static function table_missing( $table ) {
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                return ( $found !== $table );
        }

        /* --------------------------------------------------------------------- */
        /* Guide payload                                                        */
        /* --------------------------------------------------------------------- */

        /**
         * Complete guide payload for the workspace + settings screens.
         *
         * @return array
         */
        public static function guide() {
                $budget = 0.0;
                $spend  = 0.0;
                if ( class_exists( 'KIC_Budget_Guard' ) ) {
                        $budget = (float) KIC_Budget_Guard::budget();
                        $spend  = (float) KIC_Budget_Guard::month_spend();
                }

                $providers = array();
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'public_list' ) ) {
                        $providers = KIC_Provider_Chain::public_list();
                }

                return array(
                        'maxTokens'    => (int) kic_get_setting( 'max_tokens', 4096 ),
                        'autoExtend'   => (bool) kic_get_setting( 'auto_extend_tokens', 1 ),
                        'autoContinue' => (int) self::auto_continue_max(),
                        'types'        => self::types(),
                        'history'      => self::history( 30 ),
                        'budget'       => array(
                                'monthly_usd' => $budget,
                                'spent_usd'   => $spend,
                                'pct'         => ( $budget > 0 ) ? min( 100, (int) round( $spend / $budget * 100 ) ) : 0,
                        ),
                        'providers'    => $providers,
                );
        }

}
