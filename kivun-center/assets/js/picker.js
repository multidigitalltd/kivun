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

		function chosenLabel() {
			var o = select.options[select.selectedIndex];
			return o ? o.text : '';
		}

		function render(term) {
			var needle = (term || '').trim().toLowerCase();
			list.textContent = '';
			active = -1;

			options.forEach(function (opt) {
				if (needle && opt.label.toLowerCase().indexOf(needle) === -1) { return; }
				var li = document.createElement('li');
				li.className = 'kivun-pick__opt';
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', opt.value === select.value ? 'true' : 'false');
				li.dataset.value = opt.value;
				li.textContent = opt.label;
				list.appendChild(li);
			});

			if (!list.children.length) {
				var none = document.createElement('li');
				none.className = 'kivun-pick__none';
				none.textContent = select.dataset.pickEmpty || '';
				list.appendChild(none);
			}
		}

		function open() {
			render(input.value === chosenLabel() ? '' : input.value);
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.value = chosenLabel();
		}

		function highlight(next) {
			var items = list.querySelectorAll('.kivun-pick__opt');
			if (!items.length) { return; }
			if (active >= 0 && items[active]) { items[active].classList.remove('is-active'); }
			active = (next + items.length) % items.length;
			items[active].classList.add('is-active');
			items[active].scrollIntoView({ block: 'nearest' });
		}

		function choose(li) {
			if (!li || !li.dataset) { return; }
			select.value = li.dataset.value;
			// Fired so anything else listening to the filter reacts the same
			// way it would to a plain select.
			select.dispatchEvent(new Event('change', { bubbles: true }));
			close();
		}

		input.value = chosenLabel();

		input.addEventListener('focus', open);
		input.addEventListener('input', function () { open(); });

		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (list.hidden) { open(); }
				highlight(active + (e.key === 'ArrowDown' ? 1 : -1));
			} else if (e.key === 'Enter') {
				if (!list.hidden) {
					e.preventDefault();
					choose(list.querySelectorAll('.kivun-pick__opt')[active] ||
						(list.querySelectorAll('.kivun-pick__opt').length === 1 ? list.querySelector('.kivun-pick__opt') : null));
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
