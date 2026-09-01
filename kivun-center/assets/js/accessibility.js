/* global kivunA11y */
(function () {
	'use strict';

	var STORAGE_KEY = 'kivunA11y';
	var FONT_MIN = 1;
	var FONT_MAX = 1.6;
	var FONT_STEP = 0.1;

	// Features that simply toggle a class on <html>.
	var CLASS_FEATURES = [
		'contrast',
		'negative',
		'grayscale',
		'underline-links',
		'readable-font',
		'stop-animations',
		'highlight-headings',
		'highlight-links',
		'big-cursor'
	];

	var root = document.documentElement;
	var state = readState();
	var guideEl = null;

	// Apply persisted state as early as possible to avoid a flash.
	applyState();

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/**
	 * Read persisted state from localStorage.
	 *
	 * @return {Object} The stored state object.
	 */
	function readState() {
		var fallback = { classes: {}, font: 1, features: {} };
		try {
			var raw = window.localStorage.getItem(STORAGE_KEY);
			if (!raw) {
				return fallback;
			}
			var parsed = JSON.parse(raw);
			parsed.classes = parsed.classes || {};
			parsed.features = parsed.features || {};
			parsed.font = typeof parsed.font === 'number' ? parsed.font : 1;
			return parsed;
		} catch (e) {
			return fallback;
		}
	}

	/**
	 * Persist the current state to localStorage.
	 *
	 * @return {void}
	 */
	function saveState() {
		try {
			window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
		} catch (e) {
			// Storage may be unavailable; fail silently.
		}
	}

	/**
	 * Apply the in-memory state to the document.
	 *
	 * @return {void}
	 */
	function applyState() {
		CLASS_FEATURES.forEach(function (feature) {
			root.classList.toggle('kivun-a11y-' + feature, !!state.features[feature]);
		});
		var scale = clampFont(state.font);
		state.font = scale;
		root.style.setProperty('--kivun-a11y-font-scale', String(scale));
		if (state.features['reading-guide']) {
			enableReadingGuide();
		}
	}

	/**
	 * Reflect pressed/expanded state on the toolbar controls.
	 *
	 * @return {void}
	 */
	function syncButtons() {
		var buttons = document.querySelectorAll('.kivun-a11y-btn[data-a11y]');
		Array.prototype.forEach.call(buttons, function (btn) {
			var feature = btn.getAttribute('data-a11y');
			var pressed = !!state.features[feature];
			btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
		});
	}

	/**
	 * Clamp a font scale value to the allowed range.
	 *
	 * @param {number} value The desired scale.
	 * @return {number} The clamped scale.
	 */
	function clampFont(value) {
		var v = Math.round(value * 10) / 10;
		if (v < FONT_MIN) {
			return FONT_MIN;
		}
		if (v > FONT_MAX) {
			return FONT_MAX;
		}
		return v;
	}

	/**
	 * Wire up the toolbar once the DOM is ready.
	 *
	 * @return {void}
	 */
	function init() {
		var toggle = document.querySelector('.kivun-a11y-toggle');
		var panel = document.getElementById('kivun-a11y-panel');
		if (!toggle || !panel) {
			return;
		}

		syncButtons();

		toggle.addEventListener('click', function () {
			if (panel.hasAttribute('hidden')) {
				openPanel(toggle, panel);
			} else {
				closePanel(toggle, panel);
			}
		});

		var closeBtn = panel.querySelector('.kivun-a11y-close');
		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				closePanel(toggle, panel);
			});
		}

		panel.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' || event.key === 'Esc') {
				closePanel(toggle, panel);
				return;
			}
			if (event.key === 'Tab') {
				trapFocus(event, panel);
			}
		});

		// Event delegation for feature and reset buttons.
		panel.addEventListener('click', function (event) {
			var resetBtn = event.target.closest('[data-a11y-reset]');
			if (resetBtn) {
				resetAll();
				return;
			}
			var featureBtn = event.target.closest('.kivun-a11y-btn[data-a11y]');
			if (featureBtn) {
				handleFeature(featureBtn.getAttribute('data-a11y'), featureBtn);
			}
		});
	}

	/**
	 * Open the accessibility panel and move focus inside.
	 *
	 * @param {HTMLElement} toggle The trigger button.
	 * @param {HTMLElement} panel  The dialog panel.
	 * @return {void}
	 */
	function openPanel(toggle, panel) {
		panel.removeAttribute('hidden');
		toggle.setAttribute('aria-expanded', 'true');
		var focusable = getFocusable(panel);
		if (focusable.length) {
			focusable[0].focus();
		}
	}

	/**
	 * Close the accessibility panel and restore focus to the trigger.
	 *
	 * @param {HTMLElement} toggle The trigger button.
	 * @param {HTMLElement} panel  The dialog panel.
	 * @return {void}
	 */
	function closePanel(toggle, panel) {
		panel.setAttribute('hidden', '');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.focus();
	}

	/**
	 * Return the focusable elements within a container.
	 *
	 * @param {HTMLElement} container The container element.
	 * @return {Array<HTMLElement>} The focusable elements.
	 */
	function getFocusable(container) {
		var selector =
			'a[href], button:not([disabled]), input:not([disabled]), ' +
			'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
		return Array.prototype.filter.call(
			container.querySelectorAll(selector),
			function (el) {
				return el.offsetParent !== null || el === document.activeElement;
			}
		);
	}

	/**
	 * Keep keyboard focus cycling within the panel while open.
	 *
	 * @param {KeyboardEvent} event The keydown event.
	 * @param {HTMLElement}   panel The dialog panel.
	 * @return {void}
	 */
	function trapFocus(event, panel) {
		var focusable = getFocusable(panel);
		if (!focusable.length) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Handle a feature button activation.
	 *
	 * @param {string}      feature The feature key.
	 * @param {HTMLElement} button  The activated button.
	 * @return {void}
	 */
	function handleFeature(feature, button) {
		if (feature === 'font-increase') {
			setFont(state.font + FONT_STEP);
			return;
		}
		if (feature === 'font-decrease') {
			setFont(state.font - FONT_STEP);
			return;
		}

		var active = !state.features[feature];
		state.features[feature] = active;
		button.setAttribute('aria-pressed', active ? 'true' : 'false');

		if (feature === 'reading-guide') {
			if (active) {
				enableReadingGuide();
			} else {
				disableReadingGuide();
			}
		} else {
			root.classList.toggle('kivun-a11y-' + feature, active);
		}

		saveState();
	}

	/**
	 * Update the root font scale.
	 *
	 * @param {number} value The desired scale.
	 * @return {void}
	 */
	function setFont(value) {
		state.font = clampFont(value);
		root.style.setProperty('--kivun-a11y-font-scale', String(state.font));
		saveState();
	}

	/**
	 * Create and activate the horizontal reading guide.
	 *
	 * @return {void}
	 */
	function enableReadingGuide() {
		if (guideEl) {
			return;
		}
		guideEl = document.createElement('div');
		guideEl.className = 'kivun-a11y-reading-guide';
		guideEl.setAttribute('aria-hidden', 'true');
		document.body.appendChild(guideEl);
		document.addEventListener('mousemove', moveGuide);
		document.addEventListener('touchmove', moveGuide, { passive: true });
	}

	/**
	 * Remove the reading guide and its listeners.
	 *
	 * @return {void}
	 */
	function disableReadingGuide() {
		if (!guideEl) {
			return;
		}
		document.removeEventListener('mousemove', moveGuide);
		document.removeEventListener('touchmove', moveGuide);
		if (guideEl.parentNode) {
			guideEl.parentNode.removeChild(guideEl);
		}
		guideEl = null;
	}

	/**
	 * Position the reading guide at the pointer's vertical position.
	 *
	 * @param {Event} event The pointer or touch event.
	 * @return {void}
	 */
	function moveGuide(event) {
		if (!guideEl) {
			return;
		}
		var y;
		if (event.touches && event.touches.length) {
			y = event.touches[0].clientY;
		} else {
			y = event.clientY;
		}
		guideEl.style.top = y + 'px';
	}

	/**
	 * Reset every accessibility setting to its default.
	 *
	 * @return {void}
	 */
	function resetAll() {
		CLASS_FEATURES.forEach(function (feature) {
			root.classList.remove('kivun-a11y-' + feature);
		});
		disableReadingGuide();
		root.style.setProperty('--kivun-a11y-font-scale', '1');
		state = { classes: {}, font: 1, features: {} };

		try {
			window.localStorage.removeItem(STORAGE_KEY);
		} catch (e) {
			// Ignore storage errors.
		}

		var buttons = document.querySelectorAll('.kivun-a11y-btn[data-a11y]');
		Array.prototype.forEach.call(buttons, function (btn) {
			btn.setAttribute('aria-pressed', 'false');
		});
	}
})();
