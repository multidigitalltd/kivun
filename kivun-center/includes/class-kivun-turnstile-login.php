<?php
/**
 * Cloudflare Turnstile on front-end login forms (e.g. the employer login).
 *
 * Renders the widget inside every theme `wp_login_form()` and verifies the token
 * on authentication. A hidden marker limits enforcement to those front-end forms,
 * so wp-admin / wp-login.php logins are never affected (and admins can't be locked
 * out). Active only when both Turnstile keys are set in Settings.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds and verifies Cloudflare Turnstile on front-end login forms.
 */
class Kivun_Turnstile_Login {

	/**
	 * Register the hooks when Turnstile is configured.
	 *
	 * @return void
	 */
	public static function init(): void {
		$site   = trim( (string) Kivun_Admin_Settings::get( 'turnstile_site_key', '' ) );
		$secret = trim( (string) Kivun_Admin_Settings::get( 'turnstile_secret_key', '' ) );
		if ( '' === $site || '' === $secret ) {
			return;
		}

		add_filter( 'login_form_middle', array( __CLASS__, 'render' ), 20, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'verify' ), 30, 3 );
	}

	/**
	 * Inject the Turnstile widget (and a marker) into a theme login form.
	 *
	 * Only `wp_login_form()` applies this filter — wp-admin's own login form does
	 * not — so the widget appears solely on front-end forms.
	 *
	 * @param string $content The existing middle content.
	 * @param array  $args    The wp_login_form arguments (unused).
	 * @return string
	 */
	public static function render( $content, $args = array() ): string {
		unset( $args );

		$site_key = (string) Kivun_Admin_Settings::get( 'turnstile_site_key', '' );
		if ( '' === $site_key ) {
			return $content;
		}

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, WordPress.WP.EnqueuedResourceParameters.NotInFooter -- External Cloudflare API; no version, loaded async in footer.
		wp_enqueue_script( 'kivun-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );

		return $content
			. '<div class="kivun-login-turnstile" style="margin:12px 0"><div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '" data-language="he"></div></div>'
			. '<input type="hidden" name="kivun_login_turnstile" value="1">';
	}

	/**
	 * Verify the Turnstile token for front-end login submissions.
	 *
	 * @param mixed  $user     WP_User, WP_Error, or null from earlier filters.
	 * @param string $username The submitted username (unused).
	 * @param string $password The submitted password (unused).
	 * @return mixed The user, or a WP_Error when verification fails.
	 */
	public static function verify( $user, $username, $password ) {
		unset( $username, $password );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress login POST carries no nonce; the marker scopes this to our front-end form only.
		if ( empty( $_POST['kivun_login_turnstile'] ) ) {
			return $user;
		}

		$secret = (string) Kivun_Admin_Settings::get( 'turnstile_secret_key', '' );
		if ( '' === $secret ) {
			return $user;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See note above.
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( '' === $token || ! self::check( $secret, $token ) ) {
			return new \WP_Error( 'kivun_turnstile', __( 'אימות ה-CAPTCHA נכשל — נסו שוב.', 'kivun' ) );
		}

		return $user;
	}

	/**
	 * Validate a Turnstile token against Cloudflare's siteverify endpoint.
	 *
	 * @param string $secret The secret key.
	 * @param string $token  The client token.
	 * @return bool
	 */
	private static function check( string $secret, string $token ): bool {
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $remote_ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}
}
