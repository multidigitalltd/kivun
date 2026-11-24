/* global kivunCities */
/*
 * Kivun Center — the city list in forms built with Elementor Pro.
 *
 * The plugin's own forms draw the list on the server (Kivun_Cities::field()).
 * A form built in Elementor's editor is not drawn by the plugin, and Elementor
 * has no way to open a field only when "אחר" is chosen. So a city field there
 * is turned into the list here, in the browser: a text field whose id says
 * city, or whose label (or placeholder) says עיר / יישוב.
 *
 * Whichever control is in view carries the field's name. Choosing a city
 * sends the city; choosing "אחר" and typing sends what was typed — so every
 * action Elementor runs with the submission (its e-mail, its Submissions
 * list, this plugin's lead, a webhook) receives a town, not the word "אחר".
 *
 * A form that must keep a free text city is left alone when the form widget
 * (Advanced → CSS Classes) carries `kivun-keep-city`.
 */
(function () {
	'use strict';

	var cfg = window.kivunCities;
	if (!cfg || !cfg.cities || !cfg.cities.length) { return; }

	var OTHER = cfg.other;
	// עיר / יישוב as a word of its own: "צעירים" and "עירוני" are not cities.
	var BY_LABEL = /(^|[^֐-׿])(עיר|יישוב|ישוב)([^֐-׿]|$)/;
	var BY_ID = /city|yishuv/i;

	function fieldId(input) {
		var m = /^form_fields\[(.+)\]$/.exec(input.name || '');
		return m ? m[1] : '';
	}

	function isCity(input) {
		var id = fieldId(input);
		if (!id) { return false; }
		if (BY_ID.test(id)) { return true; }

		var text = input.getAttribute('placeholder') || '';
		Array.prototype.forEach.call(input.labels || [], function (label) {
			text += ' ' + label.textContent;
		});
		return BY_LABEL.test(text);
	}

	function option(select, value, label) {
		var o = document.createElement('option');
		o.value = value;
		o.textContent = label;
		select.appendChild(o);
	}

	function boxOf(select) {
		var box = select.nextElementSibling;
		return box && box.classList.contains('kivun-city-other') ? box : null;
	}

	// Shows the box while "אחר" is chosen, and hands the field's name to
	// whichever of the two holds the answer.
	function sync(select, focus) {
		var box = boxOf(select);
		if (!box) { return; }

		var name = select.getAttribute('data-kivun-ecity');
		var open = select.value === OTHER;

		box.hidden = !open;
		box.style.display = open ? '' : 'none';
		box.disabled = !open;
		box.required = open && select.required;
		if (!open) { box.value = ''; }

		if (open && box.value.trim() !== '') {
			box.name = name;
			select.removeAttribute('name');
		} else {
			// "אחר" with nothing typed is still sent, as the word.
			select.name = name;
			box.removeAttribute('name');
		}

		if (open && focus) { box.focus(); }
	}

	function convert(input) {
		var select = document.createElement('select');
		select.id = input.id;
		select.className = input.className;
		select.required = input.required;
		select.setAttribute('data-kivun-ecity', input.name);
		if (input.hasAttribute('aria-required')) {
			select.setAttribute('aria-required', input.getAttribute('aria-required'));
		}

		option(select, '', cfg.pick);
		cfg.cities.forEach(function (city) { option(select, city, city); });
		option(select, OTHER, OTHER);

		var box = document.createElement('input');
		box.type = 'text';
		box.className = input.className + ' kivun-city-other';
		box.maxLength = 100;
		box.placeholder = cfg.hint;
		box.setAttribute('aria-label', cfg.label);
		box.style.marginTop = '8px';

		// A value Elementor filled in already (a default, or a dynamic tag)
		// is kept: chosen when it is on the list, typed in the box when not.
		var given = (input.value || '').trim();

		input.parentNode.replaceChild(select, input);
		select.parentNode.insertBefore(box, select.nextSibling);

		if (given && cfg.cities.indexOf(given) !== -1) {
			select.value = given;
		} else if (given) {
			select.value = OTHER;
			box.value = given;
		}
		sync(select, false);
	}

	function scan() {
		var inputs = document.querySelectorAll('form.elementor-form input[type="text"][name^="form_fields["]');
		Array.prototype.forEach.call(inputs, function (input) {
			// The box this script added carries the field's name once a city
			// is typed in it, and a second scan must not take it for one.
			if (input.classList.contains('kivun-city-other')) { return; }
			if (input.closest('.kivun-keep-city') || !isCity(input)) { return; }
			convert(input);
		});
	}

	document.addEventListener('change', function (e) {
		var select = e.target.closest && e.target.closest('select[data-kivun-ecity]');
		if (select) { sync(select, true); }
	});

	document.addEventListener('input', function (e) {
		var box = e.target.closest && e.target.closest('.kivun-city-other');
		var select = box && box.previousElementSibling;
		if (select && select.hasAttribute('data-kivun-ecity')) { sync(select, false); }
	});

	// Elementor clears the form after a successful send. The event comes
	// before the fields are cleared, so the state is read again after it.
	document.addEventListener('reset', function (e) {
		var form = e.target;
		setTimeout(function () {
			Array.prototype.forEach.call(form.querySelectorAll('select[data-kivun-ecity]'), function (select) {
				sync(select, false);
			});
		}, 0);
	}, true);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	// A popup's form is on the page from the start, but a form put there
	// later is found when the popup opens.
	if (window.jQuery) {
		window.jQuery(document).on('elementor/popup/show', scan);
	}
}());
