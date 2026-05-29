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

}(jQuery));
