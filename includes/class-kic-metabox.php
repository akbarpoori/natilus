<?php
/**
 * Inline compose widget under the post editor (Gutenberg AND Classic).
 *
 * Registers the "Natilus" metabox, renders the mount point consumed
 * by admin/js/kic-compose.js and localizes the full runtime config (KIC object).
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Metabox {

        /**
         * Singleton instance.
         *
         * @var KIC_Metabox|null
         */
        private static $instance = null;

        /**
         * Boot the module (called from KIC_Plugin::boot()).
         *
         * @return KIC_Metabox
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
         * @return KIC_Metabox
         */
        public static function instance() {
                return self::init();
        }

        /**
         * Register own hooks.
         */
        private function __construct() {
                add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        }

        /**
         * Whether the current user may use the composer. Guarded so the module
         * never fatals even when loaded standalone.
         *
         * @return bool
         */
        private function can_compose() {
                return ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) && KIC_Security::user_can_compose() );
        }

        /**
         * Metabox screens: core types plus the kic_meta_screens filter, unique.
         *
         * @return string[]
         */
        private function screens() {
                $screens = array( 'post', 'page', 'product' );
                $extra   = apply_filters( 'kic_meta_screens', array() );
                if ( ! is_array( $extra ) ) {
                        $extra = array();
                }
                $extra   = array_filter( array_map( function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; }, $extra ) );
                $screens = array_values( array_unique( array_merge( $screens, $extra ) ) );
                return $screens;
        }

        /**
         * Register the metabox on composer-enabled screens.
         *
         * @return void
         */
        public function register_metabox() {
                if ( ! $this->can_compose() ) {
                        return;
                }
                add_meta_box(
                        'kic_compose',
                        'Natilus',
                        array( $this, 'render_metabox' ),
                        $this->screens(),
                        'normal',
                        'high'
                );
        }

        /**
         * Metabox callback: mount point + noscript + Elementor notice.
         *
         * @param WP_Post|null $post Current post.
         * @return void
         */
        public function render_metabox( $post ) {
                if ( ! $this->can_compose() ) {
                        return;
                }

                $post_id = ( $post instanceof WP_Post ) ? (int) $post->ID : 0;

                echo '<div id="kic-compose-root" class="kic-root" dir="rtl"></div>';
                echo '<noscript><div class="kic-nojs">برای استفاده از Natilus باید جاوااسکریپت مرورگر فعال باشد.</div></noscript>';

                if ( $post_id > 0 && '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
                        echo '<div class="kic-elementor-note">این نوشته با Elementor ساخته شده است — با انتشار از ویجت Natilus، محتوا در ساختار المنتور ثبت می‌شود.</div>';
                }
        }

        /**
         * Enqueue widget assets on post edit screens only.
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
                        return;
                }
                if ( ! $this->can_compose() ) {
                        return;
                }

                $post = get_post();
                if ( ! ( $post instanceof WP_Post ) ) {
                        return;
                }
                if ( ! in_array( $post->post_type, $this->screens(), true ) ) {
                        return;
                }

                $is_block = false;
                if ( function_exists( 'use_block_editor_for_post' ) ) {
                        $is_block = (bool) use_block_editor_for_post( $post );
                }

                wp_enqueue_style( 'kic-compose', KIC_URL . 'admin/css/kic-compose.css', array(), KIC_VERSION );

                $deps = array( 'jquery' );
                if ( $is_block ) {
                        $deps[] = 'wp-data';
                        $deps[] = 'wp-blocks';
                        $deps[] = 'wp-element';
                }
                wp_enqueue_script( 'kic-compose', KIC_URL . 'admin/js/kic-compose.js', $deps, KIC_VERSION, true );

                wp_localize_script( 'kic-compose', 'KIC', self::build_script_config( $post, $is_block ) );
        }

        /**
         * Build the localized KIC config for the publisher widget.
         *
         * Public static so KIC_Builder_Modes can reuse the exact same
         * payload for the Elementor editor live panel.
         *
         * @param WP_Post $post     Current post.
         * @param bool    $is_block Whether the block editor is active.
         * @return array
         */
        public static function build_script_config( $post, $is_block ) {
                $post_id = (int) $post->ID;

                // Feature flags from plugin settings (publisher-relevant only).
                $features = array(
                        'auto_sideload'  => (bool) kic_get_setting( 'auto_sideload', 1 ),
                        'jsonld'         => (bool) kic_get_setting( 'jsonld', 1 ),
                        'draft_default'  => (bool) kic_get_setting( 'draft_default', 0 ),
                );

                // Revision backups (guarded): [{key, time}].
                $backups = array();
                if ( class_exists( 'KIC_Revision_Guard' ) && method_exists( 'KIC_Revision_Guard', 'list_backups' ) ) {
                        $raw_backups = KIC_Revision_Guard::list_backups( $post_id );
                        if ( is_array( $raw_backups ) ) {
                                foreach ( $raw_backups as $item ) {
                                        if ( ! is_array( $item ) && ! is_object( $item ) ) {
                                                continue;
                                        }
                                        $item = (array) $item;
                                        $time = isset( $item['time'] ) ? $item['time'] : 0;
                                        $backups[] = array(
                                                'key'  => isset( $item['key'] ) ? (string) $item['key'] : '',
                                                'time' => is_numeric( $time ) ? (int) $time : (string) $time,
                                        );
                                }
                        }
                }

                $is_elementor = ( $post_id > 0 ) && ( '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) );

                /* Builder modes available on this screen. */
                $builder_modes = array();
                if ( class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'available_modes' ) ) {
                    $builder_modes = KIC_Builder_Modes::available_modes( $post_id );
                }

                $builder_default = 'auto';
                if ( class_exists( 'KIC_Builder_Modes' ) && method_exists( 'KIC_Builder_Modes', 'sanitize_mode' ) ) {
                    $builder_default = KIC_Builder_Modes::sanitize_mode( kic_get_setting( 'builder_mode', 'auto' ) );
                }

                return array(
                        'version'      => KIC_VERSION,
                        'ajaxurl'      => admin_url( 'admin-ajax.php' ),
                        'nonce'        => wp_create_nonce( 'kic_ajax' ),
                        'postId'       => $post_id,
                        'postType'     => (string) $post->post_type,
                        'editor'       => $is_block ? 'gutenberg' : 'classic',
                        'features'     => $features,
                        'backups'      => $backups,
                        'isElementor'  => (bool) $is_elementor,
                        'builderModes' => $builder_modes,
                        'builderDefault' => $builder_default,
                        /* v1.4 publisher mode: existing categories for the new-post picker (no AI, plain WP data). */
                        'categories'   => self::category_options(),
                        /* v2.2 — headline set (روتیتر/زیرتیتر) sync with the active theme (guarded). */
                        'themeSync'    => ( class_exists( 'KIC_Theme_Sync' ) && method_exists( 'KIC_Theme_Sync', 'js_config' ) )
                                ? KIC_Theme_Sync::js_config()
                                : array( 'theme' => array( 'name' => '', 'slug' => '', 'parent' => '' ), 'kicker' => array( 'mode' => '', 'key' => '' ), 'subtitle' => array( 'mode' => '', 'key' => '' ), 'hint' => '' ),
                        /* v2.3 — prompt-based edit of the CURRENT post: availability map (guarded). */
                        'aiEdit'       => ( class_exists( 'KIC_AI_Editor' ) && method_exists( 'KIC_AI_Editor', 'availability' ) )
                                ? KIC_AI_Editor::availability()
                                : array( 'enabled' => false, 'configured' => false ),
                        /* v2.4 — prompt-based generation + optional image generation (guarded). */
                        'generate'     => ( class_exists( 'KIC_Generator' ) && method_exists( 'KIC_Generator', 'availability' ) )
                                ? KIC_Generator::availability()
                                : array( 'enabled' => false, 'configured' => false ),
                        'imageModel'   => (string) kic_get_setting( 'image_model', '' ),
                        /* v2.9 — per-run model picker on the compose panel:
                         * base URL is needed client-side only to ask
                         * kic_list_provider_models for the live list; the
                         * stored key is reused server-side when it matches. */
                        'providerBase' => (string) kic_get_setting( 'base_url', '' ),
                        'defaultModel' => (string) kic_get_setting( 'model', '' ),
                        'i18n'         => ( class_exists( 'KIC_I18n' ) && method_exists( 'KIC_I18n', 'js_package' ) )
                                ? KIC_I18n::js_package()
                                : array( 'lang' => 'fa', 'dict' => array() ),
                );
        }

        /**
         * Flat category list (id + title) for the new-post picker.
         *
         * @return array
         */
        private static function category_options() {
                $options = array();
                $terms   = get_categories(
                        array(
                                'taxonomy'   => 'category',
                                'hide_empty' => false,
                                'number'     => 100,
                        )
                );
                if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                        return $options;
                }
                foreach ( $terms as $term ) {
                        if ( ! $term instanceof WP_Term ) {
                                continue;
                        }
                        $depth = 0;
                        $parent = (int) $term->parent;
                        while ( $parent && $depth < 3 ) {
                                $depth++;
                                $ancestor = get_term( $parent, 'category' );
                                $parent = ( $ancestor instanceof WP_Term ) ? (int) $ancestor->parent : 0;
                        }
                        $options[] = array(
                                'id'    => (int) $term->term_id,
                                'title' => str_repeat( '— ', $depth ) . $term->name,
                        );
                }
                return $options;
        }
}
