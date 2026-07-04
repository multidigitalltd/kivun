<?php
/**
 * Landing page CTA button-label dynamic tag.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the landing page call-to-action button label.
 */
class Kivun_Tag_Landing_CTA_Button extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-landing-cta-button';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'דף נחיתה — טקסט כפתור CTA', 'kivun' );
	}

	/**
	 * Render the tag output. Falls back to "להרשמה ל<שם הדף>" when no custom
	 * button label was set.
	 *
	 * @return void
	 */
	public function render(): void {
		$id    = get_the_ID();
		$label = (string) get_post_meta( $id, '_kivun_lp_cta_btn', true );

		if ( '' === trim( $label ) ) {
			/* translators: %s: landing page title. */
			$default = sprintf( __( 'להרשמה ל%s', 'kivun' ), get_the_title( $id ) );

			/**
			 * Filter the default CTA button label used when none is set.
			 *
			 * @param string $default The default button label.
			 * @param int    $id      The landing page ID.
			 */
			$label = (string) apply_filters( 'kivun_landing_cta_button_default', $default, $id );
		}

		echo esc_html( $label );
	}
}
