<?php
/**
 * Workshop capacity dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the workshop capacity.
 */
class Kivun_Tag_Workshop_Capacity extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-workshop-capacity';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'סדנה — מקסימום משתתפים', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		$cap = get_post_meta( get_the_ID(), '_kivun_ws_capacity', true );
		if ( $cap ) {
			echo esc_html( $cap );
		}
	}
}
