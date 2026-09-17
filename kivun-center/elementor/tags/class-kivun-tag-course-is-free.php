<?php
/**
 * Course is-free dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for whether the course is free or paid.
 */
class Kivun_Tag_Course_Is_Free extends Kivun_Course_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-course-is-free';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'קורס — חינמי/בתשלום', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		$price = (int) get_post_meta( get_the_ID(), '_kivun_price', true );
		echo $price > 0
			? esc_html__( 'בתשלום', 'kivun' )
			: esc_html__( 'חינמי', 'kivun' );
	}
}
