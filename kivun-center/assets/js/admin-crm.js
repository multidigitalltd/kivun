/* global kivunCrm */
/* Kivun — inline status & notes updates for the CRM metaboxes (vanilla JS). */
(function () {
	'use strict';

	function post(obj) {
		return fetch(kivunCrm.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: new URLSearchParams(obj)
		}).then(function (r) { return r.json(); });
	}

	function indicatorFor(el, scope) {
		if (scope === 'row') {
			var row = el.closest('tr');
			return row ? row.querySelector('.kivun-saved-indicator') : null;
		}
		return el.parentNode.querySelector('.kivun-saved-indicator');
	}

	function flash(indicator, ok) {
		if (!indicator) { return; }
		indicator.textContent = ok ? '✓ נשמר' : 'שגיאה';
		indicator.style.color = ok ? '#16a34a' : '#dc2626';
		indicator.style.display = 'inline';
		if (ok) {
			setTimeout(function () { indicator.style.display = 'none'; }, 1700);
		}
	}

	// Status select — save on change.
	document.addEventListener('change', function (e) {
		var select = e.target.closest('.kivun-status-select');
		if (!select) { return; }

		var indicator = select.parentNode.querySelector('.kivun-saved-indicator');
		if (indicator) { indicator.style.display = 'none'; }

		post({
			action: 'kivun_update_status',
			nonce: kivunCrm.nonce,
			table: select.dataset.table,
			id: select.dataset.id,
			status: select.value
		}).then(function (res) {
			flash(indicator, res.success);
		}).catch(function () {
			flash(indicator, false);
		});
	});

	// Delete a CRM row.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.kivun-delete-row');
		if (!btn) { return; }
		e.preventDefault();

		if (!window.confirm('למחוק את הרשומה? הפעולה אינה הפיכה.')) { return; }

		btn.disabled = true;
		post({
			action: 'kivun_delete_row',
			nonce: kivunCrm.nonce,
			table: btn.dataset.table,
			id: btn.dataset.id
		}).then(function (res) {
			if (res.success) {
				var row = btn.closest('tr');
				if (row) { row.parentNode.removeChild(row); }
			} else {
				btn.disabled = false;
				window.alert('המחיקה נכשלה.');
			}
		}).catch(function () {
			btn.disabled = false;
			window.alert('המחיקה נכשלה.');
		});
	});

	// Notes textarea — auto-save on blur.
	document.addEventListener('blur', function (e) {
		var note = e.target.closest('.kivun-notes-input');
		if (!note) { return; }

		var indicator = indicatorFor(note, 'row');

		post({
			action: 'kivun_save_note',
			nonce: kivunCrm.nonce,
			table: note.dataset.table,
			id: note.dataset.id,
			note: note.value
		}).then(function (res) {
			if (res.success) { flash(indicator, true); }
		});
	}, true);

}());
