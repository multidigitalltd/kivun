<?php
/**
 * Site-wide accessibility layer for compliance with Israeli Standard
 * ת"י 5568 and WCAG 2.2 AA.
 *
 * Provides a skip link, an accessibility toolbar, an accessibility
 * statement shortcode, and the assets that power them. Loaded on every
 * page, since accessibility must be available everywhere.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Kivun accessibility layer.
 */
class Kivun_Accessibility {

	/**
	 * Registers all accessibility hooks, assets, and shortcodes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'render_skip_link' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_toolbar' ) );
		add_shortcode( 'kivun_accessibility_statement', array( __CLASS__, 'render_statement' ) );
	}

	/**
	 * Whether the accessibility layer should run on the current request.
	 *
	 * Suppressed inside wp-admin and the Elementor editor/preview so the
	 * floating toolbar never overlaps the page-builder UI, and exposed via
	 * the `kivun_a11y_enabled` filter for full opt-out.
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		$enabled = ! is_admin();

		if ( $enabled ) {
			// Elementor editor canvas / preview iframe.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection, no state change.
			if ( isset( $_GET['elementor-preview'] ) ) {
				$enabled = false;
			} elseif ( class_exists( '\Elementor\Plugin' ) ) {
				$elementor = \Elementor\Plugin::instance();
				if ( ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() )
					|| ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) ) {
					$enabled = false;
				}
			}
		}

		/**
		 * Filter whether the Kivun accessibility layer loads on this request.
		 *
		 * @param bool $enabled Whether the toolbar, skip link and assets load.
		 */
		return (bool) apply_filters( 'kivun_a11y_enabled', $enabled );
	}

	/**
	 * Enqueues and localizes the accessibility styles and scripts site-wide.
	 *
	 * Accessibility tooling must be present on every page, so unlike the rest
	 * of the plugin's assets this load is intentionally unconditional.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		wp_enqueue_style(
			'kivun-accessibility',
			KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'accessibility', 'css' ),
			array(),
			KIVUN_VERSION
		);

		wp_enqueue_script(
			'kivun-accessibility',
			KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'accessibility', 'js' ),
			array(),
			KIVUN_VERSION,
			true
		);
		wp_script_add_data( 'kivun-accessibility', 'defer', true );

		wp_localize_script(
			'kivun-accessibility',
			'kivunA11y',
			array(
				'open'             => __( 'פתיחת תפריט נגישות', 'kivun' ),
				'close'            => __( 'סגירת תפריט נגישות', 'kivun' ),
				'reset'            => __( 'איפוס הגדרות נגישות', 'kivun' ),
				'fontIncrease'     => __( 'הגדלת גודל הטקסט', 'kivun' ),
				'fontDecrease'     => __( 'הקטנת גודל הטקסט', 'kivun' ),
				'contrast'         => __( 'ניגודיות גבוהה', 'kivun' ),
				'negative'         => __( 'היפוך צבעים', 'kivun' ),
				'grayscale'        => __( 'גווני אפור', 'kivun' ),
				'underlineLinks'   => __( 'הדגשת קישורים בקו תחתון', 'kivun' ),
				'readableFont'     => __( 'גופן קריא', 'kivun' ),
				'stopAnimations'   => __( 'עצירת אנימציות', 'kivun' ),
				'highlightHeading' => __( 'הדגשת כותרות', 'kivun' ),
				'highlightLinks'   => __( 'הדגשת קישורים', 'kivun' ),
				'readingGuide'     => __( 'סרגל קריאה', 'kivun' ),
				'bigCursor'        => __( 'סמן עכבר גדול', 'kivun' ),
			)
		);
	}

	/**
	 * Outputs the skip-to-content link as the first focusable element.
	 *
	 * @return void
	 */
	public static function render_skip_link(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		/**
		 * Filters the id (without the leading hash) the skip link targets.
		 *
		 * @param string $target The target element id. Default 'content'.
		 */
		$target = (string) apply_filters( 'kivun_a11y_skip_target', 'content' );

		printf(
			'<a class="kivun-skip-link" href="%1$s">%2$s</a>',
			esc_url( '#' . $target ),
			esc_html__( 'דלג לתוכן הראשי', 'kivun' )
		);
	}

	/**
	 * Renders the accessibility toolbar markup in the footer.
	 *
	 * @return void
	 */
	public static function render_toolbar(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		kivun_get_template( 'accessibility/toolbar.php' );
	}

	/**
	 * Renders the accessibility statement for the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes (unused).
	 * @return string The rendered statement markup.
	 */
	public static function render_statement( $atts = array() ): string {
		unset( $atts );

		ob_start();
		kivun_get_template(
			'accessibility/statement.php',
			array(
				'org_name'    => self::get_org_name(),
				'email'       => self::get_contact_email(),
				'phone'       => self::get_contact_phone(),
				'coordinator' => self::get_coordinator(),
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * Returns the organisation name for the accessibility statement.
	 *
	 * @return string The organisation name.
	 */
	public static function get_org_name(): string {
		/**
		 * Filters the organisation name shown in the accessibility statement.
		 *
		 * @param string $name The organisation name. Defaults to the site name.
		 */
		return (string) apply_filters( 'kivun_a11y_org_name', get_bloginfo( 'name' ) );
	}

	/**
	 * Returns the accessibility contact email address.
	 *
	 * @return string The contact email address.
	 */
	public static function get_contact_email(): string {
		/**
		 * Filters the contact email shown in the accessibility statement.
		 *
		 * @param string $email The contact email. Defaults to the admin email.
		 */
		return (string) apply_filters( 'kivun_a11y_contact_email', get_option( 'admin_email' ) );
	}

	/**
	 * Returns the accessibility contact phone number.
	 *
	 * @return string The contact phone number.
	 */
	public static function get_contact_phone(): string {
		/**
		 * Filters the contact phone shown in the accessibility statement.
		 *
		 * @param string $phone The contact phone number. Defaults to ''.
		 */
		return (string) apply_filters( 'kivun_a11y_contact_phone', '' );
	}

	/**
	 * Returns the accessibility coordinator name.
	 *
	 * @return string The coordinator name.
	 */
	public static function get_coordinator(): string {
		/**
		 * Filters the accessibility coordinator name in the statement.
		 *
		 * @param string $coordinator The coordinator name. Defaults to ''.
		 */
		return (string) apply_filters( 'kivun_a11y_coordinator', '' );
	}
}
