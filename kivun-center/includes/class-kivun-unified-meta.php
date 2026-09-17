<?php
/**
 * Unified virtual meta keys shared across Kivun content types.
 *
 * Exposes one consistent set of meta keys — `_kivun_short`, `_kivun_audience`,
 * `_kivun_duration`, `_kivun_cost`, `_kivun_date` — that resolve to the correct
 * value for a course, session or landing page. One Elementor "Custom Field" tag
 * (or any get_post_meta call) then works for all three types, with NO data
 * migration and nothing deleted: reads are computed on the fly from each type's
 * existing storage, so edits always stay in sync.
 *
 * (The contact email `_kivun_contact_email` and CTA `_kivun_cta_*` keys are
 * already identical across all three types.)
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides read-only unified meta for the Kivun content types.
 */
class Kivun_Unified_Meta {

	/**
	 * Unified meta key => shared field name.
	 *
	 * @var array<string,string>
	 */
	private const KEYS = array(
		'_kivun_short'    => 'short',
		'_kivun_audience' => 'audience',
		'_kivun_duration' => 'duration',
		'_kivun_cost'     => 'cost',
		'_kivun_date'     => 'date',
	);

	/**
	 * Guards against recursion — `_kivun_duration` is also a real course key.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	/**
	 * Register the metadata filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_meta' ), 10, 4 );
	}

	/**
	 * Return the unified value when one of the unified keys is requested.
	 *
	 * @param mixed  $value     The pre-filter value (null to fall through).
	 * @param int    $object_id The post ID.
	 * @param string $meta_key  The requested meta key.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed Untouched value, or the resolved value wrapped in an array.
	 */
	public static function filter_meta( $value, $object_id, $meta_key, $single ) {
		unset( $single );

		if ( self::$busy || ! isset( self::KEYS[ $meta_key ] ) ) {
			return $value;
		}

		// While resolving, let the underlying real reads hit the database.
		self::$busy = true;
		$is_kivun   = (bool) kivun_content_field_map( get_post_type( (int) $object_id ) );
		$resolved   = $is_kivun ? kivun_get_field( (int) $object_id, self::KEYS[ $meta_key ] ) : null;
		self::$busy = false;

		// Only override for Kivun content types; leave any other post untouched.
		return null === $resolved ? $value : array( $resolved );
	}
}
