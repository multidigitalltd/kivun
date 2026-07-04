<?php
/**
 * CTA banner button-label dynamic tag (courses & landing pages).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the CTA button label, with a smart default.
 */
class Kivun_Tag_CTA_Button extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-cta-button';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'הנעה לפעולה — כפתור', 'kivun' );
	}

	/**
	 * Render the tag output. Falls back to "להרשמה ל<שם הפריט>" when no custom
	 * button label was set.
	 *
	 * @return void
	 */
	public function render(): void {
		$id    = get_the_ID();
		$label = (string) get_post_meta( $id, '_kivun_cta_button', true );

		if ( '' === trim( $label ) ) {
			/* translators: %s: course / landing page title. */
			$default = sprintf( __( 'להרשמה ל%s', 'kivun' ), get_the_title( $id ) );

			/**
			 * Filter the default CTA button label used when none is set.
			 *
			 * @param string $default The default button label.
			 * @param int    $id      The post ID.
			 */
			$label = (string) apply_filters( 'kivun_cta_button_default', $default, $id );
		}

		echo esc_html( $label );
	}
}
