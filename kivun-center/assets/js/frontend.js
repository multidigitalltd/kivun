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

}(jQuery));
