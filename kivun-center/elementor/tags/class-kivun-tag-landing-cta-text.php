<?php
/**
 * Landing page CTA text dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the landing page call-to-action text.
 */
class Kivun_Tag_Landing_CTA_Text extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-landing-cta-text';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'דף נחיתה — טקסט הנעה לפעולה', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( (string) get_post_meta( get_the_ID(), '_kivun_lp_cta_text', true ) );
	}
}
