<?php
/**
 * Job requirements dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Modules\DynamicTags\Module;

/**
 * Dynamic tag for the job requirements.
 */
class Kivun_Tag_Job_Requirements extends Kivun_Job_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-job-requirements';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'משרה — דרישות', 'kivun' );
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
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_requirements', true ) );
	}
}
