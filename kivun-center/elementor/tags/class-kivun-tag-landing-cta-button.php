<?php
/**
 * Landing page CTA button-label dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the landing page call-to-action button label.
 */
class Kivun_Tag_Landing_CTA_Button extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-landing-cta-button';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'דף נחיתה — טקסט כפתור CTA', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( (string) get_post_meta( get_the_ID(), '_kivun_lp_cta_btn', true ) );
	}
}
