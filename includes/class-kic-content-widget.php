<?php
/**
 * Natilus — Elementor widget.
 *
 * Kept in its own file and required ONLY from
 * KIC_Builder_Integration::register_elementor_widget() after the Elementor
 * base classes were confirmed to exist, so declaring
 * `final class KIC_Content_Widget extends \Elementor\Widget_Base` at file
 * top level can never fatal on Elementor-less installs. PHP forbids nested
 * named-class declarations inside methods, which is why the class lives here.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( ! class_exists( 'KIC_Content_Widget', false ) ) {

        /**
         * Elementor widget echoing the Natilus-published content of the current post.
         */
        final class KIC_Content_Widget extends \Elementor\Widget_Base {

                /**
                 * @return string
                 */
                public function get_name() {
                        return 'kic_content';
                }

                /**
                 * @return string
                 */
                public function get_title() {
                        return 'محتوای Natilus';
                }

                /**
                 * @return string
                 */
                public function get_icon() {
                        return 'eicon-text';
                }

                /**
                 * @return array
                 */
                public function get_categories() {
                        return array( 'general' );
                }

                /**
                 * @return array
                 */
                public function get_keywords() {
                        return array( 'natilus', 'publisher', 'content' );
                }

                /**
                 * Editor controls (informational only — content comes from post meta).
                 *
                 * @return void
                 */
                protected function register_controls() {
                        $this->start_controls_section(
                                'kic_section',
                                array(
                                        'label' => 'محتوای منتشرشده با Natilus',
                                        'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                                )
                        );

                        $this->add_control(
                                'kic_info',
                                array(
                                        'type' => \Elementor\Controls_Manager::RAW_HTML,
                                        'raw'  => 'این ویجت محتوای منتشرشده با Natilus برای همین برگه/نوشته را نمایش می‌دهد. برای انتشار محتوای آماده، از متاباکس Natilus زیر ویرایشگر استفاده کنید.',
                                )
                        );

                        $this->end_controls_section();
                }

                /**
                 * Front-end output: the stored generated content for this post.
                 *
                 * @return void
                 */
                protected function render() {
                        $post_id = get_the_ID();
                        $content = $post_id ? get_post_meta( $post_id, '_kic_generated_content', true ) : '';

                        if ( ! is_string( $content ) || '' === $content ) {
                                echo '<p>' . esc_html( 'هنوز محتوایی با Natilus منتشر نشده است.' ) . '</p>';
                                return;
                        }

                        echo wp_kses_post( $content );
                }
        }
}
