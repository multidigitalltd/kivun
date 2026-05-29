/* Kivun — inline status update for registrations & applications metaboxes */
/* global kivunCrm, jQuery */
(function ($) {
	'use strict';

	$(document).on('change', '.kivun-status-select', function () {
		var $select    = $(this);
		var $indicator = $select.siblings('.kivun-saved-indicator');

		$indicator.hide().removeClass('error');

		$.post(kivunCrm.ajax_url, {
			action: 'kivun_update_status',
			nonce:  kivunCrm.nonce,
			table:  $select.data('table'),
			id:     $select.data('id'),
			status: $select.val(),
		}, function (res) {
			if (res.success) {
				$indicator.text('✓ נשמר').css('color', '#16a34a').show().delay(1800).fadeOut();
			} else {
				$indicator.text('שגיאה').css('color', '#dc2626').show();
			}
		});
	});

	// Notes — auto-save on blur
	$(document).on('blur', '.kivun-notes-input', function () {
		var $ta  = $(this);
		var $row = $ta.closest('tr');
		var $ind = $row.find('.kivun-saved-indicator').first();

		$.post(kivunCrm.ajax_url, {
			action: 'kivun_save_note',
			nonce:  kivunCrm.nonce,
			table:  $ta.data('table'),
			id:     $ta.data('id'),
			note:   $ta.val(),
		}, function (res) {
			if (res.success) {
				$ind.text('✓ נשמר').css('color', '#16a34a').show().delay(1600).fadeOut();
			}
		});
	});

}(jQuery));
