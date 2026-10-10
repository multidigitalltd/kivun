<?php
/**
 * Course capacity dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the course capacity.
 */
class Kivun_Tag_Course_Capacity extends Kivun_Course_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-course-capacity';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'קורס — מקסימום משתתפים', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		$cap = get_post_meta( get_the_ID(), '_kivun_capacity', true );
		if ( $cap ) {
			echo esc_html( $cap );
		}
	}
}
