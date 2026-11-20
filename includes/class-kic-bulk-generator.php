<?php
/**
 * Bulk publisher: paste many READY-made articles -> posts (draft or live).
 *
 * v2.1 — pure publisher. The legacy CSV→generation queue (keywords/tone/
 * template columns, AI processing endpoints) was fully removed: Natilus
 * never generates content. Each pasted block lands exactly as written.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Bulk_Generator {

        const PAGE_SLUG = 'kic-bulk';

        /**
         * @var KIC_Bulk_Generator|null
         */
        private static $instance = null;

        /**
         * Singleton accessor.
         *
         * @return KIC_Bulk_Generator
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Boot hook registrar.
         *
         * @return KIC_Bulk_Generator
         */
        public static function init() {
                return self::instance();
        }

        /**
         * Private constructor: registers all hooks owned by this module.
         */
        private function __construct() {
                add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
                /* v1.4 publisher mode: bulk = paste READY contents, create posts (no AI). */
                add_action( 'admin_post_kic_bulk_publish', array( $this, 'handle_publish' ) );
        }

        /**
         * Admin menu: «انتشار انبوه» under the plugin top-level page.
         *
         * @return void
         */
        public function register_menu() {
                add_submenu_page(
                        'ai-content-publishing-agent',
                        kic_t( 'انتشار انبوه' ),
                        kic_t( 'انتشار انبوه' ),
                        'manage_options',
                        self::PAGE_SLUG,
                        array( $this, 'render_page' )
                );
        }

        /**
         * Enqueue page assets (shared stylesheet only — the publisher form is
         * plain server-rendered PHP, no queue JS needed anymore).
         *
         * @param string $hook Current admin page hook suffix.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $on_page = ( self::PAGE_SLUG === $page ) || ( is_string( $hook ) && false !== strpos( $hook, self::PAGE_SLUG ) );
                if ( ! $on_page ) {
                        return;
                }

                wp_enqueue_style( 'kic-admin', KIC_URL . 'admin/css/kic-admin.css', array(), KIC_VERSION );
        }

        /**
         * Render the bulk page (publisher mode).
         *
         * @return void
         */
        public function render_page() {
                require KIC_PATH . 'admin/views/bulk-page.php';
        }

        /**
         * Publisher mode: create posts from READY contents pasted by the
         * author — one article per block, blocks separated by a standalone
         * `---` line. Optional per-block header lines (consumed from the top
         * of the block, each kind at most once, in any order):
         *   `# عنوان`   — main title.
         *   `^ روتیتر`   — kicker (the small headline above the title, v2.2).
         *   `~ زیرتیتر`  — subtitle (the explanatory line below the title, v2.2).
         * Labeled forms `روتیتر: …` / `زیرتیتر: …` also work. The headline set
         * is stored canonically AND mirrored into the active theme's own
         * fields (KIC_Theme_Sync). No AI, no provider, no word counts —
         * content lands exactly as pasted (plain text only gets standard
         * paragraph formatting).
         *
         * @return void
         */
        public function handle_publish() {
                if ( ! current_user_can( 'edit_posts' ) ) {
                        wp_die( esc_html( 'شما اجازه ساخت نوشته را ندارید.' ) );
                }
                check_admin_referer( 'kic_bulk_publish' );

                $raw     = isset( $_POST['kic_bulk_contents'] ) ? (string) wp_unslash( $_POST['kic_bulk_contents'] ) : '';
                $status  = ( isset( $_POST['kic_bulk_status'] ) && 'publish' === (string) wp_unslash( $_POST['kic_bulk_status'] ) ) ? 'publish' : 'draft';
                $use_drip = ! empty( $_POST['kic_bulk_drip'] );
                $cats_raw = isset( $_POST['kic_bulk_cats'] ) ? (string) wp_unslash( $_POST['kic_bulk_cats'] ) : '';
                $cat_id   = absint( trim( $cats_raw ) );

                /* Drip queue needs drafts — a "publish now" status would go live
                 * immediately and make the scheduled slots pointless. */
                if ( $use_drip && class_exists( 'KIC_Drip_Scheduler' ) && method_exists( 'KIC_Drip_Scheduler', 'enabled' ) && KIC_Drip_Scheduler::enabled() ) {
                        $status = 'draft';
                }

                $created = 0;
                $failed  = 0;

                $blocks = preg_split( '/^\s*-{3,}\s*$/m', $raw );
                if ( ! is_array( $blocks ) ) {
                        $blocks = array( $raw );
                }

                foreach ( $blocks as $block ) {
                        $block = trim( (string) $block );
                        if ( '' === $block ) {
                                continue;
                        }

                        $title    = '';
                        $kicker   = '';
                        $subtitle = '';
                        $lines    = preg_split( '/\R/u', $block );
                        if ( ! is_array( $lines ) ) {
                                $lines = array( $block );
                        }

                        /*
                         * v2.2 — header zone: consecutive header lines at the top
                         * of the block are consumed (`# title`, `^ kicker`,
                         * `~ subtitle`, or the labeled روتیتر:/زیرتیتر: forms);
                         * the first non-header line starts the content. Each
                         * kind is consumed at most once so body lines that
                         * merely LOOK like headers are never eaten.
                         */
                        $consumed = 0;
                        foreach ( $lines as $line_index => $raw_line ) {
                                $line    = trim( (string) $raw_line );
                                $matched = false;

                                if ( '' === $title && '' !== $line && '#' === $line[0] ) {
                                        $title   = trim( ltrim( $line, '#' ) );
                                        $matched = true;
                                } elseif ( '' === $kicker && '^' === substr( $line, 0, 1 ) ) {
                                        $kicker  = trim( substr( $line, 1 ) );
                                        $matched = true;
                                } elseif ( '' === $subtitle && '~' === substr( $line, 0, 1 ) ) {
                                        $subtitle = trim( substr( $line, 1 ) );
                                        $matched  = true;
                                } elseif ( '' === $kicker && preg_match( '/^(?:روتیتر|kicker)[\s\x{003A}]+(.+)$/iu', $line, $label_match ) ) {
                                        $kicker  = trim( $label_match[1] );
                                        $matched = true;
                                } elseif ( '' === $subtitle && preg_match( '/^(?:زیرتیتر|subtitle)[\s\x{003A}]+(.+)$/iu', $line, $label_match ) ) {
                                        $subtitle = trim( $label_match[1] );
                                        $matched  = true;
                                }

                                if ( ! $matched ) {
                                        break;
                                }
                                $consumed = $line_index + 1;
                        }
                        if ( $consumed > 0 ) {
                                $lines = array_slice( $lines, $consumed );
                        }
                        $content = trim( implode( "\n", $lines ) );
                        if ( '' === $content ) {
                                $failed++;
                                continue;
                        }
                        if ( false === strpos( $content, '<' ) ) {
                                $content = wpautop( $content );
                        }

                        $post_id = wp_insert_post(
                                array(
                                        'post_title'   => wp_slash( '' !== $title ? $title : sprintf( 'پیش‌نویس Natilus %d', $created + 1 ) ),
                                        'post_content' => wp_slash( $content ),
                                        'post_status'  => $status,
                                        'post_type'    => 'post',
                                        'post_author'  => get_current_user_id(),
                                ),
                                true
                        );

                        if ( is_wp_error( $post_id ) ) {
                                $failed++;
                                continue;
                        }

                        $post_id = (int) $post_id;
                        if ( $cat_id ) {
                                wp_set_object_terms( $post_id, array( $cat_id ), 'category', false );
                        }

                        /* v2.2 — headline set into the theme-synced fields (guarded, never fatal). */
                        if ( class_exists( 'KIC_Theme_Sync' ) && method_exists( 'KIC_Theme_Sync', 'apply_fields' ) ) {
                                KIC_Theme_Sync::apply_fields(
                                        $post_id,
                                        array(
                                                'kicker'   => $kicker,
                                                'subtitle' => $subtitle,
                                        )
                                );
                        }

                        /*
                         * Drip queue (optional): KIC_Drip_Scheduler::schedule()
                         * writes the _kic_drip meta itself AFTER arming the cron
                         * slot — setting the meta beforehand made the scheduler's
                         * idempotence guard bail out, so nothing was ever queued.
                         * Also only queue when the feature is actually enabled.
                         */
                        if ( $use_drip && class_exists( 'KIC_Drip_Scheduler' ) && method_exists( 'KIC_Drip_Scheduler', 'enabled' ) && KIC_Drip_Scheduler::enabled() ) {
                                KIC_Drip_Scheduler::schedule( $post_id );
                        }
                        $created++;
                }

                $redirect = add_query_arg(
                        array(
                                'page'          => self::PAGE_SLUG,
                                'kic_bulk_done' => $created,
                                'kic_bulk_fail' => $failed,
                        ),
                        admin_url( 'admin.php' )
                );
                wp_safe_redirect( $redirect );
                exit;
        }
}
