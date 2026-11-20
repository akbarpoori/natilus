<?php
/**
 * Natilus — JSON-LD (schema.org) output module.
 *
 * Stores the SEO payload captured with every composition and renders an
 * Article + optional FAQPage graph in wp_head. Output is gated by the
 * "jsonld" plugin setting so users can defer to their SEO plugin's schema.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_SEO_Schema {

        /**
         * Singleton instance.
         *
         * @var KIC_SEO_Schema|null
         */
        private static $instance = null;

        /**
         * @return KIC_SEO_Schema
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Bootstrap the module (called from the plugin bootstrap).
         *
         * @return KIC_SEO_Schema
         */
        public static function init() {
                return self::instance();
        }

        /**
         * Register front-end hooks.
         */
        private function __construct() {
                /*
                 * NOTE ON SCHEMA DUPLICATION: when a SEO plugin (Rank Math, Yoast,
                 * SEOPress, AIOSEO, ...) is active it usually prints its own Article
                 * and FAQPage schema, which can duplicate the graph below. Their FAQ
                 * modules take precedence: if duplicate rich-result entries appear,
                 * the admin can disable the "jsonld" setting on the Natilus settings
                 * page and our output is skipped entirely. The toggle exists exactly
                 * for this reason and is intentionally kept.
                 *
                 * Priority 20: after most SEO plugins printed their own tags, so
                 * admins inspecting the head can tell ours apart.
                 */
                add_action( 'wp_head', array( $this, 'print_jsonld' ), 20 );
        }

        /**
         * Persist the SEO payload of a composition as post meta.
         *
         * Only stores when the "jsonld" setting is enabled; when disabled the
         * SEO plugin's own schema is expected to cover rich results.
         *
         * @param int   $post_id Target post ID.
         * @param array $seo     Payload keys: seo_title, seo_description, faq.
         * @return void
         */
        public static function register_jsonld( $post_id, $seo ) {
                if ( ! kic_get_setting( 'jsonld' ) ) {
                        return; // Setting disabled: defer to the SEO plugin's schema.
                }

                $post_id = absint( $post_id );
                if ( $post_id < 1 || ! is_array( $seo ) ) {
                        return;
                }

                $faq = isset( $seo['faq'] ) ? $seo['faq'] : array();
                if ( ! is_array( $faq ) ) {
                        $faq = array();
                }

                $payload = array(
                        'title'       => isset( $seo['seo_title'] ) ? sanitize_text_field( (string) $seo['seo_title'] ) : '',
                        'description' => isset( $seo['seo_description'] ) ? sanitize_text_field( (string) $seo['seo_description'] ) : '',
                        'faq'         => $faq,
                );

                update_post_meta( $post_id, '_kic_jsonld', wp_json_encode( $payload ) );
        }

        /**
         * Print the schema.org graph (Article + optional FAQPage) on singular views.
         *
         * @return void
         */
        public function print_jsonld() {
                if ( ! is_singular() ) {
                        return;
                }
                if ( ! kic_get_setting( 'jsonld' ) ) {
                        return; // Toggle off: leave schema output to the SEO plugin.
                }

                $post_id = get_the_ID();
                if ( ! $post_id ) {
                        return;
                }

                $raw = get_post_meta( $post_id, '_kic_jsonld', true );
                if ( ! is_string( $raw ) || '' === $raw ) {
                        return;
                }

                $stored = json_decode( $raw, true );
                if ( ! is_array( $stored ) ) {
                        return;
                }

                $description = isset( $stored['description'] ) ? (string) $stored['description'] : '';

                // ----- Article node -------------------------------------------------

                $author_id   = (int) get_post_field( 'post_author', $post_id );
                $author_name = ( $author_id > 0 ) ? (string) get_the_author_meta( 'display_name', $author_id ) : '';

                $article = array(
                        '@type'         => 'Article',
                        'headline'      => get_the_title( $post_id ),
                        'datePublished' => get_the_date( 'c', $post_id ),
                        'dateModified'  => get_the_modified_date( 'c', $post_id ),
                        'author'        => array(
                                '@type' => 'Person',
                                'name'  => ( '' !== $author_name ) ? $author_name : get_bloginfo( 'name' ),
                        ),
                );

                if ( '' !== $description ) {
                        // Tag-free ~200 character excerpt (multibyte safe helper).
                        $article['description'] = wp_html_excerpt( $description, 200, '' );
                }

                // First inline <img src> of the generated content, when present.
                $generated = (string) get_post_meta( $post_id, '_kic_generated_content', true );
                if ( '' !== $generated && preg_match( '/<img[^>]*\ssrc=["\']([^"\']+)["\']/i', $generated, $img_match ) ) {
                        $image = esc_url_raw( trim( $img_match[1] ) );
                        if ( '' !== $image ) {
                                $article['image'] = $image;
                        }
                }

                $graph = array(
                        '@context' => 'https://schema.org',
                        '@graph'   => array( $article ),
                );

                // ----- FAQPage node ---------------------------------------------------

                $faq = isset( $stored['faq'] ) ? $stored['faq'] : null;
                if ( is_array( $faq ) && ! empty( $faq ) ) {
                        $questions = array();
                        foreach ( $faq as $item ) {
                                if ( ! is_array( $item ) ) {
                                        continue;
                                }

                                $question = '';
                                if ( isset( $item['question'] ) ) {
                                        $question = trim( wp_strip_all_tags( (string) $item['question'] ) );
                                } elseif ( isset( $item['q'] ) ) {
                                        $question = trim( wp_strip_all_tags( (string) $item['q'] ) );
                                }

                                $answer = '';
                                if ( isset( $item['answer'] ) ) {
                                        $answer = trim( wp_strip_all_tags( (string) $item['answer'] ) );
                                } elseif ( isset( $item['a'] ) ) {
                                        $answer = trim( wp_strip_all_tags( (string) $item['a'] ) );
                                }

                                if ( '' === $question || '' === $answer ) {
                                        continue;
                                }

                                $questions[] = array(
                                        '@type'          => 'Question',
                                        'name'           => $question,
                                        'acceptedAnswer' => array(
                                                '@type' => 'Answer',
                                                'text'  => $answer,
                                        ),
                                );
                        }

                        if ( ! empty( $questions ) ) {
                                $graph['@graph'][] = array(
                                        '@type'      => 'FAQPage',
                                        'mainEntity' => $questions,
                                );
                        }
                }

                /**
                 * Modify the JSON-LD graph before it is printed.
                 *
                 * @param array $graph   Full graph array ('@context' + '@graph' nodes).
                 * @param int   $post_id Current post ID.
                 */
                $graph = apply_filters( 'kic_jsonld_graph', $graph, $post_id );

                if ( empty( $graph ) ) {
                        return;
                }
                if ( isset( $graph['@graph'] ) && empty( $graph['@graph'] ) ) {
                        return;
                }

                // JSON_HEX_TAG neutralises </script> breakouts; UNESCAPED_SLASHES keeps URLs readable.
                $json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
                if ( ! is_string( $json ) || '' === $json ) {
                        return;
                }

                echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD payload from wp_json_encode() with JSON_HEX_TAG, which neutralises </script> breakouts; HTML-escaping would corrupt the JSON-LD document
        }
}
