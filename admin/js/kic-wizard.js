/**
 * Natilus — 3-step setup wizard logic.
 *
 * Provided globals: KIC_WIZ { ajaxurl, nonce, presets, i18n: {lang, dict} }.
 *
 * Step 1 pick a preset → Step 2 base URL + API key + model → Step 3 test
 * (kic_test_connection, values only — nothing stored) + model listing
 * (kic_list_provider_models) → finish (kic_wizard_finish saves the config).
 *
 * @package ai-content-publishing-agent
 */
(function (window, document) {
        'use strict';

        var WIZ = window.KIC_WIZ || null;
        if (!WIZ || typeof WIZ.ajaxurl === 'undefined') {
                return;
        }

        var I18N = (WIZ.i18n && WIZ.i18n.dict) ? WIZ.i18n : { lang: 'fa', dict: {} };

        function T(fa) {
                if (I18N.lang === 'en' && Object.prototype.hasOwnProperty.call(I18N.dict, fa)) {
                        var en = I18N.dict[fa];
                        if (en) {
                                return String(en);
                        }
                }
                return fa;
        }

        var root = document.getElementById('kic-wizard-root');
        if (!root) {
                return;
        }

        var state = {
                step: 1,
                label: '',
                baseUrl: '',
                apiKey: '',
                model: ''
        };

        var els = {
                steps: document.getElementById('kic-wizard-steps'),
                presets: document.getElementById('kic-wizard-presets'),
                baseUrl: document.getElementById('kic-wizard-base-url'),
                apiKey: document.getElementById('kic-wizard-api-key'),
                model: document.getElementById('kic-wizard-model'),
                summary: document.getElementById('kic-wizard-summary'),
                test: document.getElementById('kic-wizard-test'),
                models: document.getElementById('kic-wizard-models'),
                testResult: document.getElementById('kic-wizard-test-result'),
                modelsList: document.getElementById('kic-wizard-models-list'),
                back: document.getElementById('kic-wizard-back'),
                next: document.getElementById('kic-wizard-next'),
                finish: document.getElementById('kic-wizard-finish'),
                skip: document.getElementById('kic-wizard-skip'),
                done: document.getElementById('kic-wizard-done'),
                status: document.getElementById('kic-wizard-status'),
                footer: document.getElementById('kic-wizard-footer')
        };

        /**
         * POST to admin-ajax, resolve with json.data.
         *
         * @param {string} action Endpoint.
         * @param {Object} params Params.
         * @return {Promise<Object>}
         */
        function post(action, params) {
                var body = new FormData();
                body.append('action', String(action));
                body.append('nonce', String(WIZ.nonce || ''));
                Object.keys(params || {}).forEach(function (key) {
                        var value = params[key];
                        if (value !== undefined && value !== null) {
                                body.append(key, value);
                        }
                });

                return fetch(WIZ.ajaxurl, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin'
                }).then(function (resp) {
                        return resp.json();
                }).then(function (json) {
                        if (!json || json.success !== true) {
                                var message = (json && json.data && typeof json.data === 'object' && json.data.message)
                                        ? String(json.data.message)
                                        : (json && typeof json.data === 'string' ? String(json.data) : 'Request failed.');
                                throw new Error(message);
                        }
                        return json.data;
                });
        }

        function setStatus(text) {
                if (els.status) {
                        els.status.textContent = String(text || '');
                }
        }

        function renderStep() {
                var sections = root.querySelectorAll('.kic-wizard-step');
                var i;
                for (i = 0; i < sections.length; i++) {
                        var active = sections[i].getAttribute('data-step') === String(state.step);
                        sections[i].hidden = !active;
                }
                var indicators = els.steps ? els.steps.querySelectorAll('li') : [];
                for (i = 0; i < indicators.length; i++) {
                        var li = indicators[i];
                        var liStep = Number(li.getAttribute('data-step'));
                        li.classList.toggle('is-active', liStep === state.step);
                        li.classList.toggle('is-done', liStep < state.step);
                }

                if (els.back) {
                        els.back.hidden = state.step === 1;
                }
                if (els.next) {
                        els.next.hidden = state.step === 3;
                }
                if (els.finish) {
                        els.finish.hidden = state.step !== 3;
                }
                if (els.footer) {
                        els.footer.hidden = !!root.getAttribute('data-done');
                }

                if (state.step === 3 && els.summary) {
                        els.summary.textContent = state.label + ' | ' + state.baseUrl + ' | ' + (state.model || '—');
                }
        }

        /* Step 1: preset click ------------------------------------------------- */

        if (els.presets) {
                els.presets.addEventListener('click', function (e) {
                        var btn = e.target.closest ? e.target.closest('.kic-wizard-preset') : null;
                        if (!btn) {
                                return;
                        }
                        state.label = btn.getAttribute('data-label') || btn.getAttribute('data-key') || '';
                        state.baseUrl = btn.getAttribute('data-base-url') || '';
                        state.model = btn.getAttribute('data-model') || '';

                        if (els.baseUrl) {
                                els.baseUrl.value = state.baseUrl;
                        }
                        if (els.model) {
                                els.model.value = state.model;
                        }

                        var buttons = els.presets.querySelectorAll('.kic-wizard-preset');
                        var i;
                        for (i = 0; i < buttons.length; i++) {
                                buttons[i].classList.toggle('is-selected', buttons[i] === btn);
                        }
                        setStatus(state.label + ' ✓');
                });
        }

        /* Step 2: gather values ------------------------------------------------- */

        function isLocalBaseUrl(url) {
                try {
                        var host = String(url || '').replace(/^https?:\/\//i, '').split(/[\/:?#]/)[0].toLowerCase();
                        return host === 'localhost' || host === '127.0.0.1' || host === '::1';
                } catch (e) {
                        return false;
                }
        }

        function gatherStep2() {
                state.baseUrl = els.baseUrl ? els.baseUrl.value.trim().replace(/\/+$/, '') : '';
                state.apiKey = els.apiKey ? els.apiKey.value.trim() : '';
                state.model = els.model ? els.model.value.trim() : '';
                if (!state.baseUrl || !state.model) {
                        setStatus('آدرس پایه و مدل لازم هستند.');
                        return false;
                }
                if (!state.apiKey && !isLocalBaseUrl(state.baseUrl)) {
                        setStatus('برای ارائه‌دهندهٔ غیرمحلی، کلید API لازم است (سرور محلی مثل Ollama می‌تواند خالی باشد).');
                        return false;
                }
                return true;
        }

        /* Step 3: connection test ----------------------------------------------- */

        if (els.test) {
                els.test.addEventListener('click', function () {
                        if (!gatherStep2()) {
                                return;
                        }
                        els.test.disabled = true;
                        els.test.textContent = '…';
                        setStatus('');
                        if (els.testResult) {
                                els.testResult.textContent = '';
                                els.testResult.className = 'kic-wizard-result';
                        }
                        post('kic_test_connection', {
                                base_url: state.baseUrl,
                                api_key_plain: state.apiKey,
                                model: state.model
                        }).then(function (data) {
                                /* The endpoint answers success:true + connected:false on failure. */
                                if (!data || data.connected !== true) {
                                        throw new Error((data && data.message) ? String(data.message) : 'Test failed.');
                                }
                                var message = (data && typeof data === 'object' && data.message) ? String(data.message) : '';
                                if (els.testResult) {
                                        els.testResult.textContent = message || T('اتصال برقرار است.');
                                        els.testResult.className = 'kic-wizard-result is-ok';
                                }
                                root.setAttribute('data-tested', '1');
                                setStatus(T('اتصال برقرار است.'));
                        }).catch(function (err) {
                                if (els.testResult) {
                                        els.testResult.textContent = err.message || 'Test failed.';
                                        els.testResult.className = 'kic-wizard-result is-error';
                                }
                        }).then(function () {
                                els.test.disabled = false;
                                els.test.textContent = T('تست اتصال');
                        });
                });
        }

        /* Step 3: model listing -------------------------------------------------- */

        if (els.models) {
                els.models.addEventListener('click', function () {
                        if (!gatherStep2()) {
                                return;
                        }
                        els.models.disabled = true;
                        post('kic_list_provider_models', {
                                base_url: state.baseUrl,
                                api_key_plain: state.apiKey
                        }).then(function (data) {
                                var list = (data && data.models) ? data.models : (Array.isArray(data) ? data : []);
                                if (!els.modelsList) {
                                        return;
                                }
                                els.modelsList.textContent = '';
                                els.modelsList.hidden = false;
                                if (!list.length) {
                                        els.modelsList.textContent = 'مدلی از این ارائه‌دهنده خوانده نشد.';
                                        return;
                                }
                                list.slice(0, 60).forEach(function (id) {
                                        var btn = document.createElement('button');
                                        btn.type = 'button';
                                        btn.className = 'kic-wizard-model';
                                        btn.setAttribute('dir', 'ltr');
                                        btn.textContent = String(id);
                                        btn.addEventListener('click', function () {
                                                state.model = String(id);
                                                if (els.model) {
                                                        els.model.value = state.model;
                                                }
                                                els.modelsList.hidden = true;
                                                renderStep();
                                        });
                                        els.modelsList.appendChild(btn);
                                });
                        }).catch(function (err) {
                                if (els.modelsList) {
                                        els.modelsList.hidden = false;
                                        els.modelsList.textContent = err.message || 'Model listing failed.';
                                }
                        }).then(function () {
                                els.models.disabled = false;
                        });
                });
        }

        /* Navigation -------------------------------------------------------------- */

        if (els.next) {
                els.next.addEventListener('click', function () {
                        if (state.step === 2 && !gatherStep2()) {
                                return;
                        }
                        if (state.step < 3) {
                                state.step++;
                                renderStep();
                        }
                });
        }

        if (els.back) {
                els.back.addEventListener('click', function () {
                        if (state.step > 1) {
                                state.step--;
                                renderStep();
                        }
                });
        }

        if (els.finish) {
                els.finish.addEventListener('click', function () {
                        if (!gatherStep2()) {
                                return;
                        }
                        if (!root.getAttribute('data-tested')) {
                                if (!window.confirm('اتصال هنوز تست نشده — همین حالا ذخیره شود؟')) {
                                        return;
                                }
                        }
                        els.finish.disabled = true;
                        setStatus('');
                        post('kic_wizard_finish', {
                                label: state.label,
                                base_url: state.baseUrl,
                                api_key: state.apiKey,
                                model: state.model
                        }).then(function () {
                                if (els.done) {
                                        els.done.hidden = false;
                                }
                                root.setAttribute('data-done', '1');
                                renderStep();
                        }).catch(function (err) {
                                setStatus(err.message || 'Finish failed.');
                        }).then(function () {
                                els.finish.disabled = false;
                        });
                });
        }

        /* v2.1 — skip: publishing needs no provider. ---------------------------- */

        if (els.skip) {
                els.skip.addEventListener('click', function () {
                        els.skip.disabled = true;
                        setStatus('');
                        post('kic_wizard_finish', { skip: 1 }).then(function () {
                                root.setAttribute('data-done', '1');
                                if (els.done) {
                                        els.done.hidden = false;
                                }
                                renderStep();
                        }).catch(function (err) {
                                setStatus(err.message || 'Finish failed.');
                        }).then(function () {
                                els.skip.disabled = false;
                        });
                });
        }

        renderStep();

})(window, document);
