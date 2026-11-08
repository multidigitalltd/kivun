/* global kivun */
/*
 * Kivun Center — the Holland questionnaire ([kivun_holland]).
 *
 * Every screen is already in the page; this shows one at a time, keeps the
 * answers, and hands them to the server, which is the only place they are
 * scored. Answers are saved as the person goes — on every "next", and a few
 * seconds after the last click — so closing the tab costs nothing: logging
 * in again with the same phone and ID number picks up where they stopped.
 *
 * Nothing that identifies the person is kept in the browser. sessionStorage
 * holds the row number, its token and the answers so a refresh does not send
 * them back to the login; it is gone when the tab is.
 */
(function () {
	'use strict';

	var root = document.querySelector('.kivun-holland');
	if (!root || typeof kivun === 'undefined') {
		return;
	}

	var TOTAL = parseInt(root.getAttribute('data-total'), 10) || 0;
	var PAGE = root.getAttribute('data-page') || '0';
	var STORE = 'kivun_holland_' + PAGE;
	var SAVE_DELAY = 3000;

	var login = root.querySelector('[data-hl-login]');
	var form = root.querySelector('[data-hl-form]');
	var alertBox = root.querySelector('[data-hl-alert]');
	var quiz = root.querySelector('[data-hl-quiz]');
	var results = root.querySelector('[data-hl-results]');
	var screens = Array.prototype.slice.call(root.querySelectorAll('[data-hl-screen]'));
	var partLabel = root.querySelector('[data-hl-part]');
	var answeredEl = root.querySelector('[data-hl-answered]');
	var fill = root.querySelector('[data-hl-fill]');

	var state = { lead: 0, token: '', responses: blank(), screen: 0 };
	var saveTimer = null;
	var saving = Promise.resolve();

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
			fill.style.width = (TOTAL ? Math.round(100 * n / TOTAL) : 0) + '%';
		}
	}

	function missingIn(screen) {
		return Array.prototype.filter.call(screen.querySelectorAll('[data-hl-item]'), function (item) {
			var num = parseInt(item.getAttribute('data-hl-item'), 10);
			return state.responses.charAt(num - 1) === '-';
		});
	}

	// ── Screens ─────────────────────────────────────────────────────────────

	function show(index, focus) {
		state.screen = Math.max(0, Math.min(index, screens.length - 1));
		screens.forEach(function (s, i) {
			s.hidden = i !== state.screen;
		});

		var current = screens[state.screen];
		if (partLabel) {
			partLabel.textContent = current.getAttribute('data-hl-label') || '';
		}
		var alertEl = current.querySelector('[data-hl-page-alert]');
		if (alertEl) {
			alertEl.hidden = true;
		}
		remember();

		if (focus !== false) {
			var top = root.getBoundingClientRect().top + window.pageYOffset - 24;
			if (window.pageYOffset > top) {
				window.scrollTo(0, Math.max(0, top));
			}
			var heading = current.querySelector('[data-hl-focus]');
			if (heading) {
				heading.focus({ preventScroll: true });
			}
		}
	}

	// Where somebody coming back should land: the page holding their first
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

	function enterQuiz(index) {
		login.hidden = true;
		results.hidden = true;
		quiz.hidden = false;
		paintAnswers();
		show(index);
	}

	function backToLogin(message) {
		forget();
		state = { lead: 0, token: '', responses: blank(), screen: 0 };
		quiz.hidden = true;
		results.hidden = true;
		login.hidden = false;
		if (message) {
			alertBox.textContent = message;
			alertBox.hidden = false;
		}
	}

	function showResults(html) {
		forget();
		quiz.hidden = true;
		login.hidden = true;
		results.innerHTML = html;
		results.hidden = false;

		var top = root.getBoundingClientRect().top + window.pageYOffset - 24;
		window.scrollTo(0, Math.max(0, top));
		var heading = results.querySelector('[data-hl-focus]');
		if (heading) {
			heading.focus({ preventScroll: true });
		}
	}

	// ── Login ───────────────────────────────────────────────────────────────

	function fieldError(name, text) {
		var input = form.querySelector('[name="' + name + '"]');
		var slot = form.querySelector('[data-hl-error="' + name + '"]');
		if (slot) {
			slot.textContent = text || '';
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
			fieldError('name', 'נא למלא שם מלא.');
			missing = true;
		}
		if (!phone) {
			fieldError('phone', 'נא למלא מספר טלפון.');
			missing = true;
		}
		if (!id) {
			fieldError('id_number', 'נא למלא מספר תעודת זהות.');
			missing = true;
		}
		if (consent && !consent.checked) {
			fieldError('privacy_consent', 'יש לאשר את מדיניות הפרטיות.');
			missing = true;
		}
		if (missing) {
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
				alertBox.textContent = err.message;
				alertBox.hidden = false;
				var first = form.querySelector('[aria-invalid="true"]');
				if (first) {
					first.focus();
				}
			})
			.then(function () {
				btn.disabled = false;
			});
	});

	// ── The questionnaire ───────────────────────────────────────────────────

	quiz.addEventListener('change', function (e) {
		var input = e.target;
		if (!input || input.type !== 'radio') {
			return;
		}
		var item = input.closest('[data-hl-item]');
		if (!item) {
			return;
		}
		setAnswer(parseInt(item.getAttribute('data-hl-item'), 10), input.value === 'y' ? 'y' : 'n');
		item.classList.remove('is-missing');

		// The page's warning goes once there is nothing left to warn about.
		var screen = item.closest('[data-hl-screen]');
		var alertEl = screen && screen.querySelector('[data-hl-page-alert]');
		if (alertEl && !alertEl.hidden && !missingIn(screen).length) {
			alertEl.hidden = true;
		}
		paintProgress();
		remember();
		saveSoon();
	});

	// ── One question to a screen: answering moves on ───────────────────────

	// Only a choice made with the mouse or a finger moves on by itself. The
	// arrow keys also select a radio button, and a keyboard user moving
	// between "yes" and "no" must not be carried off to the next question.
	// The answer the pointer went down on; the click that follows must be
	// on the same one, and any key press in between cancels it.
	var pressed = null;
	var advanceTimer = null;
	quiz.addEventListener('pointerdown', function (e) {
		pressed = e.target.closest('.kivun-hl-opt');
	});
	quiz.addEventListener('keydown', function () {
		pressed = null;
	});

	quiz.addEventListener('click', function (e) {
		var input = e.target;
		if (!input || input.type !== 'radio' || !pressed || input.closest('.kivun-hl-opt') !== pressed) {
			return;
		}
		pressed = null;
		var screen = input.closest('[data-hl-screen]');
		if (screen && screen.classList.contains('kivun-hl-page--single')) {
			advanceFrom(screen);
		}
	});

	function advanceFrom(screen) {
		var index = screens.indexOf(screen);
		clearTimeout(advanceTimer);
		// Long enough to see the choice light up.
		advanceTimer = setTimeout(function () {
			if (state.screen !== index || missingIn(screen).length) {
				return;
			}
			var finish = screen.querySelector('[data-hl-finish]');
			if (finish) {
				finish.focus();
				return;
			}
			var upcoming = screens[index + 1];
			if (upcoming && !upcoming.querySelector('[data-hl-item]')) {
				// The end of a part: saved at once, not with the next few.
				save(false).catch(function () { /* Tried again on the next "next". */ });
			}
			show(index + 1);
		}, 300);
	}

	// ── Swipe on a phone: right for "yes", left for "no" ───────────────────

	// A deliberate sideways stroke across the question only: long enough,
	// clearly more sideways than down, and quick, so that scrolling the page
	// or a finger resting on the screen answers nothing. The question follows
	// the finger a little, tinted toward the answer it is about to give.
	var swipe = null;
	var SWIPE_MIN = 70;

	quiz.addEventListener('touchstart', function (e) {
		var item = e.target.closest('.kivun-hl-page--single [data-hl-item]');
		if (!item || e.touches.length !== 1) {
			swipe = null;
			return;
		}
		swipe = { item: item, x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now(), dx: 0, sideways: null };
	}, { passive: true });

	quiz.addEventListener('touchmove', function (e) {
		if (!swipe) {
			return;
		}
		var dx = e.touches[0].clientX - swipe.x;
		var dy = e.touches[0].clientY - swipe.y;
		if (swipe.sideways === null && (Math.abs(dx) > 10 || Math.abs(dy) > 10)) {
			swipe.sideways = Math.abs(dx) > Math.abs(dy) * 1.5;
		}
		if (!swipe.sideways) {
			return;
		}
		swipe.dx = dx;
		swipe.item.classList.add('is-swiping');
		swipe.item.style.transform = 'translateX(' + Math.round(dx * 0.4) + 'px)';
		swipe.item.classList.toggle('is-swipe-yes', dx > SWIPE_MIN);
		swipe.item.classList.toggle('is-swipe-no', dx < -SWIPE_MIN);
	}, { passive: true });

	function endSwipe() {
		if (!swipe) {
			return;
		}
		var s = swipe;
		swipe = null;
		s.item.style.transform = '';
		s.item.classList.remove('is-swiping', 'is-swipe-yes', 'is-swipe-no');
		if (!s.sideways || Math.abs(s.dx) < SWIPE_MIN || Date.now() - s.t > 1000) {
			return;
		}
		var input = s.item.querySelector('input[value="' + (s.dx > 0 ? 'y' : 'n') + '"]');
		if (!input) {
			return;
		}
		input.checked = true;
		input.dispatchEvent(new Event('change', { bubbles: true }));
		advanceFrom(s.item.closest('[data-hl-screen]'));
	}
	quiz.addEventListener('touchend', endSwipe);
	quiz.addEventListener('touchcancel', function () {
		if (swipe) {
			swipe.dx = 0;
		}
		endSwipe();
	});

	quiz.addEventListener('click', function (e) {
		var prev = e.target.closest('[data-hl-prev]');
		var next = e.target.closest('[data-hl-next]');
		if (prev) {
			clearTimeout(advanceTimer);
			show(state.screen - 1);
			return;
		}
		if (!next) {
			return;
		}

		var screen = screens[state.screen];
		var alertEl = screen.querySelector('[data-hl-page-alert]');
		var missing = missingIn(screen);

		if (missing.length) {
			missing.forEach(function (item) {
				item.classList.add('is-missing');
			});
			if (alertEl) {
				alertEl.textContent = screen.classList.contains('kivun-hl-page--single')
					? 'יש לבחור "כן" או "לא" כדי להמשיך.'
					: missing.length === 1
					? 'נשארה שאלה אחת ללא תשובה בעמוד הזה. יש לענות על כל השאלות.'
					: 'נשארו ' + missing.length + ' שאלות ללא תשובה בעמוד הזה. יש לענות על כל השאלות.';
				alertEl.hidden = false;
			}
			var radio = missing[0].querySelector('input[type="radio"]');
			if (radio) {
				missing[0].scrollIntoView({ block: 'center' });
				radio.focus({ preventScroll: true });
			}
			return;
		}

		if (!next.hasAttribute('data-hl-finish')) {
			// Only pages hold answers; an intro has nothing new to save. A
			// single question is saved with the next few, the end of a part
			// at once.
			if (screen.querySelector('[data-hl-item]')) {
				var upcoming = screens[state.screen + 1];
				if (screen.classList.contains('kivun-hl-page--single') && upcoming && upcoming.querySelector('[data-hl-item]')) {
					saveSoon();
				} else {
					save(false).catch(function () { /* Tried again on the next "next". */ });
				}
			}
			show(state.screen + 1);
			return;
		}

		// The last page. Anything still blank elsewhere — a page skipped
		// with "back" — is found before the server is asked.
		var firstGap = resumeScreen();
		if (answeredCount() < TOTAL && firstGap !== state.screen) {
			show(firstGap);
			var gapScreen = screens[firstGap];
			var gapAlert = gapScreen.querySelector('[data-hl-page-alert]');
			missingIn(gapScreen).forEach(function (item) {
				item.classList.add('is-missing');
			});
			if (gapAlert) {
				gapAlert.textContent = 'יש לענות על כל השאלות לפני הסיום — נשארו כאן שאלות ללא תשובה.';
				gapAlert.hidden = false;
			}
			return;
		}

		next.disabled = true;
		save(true)
			.then(function (data) {
				if (data.html) {
					showResults(data.html);
				}
			})
			.catch(function (err) {
				if (alertEl) {
					alertEl.textContent = err.message;
					alertEl.hidden = false;
				}
			})
			.then(function () {
				next.disabled = false;
			});
	});

	// The result's own button. Everything on it is printed, the folded
	// section included.
	results.addEventListener('click', function (e) {
		if (!e.target.closest('[data-hl-print]')) {
			return;
		}
		results.querySelectorAll('details').forEach(function (d) {
			d.open = true;
		});
		window.print();
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
