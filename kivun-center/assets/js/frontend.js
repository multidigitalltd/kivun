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

		// Manager — create a publisher on the employer's behalf.
		if (form.matches('.kivun-create-employer-form')) {
			e.preventDefault();
			handleFormSubmit(form, 'kivun_create_employer', function () {
				window.location.reload();
			});
			return;
		}

		// Manager — update an existing publisher's details.
		if (form.matches('.kivun-update-employer-form')) {
			e.preventDefault();
			handleFormSubmit(form, 'kivun_update_employer', function () {
				window.location.reload();
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

	// ── Manager dashboard — act-as switcher + add-publisher toggle ────────────────
	document.addEventListener('change', function (e) {
		var asSel = e.target.closest('#kivun-as-select');
		if (!asSel) { return; }
		var url = new URL(window.location.href);
		if (asSel.value && asSel.value !== '0') {
			url.searchParams.set('kivun_as', asSel.value);
		} else {
			url.searchParams.delete('kivun_as');
		}
		window.location.href = url.toString();
	});

	function kivunCloseForm(id) {
		var wrap = document.getElementById(id);
		if (!wrap) { return; }
		wrap.hidden = true;
		var frm = wrap.querySelector('form');
		if (frm) {
			frm.reset();
			var frmErr = frm.querySelector('.kivun-error');
			if (frmErr) { frmErr.style.display = 'none'; }
		}
	}

	function kivunOpenForm(id) {
		var wrap = document.getElementById(id);
		if (!wrap) { return null; }
		wrap.hidden = false;
		wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
		return wrap;
	}

	document.addEventListener('click', function (e) {
		// Open the "add publisher" form (button exists in two places).
		if (e.target.closest('#kivun-add-employer, #kivun-add-employer-2')) {
			var openF = kivunOpenForm('kivun-add-employer-form');
			if (openF) {
				var firstField = openF.querySelector('input[name="display_name"]');
				if (firstField) { firstField.focus(); }
			}
		}
		if (e.target.closest('#kivun-cancel-add-employer')) {
			kivunCloseForm('kivun-add-employer-form');
		}
		if (e.target.closest('#kivun-cancel-edit-employer')) {
			kivunCloseForm('kivun-edit-employer-form');
		}

		// Edit an existing publisher — populate and open the inline form.
		var empEdit = e.target.closest('.kivun-edit-employer');
		if (empEdit) {
			var empRow2 = document.querySelector('[data-employer-row="' + empEdit.dataset.id + '"]');
			var empWrap = kivunOpenForm('kivun-edit-employer-form');
			if (empRow2 && empWrap) {
				var empForm = empWrap.querySelector('form');
				var setEmp = function (name, value) {
					var el = empForm.querySelector('[name="' + name + '"]');
					if (el) { el.value = value || ''; }
				};
				setEmp('employer_id', empEdit.dataset.id);
				setEmp('display_name', empRow2.dataset.name);
				setEmp('company', empRow2.dataset.company);
				setEmp('email', empRow2.dataset.email);
				setEmp('phone', empRow2.dataset.phone);
				var focusEmp = empForm.querySelector('input[name="company"]');
				if (focusEmp) { focusEmp.focus(); }
			}
		}
	});

	// ── Manager — send a set-password link to a publisher ────────────────────────
	document.addEventListener('click', function (e) {
		var sendBtn = e.target.closest('.kivun-send-login');
		if (!sendBtn) { return; }
		if (!window.confirm(kivun.i18n.confirm_send_login)) { return; }

		var note = sendBtn.parentNode.querySelector('.kivun-saved-indicator');
		sendBtn.disabled = true;
		post(params({
			action: 'kivun_send_employer_login',
			nonce: kivun.nonce,
			employer_id: sendBtn.dataset.id
		})).then(function (res) {
			sendBtn.disabled = false;
			if (note) {
				note.textContent = res.success ? res.data.message : res.data.message;
				note.style.display = 'inline';
				setTimeout(function () { note.style.display = 'none'; }, 4000);
			}
		}).catch(function () { sendBtn.disabled = false; });
	});

	// ── Manager — disable / re-enable a publisher ────────────────────────────────
	document.addEventListener('click', function (e) {
		var togBtn = e.target.closest('.kivun-toggle-employer');
		if (!togBtn) { return; }
		var willDisable = togBtn.dataset.disable === '1';
		if (willDisable && !window.confirm(kivun.i18n.confirm_disable_employer)) { return; }

		togBtn.disabled = true;
		post(params({
			action: 'kivun_toggle_employer',
			nonce: kivun.nonce,
			employer_id: togBtn.dataset.id,
			disable: willDisable ? '1' : '0'
		})).then(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				togBtn.disabled = false;
				window.alert(res.data.message);
			}
		}).catch(function () { togBtn.disabled = false; });
	});

	// ── Employer dashboard — renew an expired job ────────────────────────────────
	document.addEventListener('click', function (e) {
		var renewBtn = e.target.closest('.kivun-renew-job');
		if (!renewBtn) { return; }

		var today = new Date();
		today.setDate(today.getDate() + 30);
		var suggested = today.toISOString().slice(0, 10);

		var newDate = window.prompt(kivun.i18n.renew_prompt, suggested);
		if (!newDate) { return; }

		renewBtn.disabled = true;
		post(params({
			action: 'kivun_renew_job',
			nonce: kivun.nonce,
			job_id: renewBtn.dataset.id,
			deadline: newDate
		})).then(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				renewBtn.disabled = false;
				window.alert(res.data.message);
			}
		}).catch(function () { renewBtn.disabled = false; });
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
		// Manager selector: default to the publisher currently being acted-as.
		var empRow = form.querySelector('.kivun-mgr-only');
		var empSel = form.querySelector('[name="employer_id"]');
		if (empRow && empSel) { empSel.value = empRow.dataset.defaultEmployer || ''; }
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
				setField('employer_id', row.dataset.employer);
				setField('deadline', row.dataset.deadline);
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

	// ── Campaign (UTM) link builder ──────────────────────────────────────────────
	function kivunCampClean(value) {
		return String(value || '')
			.trim()
			.replace(/[\s_]+/g, '-')
			.replace(/[^\p{L}\p{N}\-.]+/gu, '')
			.replace(/-{2,}/g, '-')
			.replace(/^-|-$/g, '')
			.toLowerCase();
	}

	// Mirrors the PHP builder so the preview matches exactly what gets saved.
	function kivunCampBuild(form) {
		var pick = function (selectClass, customClass) {
			var sel = form.querySelector('.' + selectClass);
			if (!sel) { return ''; }
			if (sel.value === '__custom__') {
				var custom = form.querySelector('.' + customClass);
				return custom ? kivunCampClean(custom.value) : '';
			}
			return kivunCampClean(sel.value);
		};

		var targetSel = form.querySelector('.kivun-camp-target');
		var target = targetSel ? targetSel.value : '';
		if (target === '__custom__') {
			var custom = form.querySelector('.kivun-camp-custom');
			target = custom ? custom.value.trim() : '';
		}

		var campaignEl = form.querySelector('.kivun-camp-campaign');
		var parts = {
			utm_source: pick('kivun-camp-source', 'kivun-camp-source-custom'),
			utm_medium: pick('kivun-camp-medium', 'kivun-camp-medium-custom'),
			utm_campaign: campaignEl ? kivunCampClean(campaignEl.value) : ''
		};

		if (!target || !parts.utm_source || !parts.utm_campaign) { return { url: '', parts: parts, target: target }; }

		var url;
		try { url = new URL(target); } catch (err) { return { url: '', parts: parts, target: target }; }
		Object.keys(parts).forEach(function (k) {
			url.searchParams.delete(k);
			if (parts[k]) { url.searchParams.set(k, parts[k]); }
		});
		return { url: decodeURI(url.toString()), parts: parts, target: target };
	}

	function kivunCampRefresh(form) {
		var out = form.querySelector('.kivun-camp-result');
		if (out) { out.value = kivunCampBuild(form).url; }
	}

	// Reveal the free-text input when "other" is chosen.
	document.addEventListener('change', function (e) {
		var sel = e.target.closest('.kivun-camp-target, .kivun-camp-source, .kivun-camp-medium');
		if (!sel) { return; }
		var form = sel.closest('.kivun-campaign-form');
		if (!form) { return; }

		var map = {
			'kivun-camp-target': 'kivun-camp-custom',
			'kivun-camp-source': 'kivun-camp-source-custom',
			'kivun-camp-medium': 'kivun-camp-medium-custom'
		};
		Object.keys(map).forEach(function (cls) {
			if (!sel.classList.contains(cls)) { return; }
			var custom = form.querySelector('.' + map[cls]);
			if (custom) {
				custom.hidden = sel.value !== '__custom__';
				if (!custom.hidden) { custom.focus(); }
			}
		});
		kivunCampRefresh(form);
	});

	document.addEventListener('input', function (e) {
		var field = e.target.closest('.kivun-campaign-form input');
		if (!field || field.classList.contains('kivun-camp-result')) { return; }
		kivunCampRefresh(field.closest('.kivun-campaign-form'));
	});

	// Copy — both the preview and any saved row.
	document.addEventListener('click', function (e) {
		var copy = e.target.closest('.kivun-camp-copy');
		if (!copy) { return; }
		var field = copy.parentNode.querySelector('.kivun-camp-result, .kivun-camp-saved');
		if (!field || !field.value) { return; }

		var done = function () {
			var original = copy.textContent;
			copy.textContent = '✓ הועתק';
			setTimeout(function () { copy.textContent = original; }, 1600);
		};
		if (navigator.clipboard) {
			navigator.clipboard.writeText(field.value).then(done, function () { field.select(); });
		} else {
			field.select();
			try { document.execCommand('copy'); done(); } catch (err) { /* selection is the fallback */ }
		}
	});

	document.addEventListener('submit', function (e) {
		var form = e.target.closest('.kivun-campaign-form');
		if (!form) { return; }
		e.preventDefault();

		var built = kivunCampBuild(form);
		var err = form.querySelector('.kivun-camp-error');
		if (!built.url) {
			showError(err, 'יש לבחור יעד, מקור ושם קמפיין.');
			return;
		}
		if (err) { err.style.display = 'none'; }

		var nameEl = form.querySelector('.kivun-camp-campaign');
		post(params({
			action: 'kivun_save_campaign',
			nonce: kivun.nonce,
			target_url: built.target,
			label: nameEl ? nameEl.value.trim() : '',
			utm_source: built.parts.utm_source,
			utm_medium: built.parts.utm_medium,
			utm_campaign: built.parts.utm_campaign
		})).then(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				showError(err, res.data.message);
			}
		}).catch(function () {
			showError(err, kivun.i18n.error_generic);
		});
	});

	document.addEventListener('click', function (e) {
		var del = e.target.closest('.kivun-delete-campaign');
		if (!del) { return; }
		if (!window.confirm(kivun.i18n.confirm_delete_campaign)) { return; }

		del.disabled = true;
		post(params({ action: 'kivun_delete_campaign', nonce: kivun.nonce, id: del.dataset.id }))
			.then(function (res) {
				if (res.success) {
					var row = document.querySelector('[data-campaign-row="' + del.dataset.id + '"]');
					if (row) { row.parentNode.removeChild(row); }
				} else {
					del.disabled = false;
				}
			})
			.catch(function () { del.disabled = false; });
	});

	// ── Content publishing wizard (multi-step form) ──────────────────────────────
	// Every field stays in the DOM the whole time — only visibility changes — so
	// the form still submits as one payload and the save logic is untouched.
	var KIVUN_WIZ_LAST = 4;

	function kivunWizCards(form) {
		return Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
	}

	function kivunWizShow(form, step) {
		step = Math.min(KIVUN_WIZ_LAST, Math.max(1, step));
		form.dataset.current = String(step);

		kivunWizCards(form).forEach(function (card) {
			card.classList.toggle('kivun-step-off', card.dataset.step !== String(step));
		});

		form.querySelectorAll('.kivun-wiz-step').forEach(function (li) {
			var n = Number(li.dataset.gostep);
			li.classList.toggle('is-current', n === step);
			li.classList.toggle('is-done', n < step);
		});

		var prev = form.querySelector('.kivun-wiz-prev');
		var next = form.querySelector('.kivun-wiz-next');
		var send = form.querySelector('.kivun-wiz-submit');
		if (prev) { prev.hidden = step === 1; }
		if (next) { next.hidden = step === KIVUN_WIZ_LAST; }
		if (send) { send.hidden = step !== KIVUN_WIZ_LAST; }

		var progress = form.querySelector('.kivun-wiz-progress');
		if (progress) { progress.textContent = 'שלב ' + step + ' מתוך ' + KIVUN_WIZ_LAST; }

		// TinyMCE measures itself on init; a hidden editor comes back at zero
		// height, so nudge it once its step becomes visible.
		window.dispatchEvent(new Event('resize'));
		form.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function kivunWizError(form, message) {
		var box = form.querySelector('.kivun-wiz-error');
		if (!box) { return; }
		box.textContent = message || '';
		box.hidden = !message;
	}

	// Only block on fields that are actually visible in the current step.
	function kivunWizValid(form, step) {
		var ok = true;
		kivunWizError(form, '');

		// Step 1 decides what gets created — without a type there is nothing
		// to publish, and the later steps would ask about nothing.
		if (step === 1) {
			var chosen = form.querySelectorAll('.kivun-cc-toggle:checked').length;
			if (!chosen) {
				kivunWizError(form, 'יש לבחור לפחות סוג תוכן אחד לפרסום.');
				var firstToggle = form.querySelector('.kivun-cc-toggle');
				if (firstToggle) { firstToggle.focus(); }
				return false;
			}
		}

		kivunWizCards(form).forEach(function (card) {
			if (card.dataset.step !== String(step) || card.hidden) { return; }
			card.querySelectorAll('[required]').forEach(function (field) {
				if (!field.value.trim()) {
					field.classList.add('kivun-field-invalid');
					if (ok) { field.focus(); }
					ok = false;
				} else {
					field.classList.remove('kivun-field-invalid');
				}
			});
		});
		if (!ok) { kivunWizError(form, 'יש למלא את שדות החובה המסומנים.'); }
		return ok;
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.kivun-wiz-next, .kivun-wiz-prev, .kivun-wiz-step');
		if (!btn) { return; }
		var form = btn.closest('.kivun-cc-wizard');
		if (!form) { return; }

		var current = Number(form.dataset.current || 1);

		if (btn.classList.contains('kivun-wiz-prev')) {
			kivunWizShow(form, current - 1);
			return;
		}
		if (btn.classList.contains('kivun-wiz-next')) {
			if (kivunWizValid(form, current)) { kivunWizShow(form, current + 1); }
			return;
		}
		// Clicking the stepper: forward only after the current step validates.
		var target = Number(btn.dataset.gostep);
		if (target <= current || kivunWizValid(form, current)) { kivunWizShow(form, target); }
	});

	// Submitting from any step must still surface a missing required field.
	document.addEventListener('submit', function (e) {
		var form = e.target.closest('.kivun-cc-wizard');
		if (!form) { return; }
		for (var s = 1; s <= KIVUN_WIZ_LAST; s++) {
			if (!kivunWizValid(form, s)) {
				e.preventDefault();
				kivunWizShow(form, s);
				return;
			}
		}
	});

	// Picking a type clears the "choose a type" warning immediately.
	document.addEventListener('change', function (e) {
		var toggle = e.target.closest('.kivun-cc-toggle');
		if (!toggle) { return; }
		var form = toggle.closest('.kivun-cc-wizard');
		if (form && form.querySelectorAll('.kivun-cc-toggle:checked').length) {
			kivunWizError(form, '');
		}
	});

	document.querySelectorAll('.kivun-cc-wizard').forEach(function (form) {
		kivunWizShow(form, 1);
	});

	// ── Tabs (WAI-ARIA tab pattern) — employer dashboard + content console ───────
	function getTabs(tab) {
		return Array.prototype.slice.call(tab.closest('.kivun-tabs').querySelectorAll('.kivun-tab'));
	}

	// The tabbed containers in the plugin. Panels are scoped to their own
	// container so two tab sets on one page never toggle each other.
	function tabScope(el) {
		return el.closest('.kivun-employer-dashboard, .kivun-cc-console');
	}

	// Elements belonging to THIS tab set only. The employer dashboard can be
	// nested inside the content console, and both use the same class names —
	// without this, switching a console tab would also toggle the dashboard's
	// inner panels.
	function ownedBy(dash, selector) {
		return Array.prototype.filter.call(
			dash.querySelectorAll(selector),
			function (el) { return tabScope(el) === dash; }
		);
	}

	function activateTab(tab, focusIt) {
		if (!tab) { return; }
		var dash = tabScope(tab);
		if (!dash) { return; }
		var name = tab.dataset.tab;

		ownedBy(dash, '.kivun-tab').forEach(function (t) {
			t.classList.remove('is-active');
			t.setAttribute('aria-selected', 'false');
			t.setAttribute('tabindex', '-1');
		});
		tab.classList.add('is-active');
		tab.setAttribute('aria-selected', 'true');
		tab.setAttribute('tabindex', '0');

		ownedBy(dash, '.kivun-tab-panel').forEach(function (panel) {
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

		var dash = tabScope(link);
		if (!dash) { return; }
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
		function kivunUpdateNonEvent() {
			var ev = document.querySelector('.kivun-cc-toggle[data-type="event"]:checked'),
				other = document.querySelector('.kivun-cc-toggle[data-type="landing"]:checked, .kivun-cc-toggle[data-type="course"]:checked, .kivun-cc-toggle[data-type="session"]:checked'),
				hide = ev && !other;
			document.querySelectorAll('.kivun-cc-nonevent').forEach(function (el) { el.style.display = hide ? 'none' : ''; });
		}
		document.querySelectorAll('.kivun-cc-toggle').forEach(function (cb) {
			cb.addEventListener('change', function () {
				var sec = document.querySelector('.kivun-cc-section[data-type="' + cb.dataset.type + '"]');
				if (sec) { sec.hidden = !cb.checked; }
				kivunUpdateNonEvent();
			});
		});
		kivunUpdateNonEvent();

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

}());
