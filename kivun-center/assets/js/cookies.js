/**
 * Kivun Center — cookie notice (vanilla JS, no dependencies).
 *
 * An informational banner: once the visitor acknowledges, the choice is stored
 * (localStorage + a cookie) so it is not shown again. Scripts tagged
 * `<script type="text/plain" data-kivun-cookie="...">` are then activated and
 * Google Consent Mode (when present) is granted; a `kivun:cookie-consent`
 * event is dispatched for integrations.
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

	function readConsent() {
		try {
			return JSON.parse( localStorage.getItem( STORAGE ) );
		} catch ( e ) {
			return null;
		}
	}

	function applyConsent( prefs ) {
		var parked = document.querySelectorAll( 'script[type="text/plain"][data-kivun-cookie]' );
		Array.prototype.forEach.call( parked, function ( node ) {
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

		if ( 'function' === typeof window.gtag ) {
			window.gtag( 'consent', 'update', {
				analytics_storage: 'granted',
				ad_storage: 'granted',
				ad_user_data: 'granted',
				ad_personalization: 'granted'
			} );
		}

		try {
			document.dispatchEvent( new CustomEvent( 'kivun:cookie-consent', { detail: prefs } ) );
		} catch ( e ) {}
	}

	function acknowledge() {
		var prefs = { necessary: true, analytics: true, marketing: true, t: Date.now() };
		try {
			localStorage.setItem( STORAGE, JSON.stringify( prefs ) );
		} catch ( e ) {}

		var expires = new Date();
		expires.setMonth( expires.getMonth() + MONTHS );
		document.cookie = COOKIE + '=1;expires=' + expires.toUTCString() + ';path=/;SameSite=Lax';

		applyConsent( prefs );
		if ( banner ) {
			banner.hidden = true;
		}
	}

	Array.prototype.forEach.call( root.querySelectorAll( '[data-cc-accept]' ), function ( el ) {
		el.addEventListener( 'click', acknowledge );
	} );

	var existing = readConsent();
	if ( existing ) {
		applyConsent( existing );
	} else if ( banner ) {
		banner.hidden = false;
	}
}() );
