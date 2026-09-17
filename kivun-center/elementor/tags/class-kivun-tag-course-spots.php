<?php
/**
 * Course spots-left dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the number of spots left in a course.
 */
class Kivun_Tag_Course_Spots extends Kivun_Course_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-course-spots';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'קורס — מקומות פנויים', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		$left = kivun_get_spots_left( get_the_ID() );
		if ( null === $left ) {
			return;
		}
		echo 0 === $left
			? esc_html__( 'מלא', 'kivun' )
			/* translators: %d: number of available spots. */
			: esc_html( sprintf( __( '%d מקומות', 'kivun' ), $left ) );
	}
}
