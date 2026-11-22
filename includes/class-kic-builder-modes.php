<?php
/**
 * Natilus — page builder modes.
 *
 * Selected modes let the user pick WHERE and HOW generated content lands:
 *
 * - classic:    plain HTML into the post body (TinyMCE) — the classic editor.
 * - gutenberg:  plain HTML converted to blocks client-side.
 * - elementor:  HTML converted into a NATIVE Elementor document
 *              (section > column > heading / text-editor / image widgets)
 *              written straight into _elementor_data — no "do it in
 *              Elementor" instructions needed.
 * - wpbakery:   HTML converted into NATIVE WPBakery shortcodes
 *              ([vc_row][vc_column]...) written into post_content.
 * - auto:       detected from the post (Elementor meta / WPBakery active).
 *
 * Elementor live panel: when the user opens the Elementor editor screen,
 * the full compose widget is injected as a floating panel inside the
 * editor (elementor/editor/after_enqueue_scripts) so prompts and commands
 * sit right next to the live preview.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Builder_Modes {

        const MODES = array( 'auto', 'classic', 'gutenberg', 'elementor', 'wpbakery' );

        /**
         * Singleton instance (bootstrap contract).
         *
         * @var KIC_Builder_Modes|null
         */
        private static $instance = null;

        /**
         * @return KIC_Builder_Modes
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
         * @return KIC_Builder_Modes
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
                // Floating compose panel inside the Elementor editor screen.
                add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_elementor_panel' ) );
                add_action( 'admin_footer', array( $this, 'print_elementor_panel' ) );

                /* v2.5.2 — one-time repair: releases ≤2.5.1 wrote
                 * _elementor_edit_mode='edit', but Elementor only renders a
                 * document when the meta is 'builder'. Posts published that
                 * way showed an empty body on the frontend. */
                add_action( 'admin_init', array( $this, 'migrate_elementor_edit_mode' ) );
        }

        /**
         * Flip legacy '_elementor_edit_mode' = 'edit' rows to 'builder' once.
         *
         * @return void
         */
        public function migrate_elementor_edit_mode() {
                if ( get_option( 'kic_el_mode_v252' ) ) {
                        return;
                }
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time data repair; nothing in WP API reaches this meta directly.
                $wpdb->query(
                        $wpdb->prepare(
                                "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = %s AND meta_value = %s",
                                'builder',
                                '_elementor_edit_mode',
                                'edit'
                        )
                );
                update_option( 'kic_el_mode_v252', '1', false );
        }

        /* --------------------------------------------------------------------- */
        /* Mode resolution                                                       */
        /* --------------------------------------------------------------------- */

        /**
         * Whitelist a mode key.
         *
         * @param mixed $raw Raw mode.
         * @return string
         */
        public static function sanitize_mode( $raw ) {
                $mode = sanitize_key( (string) $raw );
                return in_array( $mode, self::MODES, true ) ? $mode : 'auto';
        }

        /**
         * Resolve the effective mode for a post: explicit override wins, then
         * the stored setting, then auto-detection from the post.
         *
         * @param int    $post_id   Post id (0 = none).
         * @param string $override Explicit request override (already whitelisted).
         * @return string
         */
        public static function resolve_for_post( $post_id, $override = 'auto' ) {
                $override = self::sanitize_mode( $override );

                if ( 'auto' === $override ) {
                        $setting = self::sanitize_mode( kic_get_setting( 'builder_mode', 'auto' ) );
                        if ( 'auto' !== $setting ) {
                                $override = $setting;
                        }
                }

                if ( 'auto' === $override ) {
                        $override = self::detect_for_post( $post_id );
                }

                return $override;
        }

        /**
         * Detect the builder active on a post.
         *
         * @param int $post_id Post id.
         * @return string
         */
        public static function detect_for_post( $post_id ) {
                $post_id = absint( $post_id );

                if ( $post_id > 0 && '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
                        return 'elementor';
                }

                if ( defined( 'WPB_VSC_VERSION' ) || defined( 'WPB_VC_VERSION' ) ) {
                        return 'wpbakery';
                }

                return 'gutenberg';
        }

        /**
         * Availability of the builder modes for the workspace selector.
         *
         * @param int $post_id Post id.
         * @return array key => {label, available}.
         */
        public static function available_modes( $post_id = 0 ) {
                $elementor = (
                        class_exists( '\Elementor\Plugin' )
                        || ( $post_id > 0 && '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) )
                        || did_action( 'elementor/loaded' )
                );

                $wpbakery = defined( 'WPB_VSC_VERSION' ) || defined( 'WPB_VC_VERSION' );

                $detected = self::detect_for_post( $post_id );

                return array(
                        'auto'      => array(
                                'label'      => 'خودکار' . ( '' !== $detected ? ' (' . self::mode_label( $detected ) . ')' : '' ),
                                'available'  => true,
                        ),
                        'classic'   => array(
                                'label'      => 'ویرایشگر کلاسیک وردپرس',
                                'available'  => true,
                        ),
                        'gutenberg' => array(
                                'label'      => 'ویرایشگر گوتنبرگ',
                                'available'  => true,
                        ),
                        'elementor' => array(
                                'label'      => 'المنتور (Elementor)',
                                'available'  => (bool) $elementor,
                        ),
                        'wpbakery'  => array(
                                'label'      => 'WPBakery',
                                'available'  => (bool) $wpbakery,
                        ),
                );
        }

        /**
         * Persian label of one mode.
         *
         * @param string $mode Mode key.
         * @return string
         */
        public static function mode_label( $mode ) {
                $labels = array(
                        'auto'      => 'خودکار',
                        'classic'   => 'کلاسیک',
                        'gutenberg' => 'گوتنبرگ',
                        'elementor' => 'المنتور',
                        'wpbakery'  => 'WPBakery',
                );
                $mode = self::sanitize_mode( $mode );
                return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $labels['auto'];
        }

        /* --------------------------------------------------------------------- */
        /* Prompt instructions                                                   */
        /* --------------------------------------------------------------------- */

        /**
         * English system-prompt section describing the output contract for the
         * selected builder mode (appended by KIC_Prompt_Builder).
         *
         * @param string $mode Mode key.
         * @return string '' when the mode needs no extra instructions.
         */
        public static function prompt_section( $mode ) {
                $mode = self::sanitize_mode( $mode );

                if ( 'wpbakery' === $mode ) {
                        return <<<'EOT'
PAGE BUILDER OUTPUT (WPBakery) — STRICT:
- Compose the body for WPBakery Page Builder. Output ONLY WPBakery shortcodes, never raw HTML at top level.
- Wrap everything exactly once: [vc_row][vc_column] ... [/vc_column][/vc_row]. Additional [vc_row] blocks are allowed BETWEEN top-level sections, but never nested rows.
- Headings: [vc_custom_heading text="Plain heading text" font_container="tag:h2"] (use tag:h2, tag:h3 or tag:h4; the text value must NOT contain quotes).
- Paragraphs, lists, quotes and tables: [vc_column_text] ... [/vc_column_text] — inside it plain HTML (p, ul, li, strong, em, a) is allowed.
- Images: [vc_single_image image="URL" title="short caption"] using ONLY provided media URLs; when no media list is given, emit no image shortcodes.
- The SEO JSON comment stays the ONLY non-shortcode thing at the very end of the response.
EOT;
                }

                if ( 'elementor' === $mode ) {
                        return <<<'EOT'
PAGE BUILDER OUTPUT (Elementor) — STRICT:
- Compose the body for a native Elementor layout. Output plain semantic HTML.
- Structure the body as clearly separated top-level blocks in document order: one heading element per heading (h2/h3/h4 alone on its own), one paragraph per paragraph, lists as their own ul/ol, images inside figure elements.
- Do NOT wrap content in section/div containers, do NOT use CSS classes for layout, and never emit inline styles — the publisher converts each top-level block into an Elementor widget (heading / text editor / image) automatically.
EOT;
                }

                if ( 'classic' === $mode ) {
                        return <<<'EOT'
PAGE BUILDER OUTPUT (Classic WordPress editor):
- Compose plain semantic HTML for the classic TinyMCE editor: h2/h3/h4 headings, short paragraphs, lists, figures. No block-editor comments, no page-builder shortcodes, no inline styles.
EOT;
                }

                return ''; // gutenberg / auto keep the default contract.
        }

        /* --------------------------------------------------------------------- */
        /* Elementor conversion                                                  */
        /* --------------------------------------------------------------------- */

        /**
         * Convert HTML into an Elementor elements JSON array (native widgets).
         *
         * Every top-level block becomes a widget: headings -> heading widget;
         * paragraphs / lists / quotes / tables -> text-editor widget;
         * figures/images -> image widget; hr -> divider.
         *
         * @param string $html Generated HTML.
         * @return array Elementor document elements (top-level sections).
         */
        public static function html_to_elementor( $html ) {
                $html = trim( (string) $html );
                if ( '' === $html ) {
                        return array();
                }

                $blocks = self::split_top_level( $html );
                $widgets = array();

                foreach ( $blocks as $block ) {
                        $widget = self::elementor_widget_for_block( $block );
                        if ( null !== $widget ) {
                                $widgets[] = $widget;
                        }
                }

                if ( empty( $widgets ) ) {
                        return array();
                }

                return array( self::elementor_section( $widgets ) );
        }

        /**
         * Elementor section wrapper: section > column(100%) > widgets.
         *
         * @param array $widgets Widget element rows.
         * @return array
         */
        private static function elementor_section( array $widgets ) {
                return array(
                        'id'       => self::elementor_id(),
                        'elType'   => 'section',
                        'settings' => array(),
                        'elements' => array(
                                array(
                                        'id'       => self::elementor_id(),
                                        'elType'   => 'column',
                                        'settings' => array( '_column_size' => 100, '_inline_size' => null ),
                                        'elements' => $widgets,
                                ),
                        ),
                );
        }

        /**
         * Map one top-level block to an Elementor widget element.
         *
         * @param array $block {tag, html, text}.
         * @return array|null
         */
        private static function elementor_widget_for_block( $block ) {
                $tag = (string) $block['tag'];
                $html = (string) $block['html'];
                $text = (string) $block['text'];

                // Headings -> heading widget.
                if ( preg_match( '/^h[1-6]$/', $tag ) ) {
                        return array(
                                'id'         => self::elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'heading',
                                'settings'   => array(
                                        'title'       => $text,
                                        'header_size' => $tag,
                                ),
                        );
                }

                // Images -> image widget.
                if ( 'figure' === $tag || 'img' === $tag ) {
                        $url = '';
                        $alt = '';
                        $caption = '';
                        if ( preg_match( '/<img[^>]*src=["\']([^"\']+)["\']/i', $html, $m ) ) {
                                $url = esc_url_raw( trim( $m[1] ) );
                        }
                        if ( preg_match( '/<img[^>]*alt=["\']([^"\']*)["\']/i', $html, $m ) ) {
                                $alt = sanitize_text_field( $m[1] );
                        }
                        if ( preg_match( '~<figcaption[^>]*>(.*?)</figcaption>~is', $html, $m ) ) {
                                $caption = sanitize_text_field( trim( wp_strip_all_tags( $m[1] ) ) );
                        }
                        if ( '' === $url ) {
                                return null; // No usable image inside.
                        }
                        $settings = array(
                                'image' => array(
                                        'url' => $url,
                                        'id'  => '',
                                        'alt' => $alt,
                                ),
                        );
                        if ( '' !== $caption ) {
                                $settings['caption_source'] = 'custom';
                                $settings['caption']        = $caption;
                        }
                        return array(
                                'id'         => self::elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'image',
                                'settings'   => $settings,
                        );
                }

                // hr -> divider widget.
                if ( 'hr' === $tag ) {
                        return array(
                                'id'         => self::elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'divider',
                                'settings'   => array(),
                        );
                }

                // Everything textual -> text-editor widget (keeps inner HTML).
                if ( '' !== trim( $html ) ) {
                        return array(
                                'id'         => self::elementor_id(),
                                'elType'     => 'widget',
                                'widgetType' => 'text-editor',
                                'settings'   => array(
                                        'editor' => $html,
                                ),
                        );
                }

                return null;
        }

        /**
         * 7-char hex Elementor id.
         *
         * @return string
         */
        private static function elementor_id() {
                return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
        }

        /**
         * Split HTML into top-level blocks.
         *
         * Handles both container elements (headings, paragraphs, lists, quotes,
         * figures, tables) and void elements (hr, img). Content between known
         * tags becomes implicit paragraph blocks.
         *
         * @param string $html HTML body.
         * @return array rows {tag, html, text}.
         */
        private static function split_top_level( $html ) {
                $html = trim( (string) $html );
                $blocks = array();

                $pattern = '~(<(h[1-6]|p|ul|ol|blockquote|figure|table|img|hr)\b[^>]*>.*?</\2>|<(h[1-6]|p|ul|ol|blockquote|figure|table)\b[^>]*/>|<(img|hr)\b[^>]*/?>)~is';

                if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
                        // Fallback: treat the whole body as one text block.
                        $text = trim( wp_strip_all_tags( $html ) );
                        if ( '' !== $text ) {
                                $blocks[] = array( 'tag' => 'p', 'html' => $html, 'text' => $text );
                        }
                        return $blocks;
                }

                $last = 0;
                foreach ( $matches[0] as $index => $hit ) {
                        $before = trim( substr( $html, $last, (int) $hit[1] - $last ) );
                        if ( '' !== $before && '' !== trim( wp_strip_all_tags( $before ) ) ) {
                                $blocks[] = array(
                                        'tag'  => 'p',
                                        'html' => '<p>' . $before . '</p>',
                                        'text' => trim( wp_strip_all_tags( $before ) ),
                                );
                        }

                        $element = (string) $hit[0];
                        if ( preg_match( '~^<([a-z0-9]+)~i', $element, $tag_match ) ) {
                                $tag = strtolower( $tag_match[1] );
                                $blocks[] = array(
                                        'tag'  => $tag,
                                        'html' => $element,
                                        'text' => trim( wp_strip_all_tags( $element ) ),
                                );
                        }

                        $last = (int) $hit[1] + strlen( $element );
                }

                $tail = trim( substr( $html, $last ) );
                if ( '' !== $tail && '' !== trim( wp_strip_all_tags( $tail ) ) ) {
                        $blocks[] = array(
                                'tag'  => 'p',
                                'html' => '<p>' . $tail . '</p>',
                                'text' => trim( wp_strip_all_tags( $tail ) ),
                        );
                }

                return $blocks;
        }

        /* --------------------------------------------------------------------- */
        /* WPBakery conversion                                                  */
        /* --------------------------------------------------------------------- */

        /**
         * Convert HTML into native WPBakery shortcode markup.
         *
         * @param string $html Generated HTML.
         * @return string
         */
        public static function html_to_vc( $html ) {
                $html = trim( (string) $html );
                if ( '' === $html ) {
                        return '';
                }

                $blocks = self::split_top_level( $html );
                $inner  = array();

                foreach ( $blocks as $block ) {
                        $tag  = (string) $block['tag'];
                        $code = (string) $block['html'];
                        $text = (string) $block['text'];

                        if ( preg_match( '/^h[1-6]$/', $tag ) ) {
                                $safe = self::vc_attr_escape( $text );
                                $size = $tag;
                                $inner[] = "[vc_custom_heading text=\"{$safe}\" font_container=\"tag:{$size}\"]";
                        } elseif ( 'img' === $tag || 'figure' === $tag ) {
                                $url = '';
                                $alt = '';
                                if ( preg_match( '/<img[^>]*src=["\']([^"\']+)["\']/i', $code, $m ) ) {
                                        $url = esc_url_raw( trim( $m[1] ) );
                                }
                                if ( preg_match( '/<img[^>]*alt=["\']([^"\']*)["\']/i', $code, $m ) ) {
                                        $alt = self::vc_attr_escape( $m[1] );
                                }
                                if ( '' !== $url ) {
                                        $inner[] = "[vc_single_image image=\"{$url}\" title=\"{$alt}\"]";
                                }
                        } elseif ( 'hr' === $tag ) {
                                $inner[] = '[vc_separator]';
                        } else {
                                // Paragraphs / lists / quotes / tables -> column text.
                                $inner[] = "[vc_column_text]{$code}[/vc_column_text]";
                        }
                }

                if ( empty( $inner ) ) {
                        return '';
                }

                $glued = implode( "\n", $inner );
                return "[vc_row][vc_column]\n{$glued}\n[/vc_column][/vc_row]";
        }

        /**
         * Escape a value for a double-quoted shortcode attribute.
         *
         * @param string $value Raw value.
         * @return string
         */
        private static function vc_attr_escape( $value ) {
                $value = (string) $value;
                $value = str_replace( array( '[', ']' ), array( '(', ')' ), $value );
                $value = str_replace( '"', '\'', $value );
                return trim( wp_strip_all_tags( $value ) );
        }

        /* --------------------------------------------------------------------- */
        /* Application during confirm                                           */
        /* --------------------------------------------------------------------- */

        /**
         * Apply a builder mode to a post after a confirmed composition.
         *
         * - elementor: converts the HTML to native widgets and writes
         *   _elementor_data (replace = new document, append = extra section),
         *   sets edit mode + regenerates CSS.
         * - wpbakery: writes the converted VC shortcode markup into
         *   post_content (replace = whole body, append = tail).
         * - classic/gutenberg: returns null (the standard injector already
         *   handled the body).
         *
         * @param int    $post_id Post id.
         * @param string $html    Confirmed HTML body.
         * @param string $mode    Resolved mode.
         * @param string $target  'append' | 'replace' | 'draft_new'.
         * @return array|null {mode, applied} or null when untouched.
         */
        public static function apply_to_post( $post_id, $html, $mode, $target = 'replace' ) {
                $post_id = absint( $post_id );
                $mode    = self::sanitize_mode( $mode );
                $target  = in_array( $target, array( 'append', 'replace', 'draft_new' ), true ) ? $target : 'replace';
                if ( $post_id < 1 ) {
                        return null;
                }

                if ( 'elementor' === $mode ) {
                        $sections = self::html_to_elementor( $html );
                        if ( empty( $sections ) ) {
                                return null;
                        }

                        if ( 'append' === $target ) {
                                $existing = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
                                if ( is_array( $existing ) && ! empty( $existing ) ) {
                                        $sections = array_merge( $existing, $sections );
                                }
                        }

                        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
                        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $sections ) ) );
                        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
                        delete_post_meta( $post_id, '_elementor_css' );
                        delete_post_meta( $post_id, '_elementor_inline_css' );

                        /* Keep post_content in sync — Elementor renders from
                         * _elementor_data, but the WP editor, search, excerpts
                         * and any non-Elementor fallback need real content. An
                         * empty post_content made publishes look like nothing
                         * happened to authors checking the editor. */
                        $synced = get_post( $post_id );
                        if ( $synced && '' === trim( (string) $synced->post_content ) ) {
                                wp_update_post(
                                        array(
                                                'ID'           => $post_id,
                                                'post_content' => wp_slash( $html ),
                                        )
                                );
                        }

                        return array( 'mode' => 'elementor', 'applied' => true );
                }

                if ( 'wpbakery' === $mode ) {
                        $vc = self::html_to_vc( $html );
                        if ( '' === $vc ) {
                                return null;
                        }

                        $post = get_post( $post_id );
                        if ( ! $post ) {
                                return null;
                        }

                        $content = (string) $post->post_content;
                        $updated = ( 'replace' === $target || 'draft_new' === $target ) ? $vc : rtrim( $content ) . "\n\n" . $vc;

                        $result = wp_update_post(
                                array(
                                        'ID'           => $post_id,
                                        'post_content' => wp_slash( $updated ),
                                ),
                                true
                        );

                        if ( is_wp_error( $result ) ) {
                                return null;
                        }

                        return array( 'mode' => 'wpbakery', 'applied' => true );
                }

                return null;
        }

        /* --------------------------------------------------------------------- */
        /* Elementor editor live panel                                          */
        /* --------------------------------------------------------------------- */

        /**
         * Whether the current admin request is the Elementor editor screen.
         *
         * @return bool
         */
        private function is_elementor_editor_request() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
                $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
                if ( 'elementor' === $action ) {
                        return true;
                }
                // Elementor's own REST-ish editor loads use this flag.
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
                return isset( $_GET['elementor-preview'], $_GET['p'] ) || ( isset( $_GET['elementor_library'] ) );
        }

        /**
         * elementor/editor/after_enqueue_scripts: load the compose widget inside
         * the Elementor editor so prompts sit next to the live preview.
         *
         * @return void
         */
        public function enqueue_elementor_panel() {
                if ( ! $this->can_compose() ) {
                        return;
                }
                if ( ! $this->is_elementor_editor_request() ) {
                        // Elementor fires the hook on some AJAX contexts too; keep the
                        // panel strictly for the editor screen.
                        return;
                }

                wp_enqueue_style( 'kic-compose', KIC_URL . 'admin/css/kic-compose.css', array(), KIC_VERSION );
                wp_enqueue_script( 'kic-compose', KIC_URL . 'admin/js/kic-compose.js', array( 'jquery' ), KIC_VERSION, true );

                $post = get_post();
                $post_id = ( $post instanceof WP_Post ) ? (int) $post->ID : 0;

                wp_localize_script( 'kic-compose', 'KIC', $this->elementor_script_config( $post_id ) );
        }

        /**
         * admin_footer: print the floating panel container on the Elementor
         * editor screen (the compose widget mounts itself into it).
         *
         * @return void
         */
        public function print_elementor_panel() {
                if ( ! $this->can_compose() ) {
                        return;
                }
                if ( ! $this->is_elementor_editor_request() ) {
                        return;
                }

                echo '<div id="kic-elementor-panel" dir="rtl" aria-label="Natilus"></div>';
        }

        /**
         * Localized config for the Elementor editor panel (editor=elementor).
         *
         * @param int $post_id Post id.
         * @return array
         */
        private function elementor_script_config( $post_id ) {
                $config = array();

                if ( class_exists( 'KIC_Metabox' ) && method_exists( 'KIC_Metabox', 'build_script_config' ) ) {
                        $post = $post_id > 0 ? get_post( $post_id ) : null;
                        if ( $post instanceof WP_Post ) {
                                $config = KIC_Metabox::build_script_config( $post, false );
                        }
                }

                if ( empty( $config ) ) {
                        $config = array(
                                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                                'nonce'   => wp_create_nonce( 'kic_ajax' ),
                                'postId'  => $post_id,
                                'editor'  => 'elementor',
                                'features' => array(
                                        'auto_sideload'  => (bool) kic_get_setting( 'auto_sideload', 1 ),
                                        'jsonld'         => (bool) kic_get_setting( 'jsonld', 1 ),
                                        'draft_default' => (bool) kic_get_setting( 'draft_default', 0 ),
                                ),
                                'backups'  => array(),
                        );
                }

                $config['editor'] = 'elementor';
                $config['context'] = 'elementor';
                $config['isElementor'] = true;

                return $config;
        }

        /**
         * Compose capability check (guarded).
         *
         * @return bool
         */
        private function can_compose() {
                if ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) ) {
                        return (bool) KIC_Security::user_can_compose();
                }
                return current_user_can( 'manage_options' );
        }
}
