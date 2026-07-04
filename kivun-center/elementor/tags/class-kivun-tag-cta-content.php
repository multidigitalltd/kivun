<?php
/**
 * CTA banner content dynamic tag (courses & landing pages).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the CTA banner short content.
 */
class Kivun_Tag_CTA_Content extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-cta-content';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'הנעה לפעולה — תוכן', 'kivun' );
	}

	/**
	 * Render the tag output (line breaks preserved).
	 *
	 * @return void
	 */
	public function render(): void {
		echo nl2br( esc_html( (string) get_post_meta( get_the_ID(), '_kivun_cta_content', true ) ) );
	}
}
