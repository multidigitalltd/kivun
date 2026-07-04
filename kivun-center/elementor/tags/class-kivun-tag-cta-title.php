<?php
/**
 * CTA banner title dynamic tag (courses & landing pages).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the CTA banner title.
 */
class Kivun_Tag_CTA_Title extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-cta-title';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'הנעה לפעולה — כותרת', 'kivun' );
	}

	/**
	 * Render the tag output. Falls back to a default headline when empty.
	 *
	 * @return void
	 */
	public function render(): void {
		$id    = get_the_ID();
		$value = (string) get_post_meta( $id, '_kivun_cta_title', true );

		if ( '' === trim( $value ) ) {
			/**
			 * Filter the default CTA banner title used when none is set.
			 *
			 * @param string $default The default title.
			 * @param int    $id      The post ID.
			 */
			$value = (string) apply_filters( 'kivun_cta_title_default', __( 'רוצים להתקדם? זה הזמן', 'kivun' ), $id );
		}

		echo esc_html( $value );
	}
}
