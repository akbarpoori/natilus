<?php
/**
 * Natilus — 3-step setup wizard screen.
 *
 * Variables provided by KIC_Wizard::render_page(): $presets.
 * kic-wizard.js drives the steps + connection test + finish AJAX.
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
 * method (KIC_Wizard::render_page()), so the variables below are
 * method-scoped view data, not global definitions.
 */

$presets = isset( $presets ) && is_array( $presets ) ? $presets : array();
?>
<div class="wrap kic-wizard-wrap" dir="rtl">
        <h1 class="kic-page-title">Natilus — <?php echo esc_html( kic_t( 'راه‌اندازی اختیاری پیشنهادها' ) ); ?></h1>

        <div class="kic-card" style="padding:12px 16px;border-inline-start:3px solid #22c55e;margin-bottom:14px;">
                <p style="margin:0;"><?php echo esc_html( 'این راه‌اندازی کاملاً اختیاری است: انتشار محتوا به هیچ ارائه‌دهنده‌ای نیاز ندارد. ارائه‌دهنده فقط برای پیشنهادهای اختیاری (دسته، نامک و…) به کار می‌آید و هیچ پیش‌فرضی ندارد — هیچ‌وقت برای انتشار لازم نیست.' ); ?></p>
        </div>

        <div id="kic-wizard-root" class="kic-card kic-wizard">

                <!-- Step indicators --------------------------------------------------->
                <ol class="kic-wizard-steps" id="kic-wizard-steps">
                        <li class="is-active" data-step="1"><span>1</span> <?php echo esc_html( kic_t( 'قدم ۱ از ۳ — انتخاب ارائه‌دهنده (اختیاری)' ) ); ?></li>
                        <li data-step="2"><span>2</span> <?php echo esc_html( kic_t( 'قدم ۲ از ۳ — کلید و مدل' ) ); ?></li>
                        <li data-step="3"><span>3</span> <?php echo esc_html( kic_t( 'قدم ۳ از ۳ — تست اتصال' ) ); ?></li>
                </ol>

                <!-- Step 1: provider preset -------------------------------------------->
                <section class="kic-wizard-step" data-step="1">
                        <p class="kic-muted"><?php echo esc_html( 'اگر خواستید پیشنهادهای اختیاری فعال باشند، یک ارائه‌دهندهٔ سازگار با OpenAI را انتخاب کنید — آدرس پایه به‌صورت خودکار پر می‌شود.' ); ?></p>
                        <div class="kic-wizard-presets" id="kic-wizard-presets">
                                <?php foreach ( $presets as $key => $preset ) : ?>
                                        <?php
                                        $preset = is_array( $preset ) ? $preset : array();
                                        $label    = isset( $preset['label'] ) ? (string) $preset['label'] : (string) $key;
                                        $base_url = isset( $preset['base_url'] ) ? (string) $preset['base_url'] : '';
                                        $model    = isset( $preset['model'] ) ? (string) $preset['model'] : '';
                                        ?>
                                        <button type="button"
                                                class="kic-wizard-preset"
                                                data-key="<?php echo esc_attr( (string) $key ); ?>"
                                                data-label="<?php echo esc_attr( $label ); ?>"
                                                data-base-url="<?php echo esc_attr( $base_url ); ?>"
                                                data-model="<?php echo esc_attr( $model ); ?>">
                                                <strong><?php echo esc_html( $label ); ?></strong>
                                                <code dir="ltr"><?php echo esc_html( $base_url ); ?></code>
                                        </button>
                                <?php endforeach; ?>
                        </div>
                </section>

                <!-- Step 2: base URL / key / model ------------------------------------->
                <section class="kic-wizard-step" data-step="2" hidden>
                        <p class="kic-muted"><?php echo esc_html( 'آدرس پایه، کلید API و مدل ارائه‌دهندهٔ خود را وارد کنید.' ); ?></p>

                        <label for="kic-wizard-base-url"><?php echo esc_html( kic_t( 'آدرس پایه (Base URL)' ) ); ?></label>
                        <input type="url" id="kic-wizard-base-url" dir="ltr" class="kic-input" placeholder="https://api.openai.com/v1" autocomplete="off" />

                        <label for="kic-wizard-api-key"><?php echo esc_html( kic_t( 'کلید API' ) ); ?></label>
                        <input type="password" id="kic-wizard-api-key" dir="ltr" class="kic-input" autocomplete="off" />
                        <p class="kic-muted"><?php echo esc_html( 'برای سرورهای محلی (Ollama / LM Studio) می‌توانید کلید را خالی بگذارید.' ); ?></p>

                        <label for="kic-wizard-model"><?php echo esc_html( kic_t( 'مدل' ) ); ?></label>
                        <input type="text" id="kic-wizard-model" dir="ltr" class="kic-input" placeholder="gpt-4o-mini" autocomplete="off" />
                        <p class="kic-muted" id="kic-wizard-models-note"><?php echo esc_html( 'پس از واردکردن آدرس و کلید، می‌توانید در مرحلهٔ بعد فهرست مدل‌ها را بخوانید.' ); ?></p>
                </section>

                <!-- Step 3: connection test -------------------------------------------->
                <section class="kic-wizard-step" data-step="3" hidden>
                        <p class="kic-muted"><?php echo esc_html( 'اتصال با همان مقادیر مرحلهٔ ۲ تست می‌شود — بدون ذخیرهٔ کلید.' ); ?></p>

                        <div class="kic-wizard-summary" id="kic-wizard-summary" dir="ltr"></div>

                        <div class="kic-actions">
                                <button type="button" class="button button-primary" id="kic-wizard-test"><?php echo esc_html( kic_t( 'تست اتصال' ) ); ?></button>
                                <button type="button" class="button" id="kic-wizard-models"><?php echo esc_html( 'خواندن فهرست مدل‌ها' ); ?></button>
                        </div>

                        <div id="kic-wizard-test-result" class="kic-wizard-result"></div>
                        <div id="kic-wizard-models-list" class="kic-wizard-models" hidden></div>
                </section>

                <!-- Finish ------------------------------------------------------------->
                <section class="kic-wizard-done" id="kic-wizard-done" hidden>
                        <h2><?php echo esc_html( kic_t( 'راه‌اندازی کامل شد.' ) ); ?></h2>
                        <p class="kic-muted"><?php echo esc_html( 'ارائه‌دهنده در زنجیره ذخیره شد و اکنون پیش‌فرض است. برای تنظیمات بیشتر به «تنظیمات» بروید.' ); ?></p>
                        <p>
                                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ai-content-publishing-agent' ) ); ?>"><?php echo esc_html( kic_t( 'تنظیمات' ) ); ?></a>
                                <a class="button" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>"><?php echo esc_html( 'نوشتهٔ جدید' ); ?></a>
                        </p>
                </section>

                <!-- Footer navigation ---------------------------------------------------->
                <div class="kic-wizard-footer kic-actions" id="kic-wizard-footer">
                        <button type="button" class="button" id="kic-wizard-back" hidden><?php echo esc_html( 'بازگشت' ); ?></button>
                        <button type="button" class="button button-primary" id="kic-wizard-next"><?php echo esc_html( kic_t( 'برو به قدم بعد' ) ); ?></button>
                        <button type="button" class="button button-primary" id="kic-wizard-finish" hidden><?php echo esc_html( kic_t( 'پایان راه‌اندازی' ) ); ?></button>
                        <button type="button" class="button" id="kic-wizard-skip"><?php echo esc_html( kic_t( 'رد کردن — انتشار بدون هوش مصنوعی' ) ); ?></button>
                        <span id="kic-wizard-status" class="kic-muted"></span>
                </div>
        </div>
</div>
