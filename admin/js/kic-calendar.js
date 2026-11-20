/**
 * Natilus — publish calendar interactions.
 *
 * Provided globals: KIC_CAL { ajaxurl, nonce, i18n: {lang, dict} }.
 *
 * Responsibilities (all explicit admin actions):
 *   - drag & drop: moving a queued item to another day → kic_queue_move.
 *   - pause / resume buttons → kic_queue_pause / kic_queue_resume.
 *   - "publish next now" → kic_queue_run_next.
 *   - per-item reorder arrows → kic_queue_reorder.
 *
 * @package ai-content-publishing-agent
 */
(function (window, document) {
	'use strict';

	var CAL = window.KIC_CAL || null;
	if (!CAL || typeof CAL.ajaxurl === 'undefined') {
		return;
	}

	var I18N = (CAL.i18n && CAL.i18n.dict) ? CAL.i18n : { lang: 'fa', dict: {} };

	function T(fa) {
		if (I18N.lang === 'en' && Object.prototype.hasOwnProperty.call(I18N.dict, fa)) {
			var en = I18N.dict[fa];
			if (en) {
				return String(en);
			}
		}
		return fa;
	}

	var root = document.getElementById('kic-cal-root');
	if (!root) {
		return;
	}

	var dragging = null;

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
		body.append('nonce', String(CAL.nonce || ''));
		Object.keys(params || {}).forEach(function (key) {
			var value = params[key];
			if (value !== undefined && value !== null) {
				body.append(key, value);
			}
		});

		return fetch(CAL.ajaxurl, {
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

	/**
	 * Tiny admin notice.
	 *
	 * @param {string} text  Message.
	 * @param {string} kind  'success' | 'error'.
	 * @return {void}
	 */
	function notice(text, kind) {
		var card = document.createElement('div');
		card.className = 'notice notice-' + (kind === 'error' ? 'error' : 'success') + ' is-dismissible';
		card.style.margin = '8px 0';
		card.textContent = String(text);
		root.insertBefore(card, root.firstChild);
		window.setTimeout(function () {
			if (card.parentNode) {
				card.parentNode.removeChild(card);
			}
		}, 6000);
	}

	/* ------------------------------------------------------------------
	 * Drag & drop rescheduling.
	 * ------------------------------------------------------------------ */

	function onDragStart(e) {
		var item = e.target.closest ? e.target.closest('.kic-cal-item') : null;
		if (!item) {
			return;
		}
		dragging = item;
		item.classList.add('is-dragging');
		if (e.dataTransfer) {
			e.dataTransfer.effectAllowed = 'move';
			try {
				e.dataTransfer.setData('text/plain', item.getAttribute('data-post') || '');
			} catch (err) {
				/* older engines */
			}
		}
	}

	function onDragEnd() {
		if (dragging) {
			dragging.classList.remove('is-dragging');
		}
		dragging = null;
		document.querySelectorAll('.kic-cal-day.is-drop-target').forEach(function (day) {
			day.classList.remove('is-drop-target');
		});
	}

	function onDragOver(e) {
		var day = e.target.closest ? e.target.closest('.kic-cal-day') : null;
		if (!day || day.classList.contains('is-blank') || !dragging) {
			return;
		}
		e.preventDefault();
		if (e.dataTransfer) {
			e.dataTransfer.dropEffect = 'move';
		}
		document.querySelectorAll('.kic-cal-day.is-drop-target').forEach(function (el) {
			if (el !== day) {
				el.classList.remove('is-drop-target');
			}
		});
		day.classList.add('is-drop-target');
	}

	function onDrop(e) {
		var day = e.target.closest ? e.target.closest('.kic-cal-day') : null;
		if (!day || !dragging) {
			return;
		}
		e.preventDefault();
		day.classList.remove('is-drop-target');

		var postId = dragging.getAttribute('data-post');
		var date = day.getAttribute('data-date');
		dragging.classList.remove('is-dragging');
		dragging = null;

		if (!postId || !date) {
			return;
		}

		post('kic_queue_move', { post_id: postId, date: date }).then(function () {
			notice(T('زمان جدید ذخیره شد.'), 'success');
			window.setTimeout(function () {
				window.location.reload();
			}, 700);
		}).catch(function (err) {
			notice(err.message || 'Move failed.', 'error');
		});
	}

	root.addEventListener('dragstart', onDragStart, false);
	root.addEventListener('dragend', onDragEnd, false);
	root.addEventListener('dragover', onDragOver, false);
	root.addEventListener('drop', onDrop, false);

	/* ------------------------------------------------------------------
	 * Pause / resume.
	 * ------------------------------------------------------------------ */

	var toggleBtn = document.getElementById('kic-cal-toggle');
	var statusEl = document.getElementById('kic-cal-status');
	var paused = root.getAttribute('data-paused') === '1';

	function renderPauseState() {
		if (statusEl) {
			statusEl.textContent = paused ? T('صف متوقف است.') : T('صف در حال اجراست.');
			statusEl.classList.toggle('is-paused', paused);
		}
		if (toggleBtn) {
			toggleBtn.textContent = paused ? T('ادامهٔ صف') : T('توقف صف');
			toggleBtn.classList.toggle('button-primary', paused);
		}
	}

	if (toggleBtn) {
		toggleBtn.addEventListener('click', function () {
			var action = paused ? 'kic_queue_resume' : 'kic_queue_pause';
			toggleBtn.disabled = true;
			post(action, {}).then(function () {
				paused = !paused;
				renderPauseState();
			}).catch(function (err) {
				notice(err.message || 'Toggle failed.', 'error');
			}).then(function () {
				toggleBtn.disabled = false;
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Publish next now.
	 * ------------------------------------------------------------------ */

	var runBtn = document.getElementById('kic-cal-run-next');
	if (runBtn) {
		runBtn.addEventListener('click', function () {
			if (!window.confirm(T('انتشار آیتم بعدی همین حالا') + '؟')) {
				return;
			}
			runBtn.disabled = true;
			post('kic_queue_run_next', {}).then(function (data) {
				notice(T('آیتم بعدی منتشر شد.') + ' — ' + String(data && data.title || ''), 'success');
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}).catch(function (err) {
				notice(err.message || 'Run failed.', 'error');
			}).then(function () {
				runBtn.disabled = false;
			});
		});
	}

	/* ------------------------------------------------------------------
	 * Per-item reorder arrows.
	 * ------------------------------------------------------------------ */

	root.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.kic-cal-move') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();
		btn.disabled = true;
		post('kic_queue_reorder', {
			post_id: btn.getAttribute('data-post'),
			up: btn.getAttribute('data-up') === '1' ? 1 : 0
		}).then(function () {
			window.location.reload();
		}).catch(function (err) {
			notice(err.message || 'Reorder failed.', 'error');
			btn.disabled = false;
		});
	});

})(window, document);
