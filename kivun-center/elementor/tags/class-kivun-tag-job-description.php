<?php
/**
 * Job description dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Modules\DynamicTags\Module;

/**
 * Dynamic tag for the job description.
 */
class Kivun_Tag_Job_Description extends Kivun_Job_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-job-description';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'משרה — תיאור', 'kivun' );
	}

	/**
	 * Get the tag categories.
	 *
	 * @return array The list of category constants.
	 */
	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY, Module::TEXTAREA_CATEGORY );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo wp_kses_post( wpautop( get_post_meta( get_the_ID(), '_kivun_description', true ) ) );
	}
}
