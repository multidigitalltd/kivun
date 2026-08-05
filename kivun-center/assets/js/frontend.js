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
		// Flush any TinyMCE (WYSIWYG) editors into their textareas first.
		if (window.tinymce) { window.tinymce.triggerSave(); }

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

	// ── Employer dashboard — add / edit job form ─────────────────────────────────
	function kivunSetEditor(id, html) {
		if (window.tinymce && window.tinymce.get(id)) {
			window.tinymce.get(id).setContent(html || '');
		} else {
			var ta = document.getElementById(id);
			if (ta) { ta.value = html || ''; }
		}
	}

	function kivunFormMode(isEdit) {
		var heading = document.getElementById('kivun-new-job-heading');
		var submit = document.getElementById('kivun-new-job-submit');
		if (heading) { heading.textContent = isEdit ? heading.dataset.edit : heading.dataset.new; }
		if (submit) { submit.textContent = isEdit ? submit.dataset.edit : submit.dataset.new; }
	}

	function kivunResetJobForm() {
		var form = document.querySelector('.kivun-employer-form');
		if (!form) { return; }
		form.reset();
		form.dataset.action = 'kivun_post_job';
		var jobId = form.querySelector('input[name="job_id"]');
		if (jobId) { jobId.value = ''; }
		kivunSetEditor('kivunjobdesc', '');
		kivunSetEditor('kivunjobreq', '');
		var err = form.querySelector('.kivun-error');
		if (err) { err.style.display = 'none'; }
		kivunFormMode(false);
	}

	document.addEventListener('click', function (e) {
		if (e.target.closest('#kivun-toggle-new-job')) {
			var openForm = document.getElementById('kivun-new-job-form');
			if (openForm) {
				kivunResetJobForm();
				openForm.style.display = 'block';
				// Let any TinyMCE editors recalculate now that they are visible.
				window.dispatchEvent(new Event('resize'));
				var first = openForm.querySelector('input[name="title"]');
				if (first) { first.focus(); }
			}
		}
		if (e.target.closest('#kivun-cancel-new-job')) {
			var closeForm = document.getElementById('kivun-new-job-form');
			if (closeForm) { closeForm.style.display = 'none'; }
			kivunResetJobForm();
		}

		// Edit an existing job — populate the shared form in edit mode.
		var editBtn = e.target.closest('.kivun-edit-job');
		if (editBtn) {
			var row = editBtn.closest('[data-job-row]');
			var form = document.querySelector('.kivun-employer-form');
			var wrap = document.getElementById('kivun-new-job-form');
			if (row && form && wrap) {
				form.dataset.action = 'kivun_update_job';
				var idField = form.querySelector('input[name="job_id"]');
				if (idField) { idField.value = editBtn.dataset.id; }

				var setField = function (name, value) {
					var el = form.querySelector('[name="' + name + '"]');
					if (el) { el.value = value || ''; }
				};
				setField('title', row.dataset.title);
				setField('company', row.dataset.company);
				setField('salary', row.dataset.salary);
				setField('scope', row.dataset.scope);
				setField('region', row.dataset.region);
				setField('field', row.dataset.field);
				kivunSetEditor('kivunjobdesc', row.dataset.description);
				kivunSetEditor('kivunjobreq', row.dataset.requirements);

				kivunFormMode(true);
				wrap.style.display = 'block';
				window.dispatchEvent(new Event('resize'));
				wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
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

	// ── Employer login: move the social-login button above username/password ──────
	// Nextend Social Login renders its button wherever it likes (often below the
	// form); relocate it to just above the login form, regardless of placement.
	function placeSocialLogin() {
		var wrap = document.querySelector('.kivun-employer-login');
		if (!wrap) { return true; }
		var form = wrap.querySelector('#loginform');
		if (!form) { return true; }

		var nsl = wrap.querySelector('.nsl-container');
		if (!nsl) {
			var node = wrap.nextElementSibling, hops = 0;
			while (node && hops < 5 && !nsl) {
				if (node.classList && node.classList.contains('nsl-container')) { nsl = node; }
				else if (node.querySelector) { nsl = node.querySelector('.nsl-container'); }
				node = node.nextElementSibling; hops++;
			}
		}
		if (!nsl) { nsl = document.querySelector('.nsl-container'); }
		if (!nsl) { return false; }
		if (nsl.parentNode === wrap && nsl.nextElementSibling === form) { return true; }
		wrap.insertBefore(nsl, form);
		return true;
	}

	// ── Content creator (front-end shortcode): toggles, image preview, delete, AI ──
	if (document.querySelector('.kivun-cc-front')) {
		document.querySelectorAll('.kivun-cc-toggle').forEach(function (cb) {
			cb.addEventListener('change', function () {
				var sec = document.querySelector('.kivun-cc-section[data-type="' + cb.dataset.type + '"]');
				if (sec) { sec.hidden = !cb.checked; }
			});
		});

		var ccFile = document.querySelector('.kivun-cc-file');
		if (ccFile) {
			ccFile.addEventListener('change', function () {
				var box = document.querySelector('.kivun-cc-media__preview'),
					img = box ? box.querySelector('img') : null,
					flag = document.querySelector('.kivun-cc-remove-flag'),
					rm = document.querySelector('.kivun-cc-media__remove');
				if (flag) { flag.value = '0'; }
				if (rm) { rm.hidden = false; }
				if (ccFile.files && ccFile.files[0] && img && window.FileReader) {
					var reader = new FileReader();
					reader.onload = function (e) { img.src = e.target.result; box.style.display = ''; };
					reader.readAsDataURL(ccFile.files[0]);
				}
			});
		}

		var ccRemove = document.querySelector('.kivun-cc-media__remove');
		if (ccRemove) {
			ccRemove.addEventListener('click', function () {
				var idEl = document.querySelector('.kivun-cc-front [name="thumbnail_id"]'),
					flag = document.querySelector('.kivun-cc-remove-flag'),
					box = document.querySelector('.kivun-cc-media__preview'),
					fileEl = document.querySelector('.kivun-cc-file');
				if (idEl) { idEl.value = ''; }
				if (flag) { flag.value = '1'; }
				if (fileEl) { fileEl.value = ''; }
				if (box) { box.style.display = 'none'; }
				ccRemove.hidden = true;
			});
		}

		var ccConfirm = 'למחוק את כל התוכן המקושר? הפעולה תעביר לפח את דף הנחיתה, הקורס והסדנה.';
		document.querySelectorAll('.kivun-cc-delete-form').forEach(function (f) {
			f.addEventListener('submit', function (e) {
				if (!window.confirm(ccConfirm)) { e.preventDefault(); }
			});
		});

		var ccAi = document.querySelector('.kivun-cc-ai-btn');
		if (ccAi) {
			ccAi.addEventListener('click', function () {
				var titleEl = document.querySelector('.kivun-cc-front [name="title"]'),
					title = titleEl ? titleEl.value.trim() : '',
					ccStatus = document.querySelector('.kivun-cc-ai-status'),
					styleEl = document.querySelector('.kivun-cc-ai-style'),
					promptEl = document.querySelector('.kivun-cc-ai-prompt'),
					custom = promptEl ? promptEl.value.trim() : '';
				if (!title && !custom) {
					if (ccStatus) { ccStatus.textContent = 'מלאו כותרת או תיאור חופשי.'; }
					return;
				}
				var shortVal = (window.tinymce && tinymce.get('kivun_ccf_short'))
						? tinymce.get('kivun_ccf_short').getContent({ format: 'text' })
						: ((document.querySelector('.kivun-cc-front [name="short"]') || {}).value || ''),
					typeEl = document.querySelector('.kivun-cc-toggle:checked'),
					fd = new FormData();
				fd.append('action', 'kivun_generate_ai_image');
				fd.append('nonce', ccAi.dataset.nonce);
				fd.append('title', title);
				fd.append('desc', shortVal);
				fd.append('type', typeEl ? typeEl.dataset.type : '');
				fd.append('style', styleEl ? styleEl.value : 'photo');
				fd.append('prompt', custom);

				ccAi.disabled = true;
				if (ccStatus) { ccStatus.textContent = 'יוצר תמונה… זה עשוי לקחת עד דקה'; }

				fetch(ccAi.dataset.ajax || kivun.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						ccAi.disabled = false;
						if (res && res.success && res.data) {
							var idEl = document.querySelector('.kivun-cc-front [name="thumbnail_id"]'),
								box = document.querySelector('.kivun-cc-media__preview'),
								img = box ? box.querySelector('img') : null;
							if (idEl) { idEl.value = res.data.id; }
							if (img) { img.src = res.data.url; box.style.display = ''; }
							var rmFlag = document.querySelector('.kivun-cc-remove-flag'),
								rmBtn = document.querySelector('.kivun-cc-media__remove');
							if (rmFlag) { rmFlag.value = '0'; }
							if (rmBtn) { rmBtn.hidden = false; }
							if (ccStatus) { ccStatus.textContent = '✓ נוצרה תמונה'; }
						} else if (ccStatus) {
							ccStatus.textContent = (res && res.data && res.data.message) ? res.data.message : 'יצירת התמונה נכשלה';
						}
					})
					.catch(function () {
						ccAi.disabled = false;
						if (ccStatus) { ccStatus.textContent = 'שגיאת רשת'; }
					});
			});
		}

		var ccGen = document.querySelector('.kivun-cc-gen-btn');
		if (ccGen) {
			ccGen.addEventListener('click', function () {
				var topicEl = document.querySelector('.kivun-cc-gen-topic'),
					topic = topicEl ? topicEl.value.trim() : '',
					toneEl = document.querySelector('.kivun-cc-gen-tone'),
					imgEl = document.querySelector('.kivun-cc-gen-image'),
					imgFile = (imgEl && imgEl.files && imgEl.files[0]) ? imgEl.files[0] : null,
					gStat = document.querySelector('.kivun-cc-gen-status'),
					typeEl = document.querySelector('.kivun-cc-toggle:checked'),
					fd = new FormData();
				if (!topic && !imgFile) {
					if (gStat) { gStat.textContent = 'מלאו נושא או העלו מודעה.'; }
					return;
				}
				if (imgFile) { fd.append('image', imgFile); }
				fd.append('action', 'kivun_generate_ai_content');
				fd.append('nonce', ccGen.dataset.nonce);
				fd.append('topic', topic);
				fd.append('tone', toneEl ? toneEl.value : 'marketing');
				fd.append('type', typeEl ? typeEl.dataset.type : '');
				ccGen.disabled = true;
				if (gStat) { gStat.textContent = 'יוצר תוכן… זה עשוי לקחת מספר שניות'; }
				function setByName(name, val) {
					var el = document.querySelector('.kivun-cc-front [name="' + name + '"]');
					if (el) { el.value = val || ''; }
				}
				fetch(ccGen.dataset.ajax || kivun.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						ccGen.disabled = false;
						if (res && res.success && res.data) {
							var d = res.data, t = document.getElementById('kivun-ccf-title');
							if (t && d.title) { t.value = d.title; }
							kivunSetEditor('kivun_ccf_short', d.short);
							kivunSetEditor('kivun_ccf_long', d.long);
							setByName('audience', d.audience);
							setByName('duration', d.duration);
							setByName('cost', d.cost);
							setByName('date', d.date);
							if (gStat) { gStat.textContent = '✓ נוצר תוכן — ערכו ושמרו'; }
						} else if (gStat) {
							gStat.textContent = (res && res.data && res.data.message) ? res.data.message : 'יצירת התוכן נכשלה';
						}
					})
					.catch(function () {
						ccGen.disabled = false;
						if (gStat) { gStat.textContent = 'שגיאת רשת'; }
					});
			});
		}
	}

	if (document.querySelector('.kivun-employer-login')) {
		if (!placeSocialLogin()) {
			var tries = 0;
			var timer = setInterval(function () {
				tries++;
				if (placeSocialLogin() || tries > 20) { clearInterval(timer); }
			}, 200);
		}
		if (window.MutationObserver) {
			var host = document.querySelector('.kivun-employer-login');
			var obs = new MutationObserver(function () { placeSocialLogin(); });
			if (host && host.parentNode) {
				obs.observe(host.parentNode, { childList: true, subtree: true });
			}
		}
	}

	// ── Event popup (site-wide, once per session per event) ──────────────────────
	(function () {
		var pop = document.getElementById('kivun-epop');
		if (!pop) { return; }
		var key = 'kivunEpop_' + (pop.dataset.event || '');
		try { if (window.sessionStorage && sessionStorage.getItem(key)) { return; } } catch (e) {}

		function close() {
			pop.hidden = true;
			pop.classList.remove('is-open');
			try { if (window.sessionStorage) { sessionStorage.setItem(key, '1'); } } catch (e) {}
		}
		function open() {
			pop.hidden = false;
			// Force reflow so the transition runs.
			void pop.offsetWidth;
			pop.classList.add('is-open');
			try { if (window.sessionStorage) { sessionStorage.setItem(key, '1'); } } catch (e) {}
		}

		pop.querySelectorAll('[data-epop-close]').forEach(function (el) {
			el.addEventListener('click', function (e) { e.preventDefault(); close(); });
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && pop.classList.contains('is-open')) { close(); }
		});

		window.setTimeout(open, 900);
	}());

}());
