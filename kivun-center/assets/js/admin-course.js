/* WooCommerce product Select2 picker for course metabox */
/* global jQuery, kivunAdmin */
(function ($) {
	'use strict';

	$(function () {
		var $select = $('#_kivun_wc_product_select');
		if (!$select.length) return;

		$select.select2({
			dir: 'rtl',
			width: '400px',
			placeholder: kivunAdmin.search_placeholder,
			allowClear: true,
			ajax: {
				url: kivunAdmin.ajax_url,
				dataType: 'json',
				delay: 300,
				data: function (params) {
					return {
						action: 'kivun_search_products',
						nonce:  kivunAdmin.nonce,
						q:      params.term || '',
					};
				},
				processResults: function (res) {
					if (!res.success) return { results: [] };
					return { results: res.data };
				},
				cache: true,
			},
		});

		// Pre-select saved value
		var savedId = $select.data('selected');
		if (savedId) {
			$.post(kivunAdmin.ajax_url, {
				action: 'kivun_search_products',
				nonce:  kivunAdmin.nonce,
				id:     savedId,
			}, function (res) {
				if (res.success && res.data.length) {
					var item = res.data[0];
					var option = new Option(item.text, item.id, true, true);
					$select.append(option).trigger('change');
				}
			});
		}
	});

}(jQuery));
