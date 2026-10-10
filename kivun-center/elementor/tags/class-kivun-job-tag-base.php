<?php
/**
 * Base dynamic tag class for Kivun job tags.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

/**
 * Abstract base class for all Kivun job dynamic tags.
 */
abstract class Kivun_Job_Tag_Base extends Tag {

	/**
	 * Get the tag group.
	 *
	 * @return string The dynamic tag group slug.
	 */
	public function get_group(): string {
		return 'kivun';
	}

	/**
	 * Get the tag categories.
	 *
	 * @return array The list of category constants.
	 */
	public function get_categories(): array {
		return array( Module::TEXT_CATEGORY );
	}
}
