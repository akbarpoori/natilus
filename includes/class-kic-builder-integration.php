<?php
/**
 * Natilus — page-builder integration.
 *
 * Elementor: registers a native widget that renders the generated-content
 * meta and injects the widget element into existing documents after a
 * composition. WPBakery: registers the [kic_content] shortcode as a VC
 * element and (conservatively) appends the shortcode to freshly generated
 * posts without ever duplicating content. Slider Revolution: best-effort
 * automatic slider creation that degrades to a stored structure snapshot
 * plus a one-time admin notice describing the manual recipe.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Builder_Integration {

        /**
         * Singleton instance.
         *
         * @var KIC_Builder_Integration|null
         */
        private static $instance = null;

        /**
         * True once the Elementor widget has been registered during this request.
         *
         * @var bool
         */
        private static $elementor_registered = false;

        /**
         * @return KIC_Builder_Integration
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
         * @return KIC_Builder_Integration
         */
        public static function init() {
                return self::instance();
        }

        /**
         * Register hooks. Builder hooks that never fire when the builder is
         * inactive are harmless, so no boot-time class_exists() gate is needed.
         */
        private function __construct() {
                // Elementor: modern hook + legacy alias (both fire on some versions).
                add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
                add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_elementor_widget' ) );

                // WPBakery: element map after the VC core is fully initialised.
                add_action( 'vc_after_init', array( $this, 'register_wpbakery_element' ) );

                // Universal shortcode (also what the Elementor widget path renders).
                add_shortcode( 'kic_content', array( $this, 'render_kic_shortcode' ) );

                // One-time admin notice once a Slider Revolution structure was stored.
                add_action( 'admin_notices', array( $this, 'maybe_slider_notice' ) );
        }

        /**
         * Register the KIC content widget with Elementor (modern + legacy APIs).
         *
         * The widget class lives in its own top-level file
         * (class-kic-content-widget.php) and is required only AFTER the Elementor
         * base classes were confirmed, so the declaration can never fatal —
         * PHP rejects nested named-class declarations inside methods, so keeping
         * it inline here would kill the whole plugin load on every install.
         *
         * @param mixed $manager \Elementor\Widgets_Manager instance (null when invoked without args).
         * @return void
         */
        public function register_elementor_widget( $manager = null ) {
                // Guarded class check: no fatal when Elementor is not installed.
                if ( ! class_exists( '\Elementor\Widget_Base' ) || ! class_exists( '\Elementor\Widgets_Manager' ) ) {
                        return;
                }
                if ( ! is_object( $manager ) || self::$elementor_registered ) {
                        return; // No manager or already registered this request.
                }
                self::$elementor_registered = true;

                if ( ! class_exists( 'KIC_Content_Widget', false ) ) {
                        require_once __DIR__ . '/class-kic-content-widget.php';
                }

                $widget = new KIC_Content_Widget();

                if ( method_exists( $manager, 'register' ) ) {
                        $manager->register( $widget ); // Elementor >= 3.5 API.
                } elseif ( method_exists( $manager, 'register_widget_type' ) ) {
                        $manager->register_widget_type( $widget ); // Legacy API fallback.
                }
        }

        /**
         * Render the [kic_content] shortcode: the generated content of the
         * current post in the loop.
         *
         * @param array|string $atts Shortcode attributes (none supported by design).
         * @return string
         */
        public function render_kic_shortcode( $atts ) {
                $atts = shortcode_atts( array(), $atts, 'kic_content' ); // No parameters by design.

                $post_id = get_the_ID();
                if ( ! $post_id ) {
                        return '';
                }

                $content = get_post_meta( $post_id, '_kic_generated_content', true );
                if ( ! is_string( $content ) || '' === $content ) {
                        return '';
                }

                return wp_kses_post( $content );
        }

        /**
         * Register the [kic_content] shortcode as a WPBakery element.
         *
         * @return void
         */
        public function register_wpbakery_element() {
                if ( ! function_exists( 'vc_map' ) ) {
                        return; // WPBakery not active.
                }

                vc_map(
                        array(
                                'name'                    => 'Natilus محتوا',
                                'base'                    => 'kic_content',
                                'category'                => 'Content',
                                'icon'                    => 'icon-wpb-kic',
                                'description'             => 'محتوای تولیدشده توسط Natilus',
                                'show_settings_on_create' => false,
                                'params'                  => array(),
                        )
                );
        }

        /**
         * Sync a confirmed composition into the active page builders.
         *
         * Called right after the composer confirmed the generation. Each builder
         * path is wrapped in its own try/catch (Throwable) so a builder quirk can
         * never break the composing flow itself.
         *
         * @param int    $post_id Target post ID.
         * @param string $html    Confirmed generated HTML.
         * @return void
         */
        public function sync( $post_id, $html ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 ) {
                        return;
                }
                $html = (string) $html;

                try {
                        $this->sync_elementor( $post_id, $html );
                } catch ( Throwable $e ) {
                        // Elementor data quirks must never break composing. Swallow.
                }

                try {
                        $this->sync_wpbakery( $post_id, $html );
                } catch ( Throwable $e ) {
                        // WPBakery quirks must never break composing. Swallow.
                }

                try {
                        $this->sync_slider_revolution( $post_id, $html );
                } catch ( Throwable $e ) {
                        // Slider Revolution API drift must never break composing. Swallow.
                }
        }

        /**
         * Elementor path: store the generated HTML in meta (rendered by the KIC
         * widget / shortcode) and, when the document does not contain a KIC
         * widget yet, append one to _elementor_data and drop cached CSS.
         *
         * @param int    $post_id Target post ID.
         * @param string $html    Generated HTML.
         * @return void
         */
        private function sync_elementor( $post_id, $html ) {
                if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) !== 'edit' ) {
                        return; // Not an Elementor document.
                }

                // The KIC widget / [kic_content] shortcode renders this meta.
                update_post_meta( $post_id, '_kic_generated_content', $html );

                $data = (string) get_post_meta( $post_id, '_elementor_data', true );
                if ( '' === $data ) {
                        return; // Empty document data: nothing to append into.
                }

                // A KIC widget is already placed — do not duplicate it.
                if ( false !== strpos( $data, '"widgetType":"kic_content"' ) ) {
                        return;
                }

                $decoded = json_decode( $data, true );
                if ( ! is_array( $decoded ) || empty( $decoded ) ) {
                        return;
                }

                /*
                 * Append the KIC widget element. Decoded with assoc=true, so an empty
                 * array is used for "settings" (a stdClass cast would round-trip the
                 * same way once re-encoded).
                 */
                $decoded[] = array(
                        'id'         => substr( md5( uniqid( '', true ) ), 0, 7 ),
                        'elType'     => 'widget',
                        'widgetType' => 'kic_content',
                        'settings'   => array(),
                );

                update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $decoded ) ) );

                // Drop cached CSS so Elementor regenerates it for the updated document.
                delete_post_meta( $post_id, '_elementor_css' );
                delete_post_meta( $post_id, '_elementor_inline_css' );
        }

        /**
         * WPBakery path: ensure the [kic_content] shortcode is embedded at most
         * once in the post content.
         *
         * CONSERVATIVE by design: for "replace" mode the classic injector has
         * already written the full HTML into post_content, so the shortcode
         * variant is only an alternative path. We therefore append ONLY when a
         * fresh composition just landed (the "_kic_generated" flag set by the
         * injector), the shortcode is not embedded yet, AND the generated body
         * itself is not already present in post_content (belt-and-braces guard
         * against duplicated output in both append and replace modes).
         *
         * @param int    $post_id Target post ID.
         * @param string $html    Generated HTML.
         * @return void
         */
        private function sync_wpbakery( $post_id, $html ) {
                global $wpdb;

                if ( ! defined( 'WPB_VSC_VERSION' ) && ! defined( 'WPB_VC_VERSION' ) ) {
                        return; // WPBakery not active.
                }

                $post = get_post( $post_id );
                if ( ! $post || ! is_object( $post ) ) {
                        return;
                }

                $content = (string) $post->post_content;

                // Shortcode already embedded — never append twice.
                if ( false !== strpos( $content, '[kic_content' ) ) {
                        return;
                }

                // Only act right after a fresh composition (injector flag).
                $flag = get_post_meta( $post_id, '_kic_generated', true );
                if ( empty( $flag ) ) {
                        return;
                }

                /*
                 * Fingerprint guard: if the generated body was already injected into
                 * post_content (replace or append mode), the shortcode variant would
                 * render the same HTML a second time — skip it entirely.
                 */
                $flat_content = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
                $flat_html    = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
                if ( '' !== $flat_html ) {
                        $needle = function_exists( 'mb_substr' ) ? mb_substr( $flat_html, 0, 120 ) : substr( $flat_html, 0, 120 );
                        if ( '' !== $needle && false !== strpos( $flat_content, $needle ) ) {
                                return; // Body already inside post_content.
                        }
                }

                // Append the shortcode once, direct DB update via prepare().
                $wpdb->query(
                        $wpdb->prepare(
                                "UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d",
                                $content . "\n\n" . '[kic_content]',
                                $post_id
                        )
                );
                clean_post_cache( $post_id );
        }

        /**
         * Slider Revolution path — BEST-EFFORT by design.
         *
         * The SR internal API (RevSliderSlider / RevSlide) changes between
         * releases, so every call is guarded with method_exists() and any failure
         * (or a missing inner class) degrades to storing a structure snapshot in
         * the "_kic_slider_structure" post meta plus a one-time admin notice that
         * describes the manual gallery-slider recipe. When Slider Revolution is
         * not installed at all this method intentionally does nothing.
         *
         * @param int    $post_id Target post ID.
         * @param string $html    Generated HTML.
         * @return void
         */
        private function sync_slider_revolution( $post_id, $html ) {
                if ( ! class_exists( 'RevSliderSlider' ) ) {
                        return; // Slider Revolution absent: no slider work at all.
                }

                // Structure extraction: h2/h3 titles and the first 10 inline images.
                $titles = array();
                if ( preg_match_all( '~<h[23][^>]*>(.*?)</h[23]>~is', $html, $m ) ) {
                        foreach ( $m[1] as $raw_title ) {
                                $titles[] = trim( wp_strip_all_tags( (string) $raw_title ) );
                        }
                        $titles = array_values( array_filter( $titles ) );
                }

                $images = array();
                if ( preg_match_all( '~<img[^>]*src="([^"]+)"~is', $html, $m2 ) ) {
                        $images = array_slice( array_map( 'trim', $m2[1] ), 0, 10 );
                }

                $slides = array();
                $total  = max( count( $titles ), count( $images ) );
                for ( $i = 0; $i < $total; $i++ ) {
                        $slides[] = array(
                                'title' => isset( $titles[ $i ] ) ? $titles[ $i ] : '',
                                'image' => isset( $images[ $i ] ) ? $images[ $i ] : '',
                        );
                }

                $slider_title = get_the_title( $post_id ) . ' — KIC';

                try {
                        $slider = new RevSliderSlider();
                        if ( ! method_exists( $slider, 'create_slider' ) ) {
                                throw new Exception( 'RevSliderSlider::create_slider() is unavailable on this Slider Revolution version.' );
                        }

                        $slider_id = $slider->create_slider(
                                array(
                                        'title'       => $slider_title,
                                        'source_type' => 'gallery',
                                )
                        );

                        if ( empty( $slider_id ) || is_wp_error( $slider_id ) ) {
                                throw new Exception( 'Slider Revolution returned no slider id.' );
                        }

                        if ( ! class_exists( 'RevSlide' ) ) {
                                throw new Exception( 'RevSlide class is unavailable on this Slider Revolution version.' );
                        }

                        $position = 1;
                        foreach ( $slides as $slide_data ) {
                                $slide = new RevSlide();

                                // Required steps: throw the moment one is unavailable.
                                if ( ! method_exists( $slide, 'set_slider_id' ) ) {
                                        throw new Exception( 'RevSlide::set_slider_id() is unavailable.' );
                                }
                                $slide->set_slider_id( $slider_id );

                                $params = array(
                                        'title' => ( '' !== $slide_data['title'] ) ? $slide_data['title'] : ( 'Slide ' . $position ),
                                        'state' => 'published',
                                );
                                if ( '' !== $slide_data['image'] ) {
                                        $params['image'] = $slide_data['image'];
                                }

                                if ( ! method_exists( $slide, 'set_params' ) ) {
                                        throw new Exception( 'RevSlide::set_params() is unavailable.' );
                                }
                                $slide->set_params( $params );

                                // Optional extras across SR versions — attempt only if present.
                                if ( method_exists( $slide, 'set_layers' ) ) {
                                        $slide->set_layers( array() );
                                }
                                if ( method_exists( $slide, 'save_params' ) ) {
                                        $slide->save_params();
                                }

                                $position++;
                        }

                        return; // Slider created successfully — no fallback needed.
                } catch ( Throwable $e ) {
                        // Fall through to the structure snapshot below.
                }

                // Fallback: reusable structure snapshot + one-time admin notice flag.
                update_post_meta(
                        $post_id,
                        '_kic_slider_structure',
                        wp_json_encode(
                                array(
                                        'title'  => $slider_title,
                                        'slides' => $slides,
                                )
                        )
                );
                update_post_meta( $post_id, '_kic_slider_notice', '1' );
        }

        /**
         * One-time admin notice: a Slider Revolution structure snapshot was
         * stored for this post after automatic slider creation was unavailable.
         *
         * @return void
         */
        public function maybe_slider_notice() {
                if ( ! is_admin() ) {
                        return;
                }

                global $post;
                if ( ! $post instanceof WP_Post ) {
                        return;
                }

                if ( '1' !== get_post_meta( $post->ID, '_kic_slider_notice', true ) ) {
                        return;
                }

                $slider_title = '';
                $structure_raw = get_post_meta( $post->ID, '_kic_slider_structure', true );
                if ( is_string( $structure_raw ) && '' !== $structure_raw ) {
                        $structure = json_decode( $structure_raw, true );
                        if ( is_array( $structure ) && isset( $structure['title'] ) ) {
                                $slider_title = (string) $structure['title'];
                        }
                }
                if ( '' === $slider_title ) {
                        $slider_title = get_the_title( $post->ID ) . ' — KIC';
                }

                $message = sprintf(
                        'ساختار اسلایدر «%s» از محتوای تولیدشده آماده شد. در Slider Revolution یک اسلایدر گالری بسازید و تصاویر همین نوشته را استفاده کنید.',
                        $slider_title
                );

                echo '<div class="notice notice-info is-dismissible" style="border-left-color:#7b5cff;">';
                echo '<p><strong>Natilus:</strong> ' . esc_html( $message ) . '</p>';
                echo '</div>';

                delete_post_meta( $post->ID, '_kic_slider_notice' ); // One-time notice.
        }
}
