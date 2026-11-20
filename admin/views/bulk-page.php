<?php
/**
 * Admin view: bulk PUBLISH of ready content (Persian RTL, publisher mode).
 *
 * v1.4: the author pastes one or more FINISHED articles; Natilus creates
 * the posts exactly as pasted (plain text only gets standard paragraph
 * formatting). No AI, no provider, no word counts.
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
 * method (KIC_Bulk_Generator::render_page()), so the variables below are
 * method-scoped view data, not global definitions.
 */

$kic_done = isset( $_GET['kic_bulk_done'] ) ? absint( $_GET['kic_bulk_done'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$kic_fail = isset( $_GET['kic_bulk_fail'] ) ? absint( $_GET['kic_bulk_fail'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$kic_categories = get_categories(
        array(
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'number'     => 100,
        )
);
?>
<div class="wrap kic-page" id="kic-bulk-page" dir="rtl">

        <h1 class="kic-title">انتشار انبوه محتوای آماده</h1>
        <p class="description">محتوای کامل نوشته‌های آماده را اینجا بچسبانید — Natilus آن‌ها را بدون هیچ بازنویسی، به‌همراه وضعیت و زمان‌بندی انتخابی شما در وردپرس می‌سازد. نه تولید می‌کند، نه تعداد کلمه می‌پرسد و نه ارائه‌دهنده‌ای لازم دارد.</p>

        <?php if ( $kic_done > 0 || $kic_fail > 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                        <?php
                        echo esc_html(
                                sprintf( '%d نوشته ساخته شد.', $kic_done )
                        );
                        if ( $kic_fail > 0 ) {
                                echo ' ' . esc_html( sprintf( '%d بلوک خالی رد شد.', $kic_fail ) );
                        }
                        ?>
                </p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kic_bulk_publish" />
                <?php wp_nonce_field( 'kic_bulk_publish' ); ?>

                <div class="kic-card kic-bulk-card">
                        <h3>چگونه کار می‌کند؟</h3>
                        <ol>
                                <li>هر نوشته را در یک بلوک جدا بگذارید؛ بلوک‌ها با یک خطِ تنهاِ <code dir="ltr">---</code> از هم جدا می‌شوند.</li>
                                <li>در ابتدای هر بلوک، سرتیترها را با این سطرهای سرصفحه بدهید (همه اختیاری‌اند و می‌توانند به هر ترتیب بیایند):
                                        <ul style="margin:6px 14px 0 0;">
                                                <li><code dir="ltr"># عنوان</code> — تیتر اصلی نوشته</li>
                                                <li><code dir="ltr">^ روتیتر</code> — سرتیتر کوچک بالای تیتر (روتیتر)</li>
                                                <li><code dir="ltr">~ زیرتیتر</code> — توضیح کوتاه زیر تیتر (زیرتیتر)</li>
                                        </ul>
                                        شکل برچسب‌دار <code dir="ltr">روتیتر: متن</code> و <code dir="ltr">زیرتیتر: متن</code> هم پذیرفته می‌شود؛ هر سرصفحه فقط یک بار مصرف می‌شود و اولین سطر غیر سرصفحه، شروع محتواست.
                                </li>
                                <li>روتیتر و زیرتیتر در فیلدهای بومی قالب سایت شما ثبت و همگام می‌شوند (اگر قالب فیلدی نداشته باشد، افزونه خودش نمایش می‌دهد).</li>
                                <li>محتوا HTML یا متن ساده است؛ متن ساده فقط پاراگراف‌بندی استاندارد می‌گیرد — هیچ کلمه‌ای عوض نمی‌شود.</li>
                                <li>«ایجاد نوشته‌ها» را بزنید؛ همهٔ بلوک‌ها یک‌جا ساخته می‌شوند.</li>
                        </ol>
                </div>

                <div class="kic-card kic-bulk-card">
                        <h3>محتوای نوشته‌ها</h3>
                        <textarea name="kic_bulk_contents" id="kic-bulk-contents" class="kic-input" rows="16" dir="auto"
                                style="width:100%;font-family:inherit;line-height:1.9;"
                                placeholder="^ روتیتر نوشتهٔ اول<?php echo "\n"; ?># عنوان نوشتهٔ اول<?php echo "\n"; ?>~ زیرتیتر نوشتهٔ اول<?php echo "\n"; ?>محتوای کامل نوشتهٔ اول…<?php echo "\n"; ?>---<?php echo "\n"; ?>^ روتیتر نوشتهٔ دوم<?php echo "\n"; ?># عنوان نوشتهٔ دوم<?php echo "\n"; ?>محتوای کامل نوشتهٔ دوم…"></textarea>

                        <table class="kic-bulk-cols" style="margin-top:12px;">
                                <tbody>
                                        <tr>
                                                <th style="width:140px;text-align:right;padding:6px 8px;">وضعیت نوشته‌ها</th>
                                                <td style="padding:6px 8px;">
                                                        <select name="kic_bulk_status" class="kic-input">
                                                                <option value="draft" selected>پیش‌نویس</option>
                                                                <option value="publish">انتشار فوری</option>
                                                        </select>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th style="width:140px;text-align:right;padding:6px 8px;">دستهٔ مشترک (اختیاری)</th>
                                                <td style="padding:6px 8px;">
                                                        <select name="kic_bulk_cats" class="kic-input">
                                                                <option value="">— بدون دسته —</option>
                                                                <?php if ( is_array( $kic_categories ) ) : ?>
                                                                        <?php foreach ( $kic_categories as $kic_cat ) : ?>
                                                                                <?php if ( $kic_cat instanceof WP_Term ) : ?>
                                                                                        <option value="<?php echo esc_attr( (string) $kic_cat->term_id ); ?>"><?php echo esc_html( $kic_cat->name ); ?></option>
                                                                                <?php endif; ?>
                                                                        <?php endforeach; ?>
                                                                <?php endif; ?>
                                                        </select>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th style="width:140px;text-align:right;padding:6px 8px;">زمان‌بندی صف انتشار</th>
                                                <td style="padding:6px 8px;">
                                                        <label class="kic-check" style="display:flex;align-items:center;gap:6px;">
                                                                <input type="checkbox" name="kic_bulk_drip" value="1" />
                                                                <span>افزودن به صف انتشار نقطه‌چین — نوشته‌ها به‌صورت پیش‌نویس ساخته و در روزها و ساعت‌های تعیین‌شده (تنظیمات ← زمان‌بندی انتشار نقطه‌ای) خودکار منتشر می‌شوند</span>
                                                        </label>
                                                </td>
                                        </tr>
                                </tbody>
                        </table>

                        <p style="margin-top:14px;">
                                <button type="submit" class="button button-primary">ایجاد نوشته‌ها</button>
                        </p>
                </div>

                <p class="description">راهنمای سریع: بلوک‌ها را با خط <code dir="ltr">---</code> جدا کنید؛ سطرهای <code dir="ltr"># عنوان</code> / <code dir="ltr">^ روتیتر</code> / <code dir="ltr">~ زیرتیتر</code> در ابتدای هر بلوک سرتیترهای همان نوشته می‌شوند و با فیلدهای قالب سایت همگام ثبت می‌شوند. حداکثر حجم منطقی برای هر بار ارسال: چند ده نوشته.</p>
        </form>

        <style>
                /* Page-scoped fallback styles (unique .kic-bulk-* selectors, no collisions). */
                #kic-bulk-page .kic-bulk-card { border: 1px solid #dbe5e4; border-radius: 8px; padding: 14px 16px; background: #fff; margin: 14px 0; }
                #kic-bulk-page .kic-bulk-card h3 { margin: 0 0 10px; }
                #kic-bulk-page .kic-bulk-card ol { margin: 0 18px 0 0; }
                #kic-bulk-page .kic-bulk-card li { margin: 4px 0; }
                #kic-bulk-page code { background: #f0f4f4; padding: 1px 6px; border-radius: 4px; direction: ltr; display: inline-block; }
        </style>
</div>
