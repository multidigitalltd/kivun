/* global kivun */
/*
 * Kivun Center — the Holland questionnaire ([kivun_holland]).
 *
 * Every screen is already in the page; this shows one at a time, keeps the
 * answers, and hands them to the server, which is the only place they are
 * scored. At the end the participant is thanked, not shown a result — the
 * representative prints the report.
 *
 * Answers are saved as the person goes, so closing the tab costs nothing:
 * logging in again with the same phone and ID number picks up where they
 * stopped. Nothing that identifies the person is kept in the browser —
 * sessionStorage holds the row number, its token and the answers so a
 * refresh does not send them back to the login, and it goes with the tab.
 *
 * The behaviour follows the design handoff (Holland Demo.dc.html): a choice
 * lights its button and moves on after 300ms, during which nothing else is
 * taken; the stage can be dragged — past 60px it is tinted and stamped,
 * past 100px on release it is a choice, otherwise it springs back.
 */
(function () {
	'use strict';

	// The questionnaire, not the dashboard, which shares its stylesheet.
	var root = document.querySelector('.kivun-holland[data-total]');
	if (!root || typeof kivun === 'undefined') {
		return;
	}

	var TOTAL = parseInt(root.getAttribute('data-total'), 10) || 0;
	var PAGE = root.getAttribute('data-page') || '0';
	var STORE = 'kivun_holland_' + PAGE;
	var SAVE_DELAY = 3000;
	var ADVANCE = 300;
	var LIT = 60;
	var PICK = 100;

	var card = root.querySelector('[data-hl-card]');
	var login = root.querySelector('[data-hl-login]');
	var form = root.querySelector('[data-hl-form]');
	var alertBox = root.querySelector('[data-hl-alert]');
	var quiz = root.querySelector('[data-hl-quiz]');
	var thanks = root.querySelector('[data-hl-thanks]');
	var band = root.querySelector('[data-hl-progress]');
	var bandSteps = band ? Array.prototype.slice.call(band.querySelectorAll('.kivun-hl-steps__step')) : [];
	var screens = Array.prototype.slice.call(root.querySelectorAll('[data-hl-screen]'));
	var partLabel = root.querySelector('[data-hl-part]');
	var answeredEl = root.querySelector('[data-hl-answered]');
	var fill = root.querySelector('[data-hl-fill]');

	var state = { lead: 0, token: '', responses: blank(), screen: 0 };
	var saveTimer = null;
	var saving = Promise.resolve();
	var busy = false;        // The 300ms after a choice: nothing else is taken.
	var advanceTimer = null;

	function blank() {
		return new Array(TOTAL + 1).join('-');
	}

	function msg(key, fallback) {
		return (kivun.i18n && kivun.i18n[key]) || fallback;
	}

	// ── Talking to the server ───────────────────────────────────────────────

	function post(action, fields) {
		var body = new FormData();
		body.append('action', 'kivun_holland_' + action);
		body.append('nonce', kivun.nonce);
		Object.keys(fields).forEach(function (k) {
			body.append(k, fields[k]);
		});

		return fetch(kivun.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) {
				return r.text().then(function (text) {
					var trimmed = (text || '').trim();
					// A rejected nonce is a bare "-1": the page is older than
					// the session it was drawn for.
					if (trimmed === '-1' || trimmed === '0') {
						throw new Error(msg('session_expired', 'פג תוקף החיבור. רעננו את הדף ונסו שוב.'));
					}
					try {
						return JSON.parse(trimmed);
					} catch (e) {
						throw new Error(r.ok ? msg('bad_response', 'התקבלה תשובה לא תקינה מהשרת.') : msg('error_generic', 'אירעה שגיאה, נסה שוב.'));
					}
				});
			});
	}

	function remember() {
		try {
			sessionStorage.setItem(STORE, JSON.stringify(state));
		} catch (e) { /* Private mode: the server copy is enough. */ }
	}

	function forget() {
		try {
			sessionStorage.removeItem(STORE);
		} catch (e) { /* Nothing to do. */ }
	}

	// One save at a time. A save that started while another was in flight
	// could otherwise reach the server first and then be overwritten by the
	// older snapshot. Each waits for the one before it, and reads the answers
	// when it actually leaves, so it always carries the latest. The time it
	// leaves goes with it, so the server can also drop anything older (the
	// one sent as the tab closes does not join this queue).
	function save(finish) {
		clearTimeout(saveTimer);
		saveTimer = null;
		remember();

		var run = function () {
			var fields = { lead: state.lead, token: state.token, responses: state.responses, seq: Date.now() };
			if (finish) {
				fields.finish = '1';
			}
			return post('save', fields).then(function (res) {
				if (!res || !res.success) {
					var data = (res && res.data) || {};
					if (data.relogin) {
						backToLogin(data.message);
					}
					throw new Error(data.message || msg('error_generic', 'אירעה שגיאה, נסה שוב.'));
				}
				return res.data || {};
			});
		};

		var next = saving.catch(function () { /* The previous one's failure is its own. */ }).then(run);
		saving = next;
		return next;
	}

	// At most one save every few seconds while answers keep coming. Not
	// pushed back by each new answer: with a question to a screen, somebody
	// answering steadily would otherwise never pause long enough to be saved.
	function saveSoon() {
		if (saveTimer) {
			return;
		}
		saveTimer = setTimeout(function () {
			save(false).catch(function () { /* Tried again on the next "next". */ });
		}, SAVE_DELAY);
	}

	// ── Answers ─────────────────────────────────────────────────────────────

	function answeredCount() {
		return state.responses.replace(/-/g, '').length;
	}

	function setAnswer(num, value) {
		var i = num - 1;
		state.responses = state.responses.slice(0, i) + value + state.responses.slice(i + 1);
	}

	function paintAnswers() {
		root.querySelectorAll('[data-hl-item]').forEach(function (item) {
			var num = parseInt(item.getAttribute('data-hl-item'), 10);
			var value = state.responses.charAt(num - 1);
			item.querySelectorAll('input[type="radio"]').forEach(function (input) {
				input.checked = input.value === value;
			});
		});
		paintProgress();
	}

	function paintProgress() {
		var n = answeredCount();
		if (answeredEl) {
			answeredEl.textContent = String(n);
		}
		if (fill) {
			fill.style.width = (TOTAL ? (100 * n / TOTAL) : 0) + '%';
		}
	}

	function missingIn(screen) {
		return Array.prototype.filter.call(screen.querySelectorAll('[data-hl-item]'), function (item) {
			var num = parseInt(item.getAttribute('data-hl-item'), 10);
			return state.responses.charAt(num - 1) === '-';
		});
	}

	// ── Screens ─────────────────────────────────────────────────────────────

	function isIntro(screen) {
		return screen.hasAttribute('data-hl-intro');
	}

	// The step row above the questions: the parts before this one done, this
	// one current, the rest still to come.
	function paintSteps(part) {
		bandSteps.forEach(function (step, k) {
			step.classList.toggle('is-done', k < part);
			step.classList.toggle('is-active', k === part);
			step.classList.toggle('is-future', k > part);
			if (k === part) {
				step.setAttribute('aria-current', 'step');
			} else {
				step.removeAttribute('aria-current');
			}
		});
	}

	function scrollToTop() {
		var top = root.getBoundingClientRect().top + window.pageYOffset - 24;
		if (window.pageYOffset > top) {
			window.scrollTo(0, Math.max(0, top));
		}
	}

	function show(index, focus) {
		clearTimeout(advanceTimer);
		busy = false;
		state.screen = Math.max(0, Math.min(index, screens.length - 1));
		screens.forEach(function (s, i) {
			s.hidden = i !== state.screen;
		});

		var current = screens[state.screen];
		var part = parseInt(current.getAttribute('data-hl-part-index'), 10) || 0;
		if (band) {
			band.hidden = isIntro(current);
		}
		paintSteps(part);
		if (partLabel) {
			partLabel.textContent = current.getAttribute('data-hl-label') || '';
		}
		var alertEl = current.querySelector('[data-hl-page-alert]');
		if (alertEl) {
			alertEl.hidden = true;
		}
		current.querySelectorAll('.is-missing').forEach(function (el) {
			el.classList.remove('is-missing');
		});
		remember();

		if (focus !== false) {
			scrollToTop();
			var heading = current.querySelector('[data-hl-focus]');
			if (heading) {
				heading.focus({ preventScroll: true });
			}
		}
	}

	// Where somebody coming back should land: the screen holding their first
	// unanswered question, or the very start when there is none yet.
	function resumeScreen() {
		if (answeredCount() === 0) {
			return 0;
		}
		for (var i = 0; i < screens.length; i++) {
			if (missingIn(screens[i]).length) {
				return i;
			}
		}
		return screens.length - 1;
	}

	// The login carries its own logo, in the panel beside the form; the
	// card's logo strip is for the screens after it.
	function setLogin(on) {
		login.hidden = !on;
		if (card) {
			card.classList.toggle('is-login', on);
		}
	}

	function enterQuiz(index) {
		setLogin(false);
		thanks.hidden = true;
		quiz.hidden = false;
		paintAnswers();
		show(index);
	}

	function backToLogin(message) {
		forget();
		state = { lead: 0, token: '', responses: blank(), screen: 0 };
		quiz.hidden = true;
		thanks.hidden = true;
		setLogin(true);
		if (message) {
			alertBox.textContent = message;
			alertBox.hidden = false;
		}
	}

	function showThanks() {
		forget();
		quiz.hidden = true;
		setLogin(false);
		thanks.hidden = false;
		scrollToTop();
		var heading = thanks.querySelector('[data-hl-focus]');
		if (heading) {
			heading.focus({ preventScroll: true });
		}
	}

	function pageAlert(screen, text) {
		var alertEl = screen.querySelector('[data-hl-page-alert]');
		if (!alertEl) {
			return;
		}
		// Rebuilt each time so the shake plays again.
		alertEl.hidden = true;
		alertEl.innerHTML = '';
		var bang = document.createElement('span');
		bang.className = 'kivun-hl-bang';
		bang.setAttribute('aria-hidden', 'true');
		bang.textContent = '!';
		alertEl.appendChild(bang);
		alertEl.appendChild(document.createTextNode(text));
		void alertEl.offsetWidth;
		alertEl.hidden = false;
	}

	// ── Login ───────────────────────────────────────────────────────────────

	function fieldError(name, text) {
		var input = form.querySelector('[name="' + name + '"]');
		var slot = form.querySelector('[data-hl-error="' + name + '"]');
		if (slot) {
			var m = slot.querySelector('[data-hl-msg]');
			if (m) {
				m.textContent = text || '';
			}
			slot.hidden = !text;
		}
		if (input) {
			if (text) {
				input.setAttribute('aria-invalid', 'true');
			} else {
				input.removeAttribute('aria-invalid');
			}
		}
	}

	function clearErrors() {
		['name', 'phone', 'id_number', 'privacy_consent'].forEach(function (n) {
			fieldError(n, '');
		});
		alertBox.hidden = true;
		alertBox.textContent = '';
		login.classList.remove('is-error');
	}

	function showLoginAlert(text) {
		login.classList.toggle('is-error', !!form.querySelector('[aria-invalid="true"]'));
		alertBox.hidden = true;
		alertBox.textContent = text;
		void alertBox.offsetWidth;
		alertBox.hidden = false;
	}

	var FIX = 'יש לתקן את השדות המסומנים כדי להמשיך.';

	// An Israeli number: local digits starting 0, nine or ten of them, with
	// +972 / 972 read as the leading 0.
	function validPhone(raw) {
		var digits = String(raw).replace(/\D+/g, '');
		if (digits.indexOf('972') === 0) {
			digits = '0' + digits.slice(3);
		}
		return /^0\d{8,9}$/.test(digits);
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		clearErrors();

		var name = form.elements.name.value.trim();
		var phone = form.elements.phone.value.trim();
		var id = form.elements.id_number.value.trim();
		var consent = form.elements.privacy_consent;

		var missing = false;
		if (!name) {
			fieldError('name', 'יש למלא שם מלא');
			missing = true;
		}
		if (!phone) {
			fieldError('phone', 'יש למלא מספר טלפון');
			missing = true;
		} else if (!validPhone(phone)) {
			// The same rule the server applies, so the mistake is shown before
			// anything is sent.
			fieldError('phone', 'מספר הטלפון אינו תקין');
			missing = true;
		}
		if (!id) {
			fieldError('id_number', 'יש למלא תעודת זהות');
			missing = true;
		}
		if (consent && !consent.checked) {
			fieldError('privacy_consent', 'יש לאשר את מדיניות הפרטיות');
			missing = true;
		}
		if (missing) {
			showLoginAlert(FIX);
			var first = form.querySelector('[aria-invalid="true"]');
			if (first) {
				first.focus();
			}
			return;
		}

		var btn = form.querySelector('[data-hl-login-btn]');
		btn.disabled = true;

		var fields = { name: name, phone: phone, id_number: id, page: PAGE };
		if (consent && consent.checked) {
			fields.privacy_consent = '1';
		}

		post('start', fields)
			.then(function (res) {
				if (!res || !res.success) {
					var data = (res && res.data) || {};
					Object.keys(data.fields || {}).forEach(function (k) {
						fieldError(k, data.fields[k]);
					});
					throw new Error(data.message || msg('error_generic', 'אירעה שגיאה, נסה שוב.'));
				}
				state.lead = res.data.lead;
				state.token = res.data.token;
				state.responses = typeof res.data.responses === 'string' && res.data.responses.length === TOTAL ? res.data.responses : blank();
				enterQuiz(resumeScreen());
			})
			.catch(function (err) {
				showLoginAlert(err.message);
				var first = form.querySelector('[aria-invalid="true"]');
				if (first) {
					first.focus();
				}
			})
			.then(function () {
				btn.disabled = false;
			});
	});

	// ── Answering ───────────────────────────────────────────────────────────

	// Record a choice on a question, as the radio change does.
	function record(item, value) {
		setAnswer(parseInt(item.getAttribute('data-hl-item'), 10), value);
		item.classList.remove('is-missing');
		var screen = item.closest('[data-hl-screen]');
		var alertEl = screen && screen.querySelector('[data-hl-page-alert]');
		if (alertEl && !alertEl.hidden && !missingIn(screen).length) {
			alertEl.hidden = true;
		}
		paintProgress();
		remember();
		saveSoon();
	}

	quiz.addEventListener('change', function (e) {
		var input = e.target;
		if (!input || input.type !== 'radio') {
			return;
		}
		var item = input.closest('[data-hl-item]');
		if (item) {
			record(item, input.value === 'y' ? 'y' : 'n');
		}
	});

	// After a choice: the button stays lit for 300ms, then the next question.
	// Nothing else is taken meanwhile. On the last question the choice does
	// not submit; focus goes to "finish".
	function advanceFrom(screen) {
		var index = screens.indexOf(screen);
		clearTimeout(advanceTimer);
		busy = true;
		advanceTimer = setTimeout(function () {
			busy = false;
			if (state.screen !== index || missingIn(screen).length) {
				return;
			}
			var finish = screen.querySelector('[data-hl-finish]');
			if (finish) {
				finish.focus();
				return;
			}
			var upcoming = screens[index + 1];
			if (upcoming && isIntro(upcoming)) {
				// The end of a part: saved at once, not with the next few.
				save(false).catch(function () { /* Tried again on the next "next". */ });
			}
			show(index + 1);
		}, ADVANCE);
	}

	// Only a choice made with the mouse or a finger moves on by itself. The
	// arrow keys also select a radio button, and a keyboard user moving
	// between "yes" and "no" must not be carried off to the next question.
	// The answer the pointer went down on has to be the one clicked, and any
	// key pressed in between cancels it.
	var pressed = null;
	quiz.addEventListener('pointerdown', function (e) {
		pressed = e.target.closest('.kivun-hl-opt');
	});
	quiz.addEventListener('keydown', function () {
		pressed = null;
	});

	quiz.addEventListener('click', function (e) {
		var input = e.target;
		if (!input || input.type !== 'radio') {
			return;
		}
		if (busy) {
			// A second choice inside the 300ms is not taken.
			e.preventDefault();
			return;
		}
		if (!pressed || input.closest('.kivun-hl-opt') !== pressed) {
			return;
		}
		pressed = null;
		var screen = input.closest('[data-hl-screen]');
		if (screen && screen.classList.contains('kivun-hl-page--single')) {
			advanceFrom(screen);
		}
	});

	// ── Dragging the stage: right for "yes", left for "no" ──────────────────

	var drag = null;

	function setDrag(d, dx) {
		d.dx = dx;
		var rot = Math.max(-4, Math.min(4, dx / 25));
		d.stage.style.transform = 'translateX(' + dx + 'px) rotate(' + rot + 'deg)';
		d.item.classList.toggle('is-lit-yes', dx > LIT);
		d.item.classList.toggle('is-lit-no', dx < -LIT);
	}

	function endDrag(cancel) {
		if (!drag) {
			return;
		}
		var d = drag;
		drag = null;
		d.stage.classList.remove('is-dragging');
		d.stage.style.transform = '';
		d.item.classList.remove('is-lit-yes', 'is-lit-no');
		if (cancel || Math.abs(d.dx) <= PICK || busy) {
			return;
		}
		var input = d.item.querySelector('input[value="' + (d.dx > 0 ? 'y' : 'n') + '"]');
		if (!input) {
			return;
		}
		input.checked = true;
		record(d.item, d.dx > 0 ? 'y' : 'n');
		advanceFrom(d.item.closest('[data-hl-screen]'));
	}

	quiz.addEventListener('pointerdown', function (e) {
		var stage = e.target.closest('.kivun-hl-page--single [data-hl-stage]');
		if (!stage || busy || (e.pointerType === 'mouse' && e.button !== 0)) {
			return;
		}
		drag = { stage: stage, item: stage.closest('[data-hl-item]'), x: e.clientX, y: e.clientY, dx: 0, id: e.pointerId, sideways: null };
	});

	quiz.addEventListener('pointermove', function (e) {
		if (!drag || e.pointerId !== drag.id) {
			return;
		}
		var dx = e.clientX - drag.x;
		var dy = e.clientY - drag.y;
		// Decide once whether this is a sideways drag or the page scrolling.
		if (drag.sideways === null) {
			if (Math.abs(dx) < 8 && Math.abs(dy) < 8) {
				return;
			}
			drag.sideways = Math.abs(dx) > Math.abs(dy);
			if (!drag.sideways) {
				drag = null;
				return;
			}
			drag.stage.classList.add('is-dragging');
			try {
				drag.stage.setPointerCapture(e.pointerId);
			} catch (err) { /* Not every pointer can be captured. */ }
		}
		setDrag(drag, dx);
	});

	quiz.addEventListener('pointerup', function (e) {
		if (drag && e.pointerId === drag.id) {
			endDrag(drag.sideways !== true);
		}
	});
	quiz.addEventListener('pointercancel', function () {
		endDrag(true);
	});

	// ── Back and forward ────────────────────────────────────────────────────

	quiz.addEventListener('click', function (e) {
		var prev = e.target.closest('[data-hl-prev]');
		var next = e.target.closest('[data-hl-next]');
		if (!prev && !next) {
			return;
		}
		if (busy) {
			return;
		}
		if (prev) {
			show(state.screen - 1);
			return;
		}

		var screen = screens[state.screen];
		var missing = missingIn(screen);

		if (missing.length) {
			missing.forEach(function (item) {
				item.classList.add('is-missing');
			});
			pageAlert(screen, 'יש לבחור "כן" או "לא" כדי להמשיך.');
			var radio = missing[0].querySelector('input[type="radio"]');
			if (radio) {
				radio.focus({ preventScroll: true });
			}
			return;
		}

		if (!next.hasAttribute('data-hl-finish')) {
			// Only questions hold answers; an opening screen has nothing new
			// to save. A question is saved with the next few, the end of a
			// part at once.
			if (!isIntro(screen)) {
				var upcoming = screens[state.screen + 1];
				if (upcoming && !isIntro(upcoming)) {
					saveSoon();
				} else {
					save(false).catch(function () { /* Tried again on the next "next". */ });
				}
			}
			show(state.screen + 1);
			return;
		}

		// The last question. Anything still blank elsewhere — a question
		// skipped with "back" — is found before the server is asked.
		var firstGap = resumeScreen();
		if (answeredCount() < TOTAL && firstGap !== state.screen) {
			show(firstGap);
			var gapScreen = screens[firstGap];
			missingIn(gapScreen).forEach(function (item) {
				item.classList.add('is-missing');
			});
			pageAlert(gapScreen, 'יש לענות על כל השאלות לפני הסיום — נשארה כאן שאלה ללא תשובה.');
			return;
		}

		next.disabled = true;
		save(true)
			.then(function (data) {
				if (data.done) {
					showThanks();
				}
			})
			.catch(function (err) {
				pageAlert(screen, err.message);
			})
			.then(function () {
				next.disabled = false;
			});
	});

	// A save that is still waiting when the tab closes goes out with it.
	window.addEventListener('pagehide', function () {
		if (!saveTimer || !state.lead || !navigator.sendBeacon) {
			return;
		}
		var body = new FormData();
		body.append('action', 'kivun_holland_save');
		body.append('nonce', kivun.nonce);
		body.append('lead', state.lead);
		body.append('token', state.token);
		body.append('responses', state.responses);
		body.append('seq', Date.now());
		navigator.sendBeacon(kivun.ajax_url, body);
	});

	// ── A refresh in the middle ─────────────────────────────────────────────

	(function restore() {
		var saved = null;
		try {
			saved = JSON.parse(sessionStorage.getItem(STORE) || 'null');
		} catch (e) {
			saved = null;
		}
		if (!saved || !saved.lead || !saved.token || typeof saved.responses !== 'string' || saved.responses.length !== TOTAL) {
			return;
		}
		state = saved;
		enterQuiz(Math.min(state.screen || 0, screens.length - 1));
	})();
})();
