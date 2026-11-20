<?php
/**
 * Natilus — publish calendar screen.
 *
 * Variables provided by KIC_Queue_Manager::render_page():
 *   $month (Y-m), $queue (items of the month), $paused (bool).
 *
 * The calendar is rendered server-side; kic-calendar.js adds drag & drop
 * (move an item to another day) and the queue operation buttons via AJAX.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Server-rendered admin view: this template is require()d inside a class
 * method (KIC_Queue_Manager::render_page()), so the variables below are
 * method-scoped view data, not global definitions.
 */

$month      = isset( $month ) ? (string) $month : current_time( 'Y-m' );
$queue      = isset( $queue ) && is_array( $queue ) ? $queue : array();
$paused     = ! empty( $paused );

/* Month grid maths (RTL-safe, Saturday-first week like the WP fa calendar). */
$first_day  = strtotime( $month . '-01' );
$days_in_month = (int) gmdate( 't', $first_day );
$today_str  = current_time( 'Y-m-d' );

/* Saturday-first column order: Sat Sun Mon Tue Wed Thu Fri. */
$weekday_map = array( 6 => 0, 0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6 );
$leading_blanks = $weekday_map[ (int) gmdate( 'w', $first_day ) ];

/* Group queue items by day. */
$by_day = array();
foreach ( $queue as $item ) {
        if ( empty( $item['date'] ) ) {
                continue;
        }
        if ( ! isset( $by_day[ $item['date'] ] ) ) {
                $by_day[ $item['date'] ] = array();
        }
        $by_day[ $item['date'] ][] = $item;
}

$prev_month = gmdate( 'Y-m', strtotime( $month . '-01 -1 month' ) );
$next_month = gmdate( 'Y-m', strtotime( $month . '-01 +1 month' ) );

$week_days = array( 'ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج' );

/* English month names for the EN interface. */
$month_fa = array(
        '01' => 'ژانویه', '02' => 'فوریه', '03' => 'مارس', '04' => 'آوریل',
        '05' => 'مه', '06' => 'ژوئن', '07' => 'ژوئیه', '08' => 'اوت',
        '09' => 'سپتامبر', '10' => 'اکتبر', '11' => 'نوامبر', '12' => 'دسامبر',
);
$month_en = array(
        '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
        '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
        '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December',
);

$month_parts = explode( '-', $month );
$month_label = ( 'en' === KIC_I18n::lang() )
        ? ( $month_en[ $month_parts[1] ] . ' ' . $month_parts[0] )
        : ( $month_fa[ $month_parts[1] ] . ' ' . $month_parts[0] );
?>
<div class="wrap kic-calendar-wrap" dir="rtl">
        <h1 class="kic-page-title"><?php echo esc_html( kic_t( 'تقویم انتشار' ) ); ?></h1>

        <div id="kic-cal-root"
                data-month="<?php echo esc_attr( $month ); ?>"
                data-paused="<?php echo $paused ? '1' : '0'; ?>">

                <div class="kic-card kic-cal-toolbar">
                        <span class="kic-cal-title"><?php echo esc_html( $month_label ); ?></span>
                        <span class="kic-header-spacer"></span>
                        <span id="kic-cal-status" class="kic-cal-status <?php echo $paused ? 'is-paused' : ''; ?>">
                                <?php echo esc_html( $paused ? kic_t( 'صف متوقف است.' ) : kic_t( 'صف در حال اجراست.' ) ); ?>
                        </span>
                        <button type="button" id="kic-cal-toggle" class="button <?php echo $paused ? 'button-primary' : ''; ?>">
                                <?php echo esc_html( $paused ? kic_t( 'ادامهٔ صف' ) : kic_t( 'توقف صف' ) ); ?>
                        </button>
                        <button type="button" id="kic-cal-run-next" class="button">
                                <?php echo esc_html( kic_t( 'انتشار آیتم بعدی همین حالا' ) ); ?>
                        </button>
                </div>

                <div class="kic-card kic-cal-nav">
                        <a class="button" href="<?php echo esc_url( add_query_arg( 'month', $prev_month ) ); ?>">‹ <?php echo esc_html( kic_t( 'ماه قبل' ) ); ?></a>
                        <strong><?php echo esc_html( kic_t( 'امروز' ) . ': ' . $today_str ); ?></strong>
                        <a class="button" href="<?php echo esc_url( add_query_arg( 'month', $next_month ) ); ?>"><?php echo esc_html( kic_t( 'ماه بعد' ) ); ?> ›</a>
                </div>

                <div class="kic-card kic-cal-board">
                        <div class="kic-cal-week">
                                <?php foreach ( $week_days as $day_label ) : ?>
                                        <div class="kic-cal-weekday"><?php echo esc_html( $day_label ); ?></div>
                                <?php endforeach; ?>
                        </div>

                        <div class="kic-cal-grid" id="kic-cal-grid">
                                <?php for ( $i = 0; $i < $leading_blanks; $i++ ) : ?>
                                        <div class="kic-cal-day is-blank"></div>
                                <?php endfor; ?>

                                <?php for ( $day = 1; $day <= $days_in_month; $day++ ) : ?>
                                        <?php
                                        $date = $month . '-' . sprintf( '%02d', $day );
                                        $items = isset( $by_day[ $date ] ) ? $by_day[ $date ] : array();
                                        $is_today = ( $today_str === $date );
                                        ?>
                                        <div class="kic-cal-day<?php echo $is_today ? ' is-today' : ''; ?>"
                                                data-date="<?php echo esc_attr( $date ); ?>">
                                                <div class="kic-cal-daynum"><?php echo esc_html( number_format_i18n( $day ) ); ?></div>
                                                <div class="kic-cal-items" data-date="<?php echo esc_attr( $date ); ?>">
                                                        <?php foreach ( $items as $item ) : ?>
                                                                <div class="kic-cal-item"
                                                                        draggable="true"
                                                                        data-post="<?php echo esc_attr( (string) $item['id'] ); ?>"
                                                                        title="<?php echo esc_attr( $item['title'] . ' — ' . $item['time'] ); ?>">
                                                                        <a href="<?php echo esc_url( $item['edit_url'] ); ?>" target="_blank" rel="noopener">
                                                                                <?php echo esc_html( $item['title'] ); ?>
                                                                        </a>
                                                                        <span class="kic-cal-item-time"><?php echo esc_html( $item['time'] ); ?></span>
                                                                        <span class="kic-cal-item-controls">
                                                                                <button type="button" class="kic-cal-move" data-post="<?php echo esc_attr( (string) $item['id'] ); ?>" data-up="1" title="<?php echo esc_attr( kic_t( 'بالا' ) ); ?>">▲</button>
                                                                                <button type="button" class="kic-cal-move" data-post="<?php echo esc_attr( (string) $item['id'] ); ?>" data-up="0" title="<?php echo esc_attr( kic_t( 'پایین' ) ); ?>">▼</button>
                                                                        </span>
                                                                </div>
                                                        <?php endforeach; ?>
                                                </div>
                                        </div>
                                <?php endfor; ?>
                        </div>
                </div>

                <p class="description kic-cal-help">
                        <?php echo esc_html( kic_t( 'برای جابه‌جایی، آیتم را بکشید و روی روز دیگر رها کنید.' ) ); ?>
                        <?php if ( empty( $queue ) ) : ?>
                                <br><?php echo esc_html( kic_t( 'موردی در این ماه در صف نیست.' ) ); ?>
                        <?php endif; ?>
                </p>
        </div>
</div>
