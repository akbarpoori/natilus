<?php
/**
 * Prompt construction for the Natilus plugin.
 *
 * Builds the system / user message pair for the compose, outline and
 * regenerate stages, exposes the tone catalogue (Persian labels with
 * English model instructions) and extracts the trailing SEO JSON block
 * emitted by the model.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Prompt_Builder {

        /**
         * Singleton instance.
         *
         * @var KIC_Prompt_Builder|null
         */
        private static $instance = null;

        /**
         * Get the singleton instance.
         *
         * @return KIC_Prompt_Builder
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Bootstrap (module contract symmetry — this class is a stateless
         * utility and registers no hooks).
         *
         * @return void
         */
        public static function init() {
                self::instance();
        }

        /**
         * Private constructor (singleton).
         */
        private function __construct() {
        }

        /* ---------------------------------------------------------------------
         * Tones.
         * ------------------------------------------------------------------- */

        /**
         * Tone catalogue: key => { label: Persian UI label, instruction: English }.
         *
         * @return array
         */
        public function get_tones() {
                return array(
                        'formal'    => array(
                                'label'       => 'رسمی و حرفه‌ای',
                                'instruction' => 'Write in a formal, professional register suited to corporate publishing: precise wording, complete sentences, no slang, no exclamation marks, no emoji.',
                        ),
                        'casual'    => array(
                                'label'       => 'صمیمی و خودمانی',
                                'instruction' => 'Write in a friendly, conversational tone: address the reader directly, prefer short sentences, use light humour sparingly and stay helpful and warm.',
                        ),
                        'sales'     => array(
                                'label'       => 'فروش-محور و متقاعدکننده',
                                'instruction' => 'Write persuasive, conversion-oriented copy: lead with benefits rather than features, create honest urgency, and finish with a clear call to action. Never fabricate discounts, guarantees or statistics.',
                        ),
                        'technical' => array(
                                'label'       => 'فنی و دقیق',
                                'instruction' => 'Write technically and precisely: use correct terminology consistently, give concrete parameters and values, prefer structured step-by-step explanations, and avoid vague generalities.',
                        ),
                        'academic'  => array(
                                'label'       => 'علمی و مستند',
                                'instruction' => 'Write in an academic, evidence-based register: define terminology, hedge claims appropriately, and refer only to broadly established scientific consensus. Never fabricate citations, studies or numbers.',
                        ),
                        'custom'    => array(
                                'label'       => 'سفارشی',
                                'instruction' => '',
                        ),
                );
        }

        /**
         * Resolve the English tone instruction for an input bag.
         *
         * @param array $input Input bag (tone, custom_tone).
         * @return string
         */
        private function resolve_tone_instruction( $input ) {
                $tone = isset( $input['tone'] ) ? (string) $input['tone'] : '';
                if ( 'custom' === $tone ) {
                        $custom = isset( $input['custom_tone'] ) ? trim( (string) $input['custom_tone'] ) : '';
                        return $custom;
                }
                $tones = $this->get_tones();
                if ( isset( $tones[ $tone ] ) ) {
                        return (string) $tones[ $tone ]['instruction'];
                }
                return '';
        }

        /* ---------------------------------------------------------------------
         * System prompt.
         * ------------------------------------------------------------------- */

        /**
         * Build the system prompt for the "full" stage.
         *
         * The prompt itself is English (models follow English instructions
         * best); the content language is governed by the language rule and
         * the user message notes.
         *
         * @param array $input Compose input bag (tone, custom_tone, builder, internal_links, ...).
         * @return string
         */
        public function build_system( $input = array() ) {
                $input    = is_array( $input ) ? $input : array();
                $sections = array();

                // Role.
                $sections[] = <<<'EOT'
You are a senior WordPress content composer. You write publication-ready article bodies for live websites and you follow WordPress editorial conventions: semantic heading hierarchy, scannable short paragraphs, meaningful link text, no fluff.
EOT;

                // Strict output contract.
                $sections[] = <<<'EOT'
OUTPUT CONTRACT — STRICT:
- Return ONLY the article body as HTML. No preamble, no explanations, no wrapping <html>, <head> or <body>.
- Allowed tags (the complete whitelist — nothing else): h2, h3, h4, p, ul, ol, li, strong, em, blockquote, figure, figcaption, img, table, thead, tbody, tr, th, td, a, br, hr.
- Absolutely no markdown, no code fences, no <script>, no <style>, and no inline style attributes anywhere in the output.
EOT;

                // Media rule.
                $sections[] = <<<'EOT'
MEDIA RULE:
- When images are available, a numbered media list is provided with the request.
- Weave each provided URL into a contextually appropriate spot of the article as <figure><img src="URL" alt="short descriptive alt text"><figcaption>short caption</figcaption></figure>.
- Never invent, guess or modify URLs. Use only the URLs from the provided list, and use each listed URL exactly once.
- When no media list is provided, output no <img> elements at all.
EOT;

                // Gallery rule.
                $sections[] = <<<'EOT'
GALLERY RULE:
- When a gallery is requested, place the single marker line <!--kic:gallery--> on its own line exactly where the gallery should render inside the article flow.
- Emit the marker at most once, never inside another element, and never when no gallery was requested.
EOT;

                // SEO block rule.
                $sections[] = <<<'EOT'
SEO BLOCK RULE — MANDATORY:
- END the response with exactly one HTML comment of exactly this shape (real newlines around the JSON, valid JSON, no trailing commas, nothing after the comment):
<!--kic:seo
{"seo_title":"...","seo_description":"...","faq":[{"q":"...","a":"..."}],"alt_texts":{"https://...":"..."}}
-->
- seo_title: up to 70 characters. seo_description: up to 160 characters.
- faq: an array of up to 6 objects {"q":"...","a":"..."} written in the language of the article body.
- alt_texts: an object mapping EACH provided media URL to a short alt text. When no media list was provided, emit an empty object.
- Emit this comment exactly once, as the very last thing in the response.
EOT;

                // Language rule.
                $sections[] = <<<'EOT'
LANGUAGE RULE:
- Write the article body in the language of the user notes.
- When an explicit target language is provided, write entirely in that target language.
- Keep the SEO block values (seo_title, seo_description, faq entries, alt_texts) in the same language as the article body.
EOT;

                // Tone.
                $tone_instruction = $this->resolve_tone_instruction( $input );
                if ( '' !== $tone_instruction ) {
                        $sections[] = 'TONE: ' . $tone_instruction;
                }

                // Internal linking — only when a list is provided.
                if ( ! empty( $input['internal_links'] ) ) {
                        $sections[] = <<<'EOT'
INTERNAL LINKING RULE:
- A list of existing site posts (title + URL) is provided with the request.
- Naturally link AT MOST 4 of the given posts using <a href="URL">anchored on genuinely relevant words or phrases</a>.
- Never link more than four posts, never link the same URL twice, and never invent URLs that are not on the list.
EOT;
                }

                // Page builder mode instructions (v1.2) — mode-aware output contract.
                $builder_mode = isset( $input['builder_mode'] ) ? (string) $input['builder_mode'] : '';
                $mode_section = '';
                if ( '' !== $builder_mode && class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'prompt_section' ) ) {
                        $mode_section = KIC_Builder_Modes::prompt_section( $builder_mode );
                }

                if ( '' === $mode_section ) {
                        // Legacy note (auto/gutenberg) and any builder hints from params.
                        $builder = isset( $input['builder'] ) ? (string) $input['builder'] : '';
                        if ( 'elementor' === $builder || 'wpbakery' === $builder ) {
                                $builder_name = ( 'elementor' === $builder ) ? 'Elementor' : 'WPBakery';
                                $mode_section = 'PAGE BUILDER CONTEXT: The composed content will be embedded into ' . $builder_name . '. Output plain semantic HTML suitable for embedding: no page-builder shortcodes, no section or column wrappers, no inline styles, no markdown.';
                        }
                }

                if ( '' !== $mode_section ) {
                        $sections[] = $mode_section;
                }

                $system = implode( "\n\n", $sections );

                // Extra publisher guidance — appended verbatim from settings.
                $extra = (string) kic_get_setting( 'system_extra', '' );
                if ( '' !== trim( $extra ) ) {
                        $system .= "\n\n" . $extra;
                }

                return $system;
        }

        /**
         * Build the system prompt for the pipeline "outline" stage.
         *
         * @param array $input Compose input bag.
         * @return string
         */
        private function build_outline_system( $input ) {
                $sections = array();

                $sections[] = <<<'EOT'
You are a senior WordPress content composer. Your ONLY task in this stage is to produce the structural outline of the article.
EOT;

                $sections[] = <<<'EOT'
OUTLINE CONTRACT — STRICT:
- Return ONLY the heading skeleton of the article as plain HTML h2 and h3 lines, one heading per line, in final document order.
- Allowed tags: h2 and h3 only. No paragraphs, no lists, no attributes, no markdown, no code fences, no HTML comments, no explanations, no SEO block.
- Shape example:
<h2>First major section</h2>
<h3>Sub section of the first section</h3>
EOT;

                $sections[] = <<<'EOT'
LANGUAGE RULE:
- Write the headings in the language of the user notes, or entirely in the explicit target language when one is provided.
EOT;

                $tone_instruction = $this->resolve_tone_instruction( $input );
                if ( '' !== $tone_instruction ) {
                        $sections[] = 'TONE: ' . $tone_instruction;
                }

                return implode( "\n\n", $sections );
        }

        /* ---------------------------------------------------------------------
         * User message.
         * ------------------------------------------------------------------- */

        /**
         * Assemble the user message (Persian labels, raw publisher input).
         *
         * @param array $input Compose input bag from KIC_Ajax_Handler::build_input().
         * @return string
         */
        public function build_user( $input ) {
                $input = is_array( $input ) ? $input : array();
                $parts = array();

                // Post title.
                $title = isset( $input['title'] ) ? (string) $input['title'] : '';
                if ( '' === trim( $title ) ) {
                        $title = 'پست جدید';
                }
                $parts[] = 'عنوان نوشته: ' . $title;

                // Post type.
                $post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
                if ( '' === $post_type ) {
                        $post_type = 'post';
                }
                $parts[] = 'نوع محتوا: ' . $post_type;

                // Raw notes.
                $notes = isset( $input['content'] ) ? (string) $input['content'] : '';
                if ( '' !== trim( $notes ) ) {
                        $parts[] = "یادداشت‌ها و مواد خام (به زبان یادداشت‌ها بنویسید):\n" . trim( $notes );
                }

                // Layout prompt.
                $layout = isset( $input['layout_prompt'] ) ? (string) $input['layout_prompt'] : '';
                if ( '' !== trim( $layout ) ) {
                        $parts[] = "دستور چیدمان و ساختار:\n" . trim( $layout );
                }

                // Custom tone extra (only meaningful when tone = custom).
                if ( isset( $input['tone'] ) && 'custom' === (string) $input['tone'] ) {
                        $custom = isset( $input['custom_tone'] ) ? trim( (string) $input['custom_tone'] ) : '';
                        if ( '' !== $custom ) {
                                $parts[] = "لحن سفارشی:\n" . $custom;
                        }
                }

                // Numbered media list.
                if ( ! empty( $input['media_urls'] ) && is_array( $input['media_urls'] ) ) {
                        $lines = array();
                        $number = 1;
                        foreach ( $input['media_urls'] as $url ) {
                                $url = (string) $url;
                                if ( '' === $url ) {
                                        continue;
                                }
                                $lines[] = $number . '. ' . $url;
                                $number++;
                        }
                        if ( ! empty( $lines ) ) {
                                $parts[] = "فهرست رسانه‌ها (هر نشانی را در جای مناسب متن بگنجانید):\n" . implode( "\n", $lines );
                        }
                }

                // Target language (when not auto).
                $language = isset( $input['language'] ) ? (string) $input['language'] : 'auto';
                if ( '' === $language ) {
                        $language = 'auto';
                }
                if ( 'auto' !== $language ) {
                        $parts[] = 'زبان مقصد: ' . $language . ' — تمام متن نهایی باید به این زبان باشد.';
                }

                // Previously approved outline (pipeline stage 2).
                if ( ! empty( $input['outline'] ) ) {
                        $parts[] = "Previously approved outline — follow it exactly:\n" . trim( (string) $input['outline'] );
                }

                // Internal links list.
                if ( ! empty( $input['internal_links'] ) && is_array( $input['internal_links'] ) ) {
                        $lines = array();
                        foreach ( $input['internal_links'] as $link ) {
                                if ( ! is_array( $link ) ) {
                                        continue;
                                }
                                $link_title = isset( $link['title'] ) ? trim( (string) $link['title'] ) : '';
                                $link_url   = isset( $link['permalink'] ) ? trim( (string) $link['permalink'] ) : '';
                                if ( '' === $link_title || '' === $link_url ) {
                                        continue;
                                }
                                $lines[] = '- ' . $link_title . ' — ' . $link_url;
                        }
                        if ( ! empty( $lines ) ) {
                                $parts[] = "نوشته‌های موجود سایت برای پیوند داخلی (حداکثر ۴ مورد را بگنجانید):\n" . implode( "\n", $lines );
                        }
                }

                // WooCommerce product context.
                if ( ! empty( $input['product'] ) && is_array( $input['product'] ) ) {
                        $rendered = $this->render_product_context( $input['product'] );
                        if ( '' !== $rendered ) {
                                $parts[] = "زمینه محصول ووکامرس:\n" . $rendered;
                        }
                }

                // Translation source content.
                if ( ! empty( $input['translation_source'] ) ) {
                        $parts[] = "محتوای مبدأ برای ترجمه (زبان مقصد در بالا مشخص شده است):\n" . trim( (string) $input['translation_source'] );
                }

                return implode( "\n\n", $parts );
        }

        /**
         * Render the WooCommerce product context block for the user message.
         *
         * @param array $product { name, price, categories, tags, attributes, short_description }.
         * @return string
         */
        private function render_product_context( $product ) {
                $lines = array();

                $name = isset( $product['name'] ) ? (string) $product['name'] : '';
                if ( '' !== $name ) {
                        $lines[] = 'نام محصول: ' . $name;
                }

                $price = isset( $product['price'] ) ? (string) $product['price'] : '';
                if ( '' !== trim( $price ) ) {
                        $lines[] = 'قیمت: ' . trim( $price );
                }

                if ( ! empty( $product['categories'] ) && is_array( $product['categories'] ) ) {
                        $lines[] = 'دسته‌ها: ' . implode( '، ', array_map( 'strval', $product['categories'] ) );
                }

                if ( ! empty( $product['tags'] ) && is_array( $product['tags'] ) ) {
                        $lines[] = 'برچسب‌ها: ' . implode( '، ', array_map( 'strval', $product['tags'] ) );
                }

                if ( ! empty( $product['attributes'] ) && is_array( $product['attributes'] ) ) {
                        $lines[] = "مشخصات:\n- " . implode( "\n- ", array_map( 'strval', $product['attributes'] ) );
                }

                $short = isset( $product['short_description'] ) ? (string) $product['short_description'] : '';
                if ( '' !== trim( $short ) ) {
                        $lines[] = "توضیح کوتاه محصول:\n" . trim( $short );
                }

                return implode( "\n", $lines );
        }

        /* ---------------------------------------------------------------------
         * Message pairs.
         * ------------------------------------------------------------------- */

        /**
         * Build the system/user message pair for a compose stage.
         *
         * @param array  $input Compose input bag.
         * @param string $stage 'outline' asks ONLY for the heading skeleton; 'full' composes the article.
         * @return array array( {role, content}, {role, content} )
         */
        public function build_messages( $input, $stage = 'full' ) {
                $input = is_array( $input ) ? $input : array();
                $stage = ( 'outline' === $stage ) ? 'outline' : 'full';

                if ( 'outline' === $stage ) {
                        $system = $this->build_outline_system( $input );
                } else {
                        $system = $this->build_system( $input );
                }

                $user = $this->build_user( $input );

                return array(
                        array(
                                'role'    => 'system',
                                'content' => $system,
                        ),
                        array(
                                'role'    => 'user',
                                'content' => $user,
                        ),
                );
        }

        /* ---------------------------------------------------------------------
         * Response post-processing.
         * ------------------------------------------------------------------- */

        /**
         * Extract the trailing SEO JSON block from a raw model response.
         *
         * @param string $content Raw model output (HTML + SEO comment).
         * @return array { 'html' => string, 'seo' => array }
         */
        public function extract_json_block( $content ) {
                $content = (string) $content;

                $seo_defaults = array(
                        'seo_title'       => '',
                        'seo_description' => '',
                        'faq'             => array(),
                        'alt_texts'       => array(),
                );

                $seo  = $seo_defaults;
                $html = $content;

                if ( preg_match( '/<!--kic:seo([\s\S]*?)-->/i', $content, $matches ) ) {
                        $json = trim( $matches[1] );

                        // Strip accidental code fences inside the comment.
                        $json = $this->safe_preg_replace( '/^```[a-z]*\s*/i', '', $json );
                        $json = $this->safe_preg_replace( '/\s*```$/i', '', $json );

                        $decoded = json_decode( $json, true );
                        if ( is_array( $decoded ) ) {
                                $seo = $this->normalize_seo( $decoded, $seo_defaults );
                        }

                        // Drop every SEO comment from the HTML payload.
                        $html = $this->safe_preg_replace( '/<!--kic:seo[\s\S]*?-->/i', '', $content );
                }

                // Strip accidental leading/trailing code fences around the whole response.
                $html = $this->safe_preg_replace( '/^\s*```[a-z]*\s*\n?/i', '', $html );
                $html = $this->safe_preg_replace( '/\n?```\s*$/i', '', $html );
                $html = trim( $html );

                return array(
                        'html' => $html,
                        'seo'  => $seo,
                );
        }

        /**
         * Normalize a decoded SEO payload against graceful defaults.
         *
         * @param array $decoded Decoded JSON payload.
         * @param array $defaults Default shape.
         * @return array
         */
        private function normalize_seo( $decoded, $defaults ) {
                $seo = $defaults;

                if ( isset( $decoded['seo_title'] ) && is_string( $decoded['seo_title'] ) ) {
                        $seo['seo_title'] = $this->clamp_text( $decoded['seo_title'], 70 );
                }

                if ( isset( $decoded['seo_description'] ) && is_string( $decoded['seo_description'] ) ) {
                        $seo['seo_description'] = $this->clamp_text( $decoded['seo_description'], 160 );
                }

                if ( isset( $decoded['faq'] ) && is_array( $decoded['faq'] ) ) {
                        $faq = array();
                        foreach ( $decoded['faq'] as $qa ) {
                                if ( ! is_array( $qa ) ) {
                                        continue;
                                }
                                $question = isset( $qa['q'] ) ? trim( (string) $qa['q'] ) : '';
                                $answer   = isset( $qa['a'] ) ? trim( (string) $qa['a'] ) : '';
                                if ( '' !== $question && '' !== $answer ) {
                                        $faq[] = array(
                                                'q' => $question,
                                                'a' => $answer,
                                        );
                                }
                        }
                        $seo['faq'] = array_slice( $faq, 0, 6 );
                }

                if ( isset( $decoded['alt_texts'] ) && is_array( $decoded['alt_texts'] ) ) {
                        $alt_texts = array();
                        foreach ( $decoded['alt_texts'] as $url => $alt ) {
                                $url = esc_url_raw( (string) $url );
                                $alt = trim( (string) $alt );
                                if ( '' !== $url && '' !== $alt ) {
                                        $alt_texts[ $url ] = $alt;
                                }
                        }
                        $seo['alt_texts'] = $alt_texts;
                }

                return $seo;
        }

        /**
         * Truncate a string to a maximum character length.
         *
         * @param string $text Text.
         * @param int    $max  Maximum characters.
         * @return string
         */
        private function clamp_text( $text, $max ) {
                $text = trim( (string) $text );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, (int) $max );
                }
                return substr( $text, 0, (int) $max );
        }

        /**
         * preg_replace() wrapper that never destroys the subject on PCRE failure.
         *
         * @param string $pattern     Pattern.
         * @param string $replacement Replacement.
         * @param string $subject     Subject.
         * @return string
         */
        private function safe_preg_replace( $pattern, $replacement, $subject ) {
                $result = preg_replace( $pattern, $replacement, $subject );
                if ( null === $result || ! is_string( $result ) ) {
                        return $subject;
                }
                return $result;
        }

        /* ---------------------------------------------------------------------
         * Alt text application.
         * ------------------------------------------------------------------- */

        /**
         * Apply alt texts to <img> tags whose src matches the provided URL map.
         *
         * @param string $html     HTML payload.
         * @param array  $alt_map  url => alt text.
         * @return string
         */
        public function apply_alt_texts( $html, $alt_map ) {
                $html = (string) $html;

                if ( ! is_array( $alt_map ) || empty( $alt_map ) ) {
                        return $html;
                }

                // Normalise the map (keys become plain comparable strings).
                $map = array();
                foreach ( $alt_map as $url => $alt ) {
                        $url = (string) $url;
                        $alt = trim( (string) $alt );
                        if ( '' === $url || '' === $alt ) {
                                continue;
                        }
                        $map[ $url ] = $alt;
                }
                if ( empty( $map ) ) {
                        return $html;
                }

                $result = preg_replace_callback(
                        '/<img\b[^>]*>/i',
                        function ( $m ) use ( $map ) {
                                return KIC_Prompt_Builder::rewrite_img_alt( $m[0], $map );
                        },
                        $html
                );

                if ( null === $result || ! is_string( $result ) ) {
                        return $html;
                }

                return $result;
        }

        /**
         * Rewrite the alt attribute of one <img> tag when its src matches the map.
         *
         * @param string $tag Full <img ...> tag.
         * @param array  $map url => alt.
         * @return string
         */
        private static function rewrite_img_alt( $tag, $map ) {
                if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $src_match ) ) {
                        return $tag;
                }

                $src = self::normalize_url_for_compare( $src_match[2] );

                $alt = '';
                foreach ( $map as $url => $candidate ) {
                        if ( $src === self::normalize_url_for_compare( $url ) ) {
                                $alt = (string) $candidate;
                                break;
                        }
                }

                if ( '' === $alt ) {
                        return $tag;
                }

                $safe_alt = esc_attr( $alt );

                // Remove an existing alt attribute (single or double quoted).
                $stripped = preg_replace( '/\s+alt\s*=\s*("|\').*?\1/is', '', $tag );
                if ( null === $stripped || ! is_string( $stripped ) ) {
                        return $tag;
                }
                $tag = $stripped;

                // Split the trailing '>' (and optional self-closing slash).
                $inner      = $tag;
                $selfclose  = false;
                if ( '>' === substr( $inner, -1 ) ) {
                        $inner = substr( $inner, 0, -1 );
                }
                $inner = rtrim( $inner );
                if ( '/' === substr( $inner, -1 ) ) {
                        $selfclose = true;
                        $inner     = rtrim( substr( $inner, 0, -1 ) );
                }

                return $inner . ' alt="' . $safe_alt . '"' . ( $selfclose ? ' /' : '' ) . '>';
        }

        /**
         * Normalise a URL for src comparison purposes.
         *
         * @param string $url URL (possibly entity encoded).
         * @return string
         */
        private static function normalize_url_for_compare( $url ) {
                $url = trim( (string) $url );
                $url = str_replace( '&amp;', '&', $url );
                return $url;
        }
}
