<?php
/**
 * Prompt-based content GENERATION + optional AI image generation (v2.4).
 *
 * The author writes ONLY a topic/instruction prompt — the configured
 * provider chain writes a complete WordPress-ready article (title, kicker,
 * subtitle, body HTML, SEO fields) which is returned to the compose widget
 * for REVIEW. Nothing is published here: the user still clicks the normal
 * «انتشار محتوا» button, so the ready-content pipeline stays the single
 * write path and every word can be edited before publishing.
 *
 * An optional «تولید تصویر» checkbox asks the provider for a cover image;
 * the returned URL is handed to the normal media-import flow (sideload),
 * never written anywhere by this endpoint.
 *
 * Endpoints:
 *  - kic_generate        (prompt -> article fields; read-only preview data)
 *  - kic_generate_image  (prompt -> image URL / attachment)
 *
 * Reuses the publisher's provider chain, rate limiter, budget guard and
 * usage tracker. Strictly opt-in: gated by the generate_enabled setting
 * and only ever triggered by an explicit author click.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Generator {

        /**
         * Hard cap on the author prompt length (characters).
         */
        const MAX_PROMPT_CHARS = 4000;

        /**
         * Hard cap on generated HTML (characters) accepted from the model.
         */
        const MAX_HTML_CHARS = 120000;

        /**
         * Minimum plausible generated body before we call it unusable.
         */
        const MIN_HTML_CHARS = 80;

        /**
         * Singleton instance.
         *
         * @var KIC_Generator|null
         */
        private static $instance = null;

        /**
         * Boot the module (called from KIC_Plugin::boot()).
         *
         * @return KIC_Generator
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
         * @return KIC_Generator
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Private constructor (singleton) — registers the AJAX endpoints.
         */
        private function __construct() {
                add_action( 'wp_ajax_kic_generate', array( $this, 'ajax_generate' ) );
                add_action( 'wp_ajax_kic_generate_image', array( $this, 'ajax_generate_image' ) );
        }

        /**
         * Availability map for the compose widget (pure config check).
         *
         * @return array {enabled: bool, configured: bool}
         */
        public static function availability() {
                $enabled = (bool) kic_get_setting( 'generate_enabled', 1 );

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
         * Guards & shared plumbing (mirrors KIC_AI_Editor).
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
                if ( ! kic_get_setting( 'generate_enabled', 1 ) ) {
                        return kic_t( 'تولید محتوا با پرامپت در تنظیمات خاموش شده است.' );
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
         * The author prompt, bounded and tag-free.
         *
         * @param array $params Request params.
         * @return string '' when unusable.
         */
        private function author_prompt( $params ) {
                $prompt = isset( $params['prompt'] ) ? (string) $params['prompt'] : '';
                $prompt = trim( wp_strip_all_tags( $prompt ) );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $prompt, 'UTF-8' ) > self::MAX_PROMPT_CHARS ) {
                        $prompt = mb_substr( $prompt, 0, self::MAX_PROMPT_CHARS, 'UTF-8' );
                } elseif ( ! function_exists( 'mb_strlen' ) && strlen( $prompt ) > self::MAX_PROMPT_CHARS ) {
                        $prompt = substr( $prompt, 0, self::MAX_PROMPT_CHARS );
                }
                return $prompt;
        }

        /* ---------------------------------------------------------------------
         * kic_generate — prompt -> article fields (read-only).
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_generate — write a complete article from the author's
         * prompt and return it as fields for the widget. Nothing is saved;
         * the normal «انتشار محتوا» button remains the only write path.
         *
         * @return void
         */
        public function ajax_generate() {
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
                $prompt = $this->author_prompt( $params );

                /* Two modes, same endpoint:
                 *  - prompt only          → generate the article from scratch;
                 *  - prompt + pasted text → ORGANIZE that text per the
                 *    instruction (the «چینش» mode from the reference UX). */
                $source = isset( $params['content'] ) ? (string) $params['content'] : '';
                $source = wp_check_invalid_utf8( $source, true );
                $source = trim( wp_kses_post( $source ) );
                $source_len = function_exists( 'mb_strlen' ) ? mb_strlen( wp_strip_all_tags( $source ), 'UTF-8' ) : strlen( wp_strip_all_tags( $source ) );
                $has_source = $source_len >= 20;

                if ( $has_source && $source_len > 120000 ) {
                        wp_send_json_error( kic_t( 'متن چسبانده‌شده برای چینش بیش از حد طولانی است — آن را بخش‌بخش بفرستید.' ) );
                }

                if ( '' === $prompt && ! $has_source ) {
                        wp_send_json_error( kic_t( 'پرامپت را بنویسید — یا متن خام را در باکس محتوا بچسبانید تا هوش مصنوعی آن را بچیند.' ) );
                }
                if ( '' === $prompt ) {
                        $prompt = kic_t( 'این متن را در قالب یک مقالهٔ کامل وردپرسی بچیند — عناوین، پاراگراف‌ها و ساختار را مرتب کن بدون اینکه محتوای اصلی تغییر کند.' );
                }

                $builder_mode = class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'sanitize_mode' )
                        ? KIC_Builder_Modes::sanitize_mode( isset( $params['builder_mode'] ) ? $params['builder_mode'] : 'auto' )
                        : 'auto';

                $messages = array(
                        array(
                                'role'    => 'system',
                                'content' => $has_source ? $this->organize_prompt() : $this->system_prompt(),
                        ),
                        array(
                                'role'    => 'user',
                                'content' => $has_source
                                        ? "AUTHOR INSTRUCTION:\n" . $prompt . "\n\nSOURCE TEXT TO ORGANIZE (keep all its information, do not invent):\n" . $source . "\n\n" . $this->format_section( $builder_mode )
                                        : "AUTHOR PROMPT:\n" . $prompt . "\n\n" . $this->format_section( $builder_mode ),
                        ),
                );

                $result = KIC_API_Client::instance()->chat(
                        $messages,
                        $this->gen_args(),
                        array(
                                'action' => 'generate',
                        )
                );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( $result->get_error_message() );
                }

                $article = $this->parse_article( isset( $result['content'] ) ? $result['content'] : '' );
                if ( '' === $article['html'] ) {
                        wp_send_json_error( kic_t( 'مدل پاسخ قابل استفاده‌ای برنگرداند — پرامپت را دقیق‌تر بنویسید یا مدل دیگری در تنظیمات انتخاب کنید.' ) );
                }

                $this->finish();

                wp_send_json_success(
                        array(
                                'title'            => $article['title'],
                                'kicker'           => $article['kicker'],
                                'subtitle'         => $article['subtitle'],
                                'html'             => $article['html'],
                                'seo_title'        => $article['seo_title'],
                                'seo_description'  => $article['seo_description'],
                                'focus_keyword'    => $article['focus_keyword'],
                                'slug_en'          => $article['slug_en'],
                                'model'            => isset( $result['model'] ) ? (string) $result['model'] : '',
                                'usage'            => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(),
                                'read_only'        => true,
                        )
                );
        }

        /* ---------------------------------------------------------------------
         * kic_generate_image — prompt -> image URL (read-only).
         * ------------------------------------------------------------------- */

        /**
         * AJAX: kic_generate_image — ask the configured provider for an image
         * and return a fetchable URL. The widget then routes the URL through
         * the normal media-import list; nothing is attached here.
         *
         * @return void
         */
        public function ajax_generate_image() {
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

                $params  = $this->request_params();
                $prompt  = $this->author_prompt( $params );
                if ( '' === $prompt ) {
                        wp_send_json_error( kic_t( 'توضیح تصویر را بنویسید — مثلاً: تصویر شاخص مقاله دربارهٔ …' ) );
                }

                $provider = $this->first_image_provider();
                if ( is_wp_error( $provider ) ) {
                        wp_send_json_error( $provider->get_error_message() );
                }

                $response = $this->request_image( $provider, $prompt );
                if ( is_wp_error( $response ) ) {
                        wp_send_json_error( $response->get_error_message() );
                }

                $this->finish();

                wp_send_json_success(
                        array(
                                'url'   => $response['url'],
                                'model' => (string) $provider['model'],
                        )
                );
        }

        /* ---------------------------------------------------------------------
         * Prompt + call shaping.
         * ------------------------------------------------------------------- */

        /**
         * The writer system prompt: a WordPress content author returning a
         * strict JSON contract.
         *
         * @return string
         */
        private function system_prompt() {
                return 'You are an expert WordPress content writer. The site author gives you a topic or instruction; you write ONE complete, publication-ready article.'
                        . "\nHard rules:"
                        . "\n- Write in the SAME language as the author prompt."
                        . "\n- Output ONLY a single JSON object — no markdown fences, no commentary, no preamble."
                        . "\n- JSON keys exactly: \"title\", \"kicker\", \"subtitle\", \"html\", \"seo_title\", \"seo_description\", \"focus_keyword\", \"slug_en\"."
                        . "\n- \"html\" is the FULL article body as clean post HTML (p, h2/h3, ul/ol/li, strong, em, blockquote, figure/img only when the prompt asks for images). No <html>/<body> wrappers, no JSON inside html."
                        . "\n- \"kicker\" is a short overline label (1-4 words) or \"\"; \"subtitle\" is a one-sentence standfirst or \"\"."
                        . "\n- \"seo_title\" <= 60 chars, \"seo_description\" <= 155 chars, \"focus_keyword\" 1-3 words."
                        . "\n- \"slug_en\" is the English URL slug (kebab-case, lowercase ASCII, hyphens) translated from the title."
                        . "\n- No placeholders like [image] or lorem ipsum — write real, specific content.";
        }

        /**
         * The organizer system prompt: same JSON contract, but the model
         * ORGANIZES supplied source text instead of writing from scratch —
         * no new facts, no dropped sections, keep the author's meaning.
         *
         * @return string
         */
        private function organize_prompt() {
                return 'You are an expert WordPress content ORGANIZER (چینش). The site author gives you their raw text/notes plus an instruction. You restructure THAT text into ONE complete, publication-ready article.'
                        . "\nHard rules:"
                        . "\n- Output ONLY a single JSON object — no markdown fences, no commentary."
                        . "\n- JSON keys exactly: \"title\", \"kicker\", \"subtitle\", \"html\", \"seo_title\", \"seo_description\", \"focus_keyword\", \"slug_en\"."
                        . "\n- \"html\" is the organized article body as clean post HTML (p, h2/h3, ul/ol/li, strong, em, blockquote). No <html>/<body> wrappers."
                        . "\n- Keep ALL the source's information and its language — reorganize, reorder, retitle, retitle headings and fix flow; never invent facts, never drop whole sections."
                        . "\n- Improve obvious structure: introduce headings where the text has sections, convert run-on lists to ul/ol, split giant paragraphs."
                        . "\n- \"kicker\" is a short overline label (1-4 words) or \"\"; \"subtitle\" is a one-sentence standfirst or \"\"."
                        . "\n- \"seo_title\" <= 60 chars, \"seo_description\" <= 155 chars, \"focus_keyword\" 1-3 words."
                        . "\n- \"slug_en\" is the English URL slug (kebab-case, lowercase ASCII, hyphens) translated from the title.";
        }

        /**
         * Builder-mode formatting instruction for the generation call.
         *
         * @param string $mode Builder mode.
         * @return string
         */
        private function format_section( $mode ) {
                if ( class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'prompt_section' ) ) {
                        $section = KIC_Builder_Modes::prompt_section( $mode );
                        if ( is_string( $section ) && '' !== trim( $section ) ) {
                                return "PAGE-BUILDER FORMATTING (the article will be published into this builder):\n" . $section;
                        }
                }
                return '';
        }

        /**
         * Call args for generation: warm temperature, a completion window
         * sized for a full article, model chosen through the router.
         *
         * @return array
         */
        private function gen_args() {
                $setting = (int) kic_get_setting( 'max_tokens', 4096 );
                $max_tokens = (int) apply_filters( 'kic_generate_max_tokens', min( 16000, max( $setting, 8000 ) ), $setting );

                $args = array(
                        'max_tokens'   => max( 0, $max_tokens ),
                        'temperature'  => 0.7,
                );

                $model = '';
                if ( class_exists( 'KIC_Router' ) && method_exists( 'KIC_Router', 'model_for' ) ) {
                        $model = KIC_Router::model_for( 'generate', (string) kic_get_setting( 'model' ) );
                } else {
                        $model = (string) kic_get_setting( 'model' );
                }
                if ( '' !== $model ) {
                        $args['model'] = $model;
                }

                return $args;
        }

        /**
         * Extract the article fields from a model answer. Expects the JSON
         * contract; when the model ignored it and returned bare HTML, that
         * HTML is still accepted as the body (title stays empty).
         *
         * @param string $content Model output.
         * @return array {title,kicker,subtitle,html,seo_title,seo_description,focus_keyword}
         */
        private function parse_article( $content ) {
                $empty = array(
                        'title'           => '',
                        'kicker'          => '',
                        'subtitle'        => '',
                        'html'            => '',
                        'seo_title'       => '',
                        'seo_description' => '',
                        'focus_keyword'   => '',
                        'slug_en'         => '',
                );

                $content = trim( (string) $content );
                if ( '' === $content ) {
                        return $empty;
                }

                /* Strip markdown code fences if the model wrapped the JSON. */
                $content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
                $content = preg_replace( '/\s*```$/', '', $content );
                $content = trim( $content );

                $decoded = json_decode( $content, true );
                if ( is_array( $decoded ) ) {
                        $html = isset( $decoded['html'] ) ? (string) $decoded['html'] : '';
                        $html = $this->clean_html( $html );
                        if ( '' === $html ) {
                                return $empty;
                        }
                        return array(
                                'title'           => $this->short_text( isset( $decoded['title'] ) ? $decoded['title'] : '', 200 ),
                                'kicker'          => $this->short_text( isset( $decoded['kicker'] ) ? $decoded['kicker'] : '', 80 ),
                                'subtitle'        => $this->short_text( isset( $decoded['subtitle'] ) ? $decoded['subtitle'] : '', 300 ),
                                'html'            => $html,
                                'seo_title'       => $this->short_text( isset( $decoded['seo_title'] ) ? $decoded['seo_title'] : '', 120 ),
                                'seo_description' => $this->short_text( isset( $decoded['seo_description'] ) ? $decoded['seo_description'] : '', 200 ),
                                'focus_keyword'   => $this->short_text( isset( $decoded['focus_keyword'] ) ? $decoded['focus_keyword'] : '', 80 ),
                                'slug_en'         => sanitize_title( (string) ( isset( $decoded['slug_en'] ) ? $decoded['slug_en'] : '' ) ),
                        );
                }

                /* Fallback: bare HTML body, no metadata. */
                $html = $this->clean_html( $content );
                if ( '' === $html ) {
                        return $empty;
                }
                $empty['html'] = $html;
                return $empty;
        }

        /**
         * Whitelist-sanitize generated HTML (same post-HTML rules as the AI
         * editor) and enforce the size cap.
         *
         * @param string $html Raw model HTML.
         * @return string '' when unusable.
         */
        private function clean_html( $html ) {
                $html = trim( (string) $html );
                if ( '' === $html ) {
                        return '';
                }
                $html = wp_check_invalid_utf8( $html, true );
                $html = trim( wp_kses_post( $html ) );
                if ( function_exists( 'mb_strlen' ) ) {
                        $len = mb_strlen( $html, 'UTF-8' );
                        if ( $len > self::MAX_HTML_CHARS ) {
                                $html = mb_substr( $html, 0, self::MAX_HTML_CHARS, 'UTF-8' );
                        }
                }
                $text_len = function_exists( 'mb_strlen' ) ? mb_strlen( wp_strip_all_tags( $html ), 'UTF-8' ) : strlen( wp_strip_all_tags( $html ) );
                if ( $text_len < self::MIN_HTML_CHARS ) {
                        return '';
                }
                return $html;
        }

        /**
         * Tag-free single-line text with a hard length cap.
         *
         * @param mixed  $value Raw value.
         * @param int    $max   Max chars.
         * @return string
         */
        private function short_text( $value, $max ) {
                $text = trim( wp_strip_all_tags( (string) $value ) );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
                        return mb_substr( $text, 0, $max, 'UTF-8' );
                }
                if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $max ) {
                        return substr( $text, 0, $max );
                }
                return $text;
        }

        /* ---------------------------------------------------------------------
         * Image generation (OpenAI-compatible /images/generations).
         * ------------------------------------------------------------------- */

        /**
         * Pick the first usable provider row for image generation. Prefers a
         * row whose model looks image-capable; falls back to the configured
         * image_model setting, then the first stack row.
         *
         * @return array|WP_Error {base_url, api_key, model}
         */
        private function first_image_provider() {
                $image_model = trim( (string) kic_get_setting( 'image_model', '' ) );

                $stack = array();
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'stack' ) ) {
                        $stack = KIC_Provider_Chain::stack();
                }
                if ( empty( $stack ) ) {
                        /* Legacy single-provider settings (pre-chain installs). */
                        $legacy_base = rtrim( (string) kic_get_setting( 'base_url' ), '/' );
                        $legacy_key  = class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'decrypt' )
                                ? KIC_Security::decrypt( (string) kic_get_setting( 'api_key' ) )
                                : '';
                        $legacy_model = (string) kic_get_setting( 'model' );
                        if ( '' !== $legacy_base && '' !== $legacy_model ) {
                                $stack = array(
                                        array(
                                                'base_url' => $legacy_base,
                                                'api_key'  => $legacy_key,
                                                'model'    => $legacy_model,
                                        ),
                                );
                        }
                }
                if ( empty( $stack ) || ! is_array( $stack ) ) {
                        return new WP_Error( 'kic_no_provider', kic_t( 'هیچ ارائه‌دهندهٔ هوش مصنوعی پیکربندی نشده است — ابتدا در «تنظیمات Natilus» یک ارائه‌دهنده اضافه کنید.' ) );
                }

                $first = is_array( $stack[0] ) ? $stack[0] : array();

                /* An explicit image model setting wins; otherwise use the
                 * provider's own model slot (the user can list image models
                 * there, e.g. dall-e-3 / gpt-image-1). */
                $model = '' !== $image_model ? $image_model : ( isset( $first['model'] ) ? (string) $first['model'] : '' );
                if ( '' === $model ) {
                        return new WP_Error( 'kic_no_image_model', kic_t( 'مدل تصویر مشخص نیست — در تنظیمات «مدل تصویر» را وارد کنید (مثل dall-e-3).' ) );
                }

                return array(
                        'base_url' => isset( $first['base_url'] ) ? (string) $first['base_url'] : '',
                        'api_key'  => isset( $first['api_key'] ) ? (string) $first['api_key'] : '',
                        'model'    => $model,
                );
        }

        /**
         * POST {base_url}/images/generations and normalize the answer to a
         * fetchable http(s) URL. b64_json payloads are saved into the media
         * library first so every consumer sees a normal URL.
         *
         * @param array  $provider {base_url, api_key, model}.
         * @param string $prompt   Image description.
         * @return array|WP_Error {url}
         */
        private function request_image( $provider, $prompt ) {
                $base = isset( $provider['base_url'] ) ? rtrim( (string) $provider['base_url'], '/' ) : '';
                if ( '' === $base ) {
                        return new WP_Error( 'kic_bad_provider', kic_t( 'آدرس ارائه‌دهنده نامعتبر است.' ) );
                }

                /* Some bases already end in /v1 — avoid /v1/v1 duplication. */
                $endpoint = preg_match( '#/v\d+$#', $base ) ? $base . '/images/generations' : $base . '/v1/images/generations';

                $body = array(
                        'model'           => (string) $provider['model'],
                        'prompt'          => $prompt,
                        'n'               => 1,
                        'size'            => '1024x1024',
                        'response_format' => 'url',
                );

                $headers = array( 'Content-Type' => 'application/json' );
                if ( '' !== (string) $provider['api_key'] ) {
                        $headers['Authorization'] = 'Bearer ' . (string) $provider['api_key'];
                }

                $response = wp_remote_post(
                        $endpoint,
                        array(
                                'timeout' => 120,
                                'headers' => $headers,
                                'body'    => wp_json_encode( $body ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return $response;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
                        $message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
                        return new WP_Error( 'kic_image_http', kic_t( 'تولید تصویر ناموفق بود' ) . ( '' !== $message ? ': ' . $message : ' (HTTP ' . $code . ')' ) );
                }

                if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
                        return new WP_Error( 'kic_image_empty', kic_t( 'پاسخ ارائه‌دهنده تصویر خالی بود.' ) );
                }

                $first = $data['data'][0];
                if ( isset( $first['url'] ) && 0 === strpos( (string) $first['url'], 'http' ) ) {
                        return array( 'url' => esc_url_raw( (string) $first['url'] ) );
                }

                if ( isset( $first['b64_json'] ) && is_string( $first['b64_json'] ) ) {
                        $saved = $this->save_b64_image( $first['b64_json'] );
                        if ( is_wp_error( $saved ) ) {
                                return $saved;
                        }
                        return array( 'url' => $saved );
                }

                return new WP_Error( 'kic_image_shape', kic_t( 'فرمت پاسخ تصویر ارائه‌دهنده پشتیبانی نمی‌شود.' ) );
        }

        /**
         * Save a base64 image payload into the uploads dir and return its URL.
         *
         * @param string $b64 Base64 image data.
         * @return string|WP_Error Absolute URL of the saved file.
         */
        private function save_b64_image( $b64 ) {
                $binary = base64_decode( $b64, true );
                if ( false === $binary || '' === $binary ) {
                        return new WP_Error( 'kic_image_b64', kic_t( 'دادهٔ تصویر خراب بود.' ) );
                }

                $info = function_exists( 'getimagesizefromstring' ) ? getimagesizefromstring( $binary ) : false;
                $ext  = is_array( $info ) && isset( $info[2] ) ? image_type_to_extension( (int) $info[2], false ) : 'png';
                if ( ! is_string( $ext ) || '' === $ext ) {
                        $ext = 'png';
                }
                $ext = ltrim( $ext, '.' );

                $allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp' );
                if ( ! in_array( strtolower( $ext ), $allowed, true ) ) {
                        $ext = 'png';
                }

                $upload = wp_upload_dir();
                if ( ! is_array( $upload ) || empty( $upload['path'] ) || ! is_writable( $upload['path'] ) ) {
                        return new WP_Error( 'kic_image_dir', kic_t( 'پوشهٔ آپلود در دسترس نیست.' ) );
                }

                $filename = 'kic-gen-' . wp_generate_password( 8, false ) . '.' . $ext;
                $filepath = trailingslashit( $upload['path'] ) . $filename;

                if ( false === file_put_contents( $filepath, $binary ) ) {
                        return new WP_Error( 'kic_image_write', kic_t( 'نوشتن فایل تصویر ناموفق بود.' ) );
                }

                $url = trailingslashit( $upload['url'] ) . $filename;
                return $url;
        }
}
