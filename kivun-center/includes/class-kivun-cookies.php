<?php
/**
 * Cookie consent banner (GDPR / Israeli privacy friendly).
 *
 * Renders a front-end consent banner and a preferences dialog, stores the
 * visitor's choice, gates opt-in scripts, and (when present) updates Google
 * Consent Mode. Loaded site-wide, like the accessibility layer.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Kivun cookie-consent layer.
 */
class Kivun_Cookies {

	/**
	 * Registers hooks and assets.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner' ) );
	}

	/**
	 * Whether the banner should run on the current request. Suppressed in
	 * wp-admin and the Elementor editor/preview, and behind a setting + filter.
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		if ( is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection.
		if ( isset( $_GET['elementor-preview'] ) ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::instance();
			if ( ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() )
				|| ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) ) {
				return false;
			}
		}

		$enabled = (bool) Kivun_Admin_Settings::get( 'cookie_banner_enabled', true );

		/**
		 * Filter whether the Kivun cookie banner loads on this request.
		 *
		 * @param bool $enabled Whether the banner and assets load.
		 */
		return (bool) apply_filters( 'kivun_cookies_enabled', $enabled );
	}

	/**
	 * Enqueues the cookie-consent styles and script site-wide.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'kivun-cookies',
			KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'cookies', 'css' ),
			array(),
			KIVUN_VERSION
		);

		wp_enqueue_script(
			'kivun-cookies',
			KIVUN_URL . 'assets/js/' . Kivun_Core::asset( 'cookies', 'js' ),
			array(),
			KIVUN_VERSION,
			true
		);
		wp_script_add_data( 'kivun-cookies', 'defer', true );

		wp_localize_script(
			'kivun-cookies',
			'kivunCookies',
			array(
				'cookieName' => 'kivun_cookie_consent',
				'months'     => 6,
			)
		);
	}

	/**
	 * Renders the consent banner and preferences dialog in the footer.
	 *
	 * @return void
	 */
	public static function render_banner(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// A blank setting falls back to the site's own privacy policy page, which
		// WordPress already knows about. Without this the link is simply absent
		// on any site that never filled the field in — which is most of them,
		// since nothing on screen says the field exists.
		$policy_url = trim( (string) Kivun_Admin_Settings::get( 'cookie_policy_url', '' ) );
		if ( '' === $policy_url ) {
			$policy_url = (string) get_privacy_policy_url();
		}

		kivun_get_template(
			'cookies/banner.php',
			array( 'policy_url' => $policy_url )
		);
	}
}
