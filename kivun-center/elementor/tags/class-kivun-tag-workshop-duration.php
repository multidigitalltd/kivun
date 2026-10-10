<?php
/**
 * Workshop duration dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the workshop duration.
 */
class Kivun_Tag_Workshop_Duration extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-workshop-duration';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'דף נחיתה — משך', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo wp_kses_post( get_post_meta( get_the_ID(), '_kivun_ws_duration', true ) );
	}
}
