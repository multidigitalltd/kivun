/**
 * Browser-based voice dictation for Kivun text fields and editors.
 *
 * Adds a small microphone button next to every writable field inside a Kivun
 * context (forms, meta boxes, the content creator) and to each TinyMCE editor,
 * using the Web Speech API in Hebrew (he-IL). No external service is used — the
 * recognition runs in the browser. Silently does nothing on unsupported
 * browsers.
 */
(function () {
	'use strict';

	var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
	if (!SR) { return; }

	var activeBtn = null, rec = null;

	function stop() {
		if (rec) { try { rec.stop(); } catch (e) {} rec = null; }
		if (activeBtn) { activeBtn.classList.remove('is-rec'); activeBtn = null; }
	}

	function start(btn, onFinal) {
		if (activeBtn === btn) { stop(); return; }
		stop();
		activeBtn = btn;
		btn.classList.add('is-rec');
		rec = new SR();
		rec.lang = 'he-IL';
		rec.interimResults = false;
		rec.continuous = false;
		rec.maxAlternatives = 1;
		rec.onresult = function (e) {
			var txt = '';
			for (var i = e.resultIndex; i < e.results.length; i++) {
				if (e.results[i].isFinal) { txt += e.results[i][0].transcript; }
			}
			txt = txt.trim();
			if (txt) { onFinal(txt); }
		};
		rec.onerror = function () {};
		rec.onend = function () {
			if (activeBtn === btn) { btn.classList.remove('is-rec'); activeBtn = null; }
			rec = null;
		};
		try { rec.start(); } catch (err) { stop(); }
	}

	function micButton(onFinal) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'kivun-mic';
		b.setAttribute('aria-label', 'הקלטה קולית');
		b.title = 'הקלטה קולית (עברית)';
		b.innerHTML = '<span aria-hidden="true">🎤</span>';
		b.addEventListener('click', function (e) {
			e.preventDefault();
			start(b, onFinal);
		});
		return b;
	}

	function attachToField(field) {
		if (field.dataset.kivunMic || field.classList.contains('wp-editor-area')) { return; }
		if (field.readOnly || field.disabled) { return; }
		field.dataset.kivunMic = '1';
		var btn = micButton(function (txt) {
			var cur = field.value || '';
			field.value = cur ? (cur.replace(/\s+$/, '') + ' ' + txt) : txt;
			field.dispatchEvent(new Event('input', { bubbles: true }));
			field.focus();
		});
		if (field.nextSibling) {
			field.parentNode.insertBefore(btn, field.nextSibling);
		} else {
			field.parentNode.appendChild(btn);
		}
	}

	function attachToEditor(wrapEl) {
		if (wrapEl.dataset.kivunMic) { return; }
		var ta = wrapEl.querySelector('textarea.wp-editor-area');
		if (!ta) { return; }
		wrapEl.dataset.kivunMic = '1';
		var id = ta.id;
		var btn = micButton(function (txt) {
			var ed = window.tinymce && window.tinymce.get(id);
			if (ed && !ed.isHidden()) {
				ed.insertContent(txt + ' ');
				ed.focus();
			} else {
				ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + txt;
				ta.focus();
			}
		});
		btn.classList.add('kivun-mic--editor');
		var tools = wrapEl.querySelector('.wp-editor-tools');
		if (tools) { tools.appendChild(btn); }
		else { wrapEl.insertBefore(btn, wrapEl.firstChild); }
	}

	var SCOPES = '.kivun-cc-front, .kivun-lp-form, .kivun-lp-admin, .kivun-lead-form, '
		+ '.kivun-ef, .kivun-meta-table, .kivun-apply-form, .kivun-employer-form, [data-kivun-voice]';
	var FIELDS = 'input[type=text], input[type=email], input[type=search], input[type=tel], '
		+ 'input[type=url], input:not([type]), textarea';

	function scan() {
		var scopes = document.querySelectorAll(SCOPES);
		Array.prototype.forEach.call(scopes, function (scope) {
			Array.prototype.forEach.call(scope.querySelectorAll(FIELDS), attachToField);
		});
		Array.prototype.forEach.call(document.querySelectorAll('.wp-editor-wrap'), attachToEditor);
	}

	function init() {
		scan();
		// Editors and page-builder content initialise late — re-scan a few times.
		if (window.jQuery) {
			window.jQuery(document).on('tinymce-editor-init', scan);
		}
		var ticks = 0;
		var timer = window.setInterval(function () {
			ticks++;
			scan();
			if (ticks > 6) { window.clearInterval(timer); }
		}, 700);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
