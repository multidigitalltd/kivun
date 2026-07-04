<?php
/**
 * Landing page CTA button-URL dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Modules\DynamicTags\Module;

/**
 * Dynamic tag for the landing page call-to-action button link (URL category).
 */
class Kivun_Tag_Landing_CTA_URL extends Kivun_Workshop_Tag_Base {

	/**
	 * URL-category tag so it can be used in link controls.
	 *
	 * @return array The list of category constants.
	 */
	public function get_categories(): array {
		return array( Module::URL_CATEGORY );
	}

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-landing-cta-url';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'דף נחיתה — קישור כפתור CTA', 'kivun' );
	}

	/**
	 * Render the tag output.
	 *
	 * @return void
	 */
	public function render(): void {
		echo esc_url( (string) get_post_meta( get_the_ID(), '_kivun_lp_cta_url', true ) );
	}
}
