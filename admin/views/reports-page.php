<?php
/**
 * Natilus — reports & health screen.
 *
 * Variables provided by KIC_Reports::render_page():
 *   $opportunities (link report rows), $chain_events (failover log),
 *   $weekly (summary numbers), $weekly_text (email preview),
 *   $weekly_enabled, $spike_enabled.
 *
 * Read-only screen: diagnostics + the weekly summary preview. Fixes happen
 * in the normal editor / compose widget, never here.
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
 * method (KIC_Reports::render_page()), so the variables below are
 * method-scoped view data, not global definitions.
 */

$opportunities  = isset( $opportunities ) && is_array( $opportunities ) ? $opportunities : array();
$chain_events   = isset( $chain_events ) && is_array( $chain_events ) ? $chain_events : array();
$weekly         = isset( $weekly ) && is_array( $weekly ) ? $weekly : array();
$weekly_text    = isset( $weekly_text ) ? (string) $weekly_text : '';
$weekly_enabled = ! empty( $weekly_enabled );
$spike_enabled  = ! empty( $spike_enabled );

$weekly_usage   = isset( $weekly['usage'] ) && is_array( $weekly['usage'] ) ? $weekly['usage'] : array();
$weekly_posts   = isset( $weekly['posts'] ) && is_array( $weekly['posts'] ) ? $weekly['posts'] : array();
$weekly_failures = isset( $weekly['failures'] ) ? (int) $weekly['failures'] : 0;
?>
<div class="wrap kic-reports-wrap" dir="rtl">
        <h1 class="kic-page-title"><?php echo esc_html( kic_t( 'گزارش‌ها و سلامت' ) ); ?></h1>

        <div class="kic-card kic-reports-status">
                <span><?php echo esc_html( kic_t( 'خلاصهٔ هفتگی ایمیلی' ) ); ?>:
                        <strong><?php echo $weekly_enabled ? esc_html__( 'فعال', 'ai-content-publishing-agent' ) : esc_html__( 'غیرفعال', 'ai-content-publishing-agent' ); ?></strong>
                </span>
                <span><?php echo esc_html( kic_t( 'هشدار مصرف غیرعادی' ) ); ?>:
                        <strong><?php echo $spike_enabled ? esc_html__( 'فعال', 'ai-content-publishing-agent' ) : esc_html__( 'غیرفعال', 'ai-content-publishing-agent' ); ?></strong>
                </span>
                <span class="kic-muted"><?php echo esc_html( kic_t( 'وضعیت' ) ); ?>: <?php echo esc_html( kic_t( 'این هفته' ) . ' — ' . number_format_i18n( isset( $weekly_usage['requests'] ) ? $weekly_usage['requests'] : 0 ) . ' request / ' . number_format_i18n( isset( $weekly_usage['total_tokens'] ) ? $weekly_usage['total_tokens'] : 0 ) . ' tokens' ); ?></span>
        </div>

        <!-- Link opportunity report ------------------------------------------->
        <section class="kic-card">
                <h2 class="kic-section-title"><?php echo esc_html( kic_t( 'گزارش فرصت‌های لینک داخلی' ) ); ?></h2>
                <p class="kic-muted"><?php echo esc_html( kic_t( 'مقالات بدون لینک داخلی' ) ); ?> — <?php echo esc_html( number_format_i18n( count( $opportunities ) ) ); ?></p>

                <?php if ( empty( $opportunities ) ) : ?>
                        <p class="kic-notice kic-notice--ok"><?php echo esc_html( kic_t( 'بررسی‌های فنی همه سبزند.' ) ); ?></p>
                <?php else : ?>
                        <table class="kic-table widefat" dir="rtl">
                                <thead>
                                        <tr>
                                                <th><?php echo esc_html( kic_t( 'عنوان' ) ); ?></th>
                                                <th><?php echo esc_html( kic_t( 'تاریخ' ) ); ?></th>
                                                <th></th>
                                        </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $opportunities as $row ) : ?>
                                        <tr>
                                                <td>
                                                        <?php if ( ! empty( $row['edit_url'] ) ) : ?>
                                                                <a href="<?php echo esc_url( $row['edit_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
                                                        <?php else : ?>
                                                                <?php echo esc_html( $row['title'] ); ?>
                                                        <?php endif; ?>
                                                </td>
                                                <td dir="ltr"><?php echo esc_html( substr( (string) $row['date'], 0, 16 ) ); ?></td>
                                                <td class="kic-muted"><?php echo esc_html( kic_t( 'روی این مقاله لینک داخلی ندارد.' ) ); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                                </tbody>
                        </table>
                        <p class="kic-muted">
                                <?php
                                echo esc_html( 'برای گرفتن پیشنهاد لینک داخلی برای هر مقاله، آن را در ویرایشگر باز کنید و از بخش «پیشنهاد لینک داخلی» در تب بررسی پیش از انتشار استفاده کنید.' );
                                ?>
                        </p>
                <?php endif; ?>
        </section>

        <!-- Chain health history ----------------------------------------------->
        <section class="kic-card">
                <h2 class="kic-section-title"><?php echo esc_html( kic_t( 'تاریخچهٔ سلامت زنجیره' ) ); ?></h2>

                <?php if ( empty( $chain_events ) ) : ?>
                        <p class="kic-notice"><?php echo esc_html( kic_t( 'رویدادی ثبت نشده است.' ) ); ?></p>
                <?php else : ?>
                        <table class="kic-table widefat" dir="rtl">
                                <thead>
                                        <tr>
                                                <th><?php echo esc_html( kic_t( 'تاریخ' ) ); ?></th>
                                                <th><?php echo esc_html( kic_t( 'وضعیت' ) ); ?></th>
                                                <th>Model / Provider</th>
                                                <th><?php echo esc_html( kic_t( 'هشدار' ) ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $chain_events as $event ) : ?>
                                        <?php
                                        $event = is_array( $event ) ? $event : array();
                                        $is_healed = 'healed' === ( isset( $event['action'] ) ? (string) $event['action'] : '' );
                                        $count = isset( $event['count'] ) ? (int) $event['count'] : 1;
                                        ?>
                                        <tr>
                                                <td dir="ltr"><?php echo esc_html( date_i18n( 'Y-m-d H:i', isset( $event['ts'] ) ? (int) $event['ts'] : 0 ) ); ?></td>
                                                <td>
                                                        <span class="kic-chain-flag kic-chain-flag--<?php echo $is_healed ? 'healed' : 'failed'; ?>">
                                                                <?php echo $is_healed ? esc_html__( 'ترمیم شد', 'ai-content-publishing-agent' ) : esc_html__( 'شکست', 'ai-content-publishing-agent' ); ?>
                                                                <?php if ( $count > 1 ) : ?>
                                                                        ×<?php echo esc_html( number_format_i18n( $count ) ); ?>
                                                                <?php endif; ?>
                                                        </span>
                                                        <span class="kic-muted">
                                                                <?php echo esc_html( 'provider' === ( isset( $event['kind'] ) ? (string) $event['kind'] : '' ) ? 'provider-wide' : 'model-level' ); ?>
                                                        </span>
                                                </td>
                                                <td dir="ltr">
                                                        <?php echo esc_html( ( isset( $event['provider'] ) ? (string) $event['provider'] : '' ) . ' @ ' . ( isset( $event['model'] ) ? (string) $event['model'] : '—' ) ); ?>
                                                </td>
                                                <td class="kic-muted"><?php echo esc_html( isset( $event['note'] ) ? (string) $event['note'] : '' ); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                                </tbody>
                        </table>
                <?php endif; ?>
        </section>

        <!-- Weekly summary preview --------------------------------------------->
        <section class="kic-card">
                <h2 class="kic-section-title"><?php echo esc_html( kic_t( 'خلاصهٔ هفتگی ایمیلی' ) ); ?></h2>

                <?php if ( ! empty( $weekly_posts ) ) : ?>
                        <p class="kic-muted">
                                <?php
                                echo esc_html(
                                        kic_t( 'منتشرشده' ) . ' (' . number_format_i18n( count( $weekly_posts ) ) . ')' . ' — '
                                        . number_format_i18n( isset( $weekly_usage['total_tokens'] ) ? $weekly_usage['total_tokens'] : 0 ) . ' tokens / $'
                                        . number_format( (float) ( isset( $weekly_usage['cost'] ) ? $weekly_usage['cost'] : 0 ), 2 )
                                );
                                ?>
                        </p>
                <?php endif; ?>

                <pre class="kic-weekly-preview" dir="ltr"><?php echo esc_html( $weekly_text ); ?></pre>
                <p class="kic-muted">
                        <?php echo esc_html( 'این همان متایی است که هر هفته به ایمیل مدیر سایت ارسال می‌شود.' ); ?>
                </p>
        </section>
</div>
