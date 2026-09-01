<?php
/**
 * Job deadline dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the job application deadline.
 */
class Kivun_Tag_Job_Deadline extends Kivun_Job_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-job-deadline';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'משרה — תאריך אחרון', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		$date = get_post_meta( get_the_ID(), '_kivun_deadline', true );
		if ( ! $date ) {
			return;
		}

		// Format: YYYY-MM-DD to d/m/Y.
		$ts = strtotime( $date );
		echo $ts ? esc_html( date_i18n( 'd/m/Y', $ts ) ) : esc_html( $date );
	}
}
