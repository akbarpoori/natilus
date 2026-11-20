<?php
/**
 * Prompt-based AI edit of the CURRENT post content (v2.3).
 *
 * The author writes ONLY the edit instruction (e.g. «پاراگراف آخر را
 * کوتاه کن») — the server itself loads the SAVED post content, the model
 * applies the instruction to that content, and the result is PREVIEWED
 * before anything is written. The user never re-pastes the article text.
 *
 * Endpoints:
 *  - kic_ai_edit        (read-only preview: saved post → edited HTML)
 *  - kic_ai_edit_apply  (write: revision snapshot + wp_update_post)
 *
 * Reuses the publisher's own provider chain (KIC_API_Client with
 * failover + auto-continue), the per-user rate limiter, the budget guard
 * and the usage tracker — the exact same model-access layer as every
 * other optional helper. Strictly opt-in: gated by the ai_edit_enabled
 * setting and only ever triggered by an explicit author click.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_AI_Editor {

        /**
         * Hard cap on the instruction length (characters).
         */
        const MAX_INSTRUCTION_CHARS = 2000;

        /**
         * Hard cap on the article sent for editing (characters). Longer posts
         * are refused with a clear message instead of being silently truncated
         * (a truncated edit could never be applied without losing the tail).
         */
        const MAX_CONTENT_CHARS = 60000;

        /**
         * Minimum plausible model output before we call it unusable.
         */
        const MIN_OUTPUT_CHARS = 40;

        /**
         * Singleton instance.
         *
         * @var KIC_AI_Editor|null
         */
        private static $instance = null;

        /**
         * Boot the module (called from KIC_Plugin::boot()).
         *
         * @return KIC_AI_Editor
         */
        public static function init() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Singleton accessor.
         *
         * @return KIC_AI_Editor
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers the AJAX endpoints.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_ai_edit', array( $this, 'ajax_ai_edit' ) );
                add_action( 'wp_ajax_kic_ai_edit_apply', array( $this, 'ajax_ai_edit_apply' ) );
        }

        /**
         * Availability map for the compose widget (pure config check, no
         * network calls): enabled by settings, and SOME usable provider
         * (legacy pair or a chain row) is configured.
         *
         * @return array {enabled: bool, configured: bool}
         */
        public static function availability() {
                $enabled = (bool) kic_get_setting( 'ai_edit_enabled', 1 );

                $configured = false;
                if ( class_exists( 'KIC_API_Client' ) && method_exists( 'KIC_API_Client', 'instance' ) && method_exists( 'KIC_API_Client', 'is_configured' ) ) {
                        $configured = (bool) KIC_API_Client::instance()->is_configured();
                }
                if ( ! $configured && class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'stack' ) ) {
                        $stack = KIC_Provider_Chain::stack();
                        if ( is_array( $stack ) && ! empty( $stack ) ) {
                                $configured = true; // stack() already drops unusable rows.
                        }
                }

                return array(
                        'enabled'    => $enabled,
                        'configured' => $configured,
                );
        }

        /* ---------------------------------------------------------------------
         * Guards & shared plumbing (mirrors KIC_Term_Suggester).
         * ------------------------------------------------------------------- */

        /**
         * Nonce + capability guard.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function guard() {
                $nonce_ok = check_ajax_referer( 'kic_ajax', 'nonce', false );
                if ( false === $nonce_ok ) {
                        return kic_t( 'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' );
                }
                if ( ! ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) && KIC_Security::user_can_compose() ) ) {
                        return kic_t( 'دسترسی ندارید.' );
                }
                if ( ! kic_get_setting( 'ai_edit_enabled', 1 ) ) {
                        return kic_t( 'ویرایش با پرامپت در تنظیمات خاموش شده است.' );
                }
                return null;
        }

        /**
         * Rate-limit guard for the billable (AI) endpoints.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function rate_guard() {
                if ( class_exists( 'KIC_Rate_Limiter' ) && method_exists( 'KIC_Rate_Limiter', 'check' ) ) {
                        $limit = KIC_Rate_Limiter::check( get_current_user_id() );
                        if ( is_wp_error( $limit ) ) {
                                return (string) $limit->get_error_message();
                        }
                }
                return null;
        }

        /**
         * Count a successful AI interaction against the rate limiter.
         *
         * @return void
         */
        private function finish() {
                if ( class_exists( 'KIC_Rate_Limiter' ) && method_exists( 'KIC_Rate_Limiter', 'hit' ) ) {
                        KIC_Rate_Limiter::hit( get_current_user_id() );
                }
        }

        /**
         * Budget gate for the AI endpoints.
         *
         * @return string|null Error message, or null when the caller may proceed.
         */
        private function budget_error() {
                if ( class_exists( 'KIC_Budget_Guard' ) && method_exists( 'KIC_Budget_Guard', 'check' ) ) {
                        $budget = KIC_Budget_Guard::check();
                        if ( is_wp_error( $budget ) ) {
                                return (string) $budget->get_error_message();
                        }
                }
                return null;
        }

        /**
         * Request parameters (form or JSON body).
         *
         * @return array
         */
        private function request_params() {
                $params = isset( $_POST ) && is_array( $_POST ) ? $_POST : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every caller runs guard()/check_ajax_referer() first; each field is sanitized at its point of use
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
         * Shared post resolution + edit permission check.
         *
         * @param int $post_id Candidate post id.
         * @return WP_Post|null Post object, or null with the error already sent.
         */
        private function editable_post( $post_id ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 ) {
                        wp_send_json_error( kic_t( 'ویرایش با پرامپت روی نوشتهٔ ذخیره‌شده کار می‌کند — ابتدا نوشته را ذخیره کنید.' ) );
                }
                $post = get_post( $post_id );
                if ( ! $post ) {
                        wp_send_json_error( kic_t( 'نوشتهٔ هدف یافت نشد.' ) );
                }
                if ( get_current_user_id() && ! current_user_can( 'edit_post', $post_id ) ) {
                        wp_send_json_error( kic_t( 'اجازه ویرایش این نوشته را ندارید.' ) );
                }
                return $post;
        }

        /* ---------------------------------------------------------------------
         * Preview endpoint.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_ai_edit — apply a written edit instruction to the SAVED
         * content of the current post and return the edited HTML for preview.
         * Read-only: nothing is written to the post here.
         *
         * @return void
         */
        public function ajax_ai_edit() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }
                $rate = $this->rate_guard();
                if ( null !== $rate ) {
                        wp_send_json_error( $rate );
                }
                $budget = $this->budget_error();
                if ( null !== $budget ) {
                        wp_send_json_error( $budget );
                }

                $params = $this->request_params();

                $post = $this->editable_post( isset( $params['post_id'] ) ? $params['post_id'] : 0 );
                if ( null === $post ) {
                        return;
                }
                $post_id = (int) $post->ID;

                /* The instruction: text only, no tags, bounded length. */
                $instruction = isset( $params['instruction'] ) ? (string) $params['instruction'] : '';
                $instruction = trim( wp_strip_all_tags( $instruction ) );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $instruction, 'UTF-8' ) > self::MAX_INSTRUCTION_CHARS ) {
                        $instruction = mb_substr( $instruction, 0, self::MAX_INSTRUCTION_CHARS, 'UTF-8' );
                } elseif ( ! function_exists( 'mb_strlen' ) && strlen( $instruction ) > self::MAX_INSTRUCTION_CHARS ) {
                        $instruction = substr( $instruction, 0, self::MAX_INSTRUCTION_CHARS );
                }
                if ( '' === $instruction ) {
                        wp_send_json_error( kic_t( 'دستور ویرایش را در باکس پرامپت بنویسید — مثلاً: پاراگراف آخر را کوتاه کن.' ) );
                }

                /* The article itself: read SERVER-SIDE from the saved post — the
                 * author never re-pastes it. */
                $original = (string) $post->post_content;
                if ( '' === trim( $original ) ) {
                        wp_send_json_error( kic_t( 'محتوای این نوشته خالی است — چیزی برای ویرایش وجود ندارد.' ) );
                }
                $original_len = function_exists( 'mb_strlen' ) ? mb_strlen( $original, 'UTF-8' ) : strlen( $original );
                if ( $original_len > self::MAX_CONTENT_CHARS ) {
                        wp_send_json_error( kic_t( 'این نوشته برای ویرایش با پرامپت بیش از حد طولانی است — ویرایش را دستی انجام دهید.' ) );
                }

                $messages = array(
                        array(
                                'role'    => 'system',
                                'content' => $this->system_prompt(),
                        ),
                        array(
                                'role'    => 'user',
                                'content' => "EDIT INSTRUCTION (from the site's author):\n" . $instruction . "\n\nFULL ARTICLE HTML:\n" . $original,
                        ),
                );

                $result = KIC_API_Client::instance()->chat(
                        $messages,
                        $this->edit_args( $original ),
                        array(
                                'action'  => 'ai_edit',
                                'post_id' => $post_id,
                        )
                );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                $edited = $this->parse_edited_html( isset( $result['content'] ) ? $result['content'] : '' );
                if ( '' === $edited ) {
                        wp_send_json_error( kic_t( 'مدل پاسخ قابل استفاده‌ای برنگرداند — پرامپت را دقیق‌تر بنویسید یا مدل دیگری در تنظیمات انتخاب کنید.' ) );
                }

                $this->finish();

                wp_send_json_success(
                        array(
                                'html'        => $edited,
                                'original'   => $original,
                                'instruction' => $instruction,
                                'model'       => isset( $result['model'] ) ? (string) $result['model'] : '',
                                'usage'       => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(),
                                'read_only'   => true,
                        )
                );
        }

        /* ---------------------------------------------------------------------
         * Apply endpoint.
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_ai_edit_apply — write the PREVIEWED (approved) edit into the
         * post. A revision snapshot is taken first, so the widget footer's
         * one-click rollback covers AI edits too.
         *
         * @return void
         */
        public function ajax_ai_edit_apply() {
                $guard = $this->guard();
                if ( null !== $guard ) {
                        wp_send_json_error( $guard );
                }

                $params = $this->request_params();

                $post = $this->editable_post( isset( $params['post_id'] ) ? $params['post_id'] : 0 );
                if ( null === $post ) {
                        return;
                }
                $post_id = (int) $post->ID;

                $html = isset( $params['html'] ) ? (string) $params['html'] : '';
                if ( '' === trim( wp_strip_all_tags( $html ) ) && '' === trim( $html ) ) {
                        wp_send_json_error( kic_t( 'محتوای ویرایش‌شده‌ای برای اعمال ارسال نشده است.' ) );
                }

                /* Sanitized again server-side — the payload traveled through the
                 * browser between preview and apply. */
                $html = wp_check_invalid_utf8( $html, true );
                $html = trim( wp_kses_post( $html ) );
                if ( '' === $html ) {
                        wp_send_json_error( kic_t( 'محتوای ویرایش‌شده پس از پالایش خالی شد — چیزی اعمال نشد.' ) );
                }

                /* Backup BEFORE the write (native revision when available, rolling
                 * meta backups otherwise). Never fatal for the edit itself. */
                $backup_key = '';
                if ( class_exists( 'KIC_Revision_Guard' ) && method_exists( 'KIC_Revision_Guard', 'snapshot' ) ) {
                        $backup_key = (string) KIC_Revision_Guard::snapshot( $post_id );
                }

                $update = wp_update_post(
                        array(
                                'ID'           => $post_id,
                                'post_content' => wp_slash( $html ),
                        ),
                        true
                );
                if ( is_wp_error( $update ) ) {
                        wp_send_json_error( kic_t( 'ذخیرهٔ ویرایش ناموفق بود: ' ) . $update->get_error_message() );
                }

                wp_send_json_success(
                        array(
                                'post_id'    => $post_id,
                                'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
                                'backup_key' => $backup_key,
                        )
                );
        }

        /* ---------------------------------------------------------------------
         * Prompt + call shaping.
         * ------------------------------------------------------------------- */

        /**
         * The edit system prompt: a faithful editor, not a writer.
         *
         * @return string
         */
        private function system_prompt() {
                return 'You are a precise WordPress content EDITOR. You receive the full HTML of an existing article plus ONE edit instruction from the site\'s author. Apply exactly that instruction and return the COMPLETE edited article HTML.'
                        . "\nHard rules:"
                        . "\n- Output ONLY the edited article HTML. No preamble, no explanation, no markdown code fences, no <html>/<body> wrappers."
                        . "\n- Keep every part NOT covered by the instruction exactly as it is: headings, paragraphs, lists, tables, images (src/alt/width), links (href/target), strong/em, class and style attributes, shortcodes."
                        . "\n- Keep the article\'s original language and tone. Never translate, never add new sections, new facts or new claims."
                        . "\n- Preserve formatting as standard post HTML (p, h1-h6, ul/ol/li, table, figure, img, a, strong, em, blockquote, code, pre)."
                        . "\n- If the instruction is unclear, unrelated to the article, or cannot be applied safely, return the article UNCHANGED.";
        }

        /**
         * Call args for a full-article edit: deterministic temperature, the
         * article-sized completion window (auto-extend style, capped), model
         * chosen through the router ('edit' is a heavy task — never the cheap
         * model).
         *
         * @param string $content The article being edited.
         * @return array
         */
        private function edit_args( $content ) {
                $setting = (int) kic_get_setting( 'max_tokens', 4096 );

                /* Persian/RTL is denser than English (~2.6 chars/token, the plugin's
                 * own estimator). The edit must reproduce the WHOLE article, so the
                 * completion window needs the article's size plus 35% headroom. */
                $chars   = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );
                $needed  = (int) ceil( ( $chars / 2.6 ) * 1.35 );
                $effective = (int) ( ceil( $needed / 1000 ) * 1000 );
                if ( $effective < $setting ) {
                        $effective = $setting;
                }
                $max_tokens = (int) apply_filters( 'kic_ai_edit_max_tokens', min( 16000, $effective ), $setting, $needed );

                $args = array(
                        'max_tokens'   => max( 0, $max_tokens ),
                        'temperature'  => 0.15,
                );

                $model = '';
                if ( class_exists( 'KIC_Router' ) && method_exists( 'KIC_Router', 'model_for' ) ) {
                        $model = KIC_Router::model_for( 'edit', (string) kic_get_setting( 'model' ) );
                } else {
                        $model = (string) kic_get_setting( 'model' );
                }
                if ( '' !== $model ) {
                        $args['model'] = $model;
                }

                return $args;
        }

        /**
         * Extract the edited HTML from a model answer (defensive): strip code
         * fences, whitespace, then the standard post-HTML whitelist.
         *
         * @param string $content Model output.
         * @return string '' when unusable.
         */
        private function parse_edited_html( $content ) {
                $content = trim( (string) $content );
                if ( '' === $content ) {
                        return '';
                }

                /* Markdown fences some models insist on adding. */
                $content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
                $content = preg_replace( '/\s*```$/i', '', $content );
                $content = trim( (string) $content );

                $len = function_exists( 'mb_strlen' ) ? mb_strlen( $content, 'UTF-8' ) : strlen( $content );
                if ( $len < self::MIN_OUTPUT_CHARS ) {
                        return '';
                }

                $content = wp_check_invalid_utf8( $content, true );
                $content = wp_kses_post( $content );
                return trim( $content );
        }
}
