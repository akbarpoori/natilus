/**
 * Natilus — inline publisher widget (user-facing core).
 *
 * Vanilla JS. Expects window.KIC localized config (class-kic-metabox.php).
 * Publisher mode: the article is pasted ready-made and published EXACTLY
 * as written — no generation, no rewriting, no word counts.
 *
 * @package ai-content-publishing-agent
 */
(function (window, document) {
        'use strict';

        var KIC = window.KIC || null;
        if (!KIC || typeof KIC.ajaxurl === 'undefined') {
                return;
        }

        /* v1.4 — bilingual UI (FA default / EN via settings). */
        var KIC_I18N = (KIC.i18n && KIC.i18n.dict && typeof KIC.i18n.dict === 'object')
                ? KIC.i18n
                : { lang: 'fa', dict: {} };

        /**
         * Translate a Persian UI string when the EN dictionary has it.
         *
         * @param {string} fa Persian source string.
         * @return {string}
         */
        function T(fa) {
                if (KIC_I18N.lang === 'en' && Object.prototype.hasOwnProperty.call(KIC_I18N.dict, fa)) {
                        var en = KIC_I18N.dict[fa];
                        if (en) {
                                return String(en);
                        }
                }
                return fa;
        }

        /* ================================================================
         * Tiny utilities
         * ================================================================ */

        /**
         * DOM builder.
         *
         * @param {string} tag     Element tag.
         * @param {Object} props   Attributes / props / events.
         * @param {Node|Node[]|string|null} children Child nodes.
         * @return {HTMLElement}
         */
        function h(tag, props, children) {
                var el = document.createElement(tag);
                var k;
                var v;
                if (props) {
                        for (k in props) {
                                if (!Object.prototype.hasOwnProperty.call(props, k)) {
                                        continue;
                                }
                                v = props[k];
                                if (v === null || v === undefined || v === false) {
                                        continue;
                                }
                                if (k === 'class' || k === 'className') {
                                        el.className = String(v);
                                } else if (k === 'text') {
                                        el.textContent = String(v);
                                } else if (k === 'style' && typeof v === 'object') {
                                        for (var s in v) {
                                                if (Object.prototype.hasOwnProperty.call(v, s)) {
                                                        el.style[s] = v[s];
                                                }
                                        }
                                } else if (k === 'dataset' && typeof v === 'object') {
                                        for (var d in v) {
                                                if (Object.prototype.hasOwnProperty.call(v, d)) {
                                                        el.dataset[d] = v[d];
                                                }
                                        }
                                } else if (k === 'value') {
                                        el.value = String(v);
                                } else if (k === 'checked' || k === 'selected') {
                                        if (v === true) {
                                                el[k] = true;
                                        }
                                } else if (k.indexOf('on') === 0 && typeof v === 'function') {
                                        el.addEventListener(k.slice(2), v);
                                } else {
                                        el.setAttribute(k, String(v));
                                }
                        }
                }
                appendChildren(el, children);
                return el;
        }

        /**
         * Recursive child appender for h().
         *
         * @param {HTMLElement} el       Target.
         * @param {*} children           Children spec.
         * @return {void}
         */
        function appendChildren(el, children) {
                if (children === null || children === undefined || children === false) {
                        return;
                }
                if (Array.isArray(children)) {
                        for (var i = 0; i < children.length; i++) {
                                appendChildren(el, children[i]);
                        }
                        return;
                }
                if (typeof children === 'string' || typeof children === 'number') {
                        el.appendChild(document.createTextNode(String(children)));
                        return;
                }
                if (children instanceof Node) {
                        el.appendChild(children);
                }
        }


        /**
         * Persian-digit formatted number.
         *
         * @param {*} n Value.
         * @return {string}
         */
        function fmtNum(n) {
                var num = Number(n);
                if (!isFinite(num)) {
                        return '۰';
                }
                try {
                        return num.toLocaleString('fa-IR');
                } catch (e) {
                        return String(num);
                }
        }

        /**
         * Coerce a stored timestamp (seconds, ms or string) to a Date.
         *
         * @param {*} v Value.
         * @return {Date|null}
         */
        function toDate(v) {
                if (v === null || v === undefined || v === '') {
                        return null;
                }
                if (v instanceof Date) {
                        return v;
                }
                if (typeof v === 'number') {
                        return new Date(v < 1000000000000 ? v * 1000 : v);
                }
                var n = Number(v);
                if (isFinite(n) && n > 0) {
                        return new Date(n < 1000000000000 ? n * 1000 : n);
                }
                var d = new Date(String(v));
                return isNaN(d.getTime()) ? null : d;
        }

        /**
         * Persian medium date/short time formatting.
         *
         * @param {*} v Timestamp-ish value.
         * @return {string}
         */
        function fmtTime(v) {
                var d = toDate(v);
                if (!d) {
                        return '';
                }
                try {
                        return new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(d);
                } catch (e) {
                        return d.toISOString();
                }
        }

        /**
         * Array / object guards.
         *
         * @param {*} v Value.
         * @return {Array|Object}
         */
        function asArray(v) {
                return Array.isArray(v) ? v : [];
        }

        function asObject(v) {
                return (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
        }

        /**
         * localStorage get/set guarded (privacy modes throw).
         */
        function lsGet(key) {
                try {
                        return window.localStorage.getItem(key);
                } catch (e) {
                        return null;
                }
        }

        function lsSet(key, value) {
                try {
                        window.localStorage.setItem(key, String(value));
                } catch (e) {
                        /* ignore */
                }
        }

        /* ================================================================
         * AJAX plumbing
         * ================================================================ */

        /**
         * Pull a human-readable message out of any error-ish payload.
         *
         * @param {*} payload JSON body, raw text or object.
         * @param {number} status HTTP status.
         * @return {string}
         */
        function extractErrorMessage(payload, status) {
                var msg = '';
                var data;
                if (payload && typeof payload === 'object') {
                        data = payload.data;
                        if (data && typeof data === 'object' && data.message) {
                                msg = String(data.message);
                        } else if (typeof data === 'string' && data) {
                                msg = data;
                        } else if (payload.message) {
                                msg = String(payload.message);
                        }
                } else if (typeof payload === 'string') {
                        var text = payload.replace(/^\s+|\s+$/g, '');
                        if (text.charAt(0) === '{') {
                                try {
                                        return extractErrorMessage(JSON.parse(text), status);
                                } catch (e) {
                                        /* fall through */
                                }
                        } else if (text) {
                                msg = text.slice(0, 200);
                        }
                }
                if (!msg) {
                        msg = 'خطای ارتباط با سرور' + (status ? ' (کد ' + status + ')' : '');
                }
                return msg;
        }

        /**
         * Build the standard admin-ajax FormData.
         *
         * Array values are appended as repeated `key[]` entries so PHP
         * receives a real array (e.g. term_ids[]) instead of the string
         * FormData produces for a bare array ("3,8").
         *
         * @param {string} action Endpoint action.
         * @param {Object} params Extra params (skips null/undefined).
         * @return {FormData}
         */
        function buildForm(action, params) {
                var fd = new FormData();
                fd.append('action', String(action));
                fd.append('nonce', String(KIC.nonce || ''));
                if (params) {
                        Object.keys(params).forEach(function (key) {
                                var v = params[key];
                                if (v === undefined || v === null) {
                                        return;
                                }
                                if (Array.isArray(v)) {
                                        v.forEach(function (item) {
                                                fd.append(key + '[]', String(item));
                                        });
                                        return;
                                }
                                fd.append(key, v);
                        });
                }
                return fd;
        }

        /**
         * POST to admin-ajax and resolve with json.data on success.
         *
         * @param {string} action Endpoint action.
         * @param {Object} params Params.
         * @param {AbortSignal|null} signal Optional abort signal.
         * @return {Promise<Object>}
         */
        function post(action, params, signal) {
                return fetch(KIC.ajaxurl, {
                        method: 'POST',
                        body: buildForm(action, params),
                        credentials: 'same-origin',
                        signal: signal || undefined
                }).then(function (resp) {
                        if (!resp.ok) {
                                return resp.text().then(function (txt) {
                                        throw new Error(extractErrorMessage(txt, resp.status));
                                }).catch(function (err) {
                                        if (err instanceof Error && err.message) {
                                                throw err;
                                        }
                                        throw new Error(extractErrorMessage('', resp.status));
                                });
                        }
                        return resp.json().catch(function () {
                                throw new Error('پاسخ نامعتبر از سرور');
                        });
                }).then(function (json) {
                        if (!json || json.success !== true) {
                                throw new Error(extractErrorMessage(json, 0));
                        }
                        return json.data;
                });
        }

        /* ================================================================
         * State + DOM refs
         * ================================================================ */

        var state = {
                working: false,
                sessionBackups: [],
                collapsed: lsGet('kic_collapsed') === '1',
                /* v1.4 — publish tab: checklist + term/slug suggestions (click-to-apply). */
                prepublishChecks: [],
                prepublishWarnings: 0,
                termSuggestions: [],
                termChecked: {},
                slugSuggestion: null,
                /* v2.1 — internal-link suggestions (click-to-apply). */
                linkSuggestions: [],
                linkChecked: {},
                /* v2.3 — prompt-based AI edit of the CURRENT post (preview → apply). */
                aiEdit: { html: '', original: '', instruction: '' }
        };

        var els = {};

        /* ================================================================
         * Toasts
         * ================================================================ */

        function toastWrap() {
                if (els.toasts && els.toasts.isConnected) {
                        return els.toasts;
                }
                els.toasts = h('div', { class: 'kic-toasts', dir: 'rtl' });
                document.body.appendChild(els.toasts);
                return els.toasts;
        }

        /**
         * Show a toast. `message` may be a string or a DOM node.
         *
         * @param {string|Node} message Text or node.
         * @param {string} kind 'success'|'error'|'info'.
         * @param {number} ms Auto-dismiss delay.
         * @return {void}
         */
        function toast(message, kind, ms) {
                var card = h('div', { class: 'kic-toast kic-toast--' + (kind || 'info') });
                if (message && typeof message === 'object' && message.nodeType) {
                        card.appendChild(message);
                } else {
                        card.textContent = String(message === undefined || message === null ? '' : message);
                }
                toastWrap().appendChild(card);

                var gone = false;
                var dismiss = function () {
                        if (gone) {
                                return;
                        }
                        gone = true;
                        card.classList.add('kic-toast--out');
                        setTimeout(function () {
                                if (card.parentNode) {
                                        card.parentNode.removeChild(card);
                                }
                        }, 220);
                };
                card.addEventListener('click', dismiss);
                setTimeout(dismiss, ms || 4500);
        }

        /**
         * Toast containing a clickable external link.
         *
         * @param {string} prefix Leading text.
         * @param {string} linkText Link label.
         * @param {string} url Target URL.
         * @return {void}
         */
        function toastLink(prefix, linkText, url) {
                var frag = document.createDocumentFragment();
                frag.appendChild(document.createTextNode(prefix));
                frag.appendChild(h('a', {
                        class: 'kic-link',
                        href: url,
                        target: '_blank',
                        rel: 'noopener noreferrer',
                        text: linkText
                }));
                toast(frag, 'success', 8000);
        }

        /* ================================================================
         * Status / progress area
         * ================================================================ */

        var STATUS_LABELS = {
                idle: 'آماده',
                working: 'در حال کار',
                done: 'انجام شد',
                error: 'خطا'
        };

        function setStatus(kind) {
                if (els.badge) {
                        els.badge.className = 'kic-status kic-status--' + kind;
                        els.badge.textContent = STATUS_LABELS[kind] || kind;
                }
                if (els.spinner) {
                        els.spinner.classList.toggle('kic-hidden', kind !== 'working');
                }
        }

        function setStatusLine(text) {
                if (els.statusLine) {
                        els.statusLine.textContent = String(text || '');
                }
        }


        /* ================================================================
         * Widget skeleton
         * ================================================================ */

        function buildWidget() {
                var root = els.root;
                root.textContent = '';

                els.badge = h('span', { class: 'kic-status kic-status--idle', text: T('آماده') });
                els.collapseBtn = h('button', {
                        class: 'kic-collapse-btn',
                        type: 'button',
                        'aria-label': T('جمع‌کردن / باز‌کردن'),
                        title: T('جمع‌کردن / باز‌کردن'),
                        onclick: function () {
                                toggleCollapse();
                        }
                }, ['▾']);

                var header = h('div', { class: 'kic-header' }, [
                        h('span', { class: 'kic-title', text: 'Natilus' }),
                        h('span', { class: 'kic-header-spacer' }),
                        els.badge,
                        els.collapseBtn
                ]);

                var tabs = h('div', { class: 'kic-tabs', role: 'tablist' }, [
                        tabButton('content', T('محتوا')),
                        tabButton('media', T('رسانه')),
                        tabButton('publish', T('بررسی پیش از انتشار'))
                ]);

                var panels = h('div', { class: 'kic-panels' }, [
                        buildContentPanel(),
                        buildMediaPanel(),
                        buildPublishPanel()
                ]);

                els.body = h('div', { class: 'kic-body' }, [tabs, panels, buildFooter()]);
                root.appendChild(header);
                root.appendChild(els.body);

                if (state.collapsed) {
                        root.classList.add('kic-collapsed');
                }

                switchTab('content');
                refreshBackups();
                setStatus(state.working ? 'working' : 'idle');
        }

        function tabButton(name, label) {
                return h('button', {
                        class: 'kic-tab',
                        type: 'button',
                        role: 'tab',
                        'data-tab': name,
                        onclick: function () {
                                switchTab(name);
                        }
                }, [label]);
        }

        function switchTab(name) {
                if (!els.root) {
                        return;
                }
                var buttons = els.root.querySelectorAll('.kic-tabs .kic-tab');
                var i;
                for (i = 0; i < buttons.length; i++) {
                        var active = buttons[i].getAttribute('data-tab') === name;
                        buttons[i].classList.toggle('kic-tab--active', active);
                        buttons[i].setAttribute('aria-selected', active ? 'true' : 'false');
                }
                var panels = els.root.querySelectorAll('.kic-panels .kic-panel');
                for (i = 0; i < panels.length; i++) {
                        panels[i].classList.toggle('is-active', panels[i].getAttribute('data-panel') === name);
                }
        }

        /* ================================================================
         * Collapse
         * ================================================================ */

        function toggleCollapse(forceValue) {
                var collapsed = (forceValue !== undefined) ? !!forceValue : !state.collapsed;
                state.collapsed = collapsed;
                if (els.root) {
                        els.root.classList.toggle('kic-collapsed', collapsed);
                }
                lsSet('kic_collapsed', collapsed ? '1' : '0');
        }

        function ensureExpanded() {
                if (state.collapsed) {
                        toggleCollapse(false);
                }
        }

        /* ================================================================
         * Content tab
         * ================================================================ */

        function buildContentPanel() {
                var panel = h('div', { class: 'kic-panel', 'data-panel': 'content' });

                panel.appendChild(h('div', {
                        class: 'kic-notice',
                        text: T('محتوای آمادهٔ خود را بچسبانید و منتشر کنید — یا در بخش «تولید با پرامپت» موضوع را بنویسید تا پیش‌نویس هوش مصنوعی در همین باکس آماده شود. بدون پرامپت، متن شما دقیقاً همان‌گونه منتشر می‌شود.')
                }));

                /* Builder mode — where/how the content lands. */
                els.builderMode = h('select', { class: 'kic-select', id: 'kic-f-builder-mode' });
                buildBuilderModeOptions();

                /* Target. */
                var defaultTarget = (KIC.features && KIC.features.draft_default) ? 'draft_new' : 'append';
                els.target = h('select', { class: 'kic-select', id: 'kic-f-target', onchange: onTargetChange }, [
                        h('option', { value: 'append', text: 'افزودن به انتهای محتوا' }),
                        h('option', { value: 'replace', text: 'جایگزینی کامل محتوا' }),
                        h('option', { value: 'draft_new', text: 'ایجاد نوشتهٔ جدید' })
                ]);
                els.target.value = defaultTarget;

                /* Title / status apply to BOTH new and existing posts
                 * (empty title on an existing post = keep current title).
                 * Category / tags only apply to a NEW post. */
                els.title = h('input', {
                        type: 'text',
                        class: 'kic-input',
                        id: 'kic-f-title',
                        dir: 'auto',
                        placeholder: isNewPostContext() ? 'عنوان نوشتهٔ جدید' : 'عنوان (خالی = بدون تغییر)'
                });
                els.status = h('select', { class: 'kic-select', id: 'kic-f-status' }, [
                        h('option', { value: 'draft', text: 'پیش‌نویس' }),
                        h('option', { value: 'publish', text: 'انتشار فوری' })
                ]);
                els.cats = h('select', { class: 'kic-select', id: 'kic-f-cats' }, [
                        h('option', { value: '', text: '— بدون دسته —' })
                ]);
                asArray(KIC.categories).forEach(function (c) {
                        if (c && c.id) {
                                els.cats.appendChild(h('option', { value: String(c.id), text: String(c.title || c.id) }));
                        }
                });
                /* Post slug — user-editable; auto-filled from the title or
                 * the English-slug translation checkbox during organize. */
                els.slug = h('input', {
                        type: 'text',
                        class: 'kic-input kic-ltr',
                        id: 'kic-f-slug',
                        dir: 'ltr',
                        placeholder: 'my-post-slug'
                });

                /* v2.2 — the full headline set: روتیتر (kicker, above the
                 * title) and زیرتیتر (subtitle, below the title) — optional,
                 * synced with the active theme's own fields. */
                els.kicker = h('input', {
                        type: 'text',
                        class: 'kic-input',
                        id: 'kic-f-kicker',
                        dir: 'auto',
                        placeholder: T('روتیتر — سرتیتر کوچک بالای عنوان (اختیاری)')
                });
                els.subtitle = h('input', {
                        type: 'text',
                        class: 'kic-input',
                        id: 'kic-f-subtitle',
                        dir: 'auto',
                        placeholder: T('زیرتیتر — توضیح کوتاه زیر عنوان (اختیاری)')
                });

                var headlineGrid = h('div', { class: 'kic-grid' }, [
                        field('kic-f-kicker', T('روتیتر'), els.kicker),
                        field('kic-f-subtitle', T('زیرتیتر'), els.subtitle)
                ]);

                /* One muted line explaining how the site's own theme will
                 * receive the headline set (server-built hint). */
                var themeSyncHint = '';
                if (KIC.themeSync && KIC.themeSync.hint) {
                        themeSyncHint = h('div', { class: 'kic-themesync-hint', text: String(KIC.themeSync.hint) });
                }

                /* Title + status are always visible (they apply to existing posts too). */
                var titleStatusGrid = h('div', { class: 'kic-grid' }, [
                        field('kic-f-title', 'عنوان', els.title),
                        field('kic-f-status', 'وضعیت انتشار', els.status)
                ]);

                var newPostGrid = h('div', { class: 'kic-grid' }, [
                        field('kic-f-cats', 'دسته (نوشتهٔ جدید)', els.cats),
                        field('kic-f-slug', 'نامک (URL)', els.slug)
                ]);
                els.newPostBox = h('div', { class: 'kic-newpost' }, [newPostGrid]);
                if (els.target.value !== 'draft_new') {
                        els.newPostBox.classList.add('kic-hidden');
                }

                var engine = h('div', { class: 'kic-grid kic-engine-row' }, [
                        field('kic-f-target', 'مقصد درج', els.target),
                        field('kic-f-builder-mode', 'حالت صفحه‌ساز', els.builderMode)
                ]);

                /* The COMPLETE content — pasted by the author, published as-is. */
                els.raw = h('textarea', {
                        class: 'kic-textarea',
                        id: 'kic-f-raw',
                        rows: '12',
                        dir: 'auto',
                        placeholder: 'محتوای کامل نوشتهٔ آماده (HTML یا متن) را اینجا بچسبانید… Natilus آن را دقیقاً همان‌گونه — بدون هیچ بازنویسی — با رسانه، سئو و ساختار صفحه‌ساز در وردپرس منتشر می‌کند. متن سادهٔ بدون تگ فقط پاراگراف‌بندی استاندارد می‌گیرد.'
                });
                var rawField = field('kic-f-raw', 'محتوای کامل نوشته', els.raw);

                /* v2.4 — optional prompt-based generation: fills the SAME
                 * raw box + metadata fields; the user reviews then publishes
                 * through the normal button. Nothing is auto-published. */
                var genBox = buildGenerateSection();

                /* Optional SEO quick fields. */
                els.seoQuickTitle = h('input', {
                        type: 'text',
                        class: 'kic-input',
                        id: 'kic-seo-quick-title',
                        dir: 'auto',
                        placeholder: 'عنوان سئو (اختیاری)'
                });
                els.seoQuickDesc = h('textarea', {
                        class: 'kic-textarea',
                        id: 'kic-seo-quick-desc',
                        rows: '2',
                        dir: 'auto',
                        placeholder: 'توضیحات متا برای Yoast / Rank Math (اختیاری)'
                });
                els.seoFocusKeyword = h('input', {
                        type: 'text',
                        class: 'kic-input',
                        id: 'kic-seo-focus-keyword',
                        dir: 'auto',
                        placeholder: 'کلیدواژهٔ کانونی (اختیاری)'
                });
                var seoBox = h('details', { class: 'kic-imgpanel' }, [
                        h('summary', { text: 'سئو (اختیاری)' }),
                        h('div', { class: 'kic-imgpanel-body' }, [
                                field('kic-seo-quick-title', 'عنوان سئو', els.seoQuickTitle),
                                field('kic-seo-quick-desc', 'توضیحات متا', els.seoQuickDesc),
                                field('kic-seo-focus-keyword', 'کلیدواژهٔ کانونی', els.seoFocusKeyword)
                        ])
                ]);

                /* Checkboxes. */
                els.gallery = h('input', { type: 'checkbox', class: 'kic-check', id: 'kic-f-gallery', value: '1' });
                els.featured = h('input', { type: 'checkbox', class: 'kic-check', id: 'kic-f-featured', value: '1' });
                var checks = h('div', { class: 'kic-checks' }, [
                        checkRow(els.gallery, 'ساخت گالری از رسانه‌ها'),
                        checkRow(els.featured, 'تنظیم تصویر شاخص')
                ]);

                /* Buttons. */
                els.btnPublish = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: publishReady
                }, ['انتشار محتوا']);

                var actions = h('div', { class: 'kic-actions' }, [els.btnPublish]);

                /* Progress area. */
                els.spinner = h('span', { class: 'kic-spinner kic-hidden', 'aria-hidden': 'true' });
                els.statusLine = h('span', { class: 'kic-statusline-text', text: 'آمادهٔ انتشار.' });
                var progress = h('div', { class: 'kic-progress' }, [
                        h('div', { class: 'kic-statusline' }, [els.spinner, els.statusLine])
                ]);

                panel.appendChild(engine);
                panel.appendChild(headlineGrid);
                if (themeSyncHint) {
                        panel.appendChild(themeSyncHint);
                }
                panel.appendChild(titleStatusGrid);
                panel.appendChild(els.newPostBox);
                panel.appendChild(rawField);
                if (genBox) {
                        panel.appendChild(genBox);
                }
                panel.appendChild(seoBox);

                /* v2.3 — prompt-based edit of the CURRENT (saved) post. */
                var aiEditBox = buildAiEditSection();
                if (aiEditBox) {
                        panel.appendChild(aiEditBox);
                }

                panel.appendChild(checks);
                panel.appendChild(actions);
                panel.appendChild(progress);
                return panel;
        }

        /**
         * True when the widget lives on a brand-new (unsaved) post screen —
         * used only to pick friendlier placeholders.
         *
         * @return {boolean}
         */
        function isNewPostContext() {
                return !KIC.postId;
        }

        /**
         * Show/hide the new-post fields (category, tags) depending on the
         * selected insertion target. Title + status stay visible for both
         * new and existing posts.
         *
         * @return {void}
         */
        function onTargetChange() {
                if (!els.newPostBox) {
                        return;
                }
                var isNew = els.target.value === 'draft_new';
                els.newPostBox.classList.toggle('kic-hidden', !isNew);
                if (els.title) {
                        els.title.placeholder = isNew || isNewPostContext()
                                ? 'عنوان نوشتهٔ جدید'
                                : 'عنوان (خالی = بدون تغییر)';
                }
        }

        /**
         * Publisher mode (v1.4): publish the READY content exactly as pasted —
         * kic_publish_ready never calls an AI provider and never rewrites a word.
         *
         * @return {void}
         */
        async function publishReady() {
                if (state.working) {
                        return;
                }
                var content = els.raw ? els.raw.value : '';
                if (!content.trim()) {
                        if (els.genPrompt && (els.genPrompt.value || '').trim()) {
                                toast('پرامپت نوشته‌اید اما هنوز تولید نشده — روی «چینش با هوش مصنوعی» بزنید یا متن را در باکس محتوا بچسبانید.', 'info');
                        } else {
                                toast('محتوای نوشته خالی است — متن را در باکس محتوا بچسبانید یا با پرامپت پیش‌نویس بسازید.', 'info');
                        }
                        (els.genPrompt && (els.genPrompt.value || '').trim() ? els.genPrompt : els.raw).focus();
                        return;
                }

                var target = els.target.value || 'append';
                var isNew = target === 'draft_new';
                var mediaUrls = mediaLines();

                state.working = true;
                setStatus('working');
                setStatusLine('در حال انتشار محتوا…');
                busy(els.btnPublish, 'در حال انتشار…');

                try {
                        var data = await post('kic_publish_ready', {
                                post_id: KIC.postId,
                                target: target,
                                content: content,
                                /* Diagnostic: the browser-side length. When
                                 * the server receives empty content while this
                                 * is > 0, a WAF/host filter stripped it. */
                                content_len: String(content.length),
                                title: els.title ? els.title.value : '',
                                kicker: els.kicker ? els.kicker.value : '',
                                subtitle: els.subtitle ? els.subtitle.value : '',
                                status: els.status ? els.status.value : 'draft',
                                builder_mode: (els.builderMode && els.builderMode.value) || 'auto',
                                editor: KIC.editor === 'gutenberg' ? 'gutenberg' : 'classic',
                                gallery: els.gallery && els.gallery.checked ? 1 : '',
                                featured: els.featured && els.featured.checked ? 1 : '',
                                featured_url: els.featuredUrl ? String(els.featuredUrl) : '',
                                media_urls: mediaUrls.join('\n'),
                                cats: isNew && els.cats ? (els.cats.value || '') : '',
                                tags: '',
                                slug: isNew && els.slug ? (els.slug.value || '') : '',
                                seo: JSON.stringify({
                                        seo_title: els.seoQuickTitle ? els.seoQuickTitle.value : '',
                                        seo_description: els.seoQuickDesc ? els.seoQuickDesc.value : '',
                                        focus_keyword: els.seoFocusKeyword ? els.seoFocusKeyword.value : ''
                                })
                        });

                        handlePublishSuccess(data, target);
                } catch (err) {
                        setStatus('idle');
                        setStatusLine('خطا در انتشار محتوا.');
                        toast(err.message || 'انتشار محتوا ناموفق بود.', 'error');
                } finally {
                        state.working = false;
                        unbusy(els.btnPublish);
                }
        }

        /**
         * Success branch of the direct-publish flow.
         *
         * @param {Object} data Response data.
         * @param {string} target Insertion target (append|replace|draft_new).
         * @return {void}
         */
        function handlePublishSuccess(data, target) {
                setStatus('done');
                setStatusLine('انتشار کامل شد.');
                var result = (data && data.result) ? data.result : data;

                if (KIC.editor === 'elementor' || KIC.context === 'elementor') {
                        toast('محتوا در ساختار المنتور ثبت شد — پیش‌نمایش تازه می‌شود…', 'success', 3200);
                        setTimeout(reloadElementorPreview, 600);
                        return;
                }

                if (target === 'draft_new' || !KIC.postId) {
                        if (data && data.edit_url) {
                                toastLink('نوشته ساخته شد — ', 'باز کردن', String(data.edit_url));
                        } else {
                                toast('نوشتهٔ جدید ساخته شد.', 'success');
                        }
                        resetPublishForm(true);
                        return;
                }

                /* Existing post (append / replace) — the editor on screen is stale. */
                if (KIC.editor === 'gutenberg') {
                        try {
                                if (window.wp && window.wp.data && window.wp.data.select('core/editor') && result && result.post_id) {
                                        window.wp.data.dispatch('core/editor').editEntityRecord(
                                                'postType', (window.wp.data.select('core/editor').getCurrentPostType && window.wp.data.select('core/editor').getCurrentPostType()) || 'post',
                                                result.post_id, {}
                                        );
                                }
                        } catch (e) {
                                /* fall through to reload */
                        }
                        toast('محتوا در نوشته ثبت شد — برای دیدن نتیجه صفحه تازه‌سازی می‌شود…', 'info', 2600);
                        setTimeout(function () {
                                window.location.reload();
                        }, 1400);
                } else {
                        toast('محتوا ثبت شد — در حال تازه‌سازی صفحه…', 'success');
                        setTimeout(function () {
                                window.location.reload();
                        }, 900);
                }
                resetPublishForm(false);
        }

        /**
         * Clear the pasted payload after a successful publish so the same
         * article cannot be pasted-published twice by accident.
         *
         * @param {boolean} fullAlsoNewPost Also clear the new-post fields.
         * @return {void}
         */
        function resetPublishForm(fullAlsoNewPost) {
                if (els.raw) {
                        els.raw.value = '';
                }
                if (els.seoQuickTitle) {
                        els.seoQuickTitle.value = '';
                }
                if (els.seoQuickDesc) {
                        els.seoQuickDesc.value = '';
                }
                if (els.seoFocusKeyword) {
                        els.seoFocusKeyword.value = '';
                }
                if (els.mediaUrls) {
                        els.mediaUrls.value = '';
                }
                if (fullAlsoNewPost) {
                        if (els.title) {
                                els.title.value = '';
                        }
                        if (els.kicker) {
                                els.kicker.value = '';
                        }
                        if (els.subtitle) {
                                els.subtitle.value = '';
                        }
                        if (els.slug) {
                                els.slug.value = '';
                        }
                }
                state.termSuggestions = [];
                state.termChecked = {};
                renderTermSuggestions();
                state.linkSuggestions = [];
                state.linkChecked = {};
                renderLinkSuggestions();
                state.prepublishChecks = [];
                renderPrepublishChecks();
        }

        /* ================================================================
         * AI edit (v2.3) — prompt-based edit of the CURRENT post.
         * The author writes ONLY the instruction; the saved article is
         * loaded server-side, edited by the model, PREVIEWED here and
         * applied only after an explicit click (with a backup first).
         * ================================================================ */

        /**
         * Whether the prompt-edit feature is usable on this screen.
         *
         * @return {boolean}
         */
        function aiEditAvailable() {
                return !!(KIC.aiEdit && KIC.aiEdit.enabled && KIC.aiEdit.configured);
        }

        /**
         * Build the optional AI edit section. Rendered ONLY on a saved,
         * non-builder post (nothing to edit otherwise); when no provider
         * is configured the box stays visible with a setup hint.
         *
         * @return {HTMLElement|null} null = do not render.
         */
        /* ================================================================
         * v2.4 — prompt-based generation (article + optional image)
         * ================================================================ */

        /**
         * Whether a usable provider is configured for generation.
         *
         * @return {boolean}
         */
        function genConfigured() {
                return !!(KIC.generate && KIC.generate.configured);
        }

        /**
         * One step chip for the generation flow header (۱ → ۲ → ۳).
         *
         * @param {string}  num    Step number (Persian digit).
         * @param {string}  label  Step label.
         * @param {boolean} active Whether this step is the current one.
         * @return {Element}
         */
        function genStepChip(num, label, active) {
                return h('span', {
                        class: 'kic-gen-chip' + (active ? ' is-active' : ''),
                        'data-gen-chip': num
                }, [h('b', { text: num }), document.createTextNode(' ' + label)]);
        }

        /**
         * Mark which generation step is current in the header chips.
         *
         * @param {number} step 1|2|3 (0 = reset).
         * @return {void}
         */
        function setGenStep(step) {
                if (!els.genBox) {
                        return;
                }
                var chips = els.genBox.querySelectorAll('[data-gen-chip]');
                for (var i = 0; i < chips.length; i++) {
                        var n = parseInt(chips[i].getAttribute('data-gen-chip'), 10);
                        chips[i].classList.toggle('is-active', n === step);
                        chips[i].classList.toggle('is-done', step > 0 && n < step);
                }
        }

        /**
         * The 3-step «تولید با هوش مصنوعی» flow — mirrors the reference
         * composer: (۱) give prompt/raw → (۲) editable preview →
         * (۳) confirm, then the NORMAL publish button is the only write path.
         *
         * @return {Element|null}
         */
        function buildGenerateSection() {
                if (!KIC.generate || !KIC.generate.enabled) {
                        return null;
                }

                var configured = genConfigured();

                els.genBox = h('div', { class: 'kic-genbox', id: 'kic-gen-box' }, [
                        h('div', { class: 'kic-gen-steps' }, [
                                genStepChip('۱', T('پرامپت / متن خام'), true),
                                genStepChip('۲', T('پیش‌نمایش'), false),
                                genStepChip('۳', T('تأیید و انتشار'), false)
                        ])
                ]);

                /* ---------- Step 1: prompt (+ optional image) ---------- */
                els.genPrompt = h('textarea', {
                        class: 'kic-textarea',
                        id: 'kic-f-gen-prompt',
                        rows: '4',
                        dir: 'auto',
                        placeholder: T('موضوع یا دستور مقاله را بنویسید — مثلاً: مقاله‌ای ۸۰۰ کلمه‌ای دربارهٔ فواید خواب کافی برای وبلاگ سلامت')
                });
                if (!configured) {
                        els.genPrompt.disabled = true;
                        els.genPrompt.placeholder = T('ابتدا یک ارائه‌دهنده در «تنظیمات Natilus» پیکربندی کنید.');
                }

                els.genImage = h('input', { type: 'checkbox', class: 'kic-check', id: 'kic-f-gen-image', value: '1' });
                if (!configured) {
                        els.genImage.disabled = true;
                }

                els.genSlugEn = h('input', { type: 'checkbox', class: 'kic-check', id: 'kic-f-gen-slug-en', value: '1', checked: true });

                els.btnGenerate = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: runGenerate
                }, [T('چینش با هوش مصنوعی')]);

                els.genStatus = h('span', { class: 'kic-statusline-text', text: '' });
                els.genSpinner = h('span', { class: 'kic-spinner kic-hidden', 'aria-hidden': 'true' });

                var hintText = configured
                        ? T('متن آمادهٔ خود را در باکس بالایی بچسبانید، سپس در این پرامپت بنویسید چه کاری لازم است (چینش، عنوان‌بندی، سئو…) — نتیجه در مرحلهٔ ۲ پیش‌نمایش می‌آید و تا کلیک «انتشار محتوا» منتشر نمی‌شود.')
                        : T('چینش با پرامپت به یک ارائه‌دهندهٔ هوش مصنوعی نیاز دارد — هر سرویس سازگار با OpenAI (یا سرور محلی مثل Ollama/LM Studio) در «تنظیمات Natilus» کافی است. انتشار محتوای آماده بدون آن هم کامل کار می‌کند.');
                var hint = h('div', { class: 'kic-note', text: hintText });

                els.genStep1 = h('div', { class: 'kic-gen-step is-active' }, [
                        h('div', { class: 'kic-gen-step-title' }, [
                                h('span', { class: 'kic-gen-stepnum', text: '۱' }),
                                h('span', { text: T('دستور چینش را بنویسید (اختیاری)') })
                        ]),
                        hint,
                        field('kic-f-gen-prompt', T('دستور (پرامپت)'), els.genPrompt),
                        h('div', { class: 'kic-checks' }, [
                                checkRow(els.genImage, T('تولید تصویر با هوش مصنوعی')),
                                checkRow(els.genSlugEn, T('نامک URL را به انگلیسی بساز'))
                        ]),
                        h('div', { class: 'kic-actions' }, configured ? [els.btnGenerate] : []),
                        h('div', { class: 'kic-statusline' }, [els.genSpinner, els.genStatus])
                ]);

                if (!configured) {
                        els.btnGenerate.classList.add('kic-hidden');
                }

                /* ---------- Step 2: preview (hidden until generated) ---- */
                els.genPreviewTitle = h('div', { class: 'kic-gen-preview-title', dir: 'auto' });
                els.genPreviewKicker = h('div', { class: 'kic-gen-preview-kicker', dir: 'auto' });
                els.genPreviewBody = h('div', { class: 'kic-gen-preview-body' });
                els.genMeta = h('div', { class: 'kic-gen-meta' });

                els.btnGenConfirm = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: confirmGenerate
                }, [T('تأیید — ادامه به انتشار')]);

                els.btnGenRegen = h('button', {
                        class: 'kic-btn kic-btn--ghost',
                        type: 'button',
                        onclick: backToGenStep1
                }, [T('تولید مجدد')]);

                els.genStep2 = h('div', { class: 'kic-gen-step kic-hidden' }, [
                        h('div', { class: 'kic-gen-step-title' }, [
                                h('span', { class: 'kic-gen-stepnum', text: '۲' }),
                                h('span', { text: T('پیش‌نمایش پیش از انتشار') })
                        ]),
                        h('p', { class: 'kic-gen-desc', text: T('پیش‌نویس تولیدشده در باکس «محتوای کامل نوشته» و فیلدهای عنوان/روتیتر/زیرتیتر/سئو قرار گرفته؛ هر بخش را اینجا مرور کنید، متن را در باکس پایین ویرایش کنید و بعد تأیید کنید.') }),
                        els.genPreviewTitle,
                        els.genPreviewKicker,
                        els.genPreviewBody,
                        els.genMeta,
                        h('div', { class: 'kic-actions' }, [els.btnGenConfirm, els.btnGenRegen])
                ]);

                els.genBox.appendChild(els.genStep1);
                els.genBox.appendChild(els.genStep2);
                return els.genBox;
        }

        /**
         * Show the generated article inside the step-2 preview card.
         * The HTML was already wp_kses_post-sanitized server-side.
         *
         * @param {Object} article Generated fields.
         * @return {void}
         */
        function showGenPreview(article) {
                els.genStep1.classList.add('kic-hidden');
                els.genStep2.classList.remove('kic-hidden');
                setGenStep(2);

                els.genPreviewTitle.textContent = article.title ? String(article.title) : T('(بدون عنوان — در فیلد عنوان بنویسید)');

                var kickerBits = [];
                if (article.kicker) { kickerBits.push(String(article.kicker)); }
                if (article.subtitle) { kickerBits.push(String(article.subtitle)); }
                els.genPreviewKicker.textContent = kickerBits.join(' — ');
                els.genPreviewKicker.classList.toggle('kic-hidden', kickerBits.length === 0);

                els.genPreviewBody.innerHTML = String(article.html || '');
                els.genPreviewBody.scrollTop = 0;

                var metaBits = [];
                if (article.focus_keyword) { metaBits.push(T('کلیدواژه') + ': ' + article.focus_keyword); }
                if (article.seo_title) { metaBits.push(T('عنوان سئو') + ': ' + article.seo_title); }
                if (article.model) { metaBits.push('model: ' + article.model); }
                els.genMeta.textContent = metaBits.join(' · ');

                if (els.genStep2.scrollIntoView) {
                        els.genStep2.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
        }

        /**
         * Step-2 «تأیید» — the draft already lives in the normal form fields;
         * this just returns the user to the publish button.
         *
         * @return {void}
         */
        function confirmGenerate() {
                els.genStep2.classList.add('kic-hidden');
                setGenStep(3);
                toast(T('پیش‌نویس تأیید شد — دکمهٔ «انتشار محتوا» را بزنید.'), 'success');
                if (els.btnPublish && els.btnPublish.scrollIntoView) {
                        els.btnPublish.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        els.btnPublish.classList.add('kic-flash');
                        setTimeout(function () { els.btnPublish.classList.remove('kic-flash'); }, 1600);
                }
        }

        /**
         * Step-2 «تولید مجدد» — back to the prompt with the text kept.
         *
         * @return {void}
         */
        function backToGenStep1() {
                els.genStep2.classList.add('kic-hidden');
                els.genStep1.classList.remove('kic-hidden');
                els.genStep1.classList.add('is-active');
                setGenStep(1);
                if (els.genPrompt) {
                        els.genPrompt.focus();
                }
        }

        /**
         * POST kic_generate — prompt only; the article comes back as fields
         * and lands in the SAME raw box the publisher reads. Read-only.
         *
         * @return {void}
         */
        async function runGenerate() {
                if (state.working || !els.genPrompt) {
                        return;
                }
                var prompt = (els.genPrompt.value || '').trim();
                var source = els.raw ? (els.raw.value || '') : '';
                if (source.trim().length < 20) {
                        toast(T('متن را در باکس «محتوای کامل نوشته» بچسبانید — Natilus فقط محتوای شما را می‌چیند و چیزی از خودش تولید نمی‌کند.'), 'info');
                        els.raw.focus();
                        return;
                }
                if (!prompt) {
                        prompt = T('این متن را در قالب یک مقالهٔ کامل بچیند — ساختار، عناوین و جریان را مرتب کن.');
                }

                state.working = true;
                busy(els.btnGenerate, T('در حال تولید…'));
                els.genSpinner.classList.remove('kic-hidden');
                els.genStatus.textContent = T('در حال تولید مقاله…');

                try {
                        var data = await post('kic_generate', {
                                prompt: prompt,
                                /* Pasted raw text, when present: the model
                                 * ORGANIZES it (چینش) instead of writing
                                 * from scratch — matches the reference UX. */
                                content: source,
                                builder_mode: (els.builderMode && els.builderMode.value) || 'auto'
                        });

                        var article = (data && data.article) ? data.article : data;
                        if (!article || !article.html) {
                                throw new Error(T('پاسخ تولید قابل استفاده نبود.'));
                        }

                        /* Fill the raw box — the publisher reads exactly this. */
                        els.raw.value = String(article.html);

                        /* Fill metadata fields; never clobber text the author
                         * already typed into a field. */
                        fillIfEmpty(els.title, article.title);
                        fillIfEmpty(els.kicker, article.kicker);
                        fillIfEmpty(els.subtitle, article.subtitle);
                        fillIfEmpty(els.seoQuickTitle, article.seo_title);
                        fillIfEmpty(els.seoQuickDesc, article.seo_description);
                        fillIfEmpty(els.seoFocusKeyword, article.focus_keyword);

                        /* Slug: English translation when the checkbox is on,
                         * otherwise WordPress derives it from the title. */
                        if (els.slug && article.slug_en && els.genSlugEn && els.genSlugEn.checked) {
                                els.slug.value = String(article.slug_en);
                        }

                        /* Step 2 — preview the draft before anything is
                         * published; the user confirms, then the normal
                         * «انتشار محتوا» button is the only write path. */
                        showGenPreview(article);
                        toast(T('پیش‌نویس تولید شد — در مرحلهٔ ۲ مرور و تأیید کنید.'), 'success');

                        /* Optional image generation (explicit checkbox only). */
                        if (els.genImage && els.genImage.checked) {
                                await runGenerateImage(prompt, article);
                        }
                } catch (err) {
                        els.genStatus.textContent = '';
                        toast(err.message || T('تولید مقاله ناموفق بود.'), 'error');
                } finally {
                        state.working = false;
                        els.genSpinner.classList.add('kic-hidden');
                        unbusy(els.btnGenerate);
                }
        }

        /**
         * POST kic_generate_image — append the returned URL to the media list
         * so the normal publish flow imports it (sideload → gallery/featured).
         *
         * @param {string} prompt  Original article prompt.
         * @param {Object} article Generated article fields.
         * @return {void}
         */
        async function runGenerateImage(prompt, article) {
                var topic = (article && article.title) ? String(article.title) : prompt;
                if (els.genMeta) {
                        els.genMeta.textContent = T('در حال تولید تصویر…');
                }
                try {
                        var data = await post('kic_generate_image', {
                                prompt: 'Cover image for a WordPress article titled: ' + topic + '. Topic: ' + prompt
                        });
                        var url = data && data.url ? String(data.url) : '';
                        if (!url) {
                                toast(T('تصویر تولید نشد — مقاله بدون تصویر ادامه می‌یابد.'), 'info');
                                return;
                        }
                        if (els.mediaUrls) {
                                var lines = els.mediaUrls.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
                                if (lines.indexOf(url) === -1) {
                                        lines.push(url);
                                        els.mediaUrls.value = lines.join('\n');
                                }
                        }
                        toast(T('تصویر تولید و به فهرست رسانه اضافه شد — هنگام انتشار وارد کتابخانه می‌شود.'), 'success');
                        if (els.genMeta && article) {
                                showGenPreview(article); // restore meta line after the «در حال تولید…» note
                        }
                } catch (err) {
                        toast((err.message || T('تولید تصویر ناموفق بود.')) + ' ' + T('مقاله بدون تصویر ادامه می‌یابد.'), 'info');
                }
        }

        /**
         * Set an input's value only when it is currently empty — generated
         * suggestions never overwrite author-typed text.
         *
         * @param {Element} el   Input/textarea.
         * @param {string}  text Suggested value.
         * @return {void}
         */
        function fillIfEmpty(el, text) {
                if (!el || !text) {
                        return;
                }
                var value = String(text).trim();
                if (value !== '' && String(el.value || '').trim() === '') {
                        el.value = value;
                }
        }

        function buildAiEditSection() {
                /* Cached/older runtime config without the v2.3 flags. */
                if (!KIC.aiEdit) {
                        return null;
                }
                /* Disabled by settings — hidden entirely. */
                if (!KIC.aiEdit.enabled) {
                        return null;
                }
                /* Nothing saved yet to edit — hide on the new-post screen. */
                if (!KIC.postId) {
                        return null;
                }
                /* Elementor posts render from their own data — editing
                 * post_content would be invisible; not offered. */
                if (KIC.isElementor) {
                        return null;
                }

                var configured = aiEditAvailable();

                els.aiPrompt = h('textarea', {
                        class: 'kic-textarea',
                        id: 'kic-f-ai-prompt',
                        rows: '3',
                        dir: 'auto',
                        placeholder: T('مثلاً: پاراگراف آخر را کوتاه کن — یا: تیتر دوم را جذاب‌تر بنویس')
                });
                if (!configured) {
                        els.aiPrompt.disabled = true;
                        els.aiPrompt.placeholder = T('ابتدا یک ارائه‌دهنده در «تنظیمات Natilus» پیکربندی کنید.');
                }

                els.btnAiEdit = h('button', {
                        class: 'kic-btn',
                        type: 'button',
                        onclick: runAiEdit
                }, [T('ویرایش کن (پیش‌نمایش)')]);

                els.aiPreviewBody = h('div', {
                        class: 'kic-aiedit-preview-body',
                        dir: 'auto',
                        'aria-live': 'polite'
                });

                els.aiMeta = h('div', { class: 'kic-aiedit-meta' });

                els.btnAiApply = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: applyAiEdit
                }, [T('اعمال روی نوشته')]);

                els.btnAiDiscard = h('button', {
                        class: 'kic-btn kic-btn--ghost',
                        type: 'button',
                        onclick: function () {
                                discardAiEdit(false);
                        }
                }, [T('انصراف')]);

                els.aiTabAfter = h('button', {
                        class: 'kic-aiedit-tab is-active',
                        type: 'button',
                        'aria-selected': 'true',
                        'data-ai-tab': 'after',
                        onclick: function () {
                                switchAiTab('after');
                        }
                }, [T('متن ویرایش‌شده')]);

                els.aiTabBefore = h('button', {
                        class: 'kic-aiedit-tab',
                        type: 'button',
                        'aria-selected': 'false',
                        'data-ai-tab': 'before',
                        onclick: function () {
                                switchAiTab('before');
                        }
                }, [T('متن فعلی')]);

                els.aiPreview = h('div', { class: 'kic-aiedit-preview kic-hidden', 'data-ai-tab': 'after' }, [
                        h('div', { class: 'kic-aiedit-tabs', role: 'tablist' }, [els.aiTabAfter, els.aiTabBefore]),
                        els.aiPreviewBody,
                        els.aiMeta,
                        h('div', { class: 'kic-actions' }, [els.btnAiApply, els.btnAiDiscard])
                ]);

                var hintText = configured
                        ? T('فقط دستور ویرایش را بنویسید — متن نوشته خودکار از خود نوشتهٔ ذخیره‌شده خوانده می‌شود؛ نتیجه قبل از اعمال پیش‌نمایش می‌شود و قبل از اعمال نسخهٔ پشتیبان گرفته می‌شود.')
                        : T('ویرایش با پرامپت به یک ارائه‌دهندهٔ هوش مصنوعی نیاز دارد — هر سرویس سازگار با OpenAI (یا سرور محلی مثل Ollama/LM Studio) در «تنظیمات Natilus» کافی است. انتشار محتوا بدون آن هم کامل کار می‌کند.');
                var hint = h('div', { class: 'kic-note', text: hintText });

                var body = h('div', { class: 'kic-imgpanel-body' }, [
                        hint,
                        field('kic-f-ai-prompt', T('دستور ویرایش (پرامپت)'), els.aiPrompt),
                        h('div', { class: 'kic-actions' }, configured ? [els.btnAiEdit] : []),
                        els.aiPreview
                ]);

                if (!configured) {
                        els.btnAiEdit.classList.add('kic-hidden');
                }

                return h('details', { class: 'kic-imgpanel kic-aiedit', id: 'kic-aiedit-box' }, [
                        h('summary', { text: T('ویرایش این نوشته با پرامپت (اختیاری — هوش مصنوعی)') }),
                        body
                ]);
        }

        /**
         * POST kic_ai_edit — instruction only; the article is loaded
         * server-side from the saved post. Read-only: nothing is written.
         *
         * @return {void}
         */
        async function runAiEdit() {
                if (state.working) {
                        return;
                }
                if (!KIC.postId) {
                        toast(T('ویرایش با پرامپت روی نوشتهٔ ذخیره‌شده کار می‌کند — ابتدا نوشته را ذخیره کنید.'), 'info');
                        return;
                }
                var instruction = els.aiPrompt ? els.aiPrompt.value : '';
                if (!String(instruction).trim()) {
                        toast(T('ابتدا دستور ویرایش را در باکس پرامپت بنویسید.'), 'info');
                        if (els.aiPrompt) {
                                els.aiPrompt.focus();
                        }
                        return;
                }

                state.working = true;
                setStatus('working');
                setStatusLine('در حال ویرایش با پرامپت…');
                busy(els.btnAiEdit, 'در حال ویرایش…');

                try {
                        var data = await post('kic_ai_edit', {
                                post_id: KIC.postId,
                                instruction: String(instruction)
                        });

                        state.aiEdit = {
                                html: data && data.html ? String(data.html) : '',
                                original: data && data.original ? String(data.original) : '',
                                instruction: data && data.instruction ? String(data.instruction) : String(instruction)
                        };

                        renderAiPreview(data);
                        setStatus('done');
                        setStatusLine('ویرایش آمادهٔ بررسی است.');
                        toast(T('ویرایش آماده شد — پیش‌نمایش را بررسی و در صورت تأیید اعمال کنید.'), 'success');
                } catch (err) {
                        setStatus('error');
                        setStatusLine('خطا در ویرایش.');
                        toast(err.message || T('ویرایش ناموفق بود.'), 'error');
                } finally {
                        state.working = false;
                        unbusy(els.btnAiEdit);
                }
        }

        /**
         * Render the preview area (tabs: edited / current) from a successful
         * kic_ai_edit response.
         *
         * @param {Object} data Response payload.
         * @return {void}
         */
        function renderAiPreview(data) {
                if (!els.aiPreview || !els.aiPreviewBody) {
                        return;
                }
                els.aiPreviewBody.innerHTML = state.aiEdit.html || '';
                switchAiTab('after');

                var bits = [];
                if (data && data.model) {
                        bits.push(T('مدل') + ': ' + String(data.model));
                }
                if (data && data.usage && data.usage.total_tokens) {
                        bits.push(fmtNum(data.usage.total_tokens) + ' ' + T('توکن'));
                }
                els.aiMeta.textContent = bits.join(' · ');

                els.aiPreview.classList.remove('kic-hidden');
                try {
                        els.aiPreview.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } catch (e) {
                        /* older browsers */
                }
        }

        /**
         * Toggle the preview tab (edited vs. current content).
         *
         * @param {string} which 'after' | 'before'.
         * @return {void}
         */
        function switchAiTab(which) {
                if (!els.aiPreview) {
                        return;
                }
                var isBefore = 'before' === which;
                els.aiPreview.setAttribute('data-ai-tab', isBefore ? 'before' : 'after');
                if (els.aiPreviewBody) {
                        els.aiPreviewBody.innerHTML = isBefore
                                ? (state.aiEdit.original || '')
                                : (state.aiEdit.html || '');
                }
                [els.aiTabAfter, els.aiTabBefore].forEach(function (btn) {
                        if (!btn) {
                                return;
                        }
                        var active = btn.getAttribute('data-ai-tab') === (isBefore ? 'before' : 'after');
                        btn.classList.toggle('is-active', active);
                        btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });
        }

        /**
         * POST kic_ai_edit_apply — write the PREVIEWED edit into the post.
         * A backup is taken server-side first (rollback works from the
         * widget footer). The on-screen editor is refreshed afterwards.
         *
         * @return {void}
         */
        async function applyAiEdit() {
                if (state.working) {
                        return;
                }
                if (!state.aiEdit.html) {
                        toast(T('ابتدا ویرایش را اجرا و پیش‌نمایش را ببینید.'), 'info');
                        return;
                }
                if (!window.confirm(T('ویرایش روی نوشته اعمال شود؟ قبل از اعمال نسخهٔ پشتیبان گرفته می‌شود و از پایین ویجت قابل بازگردانی است.'))) {
                        return;
                }

                state.working = true;
                setStatus('working');
                setStatusLine('در حال اعمال ویرایش…');
                busy(els.btnAiApply, 'در حال اعمال…');

                try {
                        var data = await post('kic_ai_edit_apply', {
                                post_id: KIC.postId,
                                html: state.aiEdit.html
                        });

                        setStatus('done');
                        setStatusLine('ویرایش اعمال شد.');

                        /* The new backup shows in the footer select immediately. */
                        if (data && data.backup_key) {
                                state.sessionBackups.unshift({
                                        key: String(data.backup_key),
                                        time: Math.floor(Date.now() / 1000)
                                });
                                refreshBackups();
                        }

                        /* Gutenberg: sync the on-screen editor to the saved
                         * state, then reload (existing-post pattern). */
                        if (KIC.editor === 'gutenberg' && window.wp && window.wp.data) {
                                try {
                                        var select = window.wp.data.select('core/editor');
                                        if (select && typeof select.getCurrentPostType === 'function') {
                                                window.wp.data.dispatch('core/editor').editEntityRecord(
                                                        'postType',
                                                        select.getCurrentPostType() || 'post',
                                                        KIC.postId,
                                                        { content: state.aiEdit.html }
                                                );
                                        }
                                } catch (e) {
                                        /* fall through to reload */
                                }
                        }

                        toast(T('ویرایش روی نوشته اعمال شد — صفحه تازه‌سازی می‌شود…'), 'success', 2600);
                        setTimeout(function () {
                                window.location.reload();
                        }, 1600);
                } catch (err) {
                        setStatus('error');
                        setStatusLine('خطا در اعمال ویرایش.');
                        toast(err.message || T('اعمال ویرایش ناموفق بود.'), 'error');
                        state.working = false;
                        unbusy(els.btnAiApply);
                }
        }

        /**
         * Drop the current preview (and the instruction on an explicit
         * cancel). Nothing was ever written — the post is untouched.
         *
         * @param {boolean} quiet Suppress the toast.
         * @return {void}
         */
        function discardAiEdit(quiet) {
                state.aiEdit = { html: '', original: '', instruction: '' };
                if (els.aiPreview) {
                        els.aiPreview.classList.add('kic-hidden');
                }
                if (els.aiPreviewBody) {
                        els.aiPreviewBody.innerHTML = '';
                }
                if (els.aiMeta) {
                        els.aiMeta.textContent = '';
                }
                if (!quiet) {
                        toast(T('پیش‌نمایش کنار گذاشته شد — نوشته دست‌نخورده است.'), 'info');
                }
        }

        /* ================================================================
         * Engine row helpers (v1.2) — provider / model / builder mode
         * ================================================================ */
        /**
         * Builder-mode select options from the server availability map.
         *
         * @return {void}
         */
        function buildBuilderModeOptions() {
                if (!els.builderMode) {
                        return;
                }
                var modes = asObject(KIC.builderModes);
                var defaultMode = String(KIC.builderDefault || 'auto');
                var any = false;

                els.builderMode.textContent = '';
                Object.keys(modes).forEach(function (key) {
                        var info = asObject(modes[key]);
                        if (info.available === false) { return; }
                        any = true;
                        els.builderMode.appendChild(h('option', { value: key, text: String(info.label || key) }));
                });
                if (!any) {
                        els.builderMode.appendChild(h('option', { value: 'auto', text: 'خودکار' }));
                }
                if (KIC.editor === 'elementor' || KIC.context === 'elementor') {
                        // The Elementor live panel composes for Elementor, period.
                        els.builderMode.value = 'elementor';
                        els.builderMode.disabled = true;
                        if (els.builderMode.querySelector('option[value="elementor"]')) {
                                els.builderMode.value = 'elementor';
                        }
                } else {
                        /* v2.4 — auto pick: Elementor posts → elementor; else
                         * classic editor → classic; else gutenberg. */
                        var autoPick = defaultMode;
                        if (KIC.isElementor && els.builderMode.querySelector('option[value="elementor"]')) {
                                autoPick = 'elementor';
                        } else if (KIC.editor === 'gutenberg' && els.builderMode.querySelector('option[value="gutenberg"]')) {
                                autoPick = 'gutenberg';
                        } else if (els.builderMode.querySelector('option[value="classic"]')) {
                                autoPick = 'classic';
                        }
                        els.builderMode.value = autoPick;
                }
        }

        /* ================================================================
         * Field helpers
         * ================================================================ */

        /**
         * Label + control wrapper.
         *
         * @param {string} id Control id (label `for`).
         * @param {string} label Label text.
         * @param {Node} control Control node.
         * @return {HTMLElement}
         */
        function field(id, label, control) {
                return h('div', { class: 'kic-field' }, [
                        h('label', { class: 'kic-label', for: id, text: label }),
                        control
                ]);
        }

        /**
         * Accessible checkbox row: returns the row, input passed separately so the
         * caller keeps a direct reference for `.checked` reads.
         *
         * @param {HTMLInputElement} input Checkbox input.
         * @param {string} label Label text.
         * @return {HTMLElement}
         */
        function checkRow(input, label) {
                return h('div', { class: 'kic-check-row' }, [
                        input,
                        h('label', { class: 'kic-check-label', for: input.id, text: label })
                ]);
        }

        /* ================================================================
         * Busy helpers
         * ================================================================ */

        function busy(btn, text) {
                if (!btn) {
                        return;
                }
                btn.dataset.kicLabel = btn.textContent;
                if (text) {
                        btn.textContent = text;
                }
                btn.disabled = true;
        }

        function unbusy(btn) {
                if (!btn) {
                        return;
                }
                if (btn.dataset.kicLabel) {
                        btn.textContent = btn.dataset.kicLabel;
                }
                btn.disabled = false;
        }

        /* ================================================================
         * Media URL list helpers
         * ================================================================ */

        function mediaLines() {
                if (!els.mediaUrls) {
                        return [];
                }
                return els.mediaUrls.value.split('\n').map(function (l) {
                        return l.trim();
                }).filter(function (l) {
                        return l !== '';
                });
        }


        /* ================================================================
         * Media tab
         * ================================================================ */

        function buildMediaPanel() {
                els.mediaUrls = h('textarea', {
                        class: 'kic-textarea kic-ltr',
                        id: 'kic-f-media-urls',
                        rows: '6',
                        dir: 'ltr',
                        placeholder: 'https://example.com/photo.jpg'
                });
                els.btnSideload = h('button', {
                        class: 'kic-btn kic-btn--ghost',
                        type: 'button',
                        onclick: sideloadMedia
                }, ['وارد کردن به کتابخانه رسانه']);

                var autoNote = h('div', { class: 'kic-note' });
                if (KIC.features && KIC.features.auto_sideload) {
                        autoNote.textContent = 'اگر «وارد کردن خودکار» فعال باشد، هنگام انتشار خودکار به کتابخانه رسانه منتقل و نشانی‌ها در محتوا جایگزین می‌شوند.';
                } else {
                        autoNote.textContent = 'وارد کردن خودکار رسانه‌ها غیرفعال است؛ برای انتقال به کتابخانه رسانه از دکمه بالا استفاده کنید.';
                }

                els.mediaGrid = h('div', { class: 'kic-img-grid' });
                els.featuredUrl = '';

                /* Live thumbnails: every URL line renders a preview thumb so
                 * the author SEES the images, picks the featured one (click)
                 * and can drop bad URLs (✕) before anything is imported. */
                els.mediaUrls.addEventListener('input', renderMediaThumbs);
                renderMediaThumbs();

                return h('div', { class: 'kic-panel', 'data-panel': 'media' }, [
                        field('kic-f-media-urls', 'آدرس رسانه‌ها (هر خط یک آدرس)', els.mediaUrls),
                        els.mediaGrid,
                        h('div', { class: 'kic-actions' }, [els.btnSideload]),
                        autoNote
                ]);
        }

        /**
         * Render thumbnail cards for each URL in the media list. Click =
         * set as featured image (stored in els.featuredUrl, auto-checks the
         * featured box); ✕ removes that line.
         *
         * @return {void}
         */
        function renderMediaThumbs() {
                if (!els.mediaGrid) {
                        return;
                }
                els.mediaGrid.textContent = '';
                var urls = mediaLines().filter(function (u) {
                        return /^https?:\/\//i.test(u);
                });
                urls.forEach(function (url) {
                        var isFeat = els.featuredUrl === url;
                        var img = h('img', {
                                src: url,
                                alt: '',
                                onerror: function () { card.style.display = 'none'; }
                        });
                        var badge = h('span', {
                                class: 'kic-img-badge',
                                text: isFeat ? 'شاخص ✓' : 'شاخص',
                                onclick: function () {
                                        els.featuredUrl = (els.featuredUrl === url) ? '' : url;
                                        if (els.featuredUrl !== '' && els.featured) {
                                                els.featured.checked = true;
                                        }
                                        renderMediaThumbs();
                                }
                        });
                        var remove = h('button', {
                                class: 'kic-img-remove',
                                type: 'button',
                                text: '✕',
                                title: 'حذف از فهرست',
                                onclick: function () {
                                        var keep = mediaLines().filter(function (l) { return l !== url; });
                                        els.mediaUrls.value = keep.join('\n');
                                        if (els.featuredUrl === url) { els.featuredUrl = ''; }
                                        renderMediaThumbs();
                                }
                        });
                        var card = h('div', {
                                class: 'kic-img-thumb' + (isFeat ? ' is-featured' : ''),
                                title: url
                        }, [img, badge, remove]);
                        els.mediaGrid.appendChild(card);
                });
        }

        /**
         * POST kic_sideload and replace textarea lines with local URLs.
         *
         * @return {void}
         */
        function sideloadMedia() {
                var urls = mediaLines();
                if (!urls.length) {
                        toast('ابتدا آدرس‌ها را وارد کنید.', 'info');
                        els.mediaUrls.focus();
                        return;
                }
                busy(els.btnSideload, 'در حال انتقال…');
                post('kic_sideload', {
                        urls: JSON.stringify(urls),
                        post_id: KIC.postId
                }).then(function (data) {
                        var items = asArray(data && data.items);
                        var locals = items.map(function (it) {
                                return it && it.url ? String(it.url) : '';
                        }).filter(function (u) {
                                return u !== '';
                        });
                        els.mediaUrls.value = locals.join('\n');
                        toast(fmtNum(items.length) + ' رسانه وارد شد.', 'success');
                }).catch(function (err) {
                        toast(err.message || 'انتقال رسانه ناموفق بود.', 'error');
                }).then(function () {
                        unbusy(els.btnSideload);
                });
        }

        /* ================================================================
         * Footer: backups + rollback
         * ================================================================ */

        function buildFooter() {
                els.backupSelect = h('select', { class: 'kic-select kic-backup-select', id: 'kic-f-backup' });
                els.btnRollback = h('button', {
                        class: 'kic-btn kic-btn--danger',
                        type: 'button',
                        onclick: doRollback
                }, ['بازگردانی نسخه پشتیبان']);
                return h('div', { class: 'kic-footer' }, [
                        h('span', { class: 'kic-footer-label', text: 'بازگردانی نسخه پشتیبان:' }),
                        els.backupSelect,
                        els.btnRollback
                ]);
        }

        /**
         * Merge server backups + this-session backups into the select.
         *
         * @return {void}
         */
        function refreshBackups() {
                if (!els.backupSelect) {
                        return;
                }
                els.backupSelect.textContent = '';
                var seen = {};
                var rows = [];
                state.sessionBackups.forEach(function (b) {
                        if (b.key && !seen[b.key]) {
                                seen[b.key] = true;
                                rows.push(b);
                        }
                });
                asArray(KIC.backups).forEach(function (b) {
                        if (b && b.key && !seen[b.key]) {
                                seen[b.key] = true;
                                rows.push(b);
                        }
                });
                if (!rows.length) {
                        els.backupSelect.appendChild(h('option', { value: '', text: '— پشتیبان موجود نیست —' }));
                        els.backupSelect.disabled = true;
                        if (els.btnRollback) {
                                els.btnRollback.disabled = true;
                        }
                        return;
                }
                els.backupSelect.disabled = false;
                if (els.btnRollback) {
                        els.btnRollback.disabled = false;
                }
                rows.forEach(function (b) {
                        var when = fmtTime(b.time);
                        var label = when ? 'پشتیبان ' + when : 'پشتیبان ' + String(b.key);
                        els.backupSelect.appendChild(h('option', { value: String(b.key), text: label }));
                });
        }

        /**
         * POST kic_rollback then reload the editor.
         *
         * @return {void}
         */
        function doRollback() {
                var key = els.backupSelect.value;
                if (!key) {
                        toast('نسخه‌ای برای بازگردانی انتخاب نشده است.', 'info');
                        return;
                }
                if (!window.confirm('این نسخه پشتیبان بازگردانی شود؟ محتوای فعلی نوشته جایگزین می‌شود.')) {
                        return;
                }
                busy(els.btnRollback, 'در حال بازگردانی…');
                post('kic_rollback', { post_id: KIC.postId, key: key }).then(function () {
                        toast('بازگردانی انجام شد — در حال تازه‌سازی…', 'success');
                        setTimeout(function () {
                                window.location.reload();
                        }, 800);
                }).catch(function (err) {
                        toast(err.message || 'بازگردانی ناموفق بود.', 'error');
                        unbusy(els.btnRollback);
                });
        }

        /**
         * Current post content (Gutenberg or Classic), null if unavailable.
         * Only used by the OPTIONAL suggestion helpers.
         *
         * @return {string|null}
         */
        function getCurrentContent() {
                try {
                        if (KIC.editor === 'gutenberg' && window.wp && window.wp.data) {
                                var editorSelect = window.wp.data.select('core/editor');
                                if (editorSelect && typeof editorSelect.getEditedPostContent === 'function') {
                                        var content = editorSelect.getEditedPostContent();
                                        if (typeof content === 'string') {
                                                return content;
                                        }
                                }
                        }
                } catch (e) {
                        /* fall through */
                }
                try {
                        var ed = getTinymceEditor();
                        if (ed && typeof ed.getContent === 'function') {
                                return ed.getContent() || '';
                        }
                } catch (e) {
                        /* fall through */
                }
                return null;
        }

        /**
         * TinyMCE classic editor handle (guarded).
         *
         * @return {Object|null}
         */
        function getTinymceEditor() {
                try {
                        if (window.tinymce) {
                                var ed = window.tinymce.get('content') || window.tinymce.activeEditor;
                                if (ed) {
                                        return ed;
                                }
                        }
                } catch (e) {
                        /* ignore */
                }
                return null;
        }

        /**
         * Current editor content, or '' when unavailable (for link calls).
         *
         * @return {string}
         */
        function linkSourceContent() {
                var c = getCurrentContent();
                return (c === null) ? '' : String(c);
        }

        /* ================================================================
         * Publish tab (v1.4): checklist + term/slug suggestions.
         * Everything is click-to-apply — nothing auto-writes.
         * ================================================================ */

        /**
         * Current edited title (Gutenberg first, classic input fallback).
         *
         * @return {string}
         */
        function getCurrentTitle() {
                var title = '';
                try {
                        if (KIC.editor === 'gutenberg' && window.wp && window.wp.data) {
                                title = String(window.wp.data.select('core/editor').getEditedPostAttribute('title') || '');
                        }
                } catch (e) {
                        /* ignore */
                }
                if (!title) {
                        var input = document.getElementById('title');
                        if (input) {
                                title = input.value || '';
                        }
                }
                return title;
        }

        /**
         * Run the read-only pre-publish checks and render the result list.
         *
         * @return {void}
         */
        function runPrepublishCheck() {
                if (!KIC.postId) {
                        toast('ابتدا نوشته را ذخیره کنید تا بررسی پیش از انتشار ممکن شود.', 'info');
                        return;
                }
                var content = linkSourceContent();
                busy(els.prepublishBtn, T('در حال انجام…'));
                post('kic_prepublish_check', {
                        post_id: KIC.postId,
                        content: content || ''
                }).then(function (data) {
                        state.prepublishChecks = asArray(data && data.checks);
                        state.prepublishWarnings = Number(data && data.warnings) || 0;
                        renderPrepublishChecks();
                        toast(state.prepublishWarnings
                                ? fmtNum(state.prepublishWarnings) + ' مورد نیاز بررسی دارد — هیچ چیز خودکار تغییر نمی‌کند.'
                                : T('بررسی‌های فنی همه سبزند.'), state.prepublishWarnings ? 'info' : 'success', 4200);
                }).catch(function (err) {
                        toast(err.message || 'بررسی پیش از انتشار ناموفق بود.', 'error');
                }).then(function () {
                        unbusy(els.prepublishBtn);
                });
        }

        /**
         * Render the checklist: ok / warn / n-a rows with labels + details.
         *
         * @return {void}
         */
        function renderPrepublishChecks() {
                if (!els.prepublishList) {
                        return;
                }
                els.prepublishList.textContent = '';
                if (!state.prepublishChecks.length) {
                        els.prepublishList.appendChild(h('div', { class: 'kic-notice', text: T('همهٔ بررسی‌ها انجام شد.') }));
                        return;
                }
                state.prepublishChecks.forEach(function (check) {
                        var ok = String(check.status) === 'ok';
                        var na = String(check.status) === 'na';
                        var mark = na ? '—' : (ok ? '✓' : '!');
                        var cls = 'kic-check kic-check--' + (na ? 'na' : (ok ? 'ok' : 'warn'));
                        els.prepublishList.appendChild(h('div', { class: cls }, [
                                h('span', { class: 'kic-check-mark', text: mark }),
                                h('span', { class: 'kic-check-label', text: String(check.label || '') }),
                                check && check.detail ? h('span', { class: 'kic-check-detail', text: String(check.detail) }) : null
                        ]));
                });
        }

        /**
         * AI term suggestions (existing categories/tags only — the ids are
         * whitelisted server-side; nothing is applied without a click).
         *
         * @return {void}
         */
        function runTermSuggest() {
                if (!KIC.postId) {
                        toast('برای پیشنهاد دسته و برچسب، ابتدا نوشته را ذخیره کنید.', 'info');
                        return;
                }
                var content = linkSourceContent();
                var title = getCurrentTitle();
                if (!content && !title) {
                        toast('محتوایی برای تحلیل در دسترس نیست.', 'info');
                        return;
                }
                busy(els.termSuggestBtn, T('در حال انجام…'));
                post('kic_suggest_terms', {
                        post_id: KIC.postId,
                        content: content || '',
                        title: title
                }).then(function (data) {
                        state.termSuggestions = asArray(data && data.suggestions);
                        state.termChecked = {};
                        renderTermSuggestions();
                        toast(state.termSuggestions.length
                                ? fmtNum(state.termSuggestions.length) + ' پیشنهاد آماده است — مورد موردنظر را انتخاب کنید.'
                                : 'پیشنهادی از میان دسته‌ها و برچسب‌های موجود یافت نشد.',
                                state.termSuggestions.length ? 'success' : 'info', 3600);
                }).catch(function (err) {
                        toast(err.message || 'پیشنهاد دسته/برچسب ناموفق بود.', 'error');
                }).then(function () {
                        unbusy(els.termSuggestBtn);
                });
        }

        /**
         * Render term suggestion rows with checkboxes.
         *
         * @return {void}
         */
        function renderTermSuggestions() {
                if (!els.termSuggestList) {
                        return;
                }
                els.termSuggestList.textContent = '';
                if (!state.termSuggestions.length) {
                        els.termSuggestList.appendChild(h('div', { class: 'kic-notice', text: 'پیشنهادی دریافت نشده است.' }));
                        els.termApplyBtn.classList.add('kic-hidden');
                        return;
                }
                els.termApplyBtn.classList.remove('kic-hidden');

                var taxLabels = { 'category': 'دسته', 'post_tag': 'برچسب', 'product_cat': 'دستهٔ محصول' };

                state.termSuggestions.forEach(function (item) {
                        var id = Number(item.id);
                        var box = h('input', {
                                type: 'checkbox',
                                id: 'kic-term-' + id,
                                checked: !!state.termChecked[id],
                                onchange: function () {
                                        state.termChecked[id] = box.checked;
                                }
                        });
                        els.termSuggestList.appendChild(h('label', { class: 'kic-checkrow', for: 'kic-term-' + id }, [
                                box,
                                h('span', { class: 'kic-checkrow-tax', text: String(taxLabels[item.taxonomy] || item.taxonomy || '') }),
                                h('span', { class: 'kic-checkrow-name', text: String(item.name || '') })
                        ]));
                });
        }

        /**
         * Apply the user-checked term ids to the post (explicit click only).
         *
         * @return {void}
         */
        function applyTermSelection() {
                if (!KIC.postId) {
                        return;
                }
                var ids = [];
                state.termSuggestions.forEach(function (item) {
                        var id = Number(item.id);
                        if (state.termChecked[id] && ids.indexOf(id) === -1) {
                                ids.push(id);
                        }
                });
                if (!ids.length) {
                        toast('چیزی برای اعمال انتخاب نشده است.', 'info');
                        return;
                }
                busy(els.termApplyBtn, T('در حال انجام…'));
                post('kic_apply_terms', {
                        post_id: KIC.postId,
                        term_ids: ids
                }).then(function (data) {
                        var applied = asArray(data && data.applied);
                        toast(fmtNum(applied.length) + ' مورد روی نوشته اعمال شد.', 'success');
                        state.termSuggestions = [];
                        state.termChecked = {};
                        renderTermSuggestions();
                        if (KIC.editor === 'gutenberg' && window.wp && window.wp.data) {
                                try {
                                        window.wp.data.dispatch('core/editor').savePost();
                                } catch (e) {
                                        /* ignore */
                                }
                        }
                }).catch(function (err) {
                        toast(err.message || 'اعمال انتخاب‌ها ناموفق بود.', 'error');
                }).then(function () {
                        unbusy(els.termApplyBtn);
                });
        }

        /**
         * English slug suggestion from the current (user-approved) title.
         *
         * @return {void}
         */
        function runSlugSuggest() {
                var title = getCurrentTitle();
                if (!title) {
                        toast('ابتدا تیتر نوشته را تعیین کنید (نامک از تیتر ساخته می‌شود).', 'info');
                        return;
                }
                busy(els.slugSuggestBtn, T('در حال انجام…'));
                post('kic_suggest_slug', {
                        post_id: KIC.postId,
                        title: title
                }).then(function (data) {
                        state.slugSuggestion = {
                                slug: String(data && data.slug || ''),
                                taken: !!(data && data.taken),
                                current: String(data && data.current || '')
                        };
                        renderSlugSuggestion();
                }).catch(function (err) {
                        toast(err.message || 'پیشنهاد نامک ناموفق بود.', 'error');
                }).then(function () {
                        unbusy(els.slugSuggestBtn);
                });
        }

        /**
         * Render the slug suggestion with a single "use" button.
         *
         * @return {void}
         */
        function renderSlugSuggestion() {
                if (!els.slugSuggestList) {
                        return;
                }
                els.slugSuggestList.textContent = '';
                if (!state.slugSuggestion || !state.slugSuggestion.slug) {
                        els.slugSuggestList.appendChild(h('div', { class: 'kic-notice', text: 'هنوز پیشنهادی دریافت نشده است.' }));
                        return;
                }
                var s = state.slugSuggestion;
                els.slugSuggestList.appendChild(h('div', { class: 'kic-linkcard kic-linkcard--title' }, [
                        h('div', { class: 'kic-linkcard-main' }, [
                                h('span', { class: 'kic-linkcard-anchor', text: s.slug, dir: 'ltr' }),
                                s.taken ? h('span', { class: 'kic-check-detail', text: ' — ' + 'همین نامک قبلاً استفاده شده؛ وردپرس هنگام ذخیره شماره می‌گذارد.' }) : null,
                                (s.current && s.current !== s.slug) ? h('span', { class: 'kic-check-detail', text: ' — نامک فعلی: ' + s.current, dir: 'ltr' }) : null
                        ]),
                        h('div', { class: 'kic-linkcard-actions' }, [
                                h('button', {
                                        class: 'kic-btn kic-btn--sm kic-btn--primary',
                                        type: 'button',
                                        onclick: applySlugSuggestion
                                }, [T('اعمال')])
                        ])
                ]));
        }

        /**
         * Apply the suggested slug to the post (explicit click only).
         *
         * @return {void}
         */
        function applySlugSuggestion() {
                if (!state.slugSuggestion || !state.slugSuggestion.slug || !KIC.postId) {
                        return;
                }
                post('kic_apply_terms', {
                        post_id: KIC.postId,
                        slug: state.slugSuggestion.slug
                }).then(function (data) {
                        toast('نامک به‌روز شد: ' + String(data && data.slug || state.slugSuggestion.slug), 'success');
                        state.slugSuggestion = null;
                        renderSlugSuggestion();
                }).catch(function (err) {
                        toast(err.message || 'اعمال نامک ناموفق بود.', 'error');
                });
        }

        /* ================================================================
         * Internal-link suggestions (v2.1) — click-to-apply only.
         * ================================================================ */

        /**
         * Render the final post exactly like the site output — title,
         * kicker/subtitle, featured image and full body HTML — so the author
         * reviews the real result before publishing.
         *
         * @return {void}
         */
        function renderSitePreview() {
                if (!els.sitePreview) {
                        return;
                }
                var content = els.raw && els.raw.value ? els.raw.value.trim() : '';
                if (!content) {
                        toast('ابتدا محتوا را وارد کنید.', 'error');
                        return;
                }
                els.sitePreview.textContent = '';
                var feat = mediaLines().filter(function (u) { return /^https?:\/\//i.test(u); })[0];
                var slug = (els.slugField && els.slugField.value) || '';
                var children = [];

                if (feat && els.featured && els.featured.checked) {
                        children.push(h('img', { src: feat, class: 'kic-prev-hero' }));
                }
                if (els.kicker && els.kicker.value.trim()) {
                        children.push(h('div', { class: 'kic-preview-kicker', text: els.kicker.value.trim() }));
                }
                children.push(h('h1', { class: 'kic-preview-title', text: (els.title && els.title.value.trim()) || T('بدون عنوان') }));
                if (els.subtitle && els.subtitle.value.trim()) {
                        children.push(h('p', { class: 'kic-preview-sub', text: els.subtitle.value.trim() }));
                }
                if (slug) {
                        children.push(h('div', {
                                class: 'kic-muted',
                                text: 'نامک: ' + slug,
                                style: 'font-family:monospace;direction:ltr;text-align:right;font-size:11px'
                        }));
                }
                var body = h('div', { class: 'kic-preview-article' });
                body.innerHTML = content; // kses'd server-side on publish; preview mirrors editor content
                children.push(body);

                var card = h('div', { class: 'kic-preview' }, children);
                els.sitePreview.appendChild(card);
                els.sitePreview.classList.remove('kic-hidden');
                els.sitePreview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        /**
         * Request internal-link suggestions for the current content.
         *
         * @return {void}
         */
        function runLinkSuggest() {
                if (!KIC.postId) {
                        toast('برای پیشنهاد لینک داخلی، ابتدا نوشته را ذخیره کنید.', 'info');
                        return;
                }
                var content = linkSourceContent();
                var title = getCurrentTitle();
                if (!content && !title) {
                        toast('محتوایی برای تحلیل در دسترس نیست.', 'info');
                        return;
                }
                busy(els.linkSuggestBtn, T('در حال انجام…'));
                post('kic_suggest_links', {
                        post_id: KIC.postId,
                        content: content || '',
                        title: title
                }).then(function (data) {
                        state.linkSuggestions = asArray(data && data.suggestions);
                        state.linkChecked = {};
                        renderLinkSuggestions();
                        toast(state.linkSuggestions.length
                                ? fmtNum(state.linkSuggestions.length) + ' پیشنهاد لینک آماده است — مورد‌های موردنظر را انتخاب و درج کنید.'
                                : 'پیشنهاد لینکی برای این نوشته یافت نشد.',
                                state.linkSuggestions.length ? 'success' : 'info', 3600);
                }).catch(function (err) {
                        toast(err.message || 'پیشنهاد لینک داخلی ناموفق بود.', 'error');
                }).then(function () {
                        unbusy(els.linkSuggestBtn);
                });
        }

        /**
         * Render link suggestion rows with checkboxes.
         *
         * @return {void}
         */
        function renderLinkSuggestions() {
                if (!els.linkSuggestList) {
                        return;
                }
                els.linkSuggestList.textContent = '';
                if (!state.linkSuggestions.length) {
                        els.linkSuggestList.appendChild(h('div', { class: 'kic-notice', text: 'پیشنهادی دریافت نشده است.' }));
                        els.linkApplyBtn.classList.add('kic-hidden');
                        return;
                }
                els.linkApplyBtn.classList.remove('kic-hidden');

                state.linkSuggestions.forEach(function (item, idx) {
                        var box = h('input', {
                                type: 'checkbox',
                                id: 'kic-link-' + idx,
                                checked: !!state.linkChecked[idx],
                                onchange: function () {
                                        state.linkChecked[idx] = box.checked;
                                }
                        });
                        els.linkSuggestList.appendChild(h('label', { class: 'kic-checkrow', for: 'kic-link-' + idx }, [
                                box,
                                h('span', { class: 'kic-checkrow-name', text: String(item.anchor || '') }),
                                h('span', { class: 'kic-check-detail', text: ' ← ' + String(item.url || ''), dir: 'ltr' })
                        ]));
                });
        }

        /**
         * Wrap the first text occurrence of `anchor` (outside any tag or
         * existing link) with an <a> element. Deterministic token walk.
         *
         * @param {string} html   Editor HTML.
         * @param {string} anchor Anchor text to wrap.
         * @param {string} href   Link URL.
         * @return {{html: string, ok: boolean}}
         */
        function wrapAnchor(html, anchor, href, rel) {
                anchor = String(anchor || '').trim();
                if (!anchor) {
                        return { html: html, ok: false };
                }
                var relAttr = rel ? ' rel="' + String(rel) + '"' : '';
                var lowerHtml = html.toLowerCase();
                var lowerAnchor = anchor.toLowerCase();
                var pos = 0;
                while (true) {
                        var idx = lowerHtml.indexOf(lowerAnchor, pos);
                        if (idx < 0) {
                                return { html: html, ok: false };
                        }
                        /* Skip occurrences inside a tag or inside an existing link. */
                        var before = html.slice(0, idx);
                        var openTags = (before.match(/<a\b/gi) || []).length;
                        var closeTags = (before.match(/<\/a\s*>/gi) || []).length;
                        var insideTag = (before.lastIndexOf('<') > before.lastIndexOf('>'));
                        if (!insideTag && openTags === closeTags) {
                                var a = '<a href="' + String(href).replace(/"/g, '&quot;') + '"' + relAttr + '>';
                                return {
                                        html: html.slice(0, idx) + a + html.slice(idx, idx + anchor.length) + '</a>' + html.slice(idx + anchor.length),
                                        ok: true
                                };
                        }
                        pos = idx + lowerAnchor.length;
                }
        }

        /**
         * Is a URL off-site? Internal links stay follow (no rel), external
         * get nofollow by default — per the publish defaults.
         *
         * @param {string} url Candidate URL.
         * @return {boolean}
         */
        function isExternalUrl(url) {
                try {
                        return new URL(url, location.href).host !== location.host;
                } catch (e) {
                        return false;
                }
        }

        /**
         * Write full HTML back into the active editor (unsaved until save).
         *
         * @param {string} html New content.
         * @return {boolean} Success.
         */
        function writeEditorContent(html) {
                try {
                        if (KIC.editor === 'gutenberg' && window.wp && window.wp.data) {
                                var editor = window.wp.data.dispatch('core/editor');
                                var select = window.wp.data.select('core/editor');
                                if (editor && select && typeof editor.editEntityRecord === 'function') {
                                        editor.editEntityRecord(
                                                'postType',
                                                (select.getCurrentPostType && select.getCurrentPostType()) || 'post',
                                                Number(KIC.postId),
                                                { content: html }
                                        );
                                        return true;
                                }
                        }
                } catch (e) {
                        /* fall through */
                }
                try {
                        var ed = getTinymceEditor();
                        if (ed && typeof ed.setContent === 'function') {
                                ed.setContent(html);
                                return true;
                        }
                } catch (e) {
                        /* ignore */
                }
                return false;
        }

        /**
         * Insert every checked link suggestion into the editor content
         * (explicit click only; content is otherwise untouched).
         *
         * @return {void}
         */
        function applyLinkSelection() {
                if (!state.linkSuggestions.length) {
                        return;
                }
                var picks = [];
                state.linkSuggestions.forEach(function (item, idx) {
                        if (state.linkChecked[idx]) {
                                picks.push(item);
                        }
                });
                if (!picks.length) {
                        toast('چیزی برای درج انتخاب نشده است.', 'info');
                        return;
                }

                var html = linkSourceContent();
                if (!html) {
                        toast('محتوای ویرایشگر در دسترس نیست — ابتدا نوشته را ذخیره کنید.', 'info');
                        return;
                }

                var applied = 0;
                picks.forEach(function (item) {
                        var rel = isExternalUrl(item.url) ? 'nofollow noopener' : '';
                        var res = wrapAnchor(html, item.anchor, item.url, rel);
                        if (res.ok) {
                                html = res.html;
                                applied++;
                        }
                });

                if (!applied) {
                        toast('هیچ‌کدام از متن‌های لنگر در محتوا یافت نشد.', 'info');
                        return;
                }

                if (!writeEditorContent(html)) {
                        toast('درج در ویرایشگر ممکن نشد — لینک‌ها را دستی اضافه کنید.', 'error');
                        return;
                }

                toast(fmtNum(applied) + ' لینک در محتوا ثبت شد — با ذخیرهٔ نوشته نهایی می‌شود.', 'success', 3600);
                state.linkSuggestions = [];
                state.linkChecked = {};
                renderLinkSuggestions();
        }

        /**
         * Build the publish panel.
         *
         * @return {HTMLElement}
         */
        function buildPublishPanel() {
                var panel = h('div', { class: 'kic-panel', 'data-panel': 'publish' });

                panel.appendChild(h('div', {
                        class: 'kic-notice',
                        text: T('چک‌لیست فقط هشدار فنی است و هیچ‌چیز را خودکار اعمال نمی‌کند. پیشنهادهای دسته/نامک کاملاً اختیاری‌اند و فقط با کلیک شما اعمال می‌شوند — بدون تنظیم ارائه‌دهنده هم انتشار کامل کار می‌کند.')
                }));

                /* --- Site-like preview of the final post (v2.4) ----------- */

                els.sitePreviewBtn = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: renderSitePreview
                }, [T('پیش‌نمایش نوشته روی سایت')]);
                els.sitePreview = h('div', { class: 'kic-site-preview kic-hidden' });

                panel.appendChild(h('details', { class: 'kic-imgpanel', open: true }, [
                        h('summary', { text: T('پیش‌نمایش نوشته (مثل خروجی سایت)') }),
                        h('div', { class: 'kic-imgpanel-body' }, [
                                h('div', { class: 'kic-actions' }, [els.sitePreviewBtn]),
                                els.sitePreview
                        ])
                ]));

                /* --- Pre-publish checklist (read-only diagnostics) --------- */

                els.prepublishBtn = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: runPrepublishCheck
                }, [T('بررسی پیش از انتشار')]);
                els.prepublishList = h('div', { class: 'kic-checklist', id: 'kic-prepublish-list' });
                els.prepublishList.appendChild(h('div', { class: 'kic-notice', text: T('نتیجهٔ بررسی') + ' — ' + T('آماده') + '.' }));

                panel.appendChild(h('details', { class: 'kic-imgpanel', open: true }, [
                        h('summary', { text: T('چک‌لیست پیش از انتشار') }),
                        h('div', { class: 'kic-imgpanel-body' }, [
                                h('div', { class: 'kic-actions' }, [els.prepublishBtn]),
                                els.prepublishList
                        ])
                ]));

                /* --- Category & tag suggestions (existing terms only) ------- */

                els.termSuggestBtn = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: runTermSuggest
                }, [T('دریافت پیشنهاد')]);
                els.termApplyBtn = h('button', {
                        class: 'kic-btn kic-btn--ghost kic-hidden',
                        type: 'button',
                        onclick: applyTermSelection
                }, [T('اعمال انتخاب‌ها روی نوشته')]);
                els.termSuggestList = h('div', { class: 'kic-linklist', id: 'kic-term-suggest-list' });
                els.termSuggestList.appendChild(h('div', { class: 'kic-notice', text: T('فهرست دسته‌ها و برچسب‌های موجود سایت:') }));

                panel.appendChild(h('details', { class: 'kic-imgpanel' }, [
                        h('summary', { text: T('پیشنهاد دسته و برچسب (اختیاری)') }),
                        h('div', { class: 'kic-imgpanel-body' }, [
                                h('p', { class: 'kic-muted', text: 'اختیاری و نیازمند ارائه‌دهندهٔ تنظیم‌شده در تنظیمات — مدل فقط از میان دسته‌ها و برچسب‌های موجود سایت انتخاب می‌کند؛ هیچ دسته یا برچسبی ساخته نمی‌شود.' }),
                                h('div', { class: 'kic-actions' }, [els.termSuggestBtn, els.termApplyBtn]),
                                els.termSuggestList
                        ])
                ]));

                /* --- English slug suggestion ------------------------------- */

                els.slugSuggestBtn = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: runSlugSuggest
                }, [T('پیشنهاد نامک')]);
                els.slugSuggestList = h('div', { class: 'kic-linklist', id: 'kic-slug-suggest-list' });
                els.slugSuggestList.appendChild(h('div', { class: 'kic-notice', text: 'هنوز پیشنهادی دریافت نشده است.' }));

                panel.appendChild(h('details', { class: 'kic-imgpanel' }, [
                        h('summary', { text: T('پیشنهاد نامک انگلیسی (اختیاری)') }),
                        h('div', { class: 'kic-imgpanel-body' }, [
                                h('p', { class: 'kic-muted', text: 'اختیاری و نیازمند ارائه‌دهندهٔ تنظیم‌شده در تنظیمات — بدون آن هم نامک از عنوان نوشته ساخته می‌شود.' }),
                                h('div', { class: 'kic-actions' }, [els.slugSuggestBtn]),
                                els.slugSuggestList
                        ])
                ]));

                /* --- Internal link suggestions (existing site pages only) --- */

                els.linkSuggestBtn = h('button', {
                        class: 'kic-btn kic-btn--primary',
                        type: 'button',
                        onclick: runLinkSuggest
                }, [T('پیشنهاد لینک داخلی')]);
                els.linkApplyBtn = h('button', {
                        class: 'kic-btn kic-btn--ghost kic-hidden',
                        type: 'button',
                        onclick: applyLinkSelection
                }, [T('درج لینک‌های انتخاب‌شده')]);
                els.linkSuggestList = h('div', { class: 'kic-linklist', id: 'kic-link-suggest-list' });
                els.linkSuggestList.appendChild(h('div', { class: 'kic-notice', text: 'هنوز پیشنهادی دریافت نشده است.' }));

                panel.appendChild(h('details', { class: 'kic-imgpanel' }, [
                        h('summary', { text: T('پیشنهاد لینک داخلی (اختیاری)') }),
                        h('div', { class: 'kic-imgpanel-body' }, [
                                h('p', { class: 'kic-muted', text: 'اختیاری و نیازمند ارائه‌دهندهٔ تنظیم‌شده در تنظیمات — فقط صفحات موجود خود سایت پیشنهاد می‌شوند و هیچ لینکی بدون کلیک شما درج نمی‌شود.' }),
                                h('div', { class: 'kic-actions' }, [els.linkSuggestBtn, els.linkApplyBtn]),
                                els.linkSuggestList
                        ])
                ]));

                return panel;
        }

        /**
         * Refresh the Elementor live preview iframe (v1.2 panel context).
         *
         * @return {void}
         */
        function reloadElementorPreview() {
                try {
                        if (window.elementor && typeof window.elementor.reloadPreview === 'function') {
                                window.elementor.reloadPreview();
                                return;
                        }
                } catch (e) { /* fall through */ }

                try {
                        var iframe = document.getElementById('elementor-preview-iframe');
                        if (iframe && iframe.contentWindow) {
                                iframe.contentWindow.location.reload();
                                return;
                        }
                } catch (e) { /* fall through */ }

                try {
                        // Elementor >= 3.x: $e editor API.
                        if (window.$e && window.$e.run && typeof window.$e.run === 'function') {
                                window.$e.run('editor/documents/preview');
                        }
                } catch (e) { /* best effort */ }
        }
        /* ================================================================
         * External focus event (TinyMCE toolbar button)
         * ================================================================ */

        window.addEventListener('kic:focus', function () {
                ensureExpanded();
                switchTab('content');
                setTimeout(function () {
                        if (els.raw) {
                                els.raw.focus();
                        }
                }, 60);
        });

        /* ================================================================
         * Boot
         * ================================================================ */

        function boot() {
                var root = document.getElementById('kic-compose-root');

                /* v1.2 — Elementor editor live panel mount point. */
                if (!root && (KIC.editor === 'elementor' || KIC.context === 'elementor')) {
                        var panel = document.getElementById('kic-elementor-panel');
                        if (panel) {
                                root = h('div', { id: 'kic-compose-root', class: 'kic-root kic-elementor-context', dir: 'rtl' });
                                panel.appendChild(root);
                        }
                }

                if (!root) {
                        return;
                }
                els.root = root;
                buildWidget();
        }

        if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
        } else {
                boot();
        }
})(window, document);
