/* global kivun */
/* Kivun Center — front-end behaviour (vanilla JS, no dependencies). */
(function () {
	'use strict';

	var filterTimeout;

	// ── Helpers ──────────────────────────────────────────────────────────────────
	function post(body) {
		return fetch(kivun.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (r) { return r.json(); });
	}

	function params(obj) {
		return new URLSearchParams(obj);
	}

	function formParams(form, extra) {
		var data = new URLSearchParams(new FormData(form));
		if (extra) {
			Object.keys(extra).forEach(function (k) { data.append(k, extra[k]); });
		}
		return data;
	}

	function showError(err, message) {
		if (err) {
			err.textContent = message;
			err.style.display = 'block';
		}
	}

	function replaceWithSuccess(node, message) {
		var p = document.createElement('p');
		p.className = 'kivun-success';
		p.setAttribute('role', 'status');
		p.textContent = message;
		node.parentNode.replaceChild(p, node);
	}

	// ── Job board filters ────────────────────────────────────────────────────────
	function val(id) {
		var el = document.getElementById(id);
		return el ? el.value : '';
	}

	function loadJobs(paged) {
		var board = document.querySelector('.kivun-jobs-board');
		if (!board) { return; }

		board.classList.add('kivun-loading');

		post(params({
			action: 'kivun_filter_jobs',
			nonce: kivun.nonce,
			scope: val('kivun-filter-scope'),
			region: val('kivun-filter-region'),
			field: val('kivun-filter-field'),
			search: val('kivun-filter-search'),
			paged: paged || 1
		})).then(function (res) {
			board.classList.remove('kivun-loading');
			if (res.success) {
				board.innerHTML = res.data.html;
				var count = document.querySelector('.kivun-jobs-count');
				if (count) { count.textContent = res.data.count; }
			}
		}).catch(function () {
			board.classList.remove('kivun-loading');
		});
	}

	document.addEventListener('change', function (e) {
		if (e.target.closest('#kivun-filter-scope, #kivun-filter-region, #kivun-filter-field')) {
			loadJobs();
		}
	});

	document.addEventListener('input', function (e) {
		if (e.target.closest('#kivun-filter-search')) {
			clearTimeout(filterTimeout);
			filterTimeout = setTimeout(loadJobs, 400);
		}
	});

	// Toggle between "search by categories" and "free search" modes.
	document.addEventListener('click', function (e) {
		var tab = e.target.closest('.kivun-filtertab');
		if (!tab) { return; }

		var wrap = tab.closest('.kivun-jobs-filters');
		var mode = tab.dataset.filtermode;

		wrap.querySelectorAll('.kivun-filtertab').forEach(function (t) {
			t.classList.toggle('is-active', t === tab);
		});
		wrap.querySelectorAll('[data-filterpanel]').forEach(function (panel) {
			var show = panel.dataset.filterpanel === mode;
			panel.hidden = !show;
			if (!show) {
				panel.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
			}
		});

		loadJobs();
	});

	// ── Apply form toggle ────────────────────────────────────────────────────────
	document.addEventListener('click', function (e) {
		var toggle = e.target.closest('.kivun-apply-toggle');
		if (!toggle) { return; }
		var target = document.getElementById(toggle.dataset.target);
		if (target) {
			target.hidden = !target.hidden;
		}
	});

	// ── Generic AJAX form submit (URL-encoded) ───────────────────────────────────
	function handleFormSubmit(form, action, onSuccess) {
		var btn = form.querySelector('[type=submit]');
		var err = form.querySelector('.kivun-error');
		var originalText = btn ? btn.textContent : '';

		if (err) { err.style.display = 'none'; }
		if (btn) { btn.disabled = true; btn.textContent = kivun.i18n.sending; }

		var restore = function () {
			if (btn) { btn.disabled = false; btn.textContent = originalText; }
		};

		post(formParams(form, { action: action, nonce: kivun.nonce }))
			.then(function (res) {
				if (res.success) {
					onSuccess(res, form);
				} else {
					showError(err, res.data.message);
					restore();
				}
			})
			.catch(function () {
				showError(err, kivun.i18n.error_generic);
				restore();
			});
	}

	document.addEventListener('submit', function (e) {
		var form = e.target;

		// CV application (multipart — includes the file input).
		if (form.matches('.kivun-apply-form')) {
			e.preventDefault();
			var btn = form.querySelector('[type=submit]');
			var err = form.querySelector('.kivun-error');
			var originalText = btn ? btn.textContent : '';
			if (err) { err.style.display = 'none'; }
			if (btn) { btn.disabled = true; btn.textContent = kivun.i18n.sending; }

			var data = new FormData(form);
			data.append('action', 'kivun_submit_application');
			data.append('nonce', kivun.nonce);

			post(data).then(function (res) {
				if (res.success) {
					replaceWithSuccess(form, res.data.message);
				} else {
					showError(err, res.data.message);
					if (btn) { btn.disabled = false; btn.textContent = originalText; }
				}
			}).catch(function () {
				showError(err, kivun.i18n.error_generic);
				if (btn) { btn.disabled = false; btn.textContent = originalText; }
			});
			return;
		}

		// Course registration (free → direct, paid → WooCommerce checkout).
		if (form.matches('.kivun-register-form')) {
			e.preventDefault();
			var paidField = form.querySelector('[name=is_paid]');
			var isPaid = paidField && paidField.value === '1';
			handleFormSubmit(form, isPaid ? 'kivun_course_checkout' : 'kivun_register_course', function (res, theForm) {
				if (isPaid) {
					window.location.href = res.data.checkout_url;
				} else {
					replaceWithSuccess(theForm, res.data.message);
				}
			});
			return;
		}

		// Lead / interest form (courses + workshops).
		if (form.matches('.kivun-lead-form')) {
			e.preventDefault();
			handleFormSubmit(form, 'kivun_submit_lead', function (res, theForm) {
				replaceWithSuccess(theForm, res.data.message);
			});
			return;
		}

		// Employer self-registration.
		if (form.matches('.kivun-employer-reg-form')) {
			e.preventDefault();
			handleFormSubmit(form, 'kivun_register_employer', function (res, theForm) {
				replaceWithSuccess(theForm, res.data.message);
			});
			return;
		}

		// Employer dashboard — post/update job.
		if (form.matches('.kivun-employer-form')) {
			e.preventDefault();
			handleFormSubmit(form, form.dataset.action, function () {
				window.location.reload();
			});
			return;
		}
	});

	// ── Employer dashboard — new-job form toggle ─────────────────────────────────
	document.addEventListener('click', function (e) {
		if (e.target.closest('#kivun-toggle-new-job')) {
			var openForm = document.getElementById('kivun-new-job-form');
			if (openForm) {
				openForm.style.display = 'block';
				var first = openForm.querySelector('input, textarea, select');
				if (first) { first.focus(); }
			}
		}
		if (e.target.closest('#kivun-cancel-new-job')) {
			var closeForm = document.getElementById('kivun-new-job-form');
			if (closeForm) { closeForm.style.display = 'none'; }
		}
	});

	// ── Employer dashboard — delete job ──────────────────────────────────────────
	document.addEventListener('click', function (e) {
		var del = e.target.closest('.kivun-delete-job');
		if (!del) { return; }
		if (!window.confirm(kivun.i18n.confirm_delete)) { return; }

		var id = del.dataset.id;
		post(params({ action: 'kivun_delete_job', nonce: kivun.nonce, job_id: id })).then(function (res) {
			if (res.success) {
				var row = document.querySelector('[data-job-row="' + id + '"]');
				if (row) { row.parentNode.removeChild(row); }
			}
		});
	});

	// ── Employer dashboard tabs (WAI-ARIA tab pattern) ───────────────────────────
	function getTabs(tab) {
		return Array.prototype.slice.call(tab.closest('.kivun-tabs').querySelectorAll('.kivun-tab'));
	}

	function activateTab(tab, focusIt) {
		if (!tab) { return; }
		var dash = tab.closest('.kivun-employer-dashboard');
		var name = tab.dataset.tab;

		dash.querySelectorAll('.kivun-tab').forEach(function (t) {
			t.classList.remove('is-active');
			t.setAttribute('aria-selected', 'false');
			t.setAttribute('tabindex', '-1');
		});
		tab.classList.add('is-active');
		tab.setAttribute('aria-selected', 'true');
		tab.setAttribute('tabindex', '0');

		dash.querySelectorAll('.kivun-tab-panel').forEach(function (panel) {
			var match = panel.dataset.panel === name;
			panel.classList.toggle('is-active', match);
			panel.hidden = !match;
		});

		if (focusIt) { tab.focus(); }
	}

	document.addEventListener('click', function (e) {
		var tab = e.target.closest('.kivun-tab');
		if (tab) { activateTab(tab, false); }
	});

	document.addEventListener('keydown', function (e) {
		var tab = e.target.closest('.kivun-tab');
		if (!tab) { return; }

		var tabs = getTabs(tab);
		var count = tabs.length;
		var idx = tabs.indexOf(tab);
		var next;

		switch (e.key) {
			case 'ArrowLeft':  next = (idx + 1) % count; break;
			case 'ArrowRight': next = (idx - 1 + count) % count; break;
			case 'Home':       next = 0; break;
			case 'End':        next = count - 1; break;
			default: return;
		}
		e.preventDefault();
		activateTab(tabs[next], true);
	});

	// Jump from a job row's submissions count straight into the filtered list.
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.kivun-view-job-apps');
		if (!link) { return; }

		var dash = link.closest('.kivun-employer-dashboard');
		activateTab(dash.querySelector('.kivun-tab[data-tab="applications"]'), true);
		var jobFilter = dash.querySelector('#kivun-apps-filter-job');
		if (jobFilter) { jobFilter.value = String(link.dataset.job); }
		filterApplications();
	});

	// ── Applications filtering ───────────────────────────────────────────────────
	document.addEventListener('input', function (e) {
		if (e.target.closest('#kivun-apps-search')) {
			clearTimeout(filterTimeout);
			filterTimeout = setTimeout(filterApplications, 200);
		}
	});

	document.addEventListener('change', function (e) {
		if (e.target.closest('#kivun-apps-filter-job, #kivun-apps-filter-status')) {
			filterApplications();
		}
	});

	function filterApplications() {
		var rows = document.querySelectorAll('.kivun-app-row');
		if (!rows.length) { return; }

		var term = (val('kivun-apps-search') || '').toLowerCase().trim();
		var jobId = val('kivun-apps-filter-job');
		var status = val('kivun-apps-filter-status');
		var shown = 0;

		rows.forEach(function (row) {
			var ok =
				(!term || (row.dataset.search || '').indexOf(term) !== -1) &&
				(!jobId || String(row.dataset.job) === jobId) &&
				(!status || String(row.dataset.status) === status);

			row.style.display = ok ? '' : 'none';
			if (ok) { shown += 1; }
		});

		var empty = document.querySelector('.kivun-apps-empty');
		if (empty) { empty.style.display = shown === 0 ? 'block' : 'none'; }
	}

	// ── Application inline feedback ───────────────────────────────────────────────
	function flashSaved(indicator, ok) {
		if (!indicator) { return; }
		indicator.textContent = ok ? kivun.i18n.saved : kivun.i18n.save_error;
		indicator.style.color = ok ? '#15803d' : '#b91c1c';
		indicator.style.display = 'inline-block';
		if (ok) {
			setTimeout(function () { indicator.style.display = 'none'; }, 1600);
		}
	}

	// Status update (employer).
	document.addEventListener('change', function (e) {
		var select = e.target.closest('.kivun-app-status-select');
		if (!select) { return; }

		var row = select.closest('.kivun-app-row');
		var indicator = select.parentNode.querySelector('.kivun-saved-indicator');

		post(params({
			action: 'kivun_employer_update_app',
			nonce: kivun.nonce,
			app_id: select.dataset.app,
			status: select.value
		})).then(function (res) {
			if (res.success) {
				if (row) { row.dataset.status = res.data.status; }
				flashSaved(indicator, true);
			} else {
				flashSaved(indicator, false);
			}
		}).catch(function () {
			flashSaved(indicator, false);
		});
	});

	// Internal note auto-save on blur (employer).
	document.addEventListener('blur', function (e) {
		var note = e.target.closest('.kivun-app-note');
		if (!note) { return; }

		var row = note.closest('.kivun-app-row');
		var indicator = row ? row.querySelector('.kivun-saved-indicator') : null;

		post(params({
			action: 'kivun_employer_app_note',
			nonce: kivun.nonce,
			app_id: note.dataset.app,
			note: note.value
		})).then(function (res) {
			if (res.success) { flashSaved(indicator, true); }
		});
	}, true);

}());
