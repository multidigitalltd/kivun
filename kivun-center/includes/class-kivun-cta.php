<?php
/**
 * CTA banner values and defaults (courses & landing pages).
 *
 * Central source of truth for the CTA meta values. On the front end, an empty
 * CTA meta value is transparently replaced with a default — so it works whether
 * you pull the value via the Kivun dynamic tags or via the raw meta key in
 * Elementor. The admin (editing) is never affected, so empty fields stay empty
 * in the form.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides CTA meta values with front-end default fallbacks.
 */
class Kivun_CTA {

	/**
	 * Guard so our own reads bypass the meta filter (avoids recursion).
	 *
	 * @var bool
	 */
	private static $bypass = false;

	/**
	 * Hook the front-end default fallback into meta reads.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_meta' ), 10, 4 );
	}

	/**
	 * The CTA meta keys this helper manages.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array( '_kivun_cta_title', '_kivun_cta_content', '_kivun_cta_button' );
	}

	/**
	 * Default value for a CTA key (filterable), used when the field is empty.
	 *
	 * @param string $key The CTA meta key.
	 * @param int    $id  The post ID.
	 * @return string
	 */
	public static function default_for( string $key, int $id ): string {
		switch ( $key ) {
			case '_kivun_cta_title':
				/** This filter is documented in includes/class-kivun-cta.php */
				return (string) apply_filters( 'kivun_cta_title_default', __( 'רוצים להתקדם? זה הזמן', 'kivun' ), $id );

			case '_kivun_cta_content':
				/** This filter is documented in includes/class-kivun-cta.php */
				return (string) apply_filters( 'kivun_cta_content_default', __( 'השאירו פרטים ונחזור אליכם עם כל המידע.', 'kivun' ), $id );

			case '_kivun_cta_button':
				/* translators: %s: course / landing page title. */
				$button = sprintf( __( 'להרשמה ל%s', 'kivun' ), get_the_title( $id ) );
				/** This filter is documented in includes/class-kivun-cta.php */
				return (string) apply_filters( 'kivun_cta_button_default', $button, $id );
		}

		return '';
	}

	/**
	 * Get a CTA value with its default applied when empty. Use this from the
	 * dynamic tags (and anywhere the resolved value is needed).
	 *
	 * @param int    $id  The post ID.
	 * @param string $key The CTA meta key.
	 * @return string
	 */
	public static function value( int $id, string $key ): string {
		$stored = self::raw( $id, $key );

		return '' !== trim( $stored ) ? $stored : self::default_for( $key, $id );
	}

	/**
	 * Read the stored meta value without triggering our own filter.
	 *
	 * @param int    $id  The post ID.
	 * @param string $key The meta key.
	 * @return string
	 */
	private static function raw( int $id, string $key ): string {
		self::$bypass = true;
		$value        = (string) get_post_meta( $id, $key, true );
		self::$bypass = false;

		return $value;
	}

	/**
	 * Replace an empty CTA meta value with its default on the front end.
	 *
	 * @param mixed  $value     The short-circuit value (null to proceed normally).
	 * @param int    $object_id The post ID.
	 * @param string $meta_key  The meta key being read.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	public static function filter_meta( $value, $object_id, $meta_key, $single ) {
		if ( self::$bypass || is_admin() || ! in_array( $meta_key, self::keys(), true ) ) {
			return $value;
		}

		$stored = self::raw( (int) $object_id, $meta_key );
		if ( '' !== trim( $stored ) ) {
			return $value;
		}

		unset( $single );
		$default = self::default_for( $meta_key, (int) $object_id );

		// get_metadata_raw() returns $check[0] when $single, else $check as-is,
		// so an array with the single default works for both cases.
		return array( $default );
	}
}
