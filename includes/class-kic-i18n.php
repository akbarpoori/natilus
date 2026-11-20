<?php
/**
 * Natilus — bilingual UI strings (FA default / EN).
 *
 * The plugin ships with a Persian-first UI. This module adds a light-weight
 * dictionary layer so the admin interface can render in English too
 * (setting: تنظیمات → ویژگی‌ها → زبان رابط کاربری).
 *
 * Usage (PHP):  echo kic_t( 'زمان‌بندی انتشار' );      // EN mode returns the translation.
 * Usage (JS):   the compose / calendar / wizard scripts receive KIC.i18n
 *               { lang, dict } via wp_localize_script and use their T() helper.
 *
 * Missing keys fall back to the original Persian string, so a partial
 * translation can never blank out a label. languages/*.pot lists the same
 * strings for translators who want to add more languages later.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_I18n {

        /**
         * Singleton instance (no hooks — pure helper).
         *
         * @var KIC_I18n|null
         */
        private static $instance = null;

        /**
         * Contract bootstrap (registers nothing by design).
         *
         * @return KIC_I18n
         */
        public static function init() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Singleton accessor (lazily bootstrapped).
         *
         * @return KIC_I18n
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::init();
                }
                return self::$instance;
        }

        /**
         * Private constructor (singleton contract, no hooks).
         */
        private function __construct() {
        }

        /**
         * Active UI language: 'fa' (default) or 'en'.
         *
         * @return string
         */
        public static function lang() {
                $lang = '';
                if ( function_exists( 'kic_get_setting' ) ) {
                        $lang = strtolower( trim( (string) kic_get_setting( 'ui_lang', 'fa' ) ) );
                }
                return ( 'en' === $lang ) ? 'en' : 'fa';
        }

        /**
         * Translate one Persian UI string to English in EN mode.
         * Unknown keys and FA mode return the input unchanged.
         *
         * @param string $fa Persian source string.
         * @return string
         */
        public static function t( $fa ) {
                $fa = (string) $fa;
                if ( 'fa' === self::lang() ) {
                        return $fa;
                }
                $dict = self::dict();
                if ( isset( $dict[ $fa ] ) && '' !== trim( (string) $dict[ $fa ] ) ) {
                        return (string) $dict[ $fa ];
                }
                return $fa;
        }

        /**
         * Package handed to JavaScript via wp_localize_script.
         *
         * @return array {lang: 'fa'|'en', dict: {fa: en}}
         */
        public static function js_package() {
                return array(
                        'lang' => self::lang(),
                        'dict' => ( 'en' === self::lang() ) ? self::dict() : array(),
                );
        }

        /**
         * Persian → English dictionary. Only user-visible chrome strings.
         *
         * @return array
         */
        public static function dict() {
                return array(

                        /* ---- Shared chrome / admin menu ---------------------------------- */
                        'Natilus'                            => 'Natilus',
                        'تنظیمات'                                 => 'Settings',
                        'داشبورد مصرف'                           => 'Usage Dashboard',
                        'تقویم انتشار'                            => 'Publish Calendar',
                        'گزارش‌ها و سلامت'                        => 'Reports & Health',
                        'راه‌اندازی سریع'                         => 'Quick Setup',
                        'اسکیل‌ها'                                => 'Skills',
                        'کتابخانهٔ پرامپت'                        => 'Prompt Library',
                        'انبوه'                                   => 'Bulk',
                        'زبان رابط کاربری'                        => 'Interface language',
                        'فارسی'                                   => 'Persian',
                        'انگلیسی'                                 => 'English',
                        'ذخیرهٔ تنظیمات'                          => 'Save settings',
                        'بستن'                                    => 'Close',
                        'انصراف'                                  => 'Cancel',
                        'تأیید'                                   => 'Confirm',
                        'اعمال'                                   => 'Apply',
                        'حذف'                                     => 'Delete',
                        'در حال انجام…'                          => 'Working…',
                        'خطا'                                     => 'Error',
                        'هشدار'                                   => 'Warning',
                        'تازه‌سازی'                              => 'Refresh',
                        'ذخیره شد.'                              => 'Saved.',
                        'آماده'                                   => 'Ready',
                        'در حال کار'                              => 'Working',
                        'موفق'                                    => 'OK',

                        /* ---- Compose widget chrome --------------------------------------- */
                        'محتوا'                                   => 'Compose',
                        'رسانه'                                   => 'Media',
                        'لینک‌ها و تیترها'                        => 'Links & Titles',
                        'کتابخانه'                                => 'Library',
                        'تاریخچه'                                 => 'History',
                        'انتشار'                                  => 'Publish',
                        'ارائه‌دهنده تنظیم نشده'                  => 'No provider configured',
                        'جمع‌کردن / باز‌کردن'                     => 'Collapse / expand',

                        /* ---- Publish tab (v1.4) ------------------------------------------ */
                        'چک‌لیست پیش از انتشار'                  => 'Pre-publish checklist',
                        'بررسی پیش از انتشار'                    => 'Run pre-publish checks',
                        'نتیجهٔ بررسی'                           => 'Check results',
                        'همهٔ بررسی‌ها انجام شد.'                => 'All checks have run.',
                        'تصویر شاخص'                             => 'Featured image',
                        'دسته‌بندی'                              => 'Category',
                        'برچسب‌ها'                                => 'Tags',
                        'نامک (slug)'                            => 'Slug',
                        'توضیح متای سئو'                         => 'SEO meta description',
                        'متن جایگزین تصاویر'                     => 'Image alt text',
                        'لینک داخلی'                             => 'Internal links',
                        'این مورد بررسی نشد (نوع نوشته).'        => 'Not applicable for this post type.',
                        'بیشتر بدانید'                            => 'Learn more',
                        'پیشنهاد دسته و برچسب'                   => 'Category & tag suggestions',
                        'دریافت پیشنهاد'                         => 'Get suggestions',
                        'پیشنهاد نامک انگلیسی'                   => 'English slug suggestion',
                        'پیشنهاد نامک'                           => 'Suggest slug',
                        'اعمال انتخاب‌ها روی نوشته'              => 'Apply selection to the post',
                        'پیشنهادی از هوش مصنوعی اعمال نشد — انتخاب و کلیک شما الزامی است.' => 'Nothing AI-suggested is applied without your explicit click.',
                        'فهرست دسته‌ها و برچسب‌های موجود سایت:'   => 'Existing site categories & tags:',

                        /* ---- Pre-publish checklist items --------------------------------- */
                        'تصویر شاخص ندارد.'                     => 'No featured image set.',
                        'دسته‌بندی انتخاب نشده است.'             => 'No category selected.',
                        'نامک تکراری یا خالی است.'               => 'Slug is empty or duplicated.',
                        'توضیح متای سئو خالی است.'               => 'SEO meta description is empty.',
                        'برخی تصاویر متن جایگزین ندارند.'        => 'Some images are missing alt text.',
                        'هیچ لینک داخلی به مقالات دیگر نیست.'    => 'No internal links to other posts.',
                        'بررسی‌های فنی همه سبزند.'               => 'All technical checks are green.',

                        /* ---- Calendar (v1.4) ---------------------------------------------- */
                        'امروز'                                  => 'Today',
                        'ماه قبل'                                 => 'Previous month',
                        'ماه بعد'                                => 'Next month',
                        'صف انتشار'                              => 'Publishing queue',
                        'صف در حال اجراست.'                      => 'Queue is running.',
                        'صف متوقف است.'                          => 'Queue is paused.',
                        'توقف صف'                                 => 'Pause queue',
                        'ادامهٔ صف'                               => 'Resume queue',
                        'انتقال به امروز'                        => 'Move to today',
                        'انتشار آیتم بعدی همین حالا'            => 'Publish the next item now',
                        'جابه‌جایی آیتم‌ها'                      => 'Reorder items',
                        'بالا'                                    => 'Up',
                        'پایین'                                  => 'Down',
                        'موردی در این ماه در صف نیست.'           => 'No queued items this month.',
                        'برای جابه‌جایی، آیتم را بکشید و روی روز دیگر رها کنید.' => 'Drag an item and drop it on another day to reschedule.',
                        'هیچ آیتمی برای انتشار در صف نیست.'       => 'The queue is empty.',
                        'آیتم بعدی منتشر شد.'                    => 'The next item has been published.',
                        'زمان جدید ذخیره شد.'                    => 'New date saved.',

                        /* ---- Reports & health (v1.4) ------------------------------------- */
                        'گزارش فرصت‌های لینک داخلی'             => 'Internal-link opportunity report',
                        'مقالات بدون لینک داخلی'                => 'Published posts with no internal links',
                        'روی این مقاله لینک داخلی ندارد.'        => 'has no internal links',
                        'تاریخچهٔ سلامت زنجیره'                 => 'Chain health history',
                        'رویدادی ثبت نشده است.'                 => 'No events recorded.',
                        'خلاصهٔ هفتگی ایمیلی'                   => 'Weekly email summary',
                        'هشدار مصرف غیرعادی'                    => 'Unusual usage alert',
                        'خلاصهٔ ایمیل هفتگی'                    => 'Weekly summary email',
                        'این هفته'                               => 'This week',

                        /* ---- Setup wizard (v1.4) ----------------------------------------- */
                        'راه‌اندازی سه‌مرحله‌ای'                => '3-step setup',
                        'قدم ۱ از ۳ — انتخاب ارائه‌دهنده'       => 'Step 1 of 3 — pick a provider',
                        'قدم ۲ از ۳ — کلید و مدل'               => 'Step 2 of 3 — key & model',
                        'قدم ۳ از ۳ — تست اتصال'                => 'Step 3 of 3 — test the connection',
                        'برو به قدم بعد'                        => 'Next step',
                        'پایان راه‌اندازی'                      => 'Finish setup',
                        'راه‌اندازی کامل شد.'                   => 'Setup complete.',
                        'آدرس پایه (Base URL)'                  => 'Base URL',
                        'کلید API'                               => 'API key',
                        'مدل'                                    => 'Model',
                        'تست اتصال'                             => 'Test connection',
                        'اتصال برقرار است.'                     => 'Connection is working.',
                        'شروع دوباره'                           => 'Start over',

                        /* ---- Settings bundle (v1.4) -------------------------------------- */
                        'انتقال تنظیمات'                         => 'Transfer settings',
                        'بستهٔ قابل حمل تنظیمات'                 => 'Portable settings bundle',
                        'دریافت بستهٔ JSON'                     => 'Download JSON bundle',
                        'ورود بسته'                              => 'Import a bundle',
                        'فایل JSON بسته را اینجا بچسبانید یا انتخاب کنید.' => 'Paste or choose the bundle JSON file here.',
                        'ورود بستهٔ تنظیمات'                    => 'Import settings bundle',
                        'کلیدهای API پیش‌فرض در بسته نیستند.'   => 'API keys are excluded by default.',
                        'کلیدها را هم در بسته بگذار (هشدار: متن آشکار).' => 'Include API keys (warning: plain text).',
                        'بستهٔ تنظیمات با موفقیت وارد شد.'       => 'Settings bundle imported successfully.',
                        'بسته نامعتبر است — ساختار JSON خوانده نشد.' => 'Invalid bundle — the JSON structure could not be read.',

                        /* ---- Settings tabs ------------------------------------------------ */
                        'اتصال به ارائه‌دهنده'                  => 'Provider connection',
                        'زنجیرهٔ مدل‌ها و توکن'                 => 'Model chain & tokens',
                        'ویژگی‌ها'                               => 'Features',
                        'دسترسی‌ها'                              => 'Access',
                        'مسیریابی، بودجه و زمان‌بندی'            => 'Routing, budget & scheduling',

                        /* ---- Misc reused strings ------------------------------------------ */
                        'مشاهده'                                 => 'View',
                        'ویرایش'                                 => 'Edit',
                        'عنوان'                                  => 'Title',
                        'روتیتر'                                 => 'Kicker',
                        'زیرتیتر'                                => 'Subtitle',
                        'روتیتر — سرتیتر کوچک بالای عنوان (اختیاری)' => 'Kicker — small headline above the title (optional)',
                        'زیرتیتر — توضیح کوتاه زیر عنوان (اختیاری)'  => 'Subtitle — short line below the title (optional)',
                        'تاریخ'                                  => 'Date',
                        'وضعیت'                                  => 'Status',
                        'پیش‌نویس'                               => 'Draft',
                        'منتشرشده'                              => 'Published',
                        'زمان‌بندی‌شده'                          => 'Scheduled',
                        'در صف'                                 => 'Queued',

                        /* ---- v2.3 — prompt-based AI edit of the current post --------------- */
                        'ویرایش این نوشته با پرامپت (اختیاری — هوش مصنوعی)' => 'Edit this post with a prompt (optional — AI)',
                        'دستور ویرایش (پرامپت)'                  => 'Edit instruction (prompt)',
                        'مثلاً: پاراگراف آخر را کوتاه کن — یا: تیتر دوم را جذاب‌تر بنویس' => 'e.g. shorten the last paragraph — or: make the second heading catchier',
                        'ویرایش کن (پیش‌نمایش)'                  => 'Edit (preview)',
                        'متن ویرایش‌شده'                         => 'Edited text',
                        'متن فعلی'                               => 'Current text',
                        'اعمال روی نوشته'                        => 'Apply to the post',
                        'توکن'                                   => 'tokens',
                        'فقط دستور ویرایش را بنویسید — متن نوشته خودکار از خود نوشتهٔ ذخیره‌شده خوانده می‌شود؛ نتیجه قبل از اعمال پیش‌نمایش می‌شود و قبل از اعمال نسخهٔ پشتیبان گرفته می‌شود.' => 'Write ONLY the edit instruction — the article text is read automatically from the saved post; the result is previewed before anything is applied, and a backup is taken first.',
                        'ویرایش با پرامپت به یک ارائه‌دهندهٔ هوش مصنوعی نیاز دارد — هر سرویس سازگار با OpenAI (یا سرور محلی مثل Ollama/LM Studio) در «تنظیمات Natilus» کافی است. انتشار محتوا بدون آن هم کامل کار می‌کند.' => 'Prompt-based editing needs an AI provider — any OpenAI-compatible service (or a local server such as Ollama/LM Studio) in “Natilus Settings” is enough. Publishing works fully without it too.',
                        'ابتدا یک ارائه‌دهنده در «تنظیمات Natilus» پیکربندی کنید.' => 'First configure a provider in “Natilus Settings”.',
                        'ویرایش با پرامپت روی نوشتهٔ ذخیره‌شده کار می‌کند — ابتدا نوشته را ذخیره کنید.' => 'Prompt-based editing works on a saved post — save the post first.',
                        'ابتدا دستور ویرایش را در باکس پرامپت بنویسید.' => 'First write the edit instruction in the prompt box.',
                        'دستور ویرایش را در باکس پرامپت بنویسید — مثلاً: پاراگراف آخر را کوتاه کن.' => 'Write the edit instruction in the prompt box — e.g. shorten the last paragraph.',
                        'در حال ویرایش با پرامپت…'               => 'Editing with prompt…',
                        'ویرایش آمادهٔ بررسی است.'                => 'The edit is ready for review.',
                        'ویرایش آماده شد — پیش‌نمایش را بررسی و در صورت تأیید اعمال کنید.' => 'The edit is ready — review the preview and apply it if you approve.',
                        'خطا در ویرایش.'                         => 'Edit failed.',
                        'ویرایش ناموفق بود.'                     => 'Editing was unsuccessful.',
                        'مدل پاسخ قابل استفاده‌ای برنگرداند — پرامپت را دقیق‌تر بنویسید یا مدل دیگری در تنظیمات انتخاب کنید.' => 'The model returned no usable answer — make the prompt more specific or pick another model in Settings.',
                        'محتوای این نوشته خالی است — چیزی برای ویرایش وجود ندارد.' => 'This post has no content — there is nothing to edit.',
                        'این نوشته برای ویرایش با پرامپت بیش از حد طولانی است — ویرایش را دستی انجام دهید.' => 'This post is too long for prompt-based editing — please edit it manually.',
                        'ابتدا ویرایش را اجرا و پیش‌نمایش را ببینید.' => 'Run the edit first and check the preview.',
                        'ویرایش روی نوشته اعمال شود؟ قبل از اعمال نسخهٔ پشتیبان گرفته می‌شود و از پایین ویجت قابل بازگردانی است.' => 'Apply the edit to the post? A backup is taken first and can be restored from the bottom of this widget.',
                        'در حال اعمال ویرایش…'                   => 'Applying the edit…',
                        'ویرایش اعمال شد.'                       => 'The edit has been applied.',
                        'ویرایش روی نوشته اعمال شد — صفحه تازه‌سازی می‌شود…' => 'The edit has been applied to the post — refreshing…',
                        'خطا در اعمال ویرایش.'                   => 'Failed to apply the edit.',
                        'اعمال ویرایش ناموفق بود.'               => 'Applying the edit was unsuccessful.',
                        'محتوای ویرایش‌شده‌ای برای اعمال ارسال نشده است.' => 'No edited content was sent to apply.',
                        'محتوای ویرایش‌شده پس از پالایش خالی شد — چیزی اعمال نشد.' => 'The edited content came out empty after sanitizing — nothing was applied.',
                        'ذخیرهٔ ویرایش ناموفق بود: '             => 'Saving the edit failed: ',
                        'پیش‌نمایش کنار گذاشته شد — نوشته دست‌نخورده است.' => 'The preview was discarded — the post is untouched.',
                        'ویرایش با پرامپت در تنظیمات خاموش شده است.' => 'Prompt-based editing is turned off in Settings.',
                        'نوشتهٔ هدف یافت نشد.'                    => 'Target post not found.',
                        'اجازه ویرایش این نوشته را ندارید.'      => 'You are not allowed to edit this post.',
                        'نشست شما منقضی شده — صفحه را تازه‌سازی کنید.' => 'Your session expired — refresh the page.',
                        'دسترسی ندارید.'                         => 'You do not have permission.',
                );
        }
}

if ( ! function_exists( 'kic_t' ) ) {
        /**
         * Global helper so views can translate without the class name.
         *
         * @param string $fa Persian source string.
         * @return string
         */
        function kic_t( $fa ) {
                return KIC_I18n::t( $fa );
        }
}
