<?php
/**
 * Course price dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the course price.
 */
class Kivun_Tag_Course_Price extends Kivun_Course_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-course-price';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'קורס — עלות', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		$price = (int) get_post_meta( get_the_ID(), '_kivun_price', true );
		echo $price > 0
			? '₪' . esc_html( number_format( $price ) )
			: esc_html__( 'חינמי', 'kivun' );
	}
}
