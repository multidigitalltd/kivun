<?php
/**
 * Site-wide sticky WhatsApp button.
 *
 * A floating WhatsApp contact button shown on every front-end page. The number,
 * pre-filled message and on/off state are all controlled from one place under
 * Kivun Center → Settings.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a floating WhatsApp button across the whole site.
 */
class Kivun_WhatsApp {

	/**
	 * Hook the button into the front-end.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * Normalise the configured phone number to digits only (international, no +).
	 *
	 * @return string Digits only, or '' when not configured.
	 */
	private static function number(): string {
		$raw = (string) Kivun_Admin_Settings::get( 'whatsapp_number', '' );
		return preg_replace( '/[^0-9]/', '', $raw );
	}

	/**
	 * Whether the sticky button should be shown on the current request.
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( ! (bool) Kivun_Admin_Settings::get( 'whatsapp_enabled', false ) ) {
			return false;
		}
		return '' !== self::number();
	}

	/**
	 * Enqueue the small stylesheet only when the button is actually shown.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		wp_enqueue_style(
			'kivun-whatsapp',
			KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'whatsapp', 'css' ),
			array(),
			KIVUN_VERSION
		);
	}

	/**
	 * Output the floating WhatsApp button in the footer.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$number  = self::number();
		$message = (string) Kivun_Admin_Settings::get( 'whatsapp_message', '' );
		$url     = 'https://wa.me/' . $number;
		if ( '' !== trim( $message ) ) {
			// add_query_arg() URL-encodes the value; do not pre-encode it.
			$url = add_query_arg( 'text', $message, $url );
		}

		$label = __( 'שליחת הודעה בוואטסאפ', 'kivun' );
		?>
		<a class="kivun-wa" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>">
			<svg class="kivun-wa__icon" viewBox="0 0 24 24" width="30" height="30" aria-hidden="true" focusable="false">
				<path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.372-.025-.521-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.693.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/>
			</svg>
		</a>
		<?php
	}
}
