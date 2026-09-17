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

	// The little "saved" mark that belongs to this control — the one sitting
	// in its own cell.
	function indicatorFor(el) {
		return el.parentNode.querySelector('.kivun-saved-indicator');
	}

	function flash(indicator, ok, message) {
		if (!indicator) { return; }
		indicator.textContent = ok ? '✓ נשמר' : (message || 'שגיאה');
		indicator.style.color = ok ? '#16a34a' : '#dc2626';
		indicator.style.display = 'inline';
		if (ok) {
			setTimeout(function () { indicator.style.display = 'none'; }, 1700);
		}
	}

	// What the server said went wrong, when it said anything.
	function reason(res) {
		return (res && res.data && res.data.message) ? res.data.message : '';
	}

	// The value the row actually holds, as opposed to the one just picked.
	// Read from the option the server marked selected, so a failed save has
	// something true to go back to.
	function committed(select) {
		if (typeof select.dataset.prev === 'undefined') {
			var chosen = select.querySelector('option[selected]');
			select.dataset.prev = chosen ? chosen.value : select.value;
		}
		return select.dataset.prev;
	}

	// Status select — save on change.
	document.addEventListener('change', function (e) {
		var select = e.target.closest('.kivun-status-select');
		if (!select) { return; }

		var indicator = indicatorFor(select);
		if (indicator) { indicator.style.display = 'none'; }

		var previous = committed(select);
		var wanted = select.value;

		post({
			action: 'kivun_update_status',
			nonce: kivunCrm.nonce,
			table: select.dataset.table,
			id: select.dataset.id,
			status: wanted
		}).then(function (res) {
			if (!res || !res.success) {
				// Put back what the row actually holds. Leaving the chosen
				// value on screen is how a save that never happened passes
				// for one that did.
				select.value = previous;
				flash(indicator, false, reason(res));
				return;
			}

			// The server says what it stored; trust that over what was picked.
			var stored = (res.data && res.data.status) ? res.data.status : wanted;
			select.value = stored;
			select.dataset.prev = stored;
			flash(indicator, true);
		}).catch(function () {
			select.value = previous;
			flash(indicator, false, 'אין חיבור');
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

		// Its own cell, not the row's first indicator: with 'row' the tick
		// lands in whichever cell happens to come first, so reordering the
		// columns would quietly report the note's save somewhere else.
		var indicator = indicatorFor(note);

		post({
			action: 'kivun_save_note',
			nonce: kivunCrm.nonce,
			table: note.dataset.table,
			id: note.dataset.id,
			note: note.value
		}).then(function (res) {
			// A note that failed to save used to say nothing at all, which
			// reads exactly like one that saved.
			flash(indicator, !!(res && res.success), reason(res));
		}).catch(function () {
			flash(indicator, false, 'אין חיבור');
		});
	}, true);

	// ── Tables that fit the screen they are on ────────────────────────────────
	//
	// A console table with eleven columns does not fit a laptop, and which
	// tables those are cannot be decided at a breakpoint: each needs a
	// different width, and the console sits inside whatever the theme gives it.
	// So it is measured. A table wider than its container becomes a stack of
	// cards — one per row, each cell labelled with its column — and one that
	// fits is left alone as a table.

	// Give every cell the name of its column, taken from the header, so the
	// stacked card can say what it is showing. Read from the table itself, so
	// it cannot drift out of step with the headings.
	function labelCells(table) {
		if (table.dataset.labelled === '1') { return; }
		table.dataset.labelled = '1';

		var heads = table.querySelectorAll('thead th');
		if (!heads.length) { return; }

		Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
			Array.prototype.forEach.call(row.children, function (cell, i) {
				// A label written into the markup wins — it was chosen on purpose.
				if (!cell.hasAttribute('data-label') && heads[i]) {
					cell.setAttribute('data-label', (heads[i].textContent || '').trim());
				}
			});
		});
	}

	function fitTables() {
		var tables = document.querySelectorAll('.kivun-cc-tablewrap > .kivun-cc-table');
		if (!tables.length) { return; }

		// Measured unstacked, so the reading is of the table's real width and
		// not of the cards it was turned into last time. Cleared for all of
		// them first, then measured, then set — two reflows rather than one
		// per table.
		Array.prototype.forEach.call(tables, function (table) {
			labelCells(table);
			table.classList.remove('is-stacked');
		});

		var verdicts = Array.prototype.map.call(tables, function (table) {
			var wrap = table.parentNode;
			// A pixel of slack: sub-pixel widths should not stack a table that fits.
			return table.scrollWidth > wrap.clientWidth + 1;
		});

		Array.prototype.forEach.call(tables, function (table, i) {
			table.classList.toggle('is-stacked', verdicts[i]);
		});
	}

	var fitTimer = null;
	function fitTablesSoon() {
		window.clearTimeout(fitTimer);
		fitTimer = window.setTimeout(fitTables, 120);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', fitTables);
	} else {
		fitTables();
	}
	window.addEventListener('resize', fitTablesSoon);
	// A table inside a <details> has no width until the panel is opened.
	document.addEventListener('toggle', fitTablesSoon, true);

}());
