<?php
/**
 * Natilus — settings screen template.
 *
 * Rendered by KIC_Settings::render_settings_page() which provides
 * $template_vars (settings, has_key, presets, capability). The raw API
 * key is deliberately never handed to templates.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$kic_vars       = isset( $template_vars ) && is_array( $template_vars ) ? $template_vars : array();
$kic_settings   = isset( $kic_vars['settings'] ) && is_array( $kic_vars['settings'] ) ? $kic_vars['settings'] : kic_default_settings();
$kic_presets    = isset( $kic_vars['presets'] ) && is_array( $kic_vars['presets'] ) ? $kic_vars['presets'] : array();
$kic_has_key    = ! empty( $kic_vars['has_key'] );
$kic_capability = isset( $kic_vars['capability'] ) ? (string) $kic_vars['capability'] : 'kubit_ai_compose';

// Detect which preset (if any) matches the stored endpoint + model.
$kic_current_preset = 'custom';
foreach ( $kic_presets as $kic_preset_id => $kic_preset_data ) {
        if ( 'custom' === $kic_preset_id || empty( $kic_preset_data['base_url'] ) ) {
                continue;
        }
        if ( rtrim( (string) $kic_settings['base_url'], '/' ) !== rtrim( (string) $kic_preset_data['base_url'], '/' ) ) {
                continue;
        }
        $kic_preset_model = isset( $kic_preset_data['model'] ) ? trim( (string) $kic_preset_data['model'] ) : '';
        if ( '' === $kic_preset_model || $kic_preset_model === trim( (string) $kic_settings['model'] ) ) {
                $kic_current_preset = $kic_preset_id;
                break;
        }
}

$kic_updated = false;
if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only "saved" confirmation flag on the settings screen
        $kic_updated = ( '1' === sanitize_key( wp_unslash( $_GET['updated'] ) ) );
}

// Live budget snapshot for the routing tab (guarded against a missing module).
$kic_budget_ready = false;
$kic_budget_spend = 0.0;
$kic_budget_cap   = 0.0;
$kic_budget_alert = 80;
if ( class_exists( 'KIC_Budget_Guard' ) && method_exists( 'KIC_Budget_Guard', 'month_spend' ) && method_exists( 'KIC_Budget_Guard', 'budget' ) ) {
        $kic_budget_ready = true;
        $kic_budget_spend = (float) KIC_Budget_Guard::month_spend();
        $kic_budget_cap   = (float) KIC_Budget_Guard::budget();
        if ( method_exists( 'KIC_Budget_Guard', 'alert_pct' ) ) {
                $kic_budget_alert = (int) KIC_Budget_Guard::alert_pct();
        }
}
$kic_budget_over_alert = ( $kic_budget_cap > 0 && $kic_budget_spend >= ( $kic_budget_cap * $kic_budget_alert / 100 ) );
$kic_budget_line       = sprintf(
        'مصرف این ماه: %s دلار (از %s دلار بودجه)',
        number_format( $kic_budget_spend, 2 ),
        number_format( $kic_budget_cap, 2 )
);
$kic_has_failover_key = ! empty( $kic_vars['has_failover_key'] );
$kic_alert_pct_display = min( 99, max( 50, absint( $kic_settings['budget_alert_pct'] ) ) );

/* v1.2 — provider chain + token guide (guarded against missing modules). */
$kic_providers = isset( $kic_vars['providers'] ) && is_array( $kic_vars['providers'] ) ? $kic_vars['providers'] : array();
$kic_token_guide = isset( $kic_vars['token_guide'] ) && is_array( $kic_vars['token_guide'] ) ? $kic_vars['token_guide'] : array();
$kic_token_types = isset( $kic_token_guide['types'] ) && is_array( $kic_token_guide['types'] ) ? $kic_token_guide['types'] : array();
$kic_token_history = isset( $kic_token_guide['history'] ) && is_array( $kic_token_guide['history'] ) ? $kic_token_guide['history'] : array();
$kic_token_budget = isset( $kic_token_guide['budget'] ) && is_array( $kic_token_guide['budget'] ) ? $kic_token_guide['budget'] : array();
$kic_chain_missing = ! class_exists( 'KIC_Provider_Chain' );
$kic_fa_num = function ( $n ) { return number_format( (float) $n ); };
?>
<div class="wrap kic-settings" dir="rtl">

        <h1 class="kic-title">
                Natilus
                <?php if ( $kic_has_key ) : ?>
                        <span class="kic-badge"><?php echo esc_html( 'کلید API ذخیره شده' ); ?></span>
                <?php else : ?>
                        <span class="kic-badge kic-badge-info"><?php echo esc_html( 'بدون ارائه‌دهنده — انتشار کامل کار می‌کند' ); ?></span>
                <?php endif; ?>
        </h1>
        <p class="kic-subtitle"><?php echo esc_html( 'انتشار محتوا به هیچ ارائه‌دهنده‌ای نیاز ندارد — این بخش کاملاً اختیاری است و فقط برای پیشنهادهای اختیاری (دسته، نامک و…) به کار می‌آید.' ); ?></p>

        <?php if ( $kic_updated ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( 'تنظیمات با موفقیت ذخیره شد.' ); ?></p></div>
        <?php endif; ?>

        <div class="kic-tabs" role="tablist">
                <button type="button" class="kic-tab active" data-tab="kic-tab-provider" aria-selected="true"><?php echo esc_html( kic_t( 'اتصال به ارائه‌دهنده' ) ); ?></button>
                <button type="button" class="kic-tab" data-tab="kic-tab-chain" aria-selected="false"><?php echo esc_html( kic_t( 'زنجیرهٔ مدل‌ها و توکن' ) ); ?></button>
                <button type="button" class="kic-tab" data-tab="kic-tab-features" aria-selected="false"><?php echo esc_html( kic_t( 'ویژگی‌ها' ) ); ?></button>
                <button type="button" class="kic-tab" data-tab="kic-tab-bundle" aria-selected="false"><?php echo esc_html( kic_t( 'انتقال تنظیمات' ) ); ?></button>
                <button type="button" class="kic-tab" data-tab="kic-tab-access" aria-selected="false"><?php echo esc_html( kic_t( 'دسترسی‌ها' ) ); ?></button>
                <button type="button" class="kic-tab" data-tab="kic-tab-routing" aria-selected="false"><?php echo esc_html( kic_t( 'مسیریابی، بودجه و زمان‌بندی' ) ); ?></button>
        </div>

        <form method="post" action="" class="kic-card kic-form" id="kic-settings-form">
                <?php wp_nonce_field( 'kic_settings_save', 'kic_settings_nonce' ); ?>

                <section id="kic-tab-provider" class="kic-tab-panel active">

                        <h2 class="kic-section-title">اتصال به ارائه‌دهنده</h2>

                        <div class="kic-field">
                                <label for="kic-preset">ارائه‌دهنده</label>
                                <div class="kic-control">
                                        <select id="kic-preset" name="kic_preset">
                                                <?php foreach ( $kic_presets as $kic_preset_id => $kic_preset_data ) : ?>
                                                        <option value="<?php echo esc_attr( $kic_preset_id ); ?>" <?php selected( $kic_preset_id, $kic_current_preset ); ?>><?php echo esc_html( $kic_preset_data['label'] ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                        <p class="description">با انتخاب هر پیش‌تنظیم، «آدرس پایه» و «مدل چت» به‌صورت خودکار تکمیل می‌شوند.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-base-url">آدرس پایه (Base URL)</label>
                                <div class="kic-control">
                                        <input type="text" id="kic-base-url" name="kic_settings[base_url]" class="kic-input"
                                                dir="ltr" spellcheck="false"
                                                placeholder="https://api.example.com/v1"
                                                value="<?php echo esc_attr( (string) $kic_settings['base_url'] ); ?>" />
                                        <p class="description">نشانی نقطهٔ انتهایی سازگار با OpenAI؛ بدون بخش «/chat/completions». مثال: https://api.openai.com/v1 — برای سرور محلی (Ollama/LM Studio) کلید API می‌تواند خالی باشد.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-api-key">کلید API</label>
                                <div class="kic-control">
                                        <input type="password" id="kic-api-key" name="kic_settings[api_key]" class="kic-input"
                                                dir="ltr" autocomplete="new-password" spellcheck="false"
                                                placeholder="<?php echo esc_attr( $kic_has_key ? 'برای حفظ کلید فعلی خالی بگذارید' : 'sk-...' ); ?>"
                                                value="" />
                                        <p class="description">کلید ارائه‌دهنده؛ به‌صورت رمز‌شده در پایگاه‌داده ذخیره می‌شود و هرگز نمایش داده نمی‌شود.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-model">مدل چت</label>
                                <div class="kic-control">
                                        <input type="text" id="kic-model" name="kic_settings[model]" class="kic-input"
                                                dir="ltr" spellcheck="false"
                                                placeholder="gpt-4o-mini"
                                                value="<?php echo esc_attr( (string) $kic_settings['model'] ); ?>" />
                                        <p class="description">شناسهٔ مدل؛ مثل gpt-4o-mini یا llama-3.3-70b-versatile. برای انتخاب از فهرست، از تب «زنجیرهٔ مدل‌ها» دکمهٔ «بارگذاری مدل‌ها» را بزنید.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-temperature">دمای مدل (Temperature)</label>
                                <div class="kic-control">
                                        <input type="number" id="kic-temperature" name="kic_settings[temperature]" class="kic-input"
                                                dir="ltr" min="0" max="2" step="0.1"
                                                value="<?php echo esc_attr( (string) $kic_settings['temperature'] ); ?>" />
                                        <p class="description">عدد بین ۰ تا ۲؛ مقدار کمتر، خروجی متمرکزتر و دقیق‌تر.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-max-tokens">حداکثر توکن پاسخ</label>
                                <div class="kic-control">
                                        <input type="number" id="kic-max-tokens" name="kic_settings[max_tokens]" class="kic-input"
                                                dir="ltr" min="1" step="1"
                                                value="<?php echo esc_attr( (string) $kic_settings['max_tokens'] ); ?>" />
                                        <p class="description">سقف توکن‌های تولیدشده در هر پاسخ.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-timeout">مهلت زمانی (ثانیه)</label>
                                <div class="kic-control">
                                        <input type="number" id="kic-timeout" name="kic_settings[timeout]" class="kic-input"
                                                dir="ltr" min="10" max="300" step="1"
                                                value="<?php echo esc_attr( (string) $kic_settings['timeout'] ); ?>" />
                                        <p class="description">مهلت هر درخواست به ارائه‌دهنده؛ بین ۱۰ تا ۳۰۰ ثانیه.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-system-extra">دستور سیستم اضافه</label>
                                <div class="kic-control">
                                        <textarea id="kic-system-extra" name="kic_settings[system_extra]" rows="4"
                                                placeholder="مثلاً: همیشه به فارسی روان پاسخ بده."><?php echo esc_textarea( (string) $kic_settings['system_extra'] ); ?></textarea>
                                        <p class="description">دستور سیستم اضافه — به انتهای پرامپت سیستم افزوده می‌شود.</p>
                                </div>
                        </div>

                </section>

                <section id="kic-tab-chain" class="kic-tab-panel">

                        <h2 class="kic-section-title">زنجیرهٔ ارائه‌دهنده‌ها و مدل‌ها</h2>

                        <p class="description">
                                زنجیرهٔ اولویت، ترتیب تلاش‌ها را تعیین می‌کند: ابتدا مدل اولِ ارائه‌دهندهٔ اول، سپس مدل دوم و سوم همان ارائه‌دهنده و بعد ارائه‌دهندهٔ بعدی. اگر مدلی جواب نداد، سیستم بلافاصله روی مدل بعدی می‌رود و در صورت خطای کل ارائه‌دهنده، زنجیره به ارائه‌دهندهٔ بعدی شیفت می‌کند. ارائه‌دهنده‌هایی که اخیراً خطا داده‌اند موقتاً به انتهای صف منتقل می‌شوند و خودشان بازیابی می‌شوند. این زنجیره فقط برای پیشنهادهای اختیاری به کار می‌آید — انتشار محتوا هیچ‌وقت به آن نگاه نمی‌کند.
                        </p>

                        <?php if ( $kic_chain_missing ) : ?>
                                <div class="kic-notice kic-notice-warn"><p>ماژول زنجیرهٔ ارائه‌دهنده‌ها بارگذاری نشده است — بستهٔ ZIP را دوباره جایگزین کنید.</p></div>
                        <?php endif; ?>

                        <div class="kic-card">
                                <h3 class="kic-section-title">ارائه‌دهنده‌های زنجیره</h3>
                                <div id="kic-chain-app" dir="rtl">
                                        <noscript><p class="description">برای مدیریت زنجیره، جاوااسکریپت مرورگر باید فعال باشد. وضعیت فعلی (فقط‌خواندنی):</p></noscript>
                                        <ul class="kic-chain-readonly">
                                                <?php foreach ( $kic_providers as $kic_provider ) : ?>
                                                        <li>
                                                                <strong><?php echo esc_html( sprintf( 'اولویت %d — %s', (int) $kic_provider['priority'], $kic_provider['label'] ) ); ?></strong>
                                                                <code dir="ltr"><?php echo esc_html( (string) $kic_provider['host'] ); ?></code>
                                                                — <?php echo esc_html( implode( ' ← ', (array) $kic_provider['models'] ) ?: 'بدون مدل' ); ?>
                                                                <?php if ( ! empty( $kic_provider['down'] ) ) : ?>
                                                                        <span class="kic-badge kic-badge-warn">به‌تازگی خطا داده</span>
                                                                <?php endif; ?>
                                                        </li>
                                                <?php endforeach; ?>
                                                <?php if ( empty( $kic_providers ) ) : ?>
                                                        <li>هنوز ارائه‌دهنده‌ای در زنجیره ثبت نشده است — با فرم زیر یکی اضافه کنید (برای سرورهای محلی مثل Ollama می‌توان کلید را خالی گذاشت).</li>
                                                <?php endif; ?>
                                        </ul>
                                </div>
                        </div>

                        <div class="kic-card">
                                <h3 class="kic-section-title">حالت صفحه‌ساز پیش‌فرض</h3>
                                <div class="kic-field">
                                        <label for="kic-builder-mode">صفحه‌ساز هدف محتوا</label>
                                        <div class="kic-control">
                                                <select id="kic-builder-mode" name="kic_settings[builder_mode]">
                                                        <option value="auto" <?php selected( 'auto', (string) $kic_settings['builder_mode'] ); ?>>خودکار (بر اساس نوشته)</option>
                                                        <option value="classic" <?php selected( 'classic', (string) $kic_settings['builder_mode'] ); ?>>ویرایشگر کلاسیک وردپرس</option>
                                                        <option value="gutenberg" <?php selected( 'gutenberg', (string) $kic_settings['builder_mode'] ); ?>>ویرایشگر گوتنبرگ</option>
                                                        <option value="elementor" <?php selected( 'elementor', (string) $kic_settings['builder_mode'] ); ?>>المنتور (Elementor)</option>
                                                        <option value="wpbakery" <?php selected( 'wpbakery', (string) $kic_settings['builder_mode'] ); ?>>WPBakery</option>
                                                </select>
                                                <p class="description">وقتی کاربر حالت را روی خودکار بگذارد، بر اساس نوشته تشخیص داده می‌شود؛ در ورک اسپیس هم قابل تغییر است. در حالت المنتور محتوا به‌صورت ساختار بومی المنتور (سکشن/ستون/ویجت) ساخته می‌شود و در حالت WPBakery به‌صورت شورت‌کدهای بومی آن. وقتی ویرایشگر المنتور باز باشد، باکس پرامپت و دستورات همان‌جا کنار پیش‌نمایش زنده در دسترس است.</p>
                                        </div>
                                </div>
                        </div>

                        <div class="kic-card">
                                <h3 class="kic-section-title">راهنمای عملی توکن (فقط پیشنهادهای اختیاری)</h3>

                                <div class="kic-field">
                                        <div class="kic-control">
                                                <label class="kic-check">
                                                        <input type="checkbox" name="kic_settings[auto_extend_tokens]" value="1" <?php checked( ! empty( $kic_settings['auto_extend_tokens'] ) ); ?> />
                                                        <span>افزایش خودکار سقف توکن بر اساس نیاز مطلب (تا وقتی کار تازه نباشد قطع نمی‌شود)</span>
                                                </label>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-auto-continue">ادامهٔ خودکار پس از قطع روی سقف توکن</label>
                                        <div class="kic-control">
                                                <input type="number" id="kic-auto-continue" name="kic_settings[auto_continue_max]" class="kic-input"
                                                        dir="ltr" min="0" max="8" step="1"
                                                        value="<?php echo esc_attr( (string) $kic_settings['auto_continue_max'] ); ?>" />
                                                <p class="description">اگر پاسخ وسط کار روی سقف توکن قطع شود، خودش ادامه می‌دهد و تکه‌ها را می‌چسباند؛ این عدد سقف دفعات ادامه است (۰ = خاموش، بین ۰ تا ۸).</p>
                                        </div>
                                </div>

                                <p class="description">این بخش فقط به پیشنهادهای اختیاری (که ارائه‌دهنده نیاز دارند) مربوط است؛ انتشار محتوا هیچ توکنی مصرف نمی‌کند و هیچ تعداد کلمه‌ای نمی‌پرسد.</p>

                                <?php if ( ! empty( $kic_token_history ) ) : ?>
                                        <h4>مصرف ۳۰ روز گذشته (به تفکیک مدل و عملیات)</h4>
                                        <table class="kic-table widefat" dir="rtl">
                                                <thead>
                                                        <tr><th>عملیات</th><th>مدل</th><th>دفعات</th><th>میانگین توکن</th><th>بیشترین</th></tr>
                                                </thead>
                                                <tbody>
                                                        <?php foreach ( $kic_token_history as $kic_hrow ) : ?>
                                                                <tr>
                                                                        <td><?php echo esc_html( (string) $kic_hrow['action'] ); ?></td>
                                                                        <td dir="ltr"><?php echo esc_html( (string) $kic_hrow['model'] ); ?></td>
                                                                        <td><?php echo esc_html( number_format( (float) $kic_hrow['runs'] ) ); ?></td>
                                                                        <td><?php echo esc_html( number_format( (float) $kic_hrow['avg_total'] ) ); ?></td>
                                                                        <td><?php echo esc_html( number_format( (float) $kic_hrow['max_total'] ) ); ?></td>
                                                                </tr>
                                                        <?php endforeach; ?>
                                                </tbody>
                                        </table>
                                <?php else : ?>
                                        <p class="description">هنوز دادهٔ مصرفی ثبت نشده است؛ پس از اولین تولیدها این جدول پر می‌شود.</p>
                                <?php endif; ?>

                                <?php if ( ! empty( $kic_token_budget['monthly_usd'] ) ) : ?>
                                        <div class="kic-notice">
                                                <p><?php echo esc_html( sprintf( 'مصرف این ماه: %s دلار از %s دلار (%d%%)', number_format( (float) $kic_token_budget['spent_usd'], 2 ), number_format( (float) $kic_token_budget['monthly_usd'], 2 ), (int) $kic_token_budget['pct'] ) ); ?></p>
                                        </div>
                                <?php endif; ?>
                        </div>

                </section>

                <section id="kic-tab-features" class="kic-tab-panel">

                        <h2 class="kic-section-title"><?php echo esc_html( kic_t( 'ویژگی‌ها' ) ); ?></h2>

                        <div class="kic-field">
                                <div class="kic-control">
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[auto_sideload]" value="1" <?php checked( ! empty( $kic_settings['auto_sideload'] ) ); ?> />
                                                <span>وارد کردن خودکار رسانه‌های خارجی به کتابخانه</span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[jsonld]" value="1" <?php checked( ! empty( $kic_settings['jsonld'] ) ); ?> />
                                                <span>اسکیمای Article و FAQ (JSON-LD)</span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[internal_links]" value="1" <?php checked( ! empty( $kic_settings['internal_links'] ) ); ?> />
                                                <span>پیشنهاد لینک داخلی (فقط با کلیک شما درج می‌شود)</span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[draft_default]" value="1" <?php checked( ! empty( $kic_settings['draft_default'] ) ); ?> />
                                                <span>حالت پیش‌فرض: پیش‌نویس جدید</span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[ai_edit_enabled]" value="1" <?php checked( ! empty( $kic_settings['ai_edit_enabled'] ) ); ?> />
                                                <span>ویرایش نوشتهٔ موجود با پرامپت (هوش مصنوعی — فقط با کلیک شما و بعد از پیش‌نمایش اعمال می‌شود)</span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[generate_enabled]" value="1" <?php checked( ! empty( $kic_settings['generate_enabled'] ) ); ?> />
                                                <span>تولید مقاله از پرامپت (هوش مصنوعی — فقط با کلیک شما؛ نتیجه در باکس محتوا قرار می‌گیرد و تا کلیک «انتشار» منتشر نمی‌شود)</span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[related_posts]" value="1" <?php checked( ! empty( $kic_settings['related_posts'] ) ); ?> />
                                                <span>افزودن خودکار بلوک «مطالب مرتبط» به انتهای نوشته (۳ نوشتهٔ هم‌دسته — بدون هوش مصنوعی)</span>
                                        </label>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-image-model">مدل تصویر (اختیاری)</label>
                                <div class="kic-control">
                                        <input type="text" id="kic-image-model" name="kic_settings[image_model]" class="kic-input"
                                                dir="ltr" placeholder="dall-e-3" value="<?php echo esc_attr( (string) ( isset( $kic_settings['image_model'] ) ? $kic_settings['image_model'] : '' ) ); ?>" />
                                        <p class="description">مدلی که برای «تولید تصویر» صدا زده می‌شود — فقط برای ارائه‌دهندگانی که endpoint تصاویر سازگار با OpenAI دارند (مثل dall-e-3 یا gpt-image-1). خالی = استفاده از مدل همان ارائه‌دهنده.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-rate-limit">سقف درخواست در دقیقه (به‌ازای هر کاربر)</label>
                                <div class="kic-control">
                                        <input type="number" id="kic-rate-limit" name="kic_settings[rate_limit]" class="kic-input"
                                                dir="ltr" min="0" max="60" step="1"
                                                value="<?php echo esc_attr( (string) $kic_settings['rate_limit'] ); ?>" />
                                        <p class="description">فراتر از این تعداد، درخواست‌های کاربر موقتاً محدود می‌شوند؛ صفر = بدون محدودیت.</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label for="kic-keep-backups">تعداد نسخه‌های پشتیبان</label>
                                <div class="kic-control">
                                        <input type="number" id="kic-keep-backups" name="kic_settings[keep_backups]" class="kic-input"
                                                dir="ltr" min="1" max="10" step="1"
                                                value="<?php echo esc_attr( (string) $kic_settings['keep_backups'] ); ?>" />
                                        <p class="description">بین ۱ تا ۱۰؛ نسخه‌های قدیمی‌تر خودکار پاک می‌شوند.</p>
                                </div>
                        </div>

                        <?php
                        /* v1.4 — UI language + operational report toggles. */
                        $kic_ui_lang = isset( $kic_settings['ui_lang'] ) ? (string) $kic_settings['ui_lang'] : 'fa';
                        ?>
                        <div class="kic-field">
                                <label for="kic-ui-lang"><?php echo esc_html( kic_t( 'زبان رابط کاربری' ) ); ?></label>
                                <div class="kic-control">
                                        <select id="kic-ui-lang" name="kic_settings[ui_lang]" class="kic-input">
                                                <option value="fa" <?php selected( 'fa', $kic_ui_lang ); ?>><?php echo esc_html( kic_t( 'فارسی' ) ); ?></option>
                                                <option value="en" <?php selected( 'en', $kic_ui_lang ); ?>><?php echo esc_html( kic_t( 'انگلیسی' ) ); ?></option>
                                        </select>
                                        <p class="description">زبان رابط ویجت نگارش و صفحات مدیریتی افزونه — چیدمان فارسی پیش‌فرض است. (Interface language of the compose widget and admin screens.)</p>
                                </div>
                        </div>

                        <div class="kic-field">
                                <label><?php echo esc_html( kic_t( 'شفافیت عملیاتی' ) ); ?></label>
                                <div class="kic-control">
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[weekly_report_enabled]" value="1" <?php checked( ! empty( $kic_settings['weekly_report_enabled'] ) ); ?> />
                                                <span><?php echo esc_html( kic_t( 'خلاصهٔ هفتگی ایمیلی' ) ); ?> — <?php echo esc_html( 'هر هفته به ایمیل مدیر سایت' ); ?></span>
                                        </label>
                                        <label class="kic-check">
                                                <input type="checkbox" name="kic_settings[spike_alert_enabled]" value="1" <?php checked( ! empty( $kic_settings['spike_alert_enabled'] ) ); ?> />
                                                <span><?php echo esc_html( kic_t( 'هشدار مصرف غیرعادی' ) ); ?> — <?php echo esc_html( 'اگر مصرف یک روز چند برابر میانگین ۳۰ روزه شود' ); ?></span>
                                        </label>
                                </div>
                        </div>

                        <?php
                        /* v1.3 — RSS feeds for external/product link suggestions. */
                        $kic_rss_feeds = isset( $kic_settings['rss_feeds'] ) && is_array( $kic_settings['rss_feeds'] )
                                ? $kic_settings['rss_feeds']
                                : array();
                        ?>
                        <div class="kic-field">
                                <label for="kic-rss-feeds">فیدهای RSS برای پیشنهاد لینک خارجی</label>
                                <div class="kic-control">
                                        <textarea id="kic-rss-feeds" name="kic_settings[rss_feeds_text]" class="kic-textarea"
                                                rows="4" dir="ltr" placeholder="https://example.com/feed&#10;https://shop.example.com/products/feed"><?php
                                                echo esc_textarea( implode( "\n", array_map( 'strval', $kic_rss_feeds ) ) );
                                        ?></textarea>
                                        <p class="description">هر خط یک نشانی فید (RSS/Atom) — حداکثر ۱۰ فید. فید فروشگاه‌ها یا سایت‌های همکار را وارد کنید تا هنگام نگارش، پیشنهاد لینک مرتبط از همان فیدها نمایش داده شود. <strong>لینک خارجی فقط با کلیک خود شما در نوشته درج می‌شود؛ هوش مصنوعی به‌تنهایی چیزی اضافه نمی‌کند.</strong></p>
                                </div>
                        </div>

                        <?php
                        /* v2.2 — headline set (روتیتر/زیرتیتر) theme sync. */
                        $kic_ts_data = array(
                                'theme'    => array( 'name' => '', 'slug' => '', 'parent' => '' ),
                                'kicker'   => array(),
                                'subtitle' => array(),
                        );
                        if ( class_exists( 'KIC_Theme_Sync' ) && method_exists( 'KIC_Theme_Sync', 'settings_view_data' ) ) {
                                $kic_ts_data = KIC_Theme_Sync::settings_view_data();
                        }
                        $kic_ts_theme = $kic_ts_data['theme'];
                        $kic_ts_theme_name = ( '' !== $kic_ts_theme['parent'] )
                                ? sprintf( '%s (فرزندِ %s)', $kic_ts_theme['name'], $kic_ts_theme['parent'] )
                                : $kic_ts_theme['name'];

                        $kic_ts_labels = array(
                                'kicker'   => 'روتیتر (سرتیتر کوچک بالای عنوان)',
                                'subtitle' => 'زیرتیتر (توضیح کوتاه زیر عنوان)',
                        );
                        $kic_ts_setting = array();
                        foreach ( array( 'kicker', 'subtitle' ) as $kic_ts_field ) {
                                $kic_ts_select = isset( $kic_settings[ 'theme_sync_' . $kic_ts_field . '_key' ] ) ? (string) $kic_settings[ 'theme_sync_' . $kic_ts_field . '_key' ] : 'auto';
                                $kic_ts_setting[ $kic_ts_field ] = ( '' === $kic_ts_select ) ? 'auto' : $kic_ts_select;
                        }
                        ?>
                        <div class="kic-field">
                                <h3 class="kic-section-title" style="margin-top:18px;">همگام‌سازی روتیتر و زیرتیتر با قالب سایت</h3>
                                <p class="description">
                                        قالب فعال سایت شما: <strong><?php echo esc_html( $kic_ts_theme_name ); ?></strong>.
                                        افزونه فیلدهای روتیتر/زیرتیتر را به‌علاوهٔ عنوان اصلی می‌پذیرد (در ویجت انتشار زیر ویرایشگر و در انتشار انبوه با سطرهای <code dir="ltr"># عنوان</code> / <code dir="ltr">^ روتیتر</code> / <code dir="ltr">~ زیرتیتر</code>)
                                        و علاوه بر ذخیرهٔ استاندارد، آن‌ها را در همان فیلدهایی که قالب و افزونه‌های سایت شما واقعاً استفاده می‌کنند ثبت می‌کند تا دقیقاً مطابق ساختار قالب شما نمایش داده شوند.
                                </p>
                                <?php foreach ( array( 'kicker', 'subtitle' ) as $kic_ts_field ) : ?>
                                        <div class="kic-field" style="margin-top:10px;">
                                                <label for="kic-ts-<?php echo esc_attr( $kic_ts_field ); ?>"><?php echo esc_html( $kic_ts_labels[ $kic_ts_field ] ); ?> — فیلد مقصد</label>
                                                <div class="kic-control">
                                                        <select id="kic-ts-<?php echo esc_attr( $kic_ts_field ); ?>" name="kic_settings[theme_sync_<?php echo esc_attr( $kic_ts_field ); ?>_key]" class="kic-input">
                                                                <option value="auto" <?php selected( 'auto', $kic_ts_setting[ $kic_ts_field ] ); ?>>خودکار — همگام با ساختار قالب سایت</option>
                                                                <?php if ( ! empty( $kic_ts_data[ $kic_ts_field ] ) ) : ?>
                                                                        <?php foreach ( $kic_ts_data[ $kic_ts_field ] as $kic_ts_candidate ) : ?>
                                                                                <option value="<?php echo esc_attr( $kic_ts_candidate['key'] ); ?>" <?php selected( $kic_ts_candidate['key'], $kic_ts_setting[ $kic_ts_field ] ); ?>>
                                                                                        فیلد بومی قالب/سایت: <?php echo esc_html( $kic_ts_candidate['key'] ); ?><?php echo esc_html( 'db' === $kic_ts_candidate['source'] ? ' — در پایگاه‌دادهٔ سایت دیده شده' : ( 'registered' === $kic_ts_candidate['source'] ? ' — توسط قالب/افزونه ثبت شده' : '' ) ); ?>
                                                                                </option>
                                                                        <?php endforeach; ?>
                                                                <?php else : ?>
                                                                        <option value="none" <?php selected( 'none', $kic_ts_setting[ $kic_ts_field ] ); ?>>قالب شما فیلد بومی ندارد — نمایش خودکار افزونه</option>
                                                                <?php endif; ?>
                                                                <option value="none" <?php selected( 'none', $kic_ts_setting[ $kic_ts_field ] ); ?>>فقط متای استاندارد افزونه (بدون درج در فیلد قالب)</option>
                                                        </select>
                                                        <input type="text" class="kic-input" dir="ltr" style="margin-top:6px;"
                                                                name="kic_settings[theme_sync_<?php echo esc_attr( $kic_ts_field ); ?>_custom]"
                                                                id="kic-ts-<?php echo esc_attr( $kic_ts_field ); ?>-custom"
                                                                placeholder="کلید سفارشی (اختیاری — روی انتخاب بالا اولویت دارد)"
                                                                value="<?php echo esc_attr( isset( $kic_settings[ 'theme_sync_' . $kic_ts_field . '_custom' ] ) ? (string) $kic_settings[ 'theme_sync_' . $kic_ts_field . '_custom' ] : '' ); ?>" />
                                                        <p class="description">
                                                                «خودکار» فیلد روتیتر/زیرتیتر قالب شما را از ساختار زندهٔ سایت پیدا می‌کند (کلیدهای متای ثبت‌شده و کلیدهای موجود در پایگاه‌داده). اگر چیزی پیدا نشود، افزونه خودش این سرتیتر را در صفحهٔ نوشته نمایش می‌دهد. کلید سفارشی برای توسعه‌دهندهٔ قالب است.
                                                        </p>
                                                </div>
                                        </div>
                                <?php endforeach; ?>
                                <div class="kic-field" style="margin-top:10px;">
                                        <label><?php echo esc_html( 'نمایش خودکار وقتی قالب فیلد بومی ندارد' ); ?></label>
                                        <div class="kic-control">
                                                <label class="kic-check">
                                                        <input type="checkbox" name="kic_settings[theme_sync_auto_output]" value="1" <?php checked( ! empty( $kic_settings['theme_sync_auto_output'] ) ); ?> />
                                                        <span>در صفحهٔ نوشته، روتیتر/زیرتیتر بالای محتوا نمایش داده شوند وقتی قالب فیلدی برایشان ندارد</span>
                                                </label>
                                        </div>
                                </div>
                        </div>

                </section>

                <section id="kic-tab-bundle" class="kic-tab-panel kic-bundle-io">

                        <h2 class="kic-section-title"><?php echo esc_html( kic_t( 'بستهٔ قابل حمل تنظیمات' ) ); ?></h2>
                        <p class="kic-muted"><?php echo esc_html( 'کل زنجیرهٔ ارائه‌دهنده‌ها + تنظیمات عمومی در یک فایل JSON — برای انتقال به سایت دیگر یا به‌اشتراک‌گذاری به‌عنوان «پک آماده».' ); ?></p>

                        <h3 class="kic-section-title"><?php echo esc_html( kic_t( 'دریافت بستهٔ JSON' ) ); ?></h3>
                        <div class="kic-field">
                                <label><?php echo esc_html( kic_t( 'کلیدهای API پیش‌فرض در بسته نیستند.' ) ); ?></label>
                                <div class="kic-control">
                                        <label class="kic-check">
                                                <input type="checkbox" id="kic-bundle-include-keys" value="1" />
                                                <span><?php echo esc_html( kic_t( 'کلیدها را هم در بسته بگذار (هشدار: متن آشکار).' ) ); ?></span>
                                        </label>
                                        <p class="description"><?php echo esc_html( 'کلیدها فقط وقتی وارد بسته می‌شوند که این گزینه را فعال کنید و خودتان دانلود کنید — انتقال امن‌تر حالت پیش‌فرض است (بدون کلید).' ); ?></p>
                                        <div class="kic-actions">
                                                <button type="button" class="button button-primary" id="kic-bundle-export"><?php echo esc_html( kic_t( 'دریافت بستهٔ JSON' ) ); ?></button>
                                        </div>
                                </div>
                        </div>

                        <h3 class="kic-section-title"><?php echo esc_html( kic_t( 'ورود بستهٔ تنظیمات' ) ); ?></h3>
                        <div class="kic-field">
                                <label><?php echo esc_html( kic_t( 'فایل JSON بسته را اینجا بچسبانید یا انتخاب کنید.' ) ); ?></label>
                                <div class="kic-control">
                                        <textarea id="kic-bundle-json" rows="6" dir="ltr" placeholder='{ "plugin": "ai-content-publishing-agent", ... }'></textarea>
                                        <p class="description"><?php echo esc_html( 'ارائه‌دهنده‌ها با شناسهٔ جدید «به» تنظیمات فعلی اضافه می‌شوند (چیزی حذف نمی‌شود)؛ تنظیمات عمومی فقط کلیدهای امن جایگزین می‌شوند.' ); ?></p>
                                        <div class="kic-actions">
                                                <button type="button" class="button button-primary" id="kic-bundle-import"><?php echo esc_html( kic_t( 'ورود بستهٔ تنظیمات' ) ); ?></button>
                                        </div>
                                        <p class="kic-bundle-log" id="kic-bundle-log" hidden></p>
                                </div>
                        </div>

                </section>

                <section id="kic-tab-access" class="kic-tab-panel">

                        <h2 class="kic-section-title">دسترسی‌ها</h2>

                        <div class="kic-note">
                                <p>
                                        برای استفاده از ویجت «انتشار محتوا»، کاربر باید دارای قابلیت
                                        <code><?php echo esc_html( $kic_capability ); ?></code>
                                        باشد.
                                </p>
                                <ul class="kic-list">
                                        <li>نقش‌های «مدیر کل» (administrator) و «سردبیر» (editor) به‌طور پیش‌فرض این دسترسی را دارند.</li>
                                        <li>برای اعطای این دسترسی به نقش‌های دیگر می‌توانید از افزونه‌های مدیریت نقش مانند «Members» یا «User Role Editor» استفاده کنید.</li>
                                        <li>توسعه‌دهندگان می‌توانند نام قابلیت را با فیلتر <code>kic_capability</code> تغییر دهند.</li>
                                </ul>
                        </div>

                </section>

                <section id="kic-tab-routing" class="kic-tab-panel">

                        <h2 class="kic-section-title">مسیریابی، بودجه و زمان‌بندی</h2>

                        <p class="description">
                                این تنظیمات فقط به پیشنهادهای اختیاری (و هزینهٔ آن‌ها) مربوط‌اند — انتشار محتوا هیچ‌وقت نگاهی به این‌ها نمی‌کند. کارهای سبک روی مدل ارزان‌تر اجرا می‌شوند، در صورت خطای ارائه‌دهندهٔ اصلی درخواست روی ارائه‌دهندهٔ پشتیبان تکرار می‌شود و مصرف ماهانه سقف دلاری دارد. (زنجیرهٔ ارائه‌دهنده‌ها در تب دوم، بر این حالت پشتیبان اولویت دارد.)
                        </p>

                        <div class="kic-card">
                                <h3 class="kic-section-title">مسیریابی مدل‌ها</h3>

                                <div class="kic-field">
                                        <div class="kic-control">
                                                <label class="kic-check">
                                                        <input type="checkbox" name="kic_settings[routing_enabled]" value="1" <?php checked( ! empty( $kic_settings['routing_enabled'] ) ); ?> />
                                                        <span>فعال‌سازی مسیریابی هوشمند (مدل ارزان برای کارهای سبک)</span>
                                                </label>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-cheap-model">مدل ارزان (سبک)</label>
                                        <div class="kic-control">
                                                <input type="text" id="kic-cheap-model" name="kic_settings[cheap_model]" class="kic-input"
                                                        dir="ltr" spellcheck="false" placeholder="gpt-4o-mini"
                                                        value="<?php echo esc_attr( (string) $kic_settings['cheap_model'] ); ?>" />
                                                <p class="description">مدل سبک/ارزان‌تر برای پیشنهادهای کوتاه (دسته، نامک، لینک) — مثلاً مدل کوچک‌تر همان ارائه‌دهنده یا ارائه‌دهندهٔ ارزان‌تر؛ خالی = همان مدل اصلی.</p>
                                        </div>
                                </div>
                        </div>

                        <div class="kic-card">
                                <h3 class="kic-section-title">Failover خودکار</h3>

                                <div class="kic-field">
                                        <div class="kic-control">
                                                <label class="kic-check">
                                                        <input type="checkbox" name="kic_settings[failover_enabled]" value="1" <?php checked( ! empty( $kic_settings['failover_enabled'] ) ); ?> />
                                                        <span>در صورت خطای ارائه‌دهندهٔ اصلی، تلاش مجدد روی ارائه‌دهندهٔ پشتیبان</span>
                                                </label>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-failover-base-url">آدرس پایهٔ پشتیبان</label>
                                        <div class="kic-control">
                                                <input type="text" id="kic-failover-base-url" name="kic_settings[failover_base_url]" class="kic-input"
                                                        dir="ltr" spellcheck="false" placeholder="https://api.openai.com/v1"
                                                        value="<?php echo esc_attr( (string) $kic_settings['failover_base_url'] ); ?>" />
                                                <p class="description">نقطهٔ انتهایی سازگار با OpenAI برای ارائه‌دهندهٔ پشتیبان؛ خالی = بدون پشتیبان.</p>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-failover-api-key">کلید API پشتیبان</label>
                                        <div class="kic-control">
                                                <input type="password" id="kic-failover-api-key" name="kic_settings[failover_api_key]" class="kic-input"
                                                        dir="ltr" autocomplete="new-password" spellcheck="false"
                                                        placeholder="<?php echo esc_attr( $kic_has_failover_key ? 'برای حفظ کلید فعلی خالی بگذارید' : 'sk-...' ); ?>"
                                                        value="" />
                                                <p class="description">کلید ارائه‌دهندهٔ پشتیبان؛ رمز‌شده ذخیره می‌شود و هرگز نمایش داده نمی‌شود.</p>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-failover-model">مدل پشتیبان</label>
                                        <div class="kic-control">
                                                <input type="text" id="kic-failover-model" name="kic_settings[failover_model]" class="kic-input"
                                                        dir="ltr" spellcheck="false" placeholder="gpt-4o-mini"
                                                        value="<?php echo esc_attr( (string) $kic_settings['failover_model'] ); ?>" />
                                                <p class="description">مدلی که در حالت پشتیبان روی ارائه‌دهندهٔ دوم استفاده می‌شود.</p>
                                        </div>
                                </div>
                        </div>

                        <div class="kic-card">
                                <h3 class="kic-section-title">سقف بودجهٔ ماهانه</h3>

                                <div class="kic-field">
                                        <label for="kic-monthly-budget">سقف بودجه (دلار)</label>
                                        <div class="kic-control">
                                                <input type="number" id="kic-monthly-budget" name="kic_settings[monthly_budget_usd]" class="kic-input"
                                                        dir="ltr" min="0" max="999999" step="0.5"
                                                        value="<?php echo esc_attr( (string) $kic_settings['monthly_budget_usd'] ); ?>" />
                                                <p class="description"><?php echo esc_html( sprintf( '۰ = بدون سقف — هشدار ایمیلی در %d٪ و توقف پیشنهادهای اختیاری پس از عبور از سقف (انتشار محتوا متوقف نمی‌شود)', $kic_alert_pct_display ) ); ?></p>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-budget-alert">آستانهٔ هشدار (درصد)</label>
                                        <div class="kic-control">
                                                <input type="number" id="kic-budget-alert" name="kic_settings[budget_alert_pct]" class="kic-input"
                                                        dir="ltr" min="50" max="99" step="1"
                                                        value="<?php echo esc_attr( (string) $kic_alert_pct_display ); ?>" />
                                                <p class="description">درصدی از بودجه که عبور از آن، ایمیل هشدار را فعال می‌کند (بین ۵۰ تا ۹۹).</p>
                                        </div>
                                </div>

                                <?php if ( $kic_budget_ready ) : ?>
                                        <div class="kic-notice<?php echo $kic_budget_over_alert ? ' kic-notice-warn' : ''; ?>">
                                                <p><?php echo esc_html( $kic_budget_line ); ?></p>
                                        </div>
                                <?php endif; ?>
                        </div>

                        <div class="kic-card">
                                <h3 class="kic-section-title">زمان‌بندی انتشار نقطه‌ای (انبوه)</h3>

                                <div class="kic-field">
                                        <div class="kic-control">
                                                <label class="kic-check">
                                                        <input type="checkbox" name="kic_settings[drip_enabled]" value="1" <?php checked( ! empty( $kic_settings['drip_enabled'] ) ); ?> />
                                                        <span>انتشار تدریجی پیش‌نویس‌های انبوه (صف تقویمی)</span>
                                                </label>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-drip-per-day">نوشته در روز</label>
                                        <div class="kic-control">
                                                <input type="number" id="kic-drip-per-day" name="kic_settings[drip_posts_per_day]" class="kic-input"
                                                        dir="ltr" min="1" max="50" step="1"
                                                        value="<?php echo esc_attr( (string) $kic_settings['drip_posts_per_day'] ); ?>" />
                                                <p class="description">چند نوشته در روز منتشر شود (بین ۱ تا ۵۰).</p>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-drip-hour">ساعت انتشار</label>
                                        <div class="kic-control">
                                                <input type="number" id="kic-drip-hour" name="kic_settings[drip_publish_hour]" class="kic-input"
                                                        dir="ltr" min="0" max="23" step="1"
                                                        value="<?php echo esc_attr( (string) $kic_settings['drip_publish_hour'] ); ?>" />
                                                <p class="description">ساعت انتشار (به وقت سایت) — نوشته‌های روز روی ۱۲ ساعت از این ساعت پخش می‌شوند.</p>
                                        </div>
                                </div>

                                <div class="kic-field">
                                        <label for="kic-drip-start">شروع پس از</label>
                                        <div class="kic-control">
                                                <input type="number" id="kic-drip-start" name="kic_settings[drip_start_days]" class="kic-input"
                                                        dir="ltr" min="0" max="30" step="1"
                                                        value="<?php echo esc_attr( (string) $kic_settings['drip_start_days'] ); ?>" />
                                                <p class="description">چند روز بعد شروع شود (بین ۰ تا ۳۰).</p>
                                        </div>
                                </div>
                        </div>

                </section>

                <div class="kic-actions">
                        <button type="button" class="kic-btn kic-btn-ghost" id="kic-test-btn">
                                تست اتصال
                                <span class="kic-spinner" aria-hidden="true"></span>
                        </button>
                        <span id="kic-test-result" class="kic-test-result" aria-live="polite"></span>
                </div>

                <div class="kic-actions">
                        <button type="submit" class="kic-btn kic-btn-primary" id="kic-save-btn">ذخیره تنظیمات</button>
                        <span class="kic-muted">پس از ذخیره، با «تست اتصال» صحت تنظیمات را بررسی کنید.</span>
                </div>

        </form>
</div>
