<?php
/**
 * Dynamic tag for the settlement a job is in.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the settlement a job is in.
 */
class Kivun_Tag_Job_City extends Kivun_Job_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-job-city';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'משרה — יישוב', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_city', true ) );
	}
}
