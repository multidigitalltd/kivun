<?php
/**
 * Job company dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the job company name.
 */
class Kivun_Tag_Job_Company extends Kivun_Job_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-job-company';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'משרה — שם חברה', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_company', true ) );
	}
}
