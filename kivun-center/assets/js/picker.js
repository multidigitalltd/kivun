/**
 * A filter dropdown you can type into.
 *
 * Wraps a <select> without replacing it: the real control stays in the form,
 * keeps its name and carries the value, so what submits is unchanged and a
 * page with no JavaScript still has a working filter. Only the way a value
 * is reached changes.
 *
 * Standalone on purpose. The same lists need searching on the site's content
 * console and in wp-admin, which load different bundles, so this depends on
 * nothing but the element it is given — its labels come from data attributes
 * rather than from a localised global that only one of those pages has.
 *
 * Markup it expects:
 *   <select class="kivun-pick-src" data-pick-label="…" data-pick-empty="…">
 */
(function () {
	'use strict';

	// ── Type-to-search on the filter dropdowns ───────────────────────────────────
	// Progressive enhancement over the real <select>, which stays in the form
	// and keeps its name: with no JS the filters are exactly what they were,
	// and what submits is the select's value either way. Only the way it is
	// chosen changes.
	function kivunPicker(select) {
		if (select.dataset.picked) { return; }
		select.dataset.picked = '1';

		var wrap = document.createElement('div');
		wrap.className = 'kivun-pick';

		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'kivun-pick__input';
		input.autocomplete = 'off';
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-label', select.dataset.pickLabel || '');

		var list = document.createElement('ul');
		list.className = 'kivun-pick__list';
		list.setAttribute('role', 'listbox');
		list.hidden = true;

		select.parentNode.insertBefore(wrap, select);
		wrap.appendChild(input);
		wrap.appendChild(list);
		wrap.appendChild(select);
		select.classList.add('kivun-pick__native');

		var options = [].map.call(select.options, function (o) {
			return { value: o.value, label: o.text };
		});
		var active = -1;
		var many = select.multiple;
		// What was typed, kept apart from what the box displays. The box
		// shows the chosen labels when it is closed, and reading the search
		// term back out of it meant the labels became the term the moment
		// anything was picked — filtering the list down to nothing.
		var term = '';

		function chosen() {
			return [].filter.call(select.options, function (o) { return o.selected; });
		}

		// What the closed field reads. With several picked it names them, and
		// with none it says so in the words the field was given — an empty
		// box beside a filter looks like one that is broken rather than one
		// that is letting everything through.
		function chosenLabel() {
			var picked = chosen();

			if (!many) { return picked.length ? picked[0].text : ''; }
			if (!picked.length) { return select.dataset.pickAll || ''; }

			return picked.map(function (o) { return o.text; }).join(', ');
		}

		function render(term) {
			var needle = (term || '').trim().toLowerCase();
			list.textContent = '';
			active = -1;

			// A way back to "all of them" that is not unticking each one in
			// turn. Without it the only route back was the button that
			// clears every filter on the screen, which is a much bigger
			// thing to ask for than "never mind about the towns".
			//
			// Shown whatever has been typed, because clearing is exactly
			// what somebody does when a search has gone wrong.
			if (many && select.dataset.pickAll) {
				var all = document.createElement('li');
				all.className = 'kivun-pick__opt kivun-pick__all';
				all.setAttribute('role', 'option');
				all.dataset.all = '1';
				all.textContent = select.dataset.pickAll;
				if (!chosen().length) {
					all.classList.add('is-on');
					all.setAttribute('aria-selected', 'true');
				}
				list.appendChild(all);
			}

			options.forEach(function (opt) {
				if (needle && opt.label.toLowerCase().indexOf(needle) === -1) { return; }
				var li = document.createElement('li');
				li.className = 'kivun-pick__opt';
				li.setAttribute('role', 'option');
				var on = isOn(opt.value);
				li.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) { li.classList.add('is-on'); }
				li.dataset.value = opt.value;
				li.textContent = opt.label;
				list.appendChild(li);
			});

			if (!list.querySelectorAll('.kivun-pick__opt:not(.kivun-pick__all)').length) {
				var none = document.createElement('li');
				none.className = 'kivun-pick__none';
				none.textContent = select.dataset.pickEmpty || '';
				list.appendChild(none);
			}
		}

		function open() {
			render(term);
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			term = '';
			input.value = chosenLabel();
		}

		// The results, without the synthetic "all of them" row.
		function results() {
			return list.querySelectorAll('.kivun-pick__opt:not(.kivun-pick__all)');
		}

		function highlight(next) {
			var items = list.querySelectorAll('.kivun-pick__opt');
			if (!items.length) { return; }

			// Arrowing down into a list lands on the first result, not on
			// the row that clears the filter. That row sits at the top, so
			// without this the first key press after typing a search offered
			// to throw the search away.
			if (active < 0 && next >= 0 && items[0].dataset.all) { next = 1; }

			if (active >= 0 && items[active]) { items[active].classList.remove('is-active'); }
			active = (next + items.length) % items.length;
			items[active].classList.add('is-active');
			items[active].scrollIntoView({ block: 'nearest' });
		}

		function isOn(value) {
			return chosen().some(function (o) { return o.value === value; });
		}

		function choose(li) {
			if (!li || !li.dataset) { return; }

			// "All of them" is the absence of a choice, so it clears rather
			// than selects: an IN clause naming every value and no clause at
			// all mean the same thing to the query, and the empty one says
			// it in a way that survives a town being added tomorrow.
			if (li.dataset.all) {
				[].forEach.call(select.options, function (o) { o.selected = false; });
				select.dispatchEvent(new Event('change', { bubbles: true }));
				term = '';
				input.value = chosenLabel();
				input.select();
				render('');
				return;
			}

			if (many) {
				// Toggled rather than replaced, and the list stays open: the
				// whole point of choosing several is that the next one is
				// about to be chosen too.
				[].forEach.call(select.options, function (o) {
					if (o.value === li.dataset.value) { o.selected = !o.selected; }
				});
				select.dispatchEvent(new Event('change', { bubbles: true }));
				// The list is redrawn so the tick moves. The box follows the
				// choice too while nothing is being typed — saying "all of
				// them" over two ticked rows contradicts the list right
				// underneath it — but a search in progress is left alone.
				render(term);

				// The labels go back in the box, and are selected: the field
				// still has focus with the caret at the end, so without this
				// the next thing typed lands after them and becomes the
				// search term — "ירושלים, בית שמשעיל" matches nothing, and
				// the list empties for no reason the reader can see. A term
				// already being typed is left as it is, since the next pick
				// is usually from the same search.
				if ('' === term) {
					input.value = chosenLabel();
					input.select();
				}
				return;
			}

			select.value = li.dataset.value;
			// Fired so anything else listening to the filter reacts the same
			// way it would to a plain select.
			select.dispatchEvent(new Event('change', { bubbles: true }));
			close();
		}

		input.value = chosenLabel();

		// Selected on focus so that typing replaces the labels sitting in the
		// box rather than being appended to them.
		input.addEventListener('focus', function () { input.select(); open(); });

		// What is in the box while nothing is being searched for is a summary
		// of the choices, not text to edit — so the first thing typed replaces
		// it rather than being added to the end of it.
		//
		// Selecting it on focus is not enough on its own: a click puts the
		// caret where it was clicked *after* the focus handler has run, and
		// clicking a box that already has focus fires no focus event at all.
		// Both end with "ירושלים" still in the box and "ירושליםמודיעין" as
		// the term, which matches nothing — the list empties and the reader
		// cannot see why. Emptied here instead, at the one moment text is
		// actually arriving, which covers typing and pasting alike.
		input.addEventListener('beforeinput', function () {
			if ('' === term) { input.value = ''; }
		});
		input.addEventListener('input', function () { term = input.value; open(); });

		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (list.hidden) { open(); }
				highlight(active + (e.key === 'ArrowDown' ? 1 : -1));
			} else if (e.key === 'Enter') {
				if (!list.hidden) {
					e.preventDefault();
					// Typing until one result is left and pressing Enter takes
					// it. Counted among the results only, or the "all" row
					// would make a single match look like two and the key
					// would do nothing at all.
					var only = results();
					choose(list.querySelectorAll('.kivun-pick__opt')[active] ||
						(1 === only.length ? only[0] : null));
				}
			} else if (e.key === 'Escape') {
				close();
			}
		});

		list.addEventListener('mousedown', function (e) {
			// On mousedown rather than click: the input's blur would close the
			// list first and the click would land on nothing.
			e.preventDefault();
			choose(e.target.closest('.kivun-pick__opt'));
		});

		input.addEventListener('blur', function () {
			setTimeout(close, 0);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', kivunPickerAll);
	} else {
		kivunPickerAll();
	}

	function kivunPickerAll() {
		[].forEach.call(document.querySelectorAll('select.kivun-pick-src'), kivunPicker);
	}
}());
