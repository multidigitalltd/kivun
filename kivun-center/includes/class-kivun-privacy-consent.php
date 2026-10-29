<?php
/**
 * The agreement a visitor gives before their details are taken.
 *
 * Off until a site turns it on, because a consent nobody configured is a
 * required tickbox pointing at nothing — worse than no consent at all. Once
 * on it applies to every lead form the plugin draws, and it is not one of the
 * fields a single page can switch off: a consent that some pages collect and
 * others do not is not a consent, it is a gap with a record of itself.
 *
 * The pages it links to are settings, never code. WordPress already knows the
 * site's privacy page, so that is where the first link comes from unless the
 * plugin's own setting overrides it.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the consent is asked, what it says, and where it points.
 */
class Kivun_Privacy_Consent {

	/**
	 * Whether this site asks visitors to agree before submitting.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) Kivun_Admin_Settings::get( 'privacy_consent_enabled', false );
	}

	/**
	 * The privacy policy's address.
	 *
	 * The plugin's own setting first, then the page WordPress already knows
	 * about — a site that has set one should not have to say so twice.
	 *
	 * @return string Empty when the site has named neither.
	 */
	public static function privacy_url(): string {
		$own = trim( (string) Kivun_Admin_Settings::get( 'privacy_url', '' ) );
		if ( '' !== $own ) {
			return $own;
		}

		return function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
	}

	/**
	 * The terms of use, if the site has any.
	 *
	 * @return string
	 */
	public static function terms_url(): string {
		return trim( (string) Kivun_Admin_Settings::get( 'terms_url', '' ) );
	}

	/**
	 * Whether a form should draw the consent at all.
	 *
	 * Turned on but pointing nowhere is the one state that must not reach a
	 * visitor: a required box asking them to agree to a document the site has
	 * not named. It fails closed — the box is not drawn and nothing is
	 * demanded of them.
	 *
	 * @return bool
	 */
	public static function asked(): bool {
		return self::enabled() && '' !== self::privacy_url();
	}

	/**
	 * The sentence beside the tickbox, with its links already in place.
	 *
	 * Built here rather than in each template so the three forms cannot come
	 * to word the same agreement differently.
	 *
	 * @return string Escaped HTML, safe to echo.
	 */
	public static function label(): string {
		$privacy = self::privacy_url();
		$terms   = self::terms_url();

		$link = static fn( string $url, string $text ): string => sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $text )
		);

		if ( '' !== $terms ) {
			return sprintf(
				/* translators: 1: link to the privacy policy, 2: link to the terms of use. */
				esc_html__( 'אני מאשר/ת שקראתי ומסכים/ה ל%1$s ול%2$s.', 'kivun' ),
				$link( $privacy, __( 'מדיניות הפרטיות', 'kivun' ) ),
				$link( $terms, __( 'תנאי השימוש', 'kivun' ) )
			);
		}

		return sprintf(
			/* translators: %s: link to the privacy policy. */
			esc_html__( 'אני מאשר/ת שקראתי ומסכים/ה ל%s.', 'kivun' ),
			$link( $privacy, __( 'מדיניות הפרטיות', 'kivun' ) )
		);
	}

	/**
	 * Whether a submission carried the agreement.
	 *
	 * Checked on the server as well as in the markup, because `required` in
	 * the form is a convenience for the person filling it in and anything
	 * posting to the endpoint directly never saw it. A consent that can be
	 * skipped by posting around the form is not a record of anything.
	 *
	 * @param array $posted The submitted values.
	 * @return bool
	 */
	public static function given( array $posted ): bool {
		return ! empty( $posted['privacy_consent'] );
	}

	/**
	 * What to tell someone who submitted without it.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		return __( 'יש לאשר את מדיניות הפרטיות כדי לשלוח.', 'kivun' );
	}
}
