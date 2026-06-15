/* global kivun, jQuery */
(function ($) {
	'use strict';

	// ── Job filters ─────────────────────────────────────────────────────────────
	var filterTimeout;

	$(document).on('change', '#kivun-filter-scope, #kivun-filter-region, #kivun-filter-field', function () {
		loadJobs();
	});

	$(document).on('input', '#kivun-filter-search', function () {
		clearTimeout(filterTimeout);
		filterTimeout = setTimeout(loadJobs, 400);
	});

	function loadJobs(paged) {
		var $board = $('.kivun-jobs-board');
		if (!$board.length) return;

		$board.addClass('kivun-loading');

		$.post(kivun.ajax_url, {
			action: 'kivun_filter_jobs',
			nonce:  kivun.nonce,
			scope:  $('#kivun-filter-scope').val()  || '',
			region: $('#kivun-filter-region').val() || '',
			field:  $('#kivun-filter-field').val()  || '',
			search: $('#kivun-filter-search').val() || '',
			paged:  paged || 1,
		}, function (res) {
			$board.removeClass('kivun-loading');
			if (res.success) {
				$board.html(res.data.html);
				$('.kivun-jobs-count').text(res.data.count);
			}
		});
	}

	// ── Apply form toggle ────────────────────────────────────────────────────────
	$(document).on('click', '.kivun-apply-toggle', function () {
		var target = '#' + $(this).data('target');
		$(target).slideToggle(200);
	});

	// ── CV application submit ────────────────────────────────────────────────────
	$(document).on('submit', '.kivun-apply-form', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $btn  = $form.find('[type=submit]');
		var $err  = $form.find('.kivun-error');

		$err.hide();
		$btn.prop('disabled', true).text(kivun.i18n.sending);

		var data = new FormData(this);
		data.append('action', 'kivun_submit_application');
		data.append('nonce',  kivun.nonce);

		$.ajax({
			url:         kivun.ajax_url,
			type:        'POST',
			data:        data,
			processData: false,
			contentType: false,
			success: function (res) {
				if (res.success) {
					$form.replaceWith('<p class="kivun-success">' + res.data.message + '</p>');
				} else {
					$err.text(res.data.message).show();
					$btn.prop('disabled', false).text(kivun.i18n.submit);
				}
			},
			error: function () {
				$err.text(kivun.i18n.error_generic).show();
				$btn.prop('disabled', false).text(kivun.i18n.submit);
			},
		});
	});

	// ── Course registration submit ───────────────────────────────────────────────
	$(document).on('submit', '.kivun-register-form', function (e) {
		e.preventDefault();
		var $form   = $(this);
		var $btn    = $form.find('[type=submit]');
		var $err    = $form.find('.kivun-error');
		var isPaid  = $form.find('[name=is_paid]').val() === '1';

		$err.hide();
		$btn.prop('disabled', true).text(kivun.i18n.sending);

		if (isPaid) {
			// Paid course → go through WooCommerce
			$.post(kivun.ajax_url, $.extend({ action: 'kivun_course_checkout', nonce: kivun.nonce }, formToObj($form)), function (res) {
				if (res.success) {
					window.location.href = res.data.checkout_url;
				} else {
					$err.text(res.data.message).show();
					$btn.prop('disabled', false);
				}
			});
		} else {
			// Free course → direct registration
			$.post(kivun.ajax_url, $.extend({ action: 'kivun_register_course', nonce: kivun.nonce }, formToObj($form)), function (res) {
				if (res.success) {
					$form.replaceWith('<p class="kivun-success">' + res.data.message + '</p>');
				} else {
					$err.text(res.data.message).show();
					$btn.prop('disabled', false);
				}
			});
		}
	});

	function formToObj($form) {
		var obj = {};
		$.each($form.serializeArray(), function (_, f) { obj[f.name] = f.value; });
		return obj;
	}

	// ── Lead / Interest form (courses + workshops) ───────────────────────────────
	$(document).on('submit', '.kivun-lead-form', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $btn  = $form.find('[type=submit]');
		var $err  = $form.find('.kivun-error');

		$err.hide();
		$btn.prop('disabled', true).text(kivun.i18n.sending);

		$.post(kivun.ajax_url, $.extend({ action: 'kivun_submit_lead', nonce: kivun.nonce }, formToObj($form)), function (res) {
			if (res.success) {
				$form.replaceWith('<p class="kivun-success">' + res.data.message + '</p>');
			} else {
				$err.text(res.data.message).show();
				$btn.prop('disabled', false).text(kivun.i18n.submit);
			}
		});
	});

	// ── Employer registration ────────────────────────────────────────────────────
	$(document).on('submit', '.kivun-employer-reg-form', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $btn  = $form.find('[type=submit]');
		var $err  = $form.find('.kivun-error');

		$err.hide();
		$btn.prop('disabled', true).text(kivun.i18n.sending);

		$.post(kivun.ajax_url, $.extend({ action: 'kivun_register_employer', nonce: kivun.nonce }, formToObj($form)), function (res) {
			if (res.success) {
				$form.replaceWith('<p class="kivun-success">' + res.data.message + '</p>');
			} else {
				$err.text(res.data.message).show();
				$btn.prop('disabled', false).text(kivun.i18n.submit);
			}
		});
	});

	// ── Employer dashboard ───────────────────────────────────────────────────────
	$('#kivun-toggle-new-job').on('click', function () {
		$('#kivun-new-job-form').slideDown(200);
	});

	$('#kivun-cancel-new-job').on('click', function () {
		$('#kivun-new-job-form').slideUp(200);
	});

	$(document).on('submit', '.kivun-employer-form', function (e) {
		e.preventDefault();
		var $form   = $(this);
		var $btn    = $form.find('[type=submit]');
		var $err    = $form.find('.kivun-error');
		var action  = $form.data('action');

		$err.hide();
		$btn.prop('disabled', true).text(kivun.i18n.sending);

		$.post(kivun.ajax_url, $.extend({ action: action, nonce: kivun.nonce }, formToObj($form)), function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				$err.text(res.data.message).show();
				$btn.prop('disabled', false);
			}
		});
	});

	$(document).on('click', '.kivun-delete-job', function () {
		if (!window.confirm(kivun.i18n.confirm_delete)) return;
		var $btn = $(this);
		var id   = $btn.data('id');

		$.post(kivun.ajax_url, { action: 'kivun_delete_job', nonce: kivun.nonce, job_id: id }, function (res) {
			if (res.success) {
				$('[data-job-row="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
			}
		});
	});

	// ── Employer dashboard tabs (WAI-ARIA tab pattern) ────────────────────────────
	function activateTab($tab, focusIt) {
		var $dash = $tab.closest('.kivun-employer-dashboard');
		var tab   = $tab.data('tab');

		$dash.find('.kivun-tab')
			.removeClass('is-active')
			.attr({ 'aria-selected': 'false', tabindex: '-1' });
		$tab.addClass('is-active').attr({ 'aria-selected': 'true', tabindex: '0' });

		$dash.find('.kivun-tab-panel').removeClass('is-active').attr('hidden', 'hidden');
		$dash.find('.kivun-tab-panel[data-panel="' + tab + '"]').addClass('is-active').removeAttr('hidden');

		if (focusIt) { $tab.trigger('focus'); }
	}

	$(document).on('click', '.kivun-tab', function () {
		activateTab($(this), false);
	});

	// Keyboard navigation across the tablist (arrows + Home/End), RTL-aware.
	$(document).on('keydown', '.kivun-tab', function (e) {
		var $tabs = $(this).closest('.kivun-tabs').find('.kivun-tab');
		var count = $tabs.length;
		var idx   = $tabs.index(this);
		var next;

		switch (e.key) {
			case 'ArrowLeft':  next = (idx + 1) % count; break;
			case 'ArrowRight': next = (idx - 1 + count) % count; break;
			case 'Home':       next = 0; break;
			case 'End':        next = count - 1; break;
			default: return;
		}
		e.preventDefault();
		activateTab($tabs.eq(next), true);
	});

	// Jump from a job row's submissions count straight into the filtered list.
	$(document).on('click', '.kivun-view-job-apps', function () {
		var jobId = String($(this).data('job'));
		var $dash = $(this).closest('.kivun-employer-dashboard');

		activateTab($dash.find('.kivun-tab[data-tab="applications"]'), true);
		$dash.find('#kivun-apps-filter-job').val(jobId);
		filterApplications();
	});

	// ── Applications filtering ────────────────────────────────────────────────────
	$(document).on('input', '#kivun-apps-search', function () {
		clearTimeout(filterTimeout);
		filterTimeout = setTimeout(filterApplications, 200);
	});
	$(document).on('change', '#kivun-apps-filter-job, #kivun-apps-filter-status', filterApplications);

	function filterApplications() {
		var $rows = $('.kivun-app-row');
		if (!$rows.length) return;

		var term   = ($('#kivun-apps-search').val() || '').toLowerCase().trim();
		var jobId  = $('#kivun-apps-filter-job').val() || '';
		var status = $('#kivun-apps-filter-status').val() || '';
		var shown  = 0;

		$rows.each(function () {
			var $row = $(this);
			var ok =
				(!term   || ($row.data('search') || '').indexOf(term) !== -1) &&
				(!jobId  || String($row.data('job')) === jobId) &&
				(!status || String($row.data('status')) === status);

			$row.toggle(ok);
			if (ok) shown++;
		});

		$('.kivun-apps-empty').toggle(shown === 0);
	}

	// ── Application status update (employer) ──────────────────────────────────────
	$(document).on('change', '.kivun-app-status-select', function () {
		var $select = $(this);
		var $row    = $select.closest('.kivun-app-row');
		var $ind    = $select.siblings('.kivun-saved-indicator');

		$ind.hide();

		$.post(kivun.ajax_url, {
			action: 'kivun_employer_update_app',
			nonce:  kivun.nonce,
			app_id: $select.data('app'),
			status: $select.val(),
		}, function (res) {
			if (res.success) {
				$row.attr('data-status', res.data.status).data('status', res.data.status);
				$ind.text(kivun.i18n.saved).css('color', '#16a34a').show().delay(1600).fadeOut();
			} else {
				$ind.text(kivun.i18n.save_error).css('color', '#dc2626').show();
			}
		}).fail(function () {
			$ind.text(kivun.i18n.save_error).css('color', '#dc2626').show();
		});
	});

	// ── Application note auto-save on blur (employer) ─────────────────────────────
	$(document).on('blur', '.kivun-app-note', function () {
		var $ta  = $(this);
		var $ind = $ta.closest('.kivun-app-row').find('.kivun-saved-indicator');

		$.post(kivun.ajax_url, {
			action: 'kivun_employer_app_note',
			nonce:  kivun.nonce,
			app_id: $ta.data('app'),
			note:   $ta.val(),
		}, function (res) {
			if (res.success) {
				$ind.text(kivun.i18n.saved).css('color', '#16a34a').show().delay(1600).fadeOut();
			}
		});
	});

}(jQuery));
