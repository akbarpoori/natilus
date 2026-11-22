<?php
/**
 * Natilus — content extras borrowed from the iran-outdoor reference:
 *  - Jalali date conversion (g2j/j2g) for Persian date display.
 *  - Smart links: non-destructive internal-link injection via the_content
 *    (stored post_content stays clean — links are woven at render time).
 *  - OG card: server-side 1200x630 share image via GD when a TTF font is
 *    configured; falls back to the featured image (already handled).
 *
 * @package ai-content-publishing-agent
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class KIC_Content_Extras {

        /* ------------------------------------------------------------------
         * Jalali (from IOR class-jalali — the standard jdf algorithm).
         * ---------------------------------------------------------------- */

        /**
         * Gregorian → Jalali.
         *
         * @param int $gy Year.
         * @param int $gm Month.
         * @param int $gd Day.
         * @return int[] [jy, jm, jd]
         */
        public static function g2j( $gy, $gm, $gd ) {
                $g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
                $gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
                $days  = 355666 + ( 365 * $gy )
                        + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 )
                        + $gd + $g_d_m[ $gm - 1 ];
                $jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
                $days %= 12053;
                $jy   += 4 * intdiv( $days, 1461 );
                $days %= 1461;
                if ( $days > 365 ) {
                        $jy  += intdiv( $days - 1, 365 );
                        $days = ( $days - 1 ) % 365;
                }
                $jm = ( $days < 186 ) ? 1 + intdiv( $days, 31 ) : 7 + intdiv( $days - 186, 30 );
                $jd = 1 + ( ( $days < 186 ) ? ( $days % 31 ) : ( $days - 186 ) % 30 );
                return array( $jy, $jm, $jd );
        }

        /**
         * Jalali date string for a unix timestamp, e.g. «۱۴۰۳/۰۹/۱۵».
         *
         * @param int|null $ts Timestamp (null = now).
         * @return string
         */
        public static function jalali_date( $ts = null ) {
                $ts = null === $ts ? time() : (int) $ts;
                list( $jy, $jm, $jd ) = self::g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
                return self::fa_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) );
        }

        /**
         * Latin digits → Persian digits.
         *
         * @param string|int $s Input.
         * @return string
         */
        public static function fa_digits( $s ) {
                return strtr( (string) $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
        }

        /* ------------------------------------------------------------------
         * Smart links — render-time internal linking (non-destructive).
         * Borrowed concept from IOR Smart_Links::filter_content: the stored
         * post_content is NEVER modified; links are woven into the rendered
         * HTML, only inside <p> text, first occurrence per target, max 3.
         * ---------------------------------------------------------------- */

        /**
         * the_content filter — injects internal links into Natilus posts
         * when the smart_links setting is on.
         *
         * @param string $content Rendered content.
         * @return string
         */
        public static function inject_smart_links( $content ) {
                if ( ! kic_get_setting( 'smart_links', 0 ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
                        return $content;
                }
                $post_id = get_the_ID();
                if ( ! $post_id ) {
                        return $content;
                }

                /* Candidate targets: published posts/pages with a title long
                 * enough to be a safe link anchor (short titles over-match). */
                $targets = wp_cache_get( 'kic_smart_targets', 'kic' );
                if ( false === $targets ) {
                        $targets = array();
                        foreach ( get_posts(
                                array(
                                        'post_type'      => array( 'post', 'page' ),
                                        'post_status'    => 'publish',
                                        'posts_per_page' => 150,
                                        'orderby'        => 'date',
                                        'order'          => 'DESC',
                                        'no_found_rows'  => true,
                                )
                        ) as $t ) {
                                $title = trim( (string) $t->post_title );
                                if ( mb_strlen( $title ) < 10 || $t->ID === $post_id ) {
                                        continue;
                                }
                                $targets[] = array( 'title' => $title, 'url' => get_permalink( $t->ID ) );
                        }
                        /* Longest titles first — «راهنمای خرید اسپرسو» wins over «راهنمای خرید». */
                        usort( $targets, function ( $a, $b ) { return mb_strlen( $b['title'] ) - mb_strlen( $a['title'] ); } );
                        wp_cache_set( 'kic_smart_targets', $targets, 'kic', 300 );
                }

                if ( empty( $targets ) ) {
                        return $content;
                }

                $used   = 0;
                $max    = 3;
                $result = preg_replace_callback(
                        '/<p\b[^>]*>(.*?)<\/p>/is',
                        function ( $m ) use ( $targets, &$used, $max ) {
                                $inner = $m[1];
                                foreach ( $targets as $t ) {
                                        if ( $used >= $max ) {
                                                break;
                                        }
                                        /* Skip when already linked or inside a heading elsewhere. */
                                        if ( false === mb_strpos( $inner, $t['title'] ) || false !== strpos( $inner, 'href="' . $t['url'] . '"' ) ) {
                                                continue;
                                        }
                                        $inner = preg_replace(
                                                '/' . preg_quote( $t['title'], '/' ) . '/u',
                                                '<a href="' . esc_url( $t['url'] ) . '">' . $t['title'] . '</a>',
                                                $inner,
                                                1
                                        );
                                        $used++;
                                }
                                return '<p' . substr( $m[0], 2, strpos( $m[0], '>' ) - 1 ) . $inner . '</p>';
                        },
                        $content
                );

                return is_string( $result ) ? $result : $content;
        }

        /* ------------------------------------------------------------------
         * OG card — 1200x630 share image generated with GD.
         * Needs a TTF font that supports Persian (setting: og_font_path).
         * Without it we simply do nothing — the featured-image OG fallback
         * already covers sharing.
         * ---------------------------------------------------------------- */

        /**
         * Build a share-card PNG for a post. Returns ['file','url'] or null.
         *
         * @param int    $post_id Post id.
         * @param string $title   Card headline.
         * @return array|null
         */
        public static function og_card( $post_id, $title ) {
                $font = trim( (string) kic_get_setting( 'og_font_path', '' ) );
                if ( '' === $font || ! file_exists( $font ) || ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
                        return null;
                }
                $im = imagecreatetruecolor( 1200, 630 );
                if ( ! $im ) {
                        return null;
                }
                $bg   = imagecolorallocate( $im, 18, 24, 38 );
                $acc  = imagecolorallocate( $im, 0, 138, 118 );
                $text = imagecolorallocate( $im, 255, 255, 255 );
                imagefilledrectangle( $im, 0, 0, 1200, 630, $bg );
                imagefilledrectangle( $im, 0, 570, 1200, 630, $acc );

                $site  = trim( (string) get_bloginfo( 'name' ) );
                $title = trim( (string) $title );
                $title = '' !== $title ? $title : (string) get_the_title( $post_id );

                /* Word-wrap the title for the canvas (≈34 chars/line, max 4). */
                $lines = array();
                $buf   = '';
                foreach ( preg_split( '/\s+/u', $title ) as $w ) {
                        if ( function_exists( 'mb_strlen' ) && mb_strlen( $buf . ' ' . $w ) > 34 && '' !== $buf ) {
                                $lines[] = trim( $buf );
                                $buf     = $w;
                        } else {
                                $buf .= ' ' . $w;
                        }
                        if ( count( $lines ) >= 4 ) {
                                break;
                        }
                }
                $lines[] = trim( $buf );
                $lines   = array_slice( array_filter( $lines ), 0, 4 );

                $y = 180;
                foreach ( $lines as $line ) {
                        $box = imagettfbbox( 44, 0, $font, $line );
                        $w   = is_array( $box ) ? abs( $box[2] - $box[0] ) : 0;
                        imagettftext( $im, 44, 0, max( 60, 1140 - $w ), $y, $text, $font, $line );
                        $y += 72;
                }
                if ( '' !== $site ) {
                        imagettftext( $im, 26, 0, 60, 610, $text, $font, $site );
                }

                $upload = wp_upload_dir();
                $file   = trailingslashit( $upload['path'] ) . 'kic-og-' . $post_id . '.png';
                if ( ! imagepng( $im, $file ) ) {
                        imagedestroy( $im );
                        return null;
                }
                imagedestroy( $im );
                return array( 'file' => $file, 'url' => trailingslashit( $upload['url'] ) . 'kic-og-' . $post_id . '.png' );
        }

        /**
         * Publish-time hook (called from the ajax pipeline): when og_card
         * is feasible, generate the card and store it as the OG image.
         *
         * @param int    $post_id Post id.
         * @param string $title   Post title.
         * @return void
         */
        public static function maybe_og_card( $post_id, $title ) {
                if ( ! kic_get_setting( 'og_card', 0 ) ) {
                        return;
                }
                $card = self::og_card( $post_id, $title );
                if ( is_array( $card ) && ! empty( $card['url'] ) ) {
                        update_post_meta( $post_id, '_kic_og_image', esc_url_raw( $card['url'] ) );
                }
        }
}

add_filter( 'the_content', array( 'KIC_Content_Extras', 'inject_smart_links' ), 20 );
