<?php
/**
 * Natilus — SEO plugin integrator.
 *
 * Writes the composition's SEO payload into Rank Math / Yoast post meta,
 * always keeps the raw payload in "_kic_seo" for our own output, and prints
 * a minimal meta description + Open Graph set when NO SEO plugin is active.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_SEO_Integrator {

        /**
         * Singleton instance.
         *
         * @var KIC_SEO_Integrator|null
         */
        private static $instance = null;

        /**
         * @return KIC_SEO_Integrator
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
         * @return KIC_SEO_Integrator
         */
        public static function init() {
                return self::instance();
        }

        /**
         * Register hooks.
         */
        private function __construct() {
                /*
                 * Standalone <head> meta fallback (priority 5, before most theme SEO
                 * output). Presence of a SEO plugin is checked at render time — not at
                 * boot — so plugin activation order never matters: if Rank Math, Yoast,
                 * AIOSEO or SEOPress is loaded by the time wp_head runs, we stay silent
                 * because the SEO plugin renders its own meta tags.
                 */
                add_action( 'wp_head', array( $this, 'print_standalone_meta' ), 5 );
        }

        /**
         * Apply the SEO payload of a publishing action to the target post.
         *
         * Always stores the payload in the "_kic_seo" post meta (array) and, when
         * a supported SEO plugin is active, mirrors the title/description/focus
         * keyword into its post-level fields so its UI shows the values right away.
         *
         * @param int   $post_id Target post ID.
         * @param array $seo     Payload with "seo_title", "seo_description" and
         *                       "focus_keyword" (optionally "faq" for JSON-LD).
         * @return void
         */
        public function apply( $post_id, $seo ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 || ! is_array( $seo ) ) {
                        return;
                }

                $title       = isset( $seo['seo_title'] ) ? sanitize_text_field( (string) $seo['seo_title'] ) : '';
                $description = isset( $seo['seo_description'] ) ? sanitize_text_field( (string) $seo['seo_description'] ) : '';
                $focus_kw    = isset( $seo['focus_keyword'] ) ? sanitize_text_field( (string) $seo['focus_keyword'] ) : '';

                // Always store our own payload (feeds the standalone fallback + UI).
                update_post_meta(
                        $post_id,
                        '_kic_seo',
                        array(
                                'title'         => $title,
                                'description'   => $description,
                                'focus_keyword' => $focus_kw,
                        )
                );

                // Rank Math post-level SEO fields.
                if ( class_exists( 'RankMath' ) ) {
                        update_post_meta( $post_id, 'rank_math_title', $title );
                        update_post_meta( $post_id, 'rank_math_description', $description );
                        if ( '' !== $focus_kw ) {
                                update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
                        }
                }

                // Yoast SEO post-level fields.
                if ( defined( 'WPSEO_VERSION' ) ) {
                        update_post_meta( $post_id, '_yoast_wpseo_title', $title );
                        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
                        if ( '' !== $focus_kw ) {
                                update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_kw );
                        }
                }

                /*
                 * Forward the payload to the JSON-LD module only when a FAQ block
                 * travelled along, so a direct register_jsonld() call made elsewhere
                 * with richer data is never overwritten by a payload lacking the FAQ.
                 */
                if ( isset( $seo['faq'] ) && class_exists( 'KIC_SEO_Schema' ) && method_exists( 'KIC_SEO_Schema', 'register_jsonld' ) ) {
                        KIC_SEO_Schema::register_jsonld( $post_id, $seo );
                }
        }

        /**
         * Print the standalone meta description + Open Graph tags when no SEO
         * plugin owns the <head>.
         *
         * SEO plugins render their own meta tags when active, so we deliberately
         * stay silent in that case and only emit this minimal set for sites
         * running without any SEO plugin.
         *
         * @return void
         */
        public function print_standalone_meta() {
                if ( $this->seo_plugin_active() ) {
                        return; // A SEO plugin is printing its own meta output.
                }
                if ( ! is_singular() ) {
                        return;
                }

                $post_id = get_the_ID();
                if ( ! $post_id ) {
                        return;
                }

                $stored = get_post_meta( $post_id, '_kic_seo', true );
                if ( ! is_array( $stored ) ) {
                        return;
                }

                $title       = isset( $stored['title'] ) ? trim( (string) $stored['title'] ) : '';
                $description = isset( $stored['description'] ) ? trim( (string) $stored['description'] ) : '';

                if ( '' === $title && '' === $description ) {
                        return;
                }

                echo "\n<!-- Natilus: standalone SEO meta (no SEO plugin detected) -->\n";

                if ( '' !== $description ) {
                        echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
                }
                if ( '' !== $title ) {
                        echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
                }
                if ( '' !== $description ) {
                        echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
                }
        }

        /**
         * Whether a known SEO plugin is running (and thus owns <head> meta).
         *
         * @return bool
         */
        private function seo_plugin_active() {
                return class_exists( 'RankMath' )
                        || defined( 'WPSEO_VERSION' )     // Yoast SEO.
                        || defined( 'AIOSEO_VERSION' )    // All in One SEO.
                        || defined( 'SEOPRESS_VERSION' ); // SEOPress.
        }
}
