<?php
/**
 * Job salary dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the job salary.
 */
class Kivun_Tag_Job_Salary extends Kivun_Job_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-job-salary';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'משרה — שכר', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_salary', true ) );
	}
}
