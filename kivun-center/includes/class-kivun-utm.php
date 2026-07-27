<?php
/**
 * UTM arrival-source capture.
 *
 * A small footer script stores any utm_* query parameters (last touch) in a
 * cookie on every front-end page. When a lead/registration is saved, the UTM
 * label is folded into the record's "source" so it shows in the leads &
 * registrations table as the arrival source.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Captures and exposes UTM campaign data.
 */
class Kivun_Utm {

	/**
	 * UTM parameter keys we track.
	 */
	private const KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );

	/**
	 * Register the capture script.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'capture_script' ) );
	}

	/**
	 * Output a tiny script that stores utm_* params in the kivun_utm cookie.
	 *
	 * @return void
	 */
	public static function capture_script(): void {
		if ( is_admin() ) {
			return;
		}
		?>
		<script>
		( function () {
			try {
				var p = new URLSearchParams( window.location.search ),
					keys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content'],
					u = {}, has = false;
				keys.forEach( function ( k ) { var v = p.get( k ); if ( v ) { u[k] = v.slice( 0, 150 ); has = true; } } );
				if ( has ) {
					document.cookie = 'kivun_utm=' + encodeURIComponent( JSON.stringify( u ) ) + ';path=/;max-age=' + ( 60 * 60 * 24 * 30 ) + ';SameSite=Lax';
				}
			} catch ( e ) {}
		} () );
		</script>
		<?php
	}

	/**
	 * The current visitor's UTM data (from the cookie), sanitized.
	 *
	 * @return array<string,string>
	 */
	public static function data(): array {
		if ( empty( $_COOKIE['kivun_utm'] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized below.
		$raw = json_decode( wp_unslash( $_COOKIE['kivun_utm'] ), true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( self::KEYS as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * A short "source / medium / campaign" label, or '' when no UTM is present.
	 *
	 * @return string
	 */
	public static function label(): string {
		$data  = self::data();
		$parts = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$parts[] = $data[ $key ];
			}
		}
		return $parts ? implode( ' / ', $parts ) : '';
	}

	/**
	 * Fold the UTM label into a record's source string.
	 *
	 * @param string $source The existing source label.
	 * @return string
	 */
	public static function append_source( string $source ): string {
		$label = self::label();
		if ( '' === $label ) {
			return $source;
		}
		$utm = 'UTM: ' . $label;
		return '' !== trim( $source ) ? $source . ' · ' . $utm : $utm;
	}
}
