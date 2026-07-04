<?php
/**
 * CTA banner content dynamic tag (courses & landing pages).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic tag for the CTA banner short content.
 */
class Kivun_Tag_CTA_Content extends Kivun_Workshop_Tag_Base {

	/**
	 * Get the tag name.
	 *
	 * @return string The tag name.
	 */
	public function get_name(): string {
		return 'kivun-cta-content';
	}

	/**
	 * Get the tag title.
	 *
	 * @return string The tag title.
	 */
	public function get_title(): string {
		return __( 'הנעה לפעולה — תוכן', 'kivun' );
	}

	/**
	 * Render the tag output (line breaks preserved). Falls back to a default
	 * line when empty.
	 *
	 * @return void
	 */
	public function render(): void {
		$id    = get_the_ID();
		$value = (string) get_post_meta( $id, '_kivun_cta_content', true );

		if ( '' === trim( $value ) ) {
			/**
			 * Filter the default CTA banner content used when none is set.
			 *
			 * @param string $default The default content line.
			 * @param int    $id      The post ID.
			 */
			$value = (string) apply_filters( 'kivun_cta_content_default', __( 'השאירו פרטים ונחזור אליכם עם כל המידע.', 'kivun' ), $id );
		}

		echo nl2br( esc_html( $value ) );
	}
}
