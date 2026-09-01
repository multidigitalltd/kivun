<?php
/**
 * Landing page short-description dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the landing page short description (rich text).
 */
class Kivun_Tag_Landing_Short extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-landing-short';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'דף נחיתה — תיאור קצר', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo wp_kses_post( (string) get_post_meta( get_the_ID(), '_kivun_lp_short', true ) );
	}
}
