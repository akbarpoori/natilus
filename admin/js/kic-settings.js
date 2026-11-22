/**
 * Natilus — settings page behaviour.
 *
 * 1) Tab switching, 2) preset autofill with overwrite confirmation,
 * 3) "test connection" AJAX round-trip with Persian status output,
 * 4) provider × model priority chain manager (add / edit / delete /
 *    reorder / load models), rendered inside #kic-chain-app.
 *
 * Provided globals: KIC_SETTINGS { ajaxurl, nonce, has_key, presets, providers }.
 */

/* global jQuery, KIC_SETTINGS */
(function ( $ ) {
        'use strict';

        var KIC = window.KIC_SETTINGS || {};
        var chainState = { providers: ( KIC.providers || [] ).slice() };

        /**
         * Switch between the settings tabs.
         */
        function onTabClick() {
                /* jshint validthis: true */
                var $btn   = $( this );
                var target = String( $btn.data( 'tab' ) || '' );

                if ( ! target ) {
                        return;
                }

                $( '.kic-settings .kic-tab' ).removeClass( 'active' ).attr( 'aria-selected', 'false' );
                $btn.addClass( 'active' ).attr( 'aria-selected', 'true' );

                $( '.kic-settings .kic-tab-panel' ).removeClass( 'active' );
                $( '#' + target ).addClass( 'active' );
        }

        /**
         * Autofill base_url + model from the chosen preset, asking for
         * confirmation before overwriting values the user typed themselves.
         */
        function onPresetChange() {
                /* jshint validthis: true */
                var presets = KIC.presets || {};
                var preset  = String( $( this ).val() || '' );
                var data    = presets[ preset ];

                if ( ! data || preset === 'custom' ) {
                        return;
                }

                var $base        = $( '#kic-base-url' );
                var $model       = $( '#kic-model' );
                var base         = String( data.base_url || '' );
                var model        = String( data.model || '' );
                var currentBase  = String( $base.val() || '' ).trim();
                var currentModel = String( $model.val() || '' ).trim();

                if ( currentBase === '' || currentBase === base ||
                        window.confirm( 'مقدار فعلی «آدرس پایه» با مقدار پیش‌تنظیم جایگزین شود؟' ) ) {
                        $base.val( base );
                }

                if ( currentModel === '' || currentModel === model ||
                        window.confirm( 'مقدار فعلی «مدل چت» با مقدار پیش‌تنظیم جایگزین شود؟' ) ) {
                        $model.val( model );
                }
        }

        /**
         * POST the current (possibly unsaved) values to the connection tester.
         */
        function onTestClick( event ) {
                event.preventDefault();

                var $btn    = $( '#kic-test-btn' );
                var $result = $( '#kic-test-result' );

                if ( $btn.hasClass( 'kic-loading' ) ) {
                        return;
                }

                var apiKey  = String( $( '#kic-api-key' ).val() || '' ).trim();
                var baseUrl = String( $( '#kic-base-url' ).val() || '' ).trim();
                var model   = String( $( '#kic-model' ).val() || '' ).trim();

                if ( ! baseUrl || ! model ) {
                        $result.removeClass( 'kic-test-ok kic-test-fail' )
                                .addClass( 'kic-test-fail' )
                                .text( 'آدرس پایه و مدل چت الزامی هستند.' );
                        return;
                }

                if ( '' === apiKey && ! KIC.has_key ) {
                        $result.removeClass( 'kic-test-ok kic-test-fail' )
                                .addClass( 'kic-test-fail' )
                                .text( 'برای آزمایش اتصال ابتدا کلید API را وارد کنید و ذخیره کنید.' );
                        return;
                }

                $btn.addClass( 'kic-loading' ).prop( 'disabled', true );
                $result.removeClass( 'kic-test-ok kic-test-fail' ).text( 'در حال آزمایش…' );

                $.post( KIC.ajaxurl, {
                        action:        'kic_test_connection',
                        nonce:         KIC.nonce,
                        base_url:      baseUrl,
                        model:         model,
                        api_key_plain: apiKey
                } ).done( function ( response ) {
                        var ok      = false;
                        var text    = 'پاسخ نامعتبر از سرور.';
                        var latency = 0;
                        var usage   = 0;

                        if ( response && response.data ) {
                                ok      = !! response.success && !! response.data.connected;
                                text    = String( response.data.message || text );
                                latency = parseInt( response.data.latency_ms, 10 ) || 0;
                                if ( response.data.usage && response.data.usage.total_tokens ) {
                                        usage = parseInt( response.data.usage.total_tokens, 10 ) || 0;
                                }
                        }

                        if ( ok && latency > 0 ) {
                                text += ' (' + latency + ' میلی‌ثانیه';
                                if ( usage > 0 ) {
                                        text += '، ' + usage + ' توکن';
                                }
                                text += ')';
                        }

                        $result.removeClass( 'kic-test-ok kic-test-fail' )
                                .addClass( ok ? 'kic-test-ok' : 'kic-test-fail' )
                                .text( text );
                } ).fail( function () {
                        $result.removeClass( 'kic-test-ok kic-test-fail' )
                                .addClass( 'kic-test-fail' )
                                .text( 'خطای شبکه در ارتباط با سرور؛ دوباره تلاش کنید.' );
                } ).always( function () {
                        $btn.removeClass( 'kic-loading' ).prop( 'disabled', false );
                } );
        }

        /* ================================================================
         * Provider × model chain manager
         * ================================================================ */

        /**
         * Persian digits.
         */
        function fa( n ) {
                var num = Number( n );
                if ( ! isFinite( num ) ) { return '۰'; }
                try {
                        return num.toLocaleString( 'fa-IR' );
                } catch ( e ) {
                        return String( num );
                }
        }

        function escAttr( s ) {
                return String( s === null || s === undefined ? '' : s )
                        .replace( /&/g, '&amp;' )
                        .replace( /"/g, '&quot;' )
                        .replace( /</g, '&lt;' )
                        .replace( />/g, '&gt;' );
        }

        /**
         * POST a chain action and refresh the rendered chain.
         */
        function chainPost( action, params ) {
                params = params || {};
                params.action = action;
                params.nonce  = KIC.nonce;

                return $.post( KIC.ajaxurl, params ).then( function ( response ) {
                        if ( response && response.success && response.data && response.data.providers ) {
                                chainState.providers = response.data.providers;
                        }
                        return response;
                } );
        }

        /**
         * Render the whole chain (cards + add form) into #kic-chain-app.
         */
        function renderChain() {
                var $app = $( '#kic-chain-app' );
                if ( ! $app.length ) {
                        return;
                }

                var html = '';

                html += '<div class="kic-chain-list">';
                $.each( chainState.providers, function ( i, p ) {
                        var models = ( p.models || [] );
                        var modelChips = '';
                        $.each( models, function ( m, model ) {
                                modelChips += '<span class="kic-model-chip" dir="ltr">' + escAttr( model ) + '</span>';
                        } );
                        if ( ! modelChips ) {
                                modelChips = '<span class="kic-muted">مدلی ثبت نشده</span>';
                        }

                        html += '<div class="kic-chain-card" data-id="' + escAttr( p.id ) + '">' +
                                '<div class="kic-chain-card-head">' +
                                        '<strong class="kic-chain-priority">' + fa( p.priority || ( i + 1 ) ) + '</strong>' +
                                        '<span class="kic-chain-label">' + escAttr( p.label ) + ' <code dir="ltr">' + escAttr( p.host ) + '</code></span>' +
                                        ( p.down ? '<span class="kic-badge kic-badge-warn">به‌تازگی خطا داده</span>' : '' ) +
                                        '<span class="kic-chain-spacer"></span>' +
                                        '<button type="button" class="kic-btn kic-btn-ghost kic-btn-sm kic-chain-test" title="آزمایش اتصال همین ارائه‌دهنده">آزمایش</button>' +
                                        '<button type="button" class="kic-btn kic-btn-ghost kic-btn-sm kic-chain-up" title="بالا بردن اولویت">▲</button>' +
                                        '<button type="button" class="kic-btn kic-btn-ghost kic-btn-sm kic-chain-down" title="پایین بردن اولویت">▼</button>' +
                                        '<button type="button" class="kic-btn kic-btn-ghost kic-btn-sm kic-chain-edit">ویرایش</button>' +
                                        '<button type="button" class="kic-btn kic-btn-danger kic-btn-sm kic-chain-delete">حذف</button>' +
                                '</div>' +
                                '<div class="kic-chain-models">' + modelChips + '</div>' +
                                '<div class="kic-chain-test-result kic-hidden"></div>' +
                        '</div>';
                } );
                if ( ! chainState.providers.length ) {
                        html += '<p class="description">هنوز ارائه‌دهنده‌ای در زنجیره نیست — با فرم زیر اضافه کنید. (برای سرورهای محلی مثل Ollama می‌توانید کلید API را خالی بگذارید.)</p>';
                }
                html += '</div>';

                html +=
                        '<div class="kic-chain-form kic-card" id="kic-chain-form">' +
                                '<h4 class="kic-section-title">' + ( 'edit' === chainState.mode ? 'ویرایش ارائه‌دهنده' : 'افزودن ارائه‌دهنده به زنجیره' ) + '</h4>' +
                                '<div class="kic-field"><label>برچسب</label><div class="kic-control">' +
                                        '<input type="text" class="kic-input" id="kic-chain-label" dir="auto" placeholder="مثلاً: کوبیت / OpenAI / Groq" value="' + escAttr( chainState.edit && chainState.edit.label || '' ) + '" /></div></div>' +
                                '<div class="kic-field"><label>آدرس پایه</label><div class="kic-control">' +
                                        '<input type="text" class="kic-input" id="kic-chain-base" dir="ltr" spellcheck="false" placeholder="https://api.openai.com/v1" value="' + escAttr( chainState.edit && chainState.edit.base_url || '' ) + '" /></div></div>' +
                                '<div class="kic-field"><label>کلید API' + ( chainState.mode === 'edit' ? ' (خالی = بدون تغییر؛ برای سرور محلی خالی بگذارید)' : ' (برای سرور محلی خالی بگذارید)' ) + '</label><div class="kic-control">' +
                                        '<input type="password" class="kic-input" id="kic-chain-key" dir="ltr" autocomplete="new-password" placeholder="sk-..." value="" /></div></div>' +
                                '<div class="kic-field"><label>مدل‌ها (اولویت ۱ تا ۳)</label><div class="kic-control kic-chain-models-inputs">' +
                                        '<input type="text" class="kic-input kic-chain-model" dir="ltr" placeholder="مدل ۱ — پیش‌فرض" value="' + escAttr( ( chainState.edit && chainState.edit.models && chainState.edit.models[ 0 ] ) || '' ) + '" />' +
                                        '<input type="text" class="kic-input kic-chain-model" dir="ltr" placeholder="مدل ۲ — جایگزین خودکار" value="' + escAttr( ( chainState.edit && chainState.edit.models && chainState.edit.models[ 1 ] ) || '' ) + '" />' +
                                        '<input type="text" class="kic-input kic-chain-model" dir="ltr" placeholder="مدل ۳ — جایگزین خودکار" value="' + escAttr( ( chainState.edit && chainState.edit.models && chainState.edit.models[ 2 ] ) || '' ) + '" />' +
                                        '<button type="button" class="kic-btn kic-btn-ghost kic-btn-sm" id="kic-chain-load-models">بارگذاری مدل‌ها از ارائه‌دهنده</button>' +
                                        '<button type="button" class="kic-btn kic-btn-ghost kic-btn-sm" id="kic-chain-load-refresh">تازه‌سازی فهرست</button>' +
                                        '<span class="kic-chain-load-status" aria-live="polite"></span>' +
                                '</div></div>' +
                                '<div class="kic-actions">' +
                                        '<button type="button" class="kic-btn kic-btn-primary" id="kic-chain-save">ذخیره در زنجیره</button>' +
                                        ( chainState.mode === 'edit' ? '<button type="button" class="kic-btn kic-btn-ghost" id="kic-chain-cancel">انصراف</button>' : '' ) +
                                '</div>' +
                                '<p class="description">اگر مدل‌ها را خالی بگذارید و «بارگذاری مدل‌ها» را بزنید، فهرست مدل‌های ارائه‌دهنده خوانده می‌شود و مدل اول به‌طور خودکار انتخاب می‌شود.</p>' +
                        '</div>';

                $app.html( html );
        }

        /**
         * Collect the add/edit form values.
         */
        function chainFormValues() {
                var models = [];
                $( '#kic-chain-form .kic-chain-model' ).each( function () {
                        var v = String( $( this ).val() || '' ).trim();
                        if ( v && models.indexOf( v ) === -1 ) {
                                models.push( v );
                        }
                } );
                return {
                        id:       chainState.mode === 'edit' ? ( chainState.edit && chainState.edit.id ) : '',
                        label:     String( $( '#kic-chain-label' ).val() || '' ).trim(),
                        base_url:  String( $( '#kic-chain-base' ).val() || '' ).trim(),
                        api_key:   String( $( '#kic-chain-key' ).val() || '' ).trim(),
                        models:    models
                };
        }

        /**
         * Save (insert or update) one provider in the chain.
         */
        function onChainSave() {
                var values = chainFormValues();

                if ( ! values.base_url ) {
                        window.alert( 'آدرس پایه الزامی است.' );
                        return;
                }
                if ( ! values.models.length && chainState.mode !== 'edit' ) {
                        window.alert( 'حداقل یک مدل لازم است؛ می‌توانید «بارگذاری مدل‌ها» را بزنید.' );
                        return;
                }

                var $btn = $( '#kic-chain-save' );
                $btn.prop( 'disabled', true ).text( 'در حال ذخیره…' );

                chainPost( 'kic_save_provider', { provider: JSON.stringify( values ) } )
                        .done( function ( response ) {
                                if ( ! ( response && response.success ) ) {
                                        window.alert( ( response && response.data && response.data.message ) || 'ذخیره ناموفق بود.' );
                                        return;
                                }
                                chainState.mode = 'add';
                                chainState.edit = null;
                                renderChain();
                        } )
                        .fail( function () {
                                window.alert( 'خطای ارتباط با سرور؛ دوباره تلاش کنید.' );
                        } )
                        .always( function () {
                                $btn.prop( 'disabled', false ).text( 'ذخیره در زنجیره' );
                        } );
        }

        /**
         * «بارگذاری مدل‌ها» next to the main chat-model field: reads the
         * /models endpoint of the base URL in the form, fills a dropdown, and
         * picking one writes it into the model input. Falls back to preset
         * model lists when the endpoint does not answer.
         */
        function onModelFieldLoad( e ) {
                e.preventDefault();
                var baseUrl = String( $( '#kic-base-url' ).val() || '' ).trim();
                var apiKey  = String( $( '#kic-api-key' ).val() || '' ).trim();
                var $status = $( '#kic-model-load-status' );
                var $list   = $( '#kic-model-list' );
                var $btn    = $( '#kic-model-load' );

                if ( ! baseUrl ) {
                        $status.text( 'ابتدا آدرس پایه ارائه‌دهنده را وارد و ذخیره کنید.' );
                        return;
                }

                $btn.prop( 'disabled', true );
                $status.text( 'در حال بارگذاری مدل‌ها…' );

                var params = { action: 'kic_list_provider_models', nonce: KIC.nonce, base_url: baseUrl, refresh: 1 };
                if ( apiKey ) {
                        params.api_key_plain = apiKey;
                }

                $.post( KIC.ajaxurl, params ).done( function ( response ) {
                        var data = ( response && response.data ) || {};
                        var models = data.models || [];
                        var message = data.message || '';

                        if ( ! models.length && data.fallback && data.fallback.length ) {
                                models = data.fallback;
                        }
                        if ( ! models.length ) {
                                $status.text( message || 'هیچ مدلی یافت نشد — شناسه را دستی بنویسید.' );
                                return;
                        }

                        var current = String( $( '#kic-model' ).val() || '' ).trim();
                        $list.empty().append( $( '<option/>' ).val( '' ).text( '— انتخاب مدل —' ) );
                        $.each( models, function ( i, m ) {
                                $list.append( $( '<option/>' ).val( m ).text( m ).prop( 'selected', m === current ) );
                        } );
                        $list.show();
                        $status.text( models.length + ' مدل یافت شد' + ( message ? ' — ' + message : '' ) );
                } ).fail( function () {
                        $status.text( 'خطای ارتباط با سرور.' );
                } ).always( function () {
                        $btn.prop( 'disabled', false );
                } );
        }

        /**
         * Load the /models list of the base URL entered in the form and fill
         * the model priority slots (first model becomes the default).
         *
         * @param {boolean} refresh Skip the server-side transient cache.
         */
        function onChainLoadModels( refresh ) {
                var baseUrl = String( $( '#kic-chain-base' ).val() || '' ).trim();
                var apiKey  = String( $( '#kic-chain-key' ).val() || '' ).trim();
                var $status = $( '.kic-chain-load-status' );
                var $btn    = $( '#kic-chain-load-models' );

                if ( ! baseUrl ) {
                        $status.text( 'ابتدا آدرس پایه را وارد کنید.' );
                        return;
                }

                $btn.prop( 'disabled', true );
                $status.text( 'در حال بارگذاری مدل‌ها…' );

                var params = { action: 'kic_list_provider_models', nonce: KIC.nonce, base_url: baseUrl };
                if ( apiKey ) {
                        params.api_key_plain = apiKey;
                } else if ( chainState.mode === 'edit' ) {
                        params.id = chainState.edit.id;
                }
                if ( refresh ) {
                        params.refresh = 1;
                }

                $.post( KIC.ajaxurl, params ).done( function ( response ) {
                        var data = ( response && response.data ) || {};
                        var models = data.models || [];
                        var message = data.message || '';

                        if ( ! models.length && data.fallback && data.fallback.length ) {
                                models = data.fallback;
                                $status.text( 'فهرست مدل‌ها از پیش‌تنظیم شناخته‌شده پر شد' + ( message ? ' (' + message + ')' : '' ) );
                        } else if ( ! models.length ) {
                                $status.text( message || 'هیچ مدلی یافت نشد؛ شناسه‌ها را دستی وارد کنید.' );
                                return;
                        } else {
                                $status.text( fa( models.length ) + ' مدل بارگذاری شد' + ( message ? ' — ' + message : '' ) );
                        }

                        var $slots = $( '#kic-chain-form .kic-chain-model' );
                        $slots.val( '' );
                        $.each( models.slice( 0, $slots.length ), function ( i, model ) {
                                $slots.eq( i ).val( model );
                        } );
                } ).fail( function () {
                        $status.text( 'خطای ارتباط با سرور.' );
                } ).always( function () {
                        $btn.prop( 'disabled', false );
                } );
        }

        /**
         * Test one saved chain provider (uses its stored key + first model).
         */
        function onChainTest( $card ) {
                var id = String( $card.data( 'id' ) || '' );
                var provider = null;
                $.each( chainState.providers, function ( i, p ) {
                        if ( p.id === id ) { provider = p; }
                } );
                if ( ! provider ) {
                        return;
                }

                var $btn = $card.find( '.kic-chain-test' );
                var $result = $card.find( '.kic-chain-test-result' ).removeClass( 'kic-hidden' );

                var firstModel = ( provider.models && provider.models[ 0 ] ) || '';
                if ( ! firstModel ) {
                        $result.text( 'ابتدا مدل این ارائه‌دهنده را ثبت کنید.' );
                        return;
                }

                $btn.prop( 'disabled', true );
                $result.text( 'در حال آزمایش…' );

                $.post( KIC.ajaxurl, {
                        action:   'kic_test_connection',
                        nonce:    KIC.nonce,
                        id:       provider.id,
                        base_url: provider.base_url || '',
                        model:    firstModel
                } ).done( function ( response ) {
                        var ok = !!( response && response.success && response.data && response.data.connected );
                        var text = ok
                                ? 'اتصال موفق (' + ( parseInt( response.data.latency_ms, 10 ) || 0 ) + ' میلی‌ثانیه)'
                                : ( ( response && response.data && response.data.message ) || 'اتصال ناموفق بود.' );
                        $result.text( text );
                } ).fail( function () {
                        $result.text( 'خطای ارتباط با سرور.' );
                } ).always( function () {
                        $btn.prop( 'disabled', false );
                } );
        }

        /**
         * Reorder helpers: swap the clicked provider with its neighbour.
         */
        function onChainMove( $card, up ) {
                var id = String( $card.data( 'id' ) || '' );
                var ids = [];
                $.each( chainState.providers, function ( i, p ) { ids.push( p.id ); } );

                var index = ids.indexOf( id );
                var swap  = up ? index - 1 : index + 1;
                if ( index < 0 || swap < 0 || swap >= ids.length ) {
                        return;
                }

                var tmp   = ids[ swap ];
                ids[ swap ] = ids[ index ];
                ids[ index ] = tmp;

                $card.find( '.kic-chain-up,.kic-chain-down' ).prop( 'disabled', true );

                chainPost( 'kic_reorder_providers', { ids: JSON.stringify( ids ) } )
                        .done( function () { renderChain(); } )
                        .fail( function () { renderChain(); } );
        }

        /**
         * Delete one provider.
         */
        function onChainDelete( $card ) {
                var id = String( $card.data( 'id' ) || '' );
                var provider = null;
                $.each( chainState.providers, function ( i, p ) {
                        if ( p.id === id ) { provider = p; }
                } );

                if ( ! window.confirm( 'ارائه‌دهندهٔ «' + ( provider ? provider.label : id ) + '» از زنجیره حذف شود؟' ) ) {
                        return;
                }

                chainPost( 'kic_delete_provider', { id: id } )
                        .done( function () { renderChain(); } )
                        .fail( function () { window.alert( 'حذف ناموفق بود.' ); } );
        }

        /**
         * Enter edit mode for one provider.
         */
        function onChainEdit( $card ) {
                var id = String( $card.data( 'id' ) || '' );
                var provider = null;
                $.each( chainState.providers, function ( i, p ) {
                        if ( p.id === id ) { provider = p; }
                } );
                if ( ! provider ) {
                        return;
                }

                chainState.mode = 'edit';
                chainState.edit = {
                        id:       provider.id,
                        label:    provider.label,
                        base_url: provider.base_url || ( 'https://' + provider.host ),
                        models:   provider.models || []
                };
                renderChain();
                $( '#kic-chain-label' ).trigger( 'focus' );
        }

        /* ------------------------------------------------------------------
         * v1.4 — portable settings bundle (export / import).
         * ------------------------------------------------------------------ */

        /**
         * Show the bundle log line.
         *
         * @param {string} text  Message.
         * @param {string} kind  'ok' | 'error'.
         * @return {void}
         */
        function bundleLog( text, kind ) {
                var $log = $( '#kic-bundle-log' );
                if ( ! $log.length ) {
                        return;
                }
                $log
                        .removeClass( 'is-ok is-error' )
                        .addClass( kind === 'error' ? 'is-error' : 'is-ok' )
                        .text( String( text ) )
                        .prop( 'hidden', false );
        }

        /**
         * Export the bundle JSON and trigger a client-side download.
         *
         * @return {void}
         */
        function onBundleExport() {
                var includeKeys = $( '#kic-bundle-include-keys' ).prop( 'checked' ) ? 1 : 0;

                if ( includeKeys && ! window.confirm( 'کلیدهای API به‌صورت متن آشکار در فایل قرار می‌گیرند — مطمئنید؟' ) ) {
                        return;
                }

                var $btn = $( '#kic-bundle-export' ).prop( 'disabled', true );
                $.post( KIC.ajaxurl, {
                        action: 'kic_bundle_export',
                        nonce: KIC.nonce,
                        include_keys: includeKeys
                } ).done( function ( response ) {
                        if ( ! response || ! response.success ) {
                                bundleLog( 'خروجی بسته ناموفق بود.', 'error' );
                                return;
                        }
                        var data = response.data || {};
                        var blob = new Blob( [ String( data.json || '' ) ], { type: 'application/json' } );
                        var url = window.URL.createObjectURL( blob );
                        var a = document.createElement( 'a' );
                        a.href = url;
                        a.download = String( data.filename || 'kic-bundle.json' );
                        document.body.appendChild( a );
                        a.click();
                        window.setTimeout( function () {
                                window.URL.revokeObjectURL( url );
                                a.remove();
                        }, 400 );
                        bundleLog( 'بسته آماده شد و دانلود آغاز شد.', 'ok' );
                } ).fail( function () {
                        bundleLog( 'خروجی بسته ناموفق بود.', 'error' );
                } ).always( function () {
                        $btn.prop( 'disabled', false );
                } );
        }

        /**
         * Import a pasted bundle JSON (AJAX, merge semantics server-side).
         *
         * @return {void}
         */
        function onBundleImport() {
                var raw = String( $( '#kic-bundle-json' ).val() || '' ).trim();
                if ( ! raw ) {
                        bundleLog( 'متن JSON بسته را در کادر بچسبانید.', 'error' );
                        return;
                }

                var $btn = $( '#kic-bundle-import' ).prop( 'disabled', true );
                $.post( KIC.ajaxurl, {
                        action: 'kic_bundle_import',
                        nonce: KIC.nonce,
                        bundle: raw
                } ).done( function ( response ) {
                        if ( ! response || ! response.success ) {
                                var message = ( response && response.data && response.data.message )
                                        ? response.data.message
                                        : 'بسته نامعتبر است — ساختار JSON خوانده نشد.';
                                bundleLog( message, 'error' );
                                return;
                        }
                        var data = response.data || {};
                        bundleLog(
                                ( data.message ? data.message + ' ' : '' ) +
                                '(' + ( data.providers || 0 ) + ' provider / ' + ( data.settings || 0 ) + ' setting)',
                                'ok'
                        );
                } ).fail( function () {
                        bundleLog( 'ورود بسته ناموفق بود.', 'error' );
                } ).always( function () {
                        $btn.prop( 'disabled', false );
                } );
        }

        $( function () {
                $( document ).on( 'click', '.kic-settings .kic-tab', onTabClick );
                $( document ).on( 'change', '#kic-preset', onPresetChange );
                $( document ).on( 'click', '#kic-test-btn', onTestClick );

                /* v1.4 — bundle export / import. */
                $( document ).on( 'click', '#kic-bundle-export', onBundleExport );
                $( document ).on( 'click', '#kic-bundle-import', onBundleImport );

                /* Chain manager events (delegated). */
                renderChain();
                $( document ).on( 'click', '#kic-chain-save', onChainSave );
                $( document ).on( 'click', '#kic-chain-load-models', function () { onChainLoadModels( false ); } );
                $( document ).on( 'click', '#kic-chain-load-refresh', function () { onChainLoadModels( true ); } );
                $( document ).on( 'click', '#kic-chain-cancel', function () {
                        chainState.mode = 'add';
                        chainState.edit = null;
                        renderChain();
                } );
                $( document ).on( 'click', '.kic-chain-up', function () { onChainMove( $( this ).closest( '.kic-chain-card' ), true ); } );
                $( document ).on( 'click', '.kic-chain-down', function () { onChainMove( $( this ).closest( '.kic-chain-card' ), false ); } );
                $( document ).on( 'click', '.kic-chain-delete', function () { onChainDelete( $( this ).closest( '.kic-chain-card' ) ); } );
                $( document ).on( 'click', '.kic-chain-edit', function () { onChainEdit( $( this ).closest( '.kic-chain-card' ) ); } );
                $( document ).on( 'click', '.kic-chain-test', function () { onChainTest( $( this ).closest( '.kic-chain-card' ) ); } );
                $( document ).on( 'click', '#kic-model-load', onModelFieldLoad );
                $( document ).on( 'change', '#kic-model-list', function () {
                        var v = String( $( this ).val() || '' );
                        if ( v ) {
                                $( '#kic-model' ).val( v );
                        }
                } );
        } );
}( jQuery ) );
