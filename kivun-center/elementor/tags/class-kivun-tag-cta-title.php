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
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( (string) get_post_meta( get_the_ID(), '_kivun_cta_title', true ) );
	}
}
