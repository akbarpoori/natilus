<?php
/**
 * Consumption dashboard view — server-rendered, no JavaScript.
 *
 * Provided by KIC_Token_Tracker::render_page():
 *
 * @var array $stats Aggregates from KIC_Token_Tracker::get_stats().
 * @var int   $days  Selected range (7|30|90).
 *
 * Reuses the shared .kic-card / .kic-badge / .kic-table classes from
 * kic-admin.css plus a small scoped <style> block for the dashboard-only
 * .kic-dash-* layout pieces (stat grid, bar chart, side-by-side tables).
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
 * method (KIC_Token_Tracker::render_page()), so the variables below are
 * method-scoped view data, not global definitions.
 */

$days  = isset( $days ) ? (int) $days : 30;
$stats = isset( $stats ) && is_array( $stats ) ? $stats : array();

$totals    = isset( $stats['totals'] ) && is_array( $stats['totals'] ) ? $stats['totals'] : array();
$by_day    = isset( $stats['by_day'] ) && is_array( $stats['by_day'] ) ? $stats['by_day'] : array();
$by_user   = isset( $stats['by_user'] ) && is_array( $stats['by_user'] ) ? $stats['by_user'] : array();
$by_action = isset( $stats['by_action'] ) && is_array( $stats['by_action'] ) ? $stats['by_action'] : array();
$recent    = isset( $stats['recent'] ) && is_array( $stats['recent'] ) ? $stats['recent'] : array();

$total_requests   = isset( $totals['requests'] ) ? (int) $totals['requests'] : 0;
$total_prompt     = isset( $totals['prompt_tokens'] ) ? (int) $totals['prompt_tokens'] : 0;
$total_completion = isset( $totals['completion_tokens'] ) ? (int) $totals['completion_tokens'] : 0;
$total_tokens     = isset( $totals['total_tokens'] ) ? (int) $totals['total_tokens'] : 0;
$today_tokens     = isset( $totals['today_tokens'] ) ? (int) $totals['today_tokens'] : 0;
$total_cost       = isset( $totals['cost_estimate'] ) ? (float) $totals['cost_estimate'] : 0.0;

// Locale-aware number formatting with Persian numerals.
$kic_fa_map = array(
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
);
$kic_fmt = function ( $number, $decimals = 0 ) use ( $kic_fa_map ) {
        return strtr( number_format_i18n( $number, $decimals ), $kic_fa_map );
};

// Bar chart scale.
$max_tokens = 0;
foreach ( $by_day as $day_row ) {
        $day_tokens = isset( $day_row['total_tokens'] ) ? (int) $day_row['total_tokens'] : 0;
        if ( $day_tokens > $max_tokens ) {
                $max_tokens = $day_tokens;
        }
}
$day_count  = count( $by_day );
$label_step = $day_count > 0 ? max( 1, (int) ceil( $day_count / 6 ) ) : 1;

$kic_ranges = array(
        7  => '۷ روز',
        30 => '۳۰ روز',
        90 => '۹۰ روز',
);

/* v2.1 — the actions that can actually occur in a pure publisher:
 * optional suggestions + connection test. Legacy generation-era
 * rows (compose/stream/image/…) may still exist in old usage rows. */
$kic_action_labels = array(
        'suggest_terms'  => 'پیشنهاد دسته/برچسب',
        'suggest_slug'   => 'پیشنهاد نامک',
        'suggest_links'  => 'پیشنهاد لینک داخلی',
        'test'           => 'تست اتصال',
        'compose'        => 'تولید محتوا (قدیمی)',
        'stream'         => 'تولید جریانی (قدیمی)',
        'image'          => 'تولید تصویر (قدیمی)',
        'regenerate'     => 'بازتولید (قدیمی)',
        'translate'      => 'ترجمه (قدیمی)',
        'bulk'           => 'تولید گروهی (قدیمی)',
        'woo'            => 'ووکامرس (قدیمی)',
);
?>
<div class="wrap kic-dash" dir="rtl">

<style type="text/css">
/* Scoped to the consumption dashboard. */
.kic-dash-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 16px; }
.kic-dash-title { margin: 0; font-size: 22px; line-height: 1.4; }
.kic-dash-range { display: flex; flex-wrap: wrap; gap: 8px; }
.kic-dash .kic-badge { display: inline-block; padding: 4px 14px; border-radius: 999px; border: 1px solid #dcdcde; background: #fff; color: #2c3338; text-decoration: none; }
.kic-dash .kic-badge:hover { border-color: #0f766e; color: #0f766e; }
.kic-dash .kic-badge.kic-dash-pill-active { background: #0f766e; border-color: #0f766e; color: #fff; }
.kic-dash-cards { display: grid; grid-template-columns: repeat( auto-fit, minmax( 180px, 1fr ) ); gap: 12px; margin: 0 0 16px; }
.kic-dash-card { display: flex; flex-direction: column; gap: 6px; padding: 16px; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
.kic-dash-card-label { font-size: 12px; opacity: .75; }
.kic-dash-card-value { font-size: 24px; font-weight: 700; color: #0f766e; line-height: 1.3; }
.kic-dash-card-note { font-size: 11px; opacity: .7; }
.kic-dash-chart { padding: 16px; margin: 0 0 16px; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
.kic-dash-chart-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin: 0 0 12px; }
.kic-dash-chart-head strong { font-size: 14px; }
.kic-dash-chart-max { font-size: 12px; opacity: .75; }
.kic-dash-bars { display: flex; align-items: flex-end; gap: 4px; direction: ltr; }
.kic-dash-col { flex: 1 1 0; min-width: 0; }
.kic-dash-track { height: 180px; display: flex; align-items: flex-end; }
.kic-dash-bar { width: 100%; min-height: 3px; border-radius: 3px 3px 0 0; background: linear-gradient( 180deg, #2dd4bf, #0f766e ); }
.kic-dash-lbl { display: block; font-size: 9px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; margin-top: 4px; opacity: .65; }
.kic-dash-lbl-hidden { visibility: hidden; }
.kic-dash-cols { display: flex; flex-wrap: wrap; gap: 16px; margin: 0 0 16px; }
.kic-dash-colbox { flex: 1 1 320px; min-width: 0; padding: 16px; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
.kic-dash-h2 { font-size: 14px; margin: 0 0 10px; }
.kic-dash .kic-table { width: 100%; border-collapse: collapse; }
.kic-dash .kic-table th, .kic-dash .kic-table td { padding: 8px 10px; text-align: right; border-bottom: 1px solid #f0f0f1; font-size: 12.5px; }
.kic-dash .kic-table th { font-weight: 700; }
.kic-dash .kic-table tbody tr:nth-child( even ) { background: #fafbfc; }
.kic-dash-empty { text-align: center; opacity: .6; padding: 14px 8px; }
.kic-dash-recent { padding: 16px; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
.kic-dash-footer { font-size: 11px; opacity: .6; margin: 10px 0 0; }
</style>

        <div class="kic-dash-head">
                <h1 class="kic-dash-title"><?php esc_html_e( 'داشبورد مصرف پیشنهادهای اختیاری (Natilus)', 'ai-content-publishing-agent' ); ?></h1>
                <div class="kic-dash-range">
                        <?php foreach ( $kic_ranges as $range_days => $range_label ) : ?>
                                <a class="kic-badge<?php echo ( (int) $range_days === $days ) ? ' kic-dash-pill-active' : ''; ?>"
                                        href="<?php echo esc_url( add_query_arg( array( 'page' => 'kic-dashboard', 'days' => $range_days ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $range_label ); ?></a>
                        <?php endforeach; ?>
                </div>
        </div>

        <div class="kic-dash-cards">
                <div class="kic-card kic-dash-card">
                        <span class="kic-dash-card-label"><?php esc_html_e( 'کل درخواست‌ها', 'ai-content-publishing-agent' ); ?></span>
                        <span class="kic-dash-card-value"><?php echo esc_html( $kic_fmt( $total_requests ) ); ?></span>
                        <span class="kic-dash-card-note"><?php echo esc_html( sprintf( '%s: %s %s', 'بازه', $kic_fmt( $days ), 'روز' ) ); ?></span>
                </div>
                <div class="kic-card kic-dash-card">
                        <span class="kic-dash-card-label"><?php esc_html_e( 'توکن ورودی', 'ai-content-publishing-agent' ); ?></span>
                        <span class="kic-dash-card-value"><?php echo esc_html( $kic_fmt( $total_prompt ) ); ?></span>
                </div>
                <div class="kic-card kic-dash-card">
                        <span class="kic-dash-card-label"><?php esc_html_e( 'توکن خروجی', 'ai-content-publishing-agent' ); ?></span>
                        <span class="kic-dash-card-value"><?php echo esc_html( $kic_fmt( $total_completion ) ); ?></span>
                </div>
                <div class="kic-card kic-dash-card">
                        <span class="kic-dash-card-label"><?php esc_html_e( 'مجموع توکن', 'ai-content-publishing-agent' ); ?></span>
                        <span class="kic-dash-card-value"><?php echo esc_html( $kic_fmt( $total_tokens ) ); ?></span>
                        <span class="kic-dash-card-note"><?php echo esc_html( sprintf( '%s: %s %s', 'امروز', $kic_fmt( $today_tokens ), 'توکن' ) ); ?></span>
                </div>
                <div class="kic-card kic-dash-card">
                        <span class="kic-dash-card-label"><?php esc_html_e( 'هزینه تخمینی', 'ai-content-publishing-agent' ); ?></span>
                        <span class="kic-dash-card-value"><?php echo esc_html( $kic_fmt( $total_cost, 4 ) ); ?></span>
                        <span class="kic-dash-card-note"><?php esc_html_e( 'تخمینی · واحد دلار', 'ai-content-publishing-agent' ); ?></span>
                </div>
        </div>

        <div class="kic-card kic-dash-chart">
                <div class="kic-dash-chart-head">
                        <strong><?php esc_html_e( 'مصرف روزانه توکن', 'ai-content-publishing-agent' ); ?></strong>
                        <span class="kic-dash-chart-max"><?php echo esc_html( sprintf( '%s: %s %s', 'بیشترین روزانه', $kic_fmt( $max_tokens ), 'توکن' ) ); ?></span>
                </div>
                <?php if ( empty( $by_day ) ) : ?>
                        <p class="kic-dash-empty"><?php esc_html_e( 'داده‌ای برای نمایش وجود ندارد.', 'ai-content-publishing-agent' ); ?></p>
                <?php else : ?>
                        <div class="kic-dash-bars">
                                <?php foreach ( $by_day as $index => $day_row ) : ?>
                                        <?php
                                        $day_date   = isset( $day_row['date'] ) ? (string) $day_row['date'] : '';
                                        $day_tokens = isset( $day_row['total_tokens'] ) ? (int) $day_row['total_tokens'] : 0;
                                        $percent    = $max_tokens > 0 ? (int) round( ( $day_tokens / $max_tokens ) * 100 ) : 0;
                                        if ( $percent < 2 ) {
                                                $percent = 2;
                                        }
                                        $show_label = ( 0 === ( $index % $label_step ) ) || ( $index === ( $day_count - 1 ) );
                                        $bar_title  = sprintf( 'تاریخ: %s — %s %s', $day_date, $kic_fmt( $day_tokens ), 'توکن' );
                                        ?>
                                        <div class="kic-dash-col">
                                                <div class="kic-dash-track">
                                                        <div class="kic-dash-bar" style="height: <?php echo esc_attr( $percent ); ?>%;" title="<?php echo esc_attr( $bar_title ); ?>"></div>
                                                </div>
                                                <span class="kic-dash-lbl<?php echo $show_label ? '' : ' kic-dash-lbl-hidden'; ?>"><?php echo esc_html( $day_date ); ?></span>
                                        </div>
                                <?php endforeach; ?>
                        </div>
                <?php endif; ?>
        </div>

        <div class="kic-dash-cols">
                <div class="kic-card kic-dash-colbox">
                        <h2 class="kic-dash-h2"><?php esc_html_e( 'بر اساس کاربر', 'ai-content-publishing-agent' ); ?></h2>
                        <table class="kic-table kic-dash-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'کاربر', 'ai-content-publishing-agent' ); ?></th>
                                                <th><?php esc_html_e( 'درخواست‌ها', 'ai-content-publishing-agent' ); ?></th>
                                                <th><?php esc_html_e( 'مجموع توکن', 'ai-content-publishing-agent' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php if ( empty( $by_user ) ) : ?>
                                                <tr><td colspan="3" class="kic-dash-empty"><?php esc_html_e( 'داده‌ای موجود نیست.', 'ai-content-publishing-agent' ); ?></td></tr>
                                        <?php else : ?>
                                                <?php foreach ( $by_user as $user_row ) : ?>
                                                        <?php $user_row = is_array( $user_row ) ? $user_row : array(); ?>
                                                        <tr>
                                                                <td><?php echo esc_html( isset( $user_row['display_name'] ) && '' !== (string) $user_row['display_name'] ? (string) $user_row['display_name'] : '—' ); ?></td>
                                                                <td><?php echo esc_html( $kic_fmt( isset( $user_row['requests'] ) ? (int) $user_row['requests'] : 0 ) ); ?></td>
                                                                <td><?php echo esc_html( $kic_fmt( isset( $user_row['total_tokens'] ) ? (int) $user_row['total_tokens'] : 0 ) ); ?></td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        <?php endif; ?>
                                </tbody>
                        </table>
                </div>

                <div class="kic-card kic-dash-colbox">
                        <h2 class="kic-dash-h2"><?php esc_html_e( 'بر اساس عملیات', 'ai-content-publishing-agent' ); ?></h2>
                        <table class="kic-table kic-dash-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'عملیات', 'ai-content-publishing-agent' ); ?></th>
                                                <th><?php esc_html_e( 'درخواست‌ها', 'ai-content-publishing-agent' ); ?></th>
                                                <th><?php esc_html_e( 'مجموع توکن', 'ai-content-publishing-agent' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php if ( empty( $by_action ) ) : ?>
                                                <tr><td colspan="3" class="kic-dash-empty"><?php esc_html_e( 'داده‌ای موجود نیست.', 'ai-content-publishing-agent' ); ?></td></tr>
                                        <?php else : ?>
                                                <?php foreach ( $by_action as $action_row ) : ?>
                                                        <?php
                                                        $action_row  = is_array( $action_row ) ? $action_row : array();
                                                        $action_slug = isset( $action_row['action'] ) ? (string) $action_row['action'] : '';
                                                        $action_label = isset( $kic_action_labels[ $action_slug ] ) ? $kic_action_labels[ $action_slug ] : $action_slug;
                                                        ?>
                                                        <tr>
                                                                <td><span title="<?php echo esc_attr( $action_slug ); ?>"><?php echo esc_html( '' !== $action_label ? $action_label : '—' ); ?></span></td>
                                                                <td><?php echo esc_html( $kic_fmt( isset( $action_row['requests'] ) ? (int) $action_row['requests'] : 0 ) ); ?></td>
                                                                <td><?php echo esc_html( $kic_fmt( isset( $action_row['total_tokens'] ) ? (int) $action_row['total_tokens'] : 0 ) ); ?></td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        <?php endif; ?>
                                </tbody>
                        </table>
                </div>
        </div>

        <div class="kic-card kic-dash-recent">
                <h2 class="kic-dash-h2"><?php esc_html_e( 'آخرین درخواست‌ها', 'ai-content-publishing-agent' ); ?></h2>
                <table class="kic-table kic-dash-table">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'زمان', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'کاربر', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'عملیات', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'مدل@ارائه‌دهنده', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'ورودی', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'خروجی', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'مجموع', 'ai-content-publishing-agent' ); ?></th>
                                        <th><?php esc_html_e( 'هزینه', 'ai-content-publishing-agent' ); ?></th>
                                </tr>
                        </thead>
                        <tbody>
                                <?php if ( empty( $recent ) ) : ?>
                                        <tr><td colspan="8" class="kic-dash-empty"><?php esc_html_e( 'هنوز درخواستی ثبت نشده است.', 'ai-content-publishing-agent' ); ?></td></tr>
                                <?php else : ?>
                                        <?php foreach ( $recent as $log_row ) : ?>
                                                <?php
                                                $log_row = is_array( $log_row ) ? $log_row : array();

                                                $time_label = '';
                                                if ( ! empty( $log_row['human'] ) ) {
                                                        $time_label = strtr( sprintf( '%s %s', (string) $log_row['human'], 'پیش' ), $kic_fa_map );
                                                } elseif ( ! empty( $log_row['created_at'] ) && '0000-00-00' !== substr( (string) $log_row['created_at'], 0, 10 ) ) {
                                                        $log_ts = strtotime( (string) $log_row['created_at'] );
                                                        if ( false !== $log_ts && $log_ts > 0 ) {
                                                                $time_label = strtr( date_i18n( 'Y/m/d H:i', $log_ts ), $kic_fa_map );
                                                        }
                                                }

                                                $action_slug  = isset( $log_row['action'] ) ? (string) $log_row['action'] : '';
                                                $action_label = isset( $kic_action_labels[ $action_slug ] ) ? $kic_action_labels[ $action_slug ] : $action_slug;

                                                $model_parts = array();
                                                if ( ! empty( $log_row['model'] ) ) {
                                                        $model_parts[] = (string) $log_row['model'];
                                                }
                                                if ( ! empty( $log_row['provider'] ) ) {
                                                        $model_parts[] = (string) $log_row['provider'];
                                                }
                                                $model_label = implode( '@', $model_parts );
                                                if ( '' === $model_label ) {
                                                        $model_label = '—';
                                                }

                                                $user_label = ( isset( $log_row['display_name'] ) && '' !== (string) $log_row['display_name'] )
                                                        ? (string) $log_row['display_name']
                                                        : '—';

                                                $tokens_in  = isset( $log_row['prompt_tokens'] ) ? (int) $log_row['prompt_tokens'] : 0;
                                                $tokens_out = isset( $log_row['completion_tokens'] ) ? (int) $log_row['completion_tokens'] : 0;
                                                $tokens_all = isset( $log_row['total_tokens'] ) ? (int) $log_row['total_tokens'] : 0;
                                                $cost       = isset( $log_row['cost_estimate'] ) ? (float) $log_row['cost_estimate'] : 0.0;
                                                ?>
                                                <tr>
                                                        <td><?php echo esc_html( $time_label ); ?></td>
                                                        <td><?php echo esc_html( $user_label ); ?></td>
                                                        <td><?php echo esc_html( '' !== $action_label ? $action_label : '—' ); ?></td>
                                                        <td><?php echo esc_html( $model_label ); ?></td>
                                                        <td><?php echo esc_html( $kic_fmt( $tokens_in ) ); ?></td>
                                                        <td><?php echo esc_html( $kic_fmt( $tokens_out ) ); ?></td>
                                                        <td><?php echo esc_html( $kic_fmt( $tokens_all ) ); ?></td>
                                                        <td><?php echo esc_html( $kic_fmt( $cost, 4 ) ); ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                <?php endif; ?>
                        </tbody>
                </table>
                <p class="kic-dash-footer"><?php esc_html_e( 'آمار تجمعی تا ۶۰ ثانیه در حافظه نهان ذخیره می‌شود و با ثبت درخواست جدید تازه می‌شود. زمان‌ها به وقت سایت است.', 'ai-content-publishing-agent' ); ?></p>
        </div>

</div>
