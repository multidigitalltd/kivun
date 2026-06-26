/**
 * Kivun Center — cookie consent (vanilla JS, no dependencies).
 *
 * Stores the visitor's choice in localStorage + a cookie, activates opt-in
 * scripts tagged `<script type="text/plain" data-kivun-cookie="analytics">`,
 * updates Google Consent Mode when present, and fires a `kivun:cookie-consent`
 * event with the chosen preferences.
 */
( function () {
	'use strict';

	var STORAGE = 'kivunCookieConsent';
	var cfg = window.kivunCookies || {};
	var COOKIE = cfg.cookieName || 'kivun_cookie_consent';
	var MONTHS = cfg.months || 6;

	var root = document.querySelector( '.kivun-cc' );
	if ( ! root ) {
		return;
	}

	var banner = root.querySelector( '.kivun-cc-banner' );
	var modal = root.querySelector( '.kivun-cc-modal' );
	var panel = root.querySelector( '.kivun-cc-modal__panel' );
	var reopen = root.querySelector( '.kivun-cc-reopen' );
	var toggles = Array.prototype.slice.call( root.querySelectorAll( '.kivun-cc-switch input[type="checkbox"]' ) );
	var lastFocus = null;

	function readConsent() {
		try {
			return JSON.parse( localStorage.getItem( STORAGE ) );
		} catch ( e ) {
			return null;
		}
	}

	function writeConsent( prefs ) {
		prefs.t = Date.now();
		try {
			localStorage.setItem( STORAGE, JSON.stringify( prefs ) );
		} catch ( e ) {}

		var expires = new Date();
		expires.setMonth( expires.getMonth() + MONTHS );
		var value = encodeURIComponent( JSON.stringify( { a: prefs.analytics ? 1 : 0, m: prefs.marketing ? 1 : 0 } ) );
		document.cookie = COOKIE + '=' + value + ';expires=' + expires.toUTCString() + ';path=/;SameSite=Lax';

		applyConsent( prefs );
	}

	function applyConsent( prefs ) {
		// Activate opt-in scripts that were parked as text/plain.
		var parked = document.querySelectorAll( 'script[type="text/plain"][data-kivun-cookie]' );
		Array.prototype.forEach.call( parked, function ( node ) {
			var category = node.getAttribute( 'data-kivun-cookie' );
			if ( 'necessary' !== category && ! prefs[ category ] ) {
				return;
			}
			var script = document.createElement( 'script' );
			if ( node.src ) {
				script.src = node.src;
			} else {
				script.textContent = node.textContent;
			}
			Array.prototype.forEach.call( node.attributes, function ( attr ) {
				if ( 'type' !== attr.name && 'data-kivun-cookie' !== attr.name ) {
					script.setAttribute( attr.name, attr.value );
				}
			} );
			node.parentNode.replaceChild( script, node );
		} );

		// Google Consent Mode v2 (only if gtag is present).
		if ( 'function' === typeof window.gtag ) {
			window.gtag( 'consent', 'update', {
				analytics_storage: prefs.analytics ? 'granted' : 'denied',
				ad_storage: prefs.marketing ? 'granted' : 'denied',
				ad_user_data: prefs.marketing ? 'granted' : 'denied',
				ad_personalization: prefs.marketing ? 'granted' : 'denied'
			} );
		}

		try {
			document.dispatchEvent( new CustomEvent( 'kivun:cookie-consent', { detail: prefs } ) );
		} catch ( e ) {}
	}

	function showBanner() { if ( banner ) { banner.hidden = false; } }
	function hideBanner() { if ( banner ) { banner.hidden = true; } }
	function showReopen() { if ( reopen ) { reopen.hidden = false; } }

	function syncToggles() {
		var prefs = readConsent() || {};
		toggles.forEach( function ( t ) {
			if ( t.disabled ) {
				t.checked = true;
			} else {
				t.checked = !! prefs[ t.value ];
			}
		} );
	}

	function openModal() {
		lastFocus = document.activeElement;
		syncToggles();
		if ( modal ) { modal.hidden = false; }
		if ( panel ) { panel.focus(); }
	}

	function closeModal() {
		if ( modal ) { modal.hidden = true; }
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function finish( prefs ) {
		writeConsent( prefs );
		hideBanner();
		closeModal();
		showReopen();
	}

	function acceptAll() { finish( { necessary: true, analytics: true, marketing: true } ); }
	function rejectAll() { finish( { necessary: true, analytics: false, marketing: false } ); }
	function saveCustom() {
		var prefs = { necessary: true };
		toggles.forEach( function ( t ) {
			if ( ! t.disabled ) {
				prefs[ t.value ] = t.checked;
			}
		} );
		finish( prefs );
	}

	function on( selector, handler ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), function ( el ) {
			el.addEventListener( 'click', handler );
		} );
	}

	on( '[data-cc-accept]', acceptAll );
	on( '[data-cc-reject]', rejectAll );
	on( '[data-cc-save]', saveCustom );
	on( '[data-cc-settings]', openModal );
	on( '[data-cc-close]', closeModal );
	if ( reopen ) {
		reopen.addEventListener( 'click', openModal );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modal && ! modal.hidden ) {
			closeModal();
		}
	} );

	// Initial state.
	var existing = readConsent();
	if ( existing ) {
		applyConsent( existing );
		showReopen();
	} else {
		showBanner();
	}
}() );
