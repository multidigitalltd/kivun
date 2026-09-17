<?php
/**
 * Client for the Mercaz Kivun REST API.
 *
 * Both the content API and the jobs API are plain WordPress REST endpoints
 * behind HTTP Basic auth with an Application Password. This class owns the
 * transport, the credentials, and the diagnostics — the field mapping for each
 * content type is built on top of it.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Mercaz Kivun site.
 */
class Kivun_Mercaz {

	/**
	 * The Mercaz Kivun API base. It is fixed, so it is not asked for: the jobs
	 * documentation is published on a staging host but the API itself is not.
	 * Override with the kivun_mercaz_base_url filter if that ever changes.
	 */
	const DEFAULT_BASE = 'https://mercaz-kivun.co.il/wp-json/wp/v2';

	/**
	 * Credentials to use instead of the stored ones, for testing values that
	 * are typed into the settings form but not saved yet.
	 *
	 * @var array{user:string,pass:string}|null
	 */
	private static $override;

	/**
	 * Use these credentials for the rest of the request.
	 *
	 * @param string $user Username.
	 * @param string $pass Application password.
	 * @return void
	 */
	public static function use_credentials( string $user, string $pass ): void {
		$user = trim( $user );
		$pass = trim( $pass );

		self::$override = ( '' !== $user && '' !== $pass )
			? array(
				'user' => $user,
				'pass' => $pass,
			)
			: null;
	}

	/**
	 * Register the admin-side AJAX handlers.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_mercaz_test', array( __CLASS__, 'ajax_test' ) );
	}

	/**
	 * The API base for a given content type.
	 *
	 * @param string $type Content type key.
	 * @return string Base URL with no trailing slash, or '' when unconfigured.
	 */
	public static function base_url( string $type = '' ): string {
		$saved = trim( (string) Kivun_Admin_Settings::get( 'mercaz_url', '' ) );
		$base  = '' !== $saved ? $saved : self::DEFAULT_BASE;

		/**
		 * Filter the API base, per content type.
		 *
		 * @param string $base The base URL.
		 * @param string $type Content type key.
		 */
		return untrailingslashit( (string) apply_filters( 'kivun_mercaz_base_url', $base, $type ) );
	}

	/**
	 * Whether credentials and a base URL are present.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return '' !== trim( (string) Kivun_Admin_Settings::get( 'mercaz_user', '' ) )
			&& '' !== trim( (string) Kivun_Admin_Settings::get( 'mercaz_pass', '' ) );
	}

	/**
	 * Perform a request against the API.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path below the base, e.g. 'jobs' or 'users/me'.
	 * @param array<string,mixed>      $query  Query parameters.
	 * @param array<string,mixed>|null $body   JSON body, or null for none.
	 * @param string                   $type   Content type key, to pick the right base URL.
	 * @return array<string,mixed>|\WP_Error Decoded body on success.
	 */
	public static function request( string $method, string $path, array $query = array(), $body = null, string $type = '' ) {
		$base = self::base_url( $type );
		if ( '' === $base ) {
			return new \WP_Error( 'kivun_mercaz_unconfigured', __( 'לא הוגדרה כתובת ל-API של מרכז כיוון.', 'kivun' ) );
		}

		$user = self::$override ? self::$override['user'] : trim( (string) Kivun_Admin_Settings::get( 'mercaz_user', '' ) );
		$pass = self::$override ? self::$override['pass'] : trim( (string) Kivun_Admin_Settings::get( 'mercaz_pass', '' ) );
		if ( '' === $user || '' === $pass ) {
			return new \WP_Error( 'kivun_mercaz_unconfigured', __( 'לא הוגדרו פרטי התחברות ל-API של מרכז כיוון.', 'kivun' ) );
		}

		$url = $base . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				// Application passwords are shown with spaces; the server accepts
				// them either way, but stripping avoids a copy-paste failure.
				'Authorization' => 'Basic ' . base64_encode( $user . ':' . str_replace( ' ', '', $pass ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by HTTP Basic auth.
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'kivun_mercaz_http_' . $code,
				self::friendly_error( $code, $data ),
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		return array(
			'data'    => $data,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Turn an API failure into something a content manager can act on.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $data Decoded response body.
	 * @return string
	 */
	private static function friendly_error( int $code, $data ): string {
		$api_code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
		$message  = is_array( $data ) && isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '';

		// The documented refusals, named so the cause is obvious.
		$known = array(
			'rest_cannot_edit_others'       => __( 'התוכן שייך למשתמש אחר — רק היוצר שלו יכול לערוך אותו.', 'kivun' ),
			'ckvn_invalid_content_category' => __( 'מאמר חייב לשאת בדיוק קטגוריה אחת מהשתיים המותרות.', 'kivun' ),
			'ckvn_forbidden_media'          => __( 'התמונה שייכת למשתמש אחר. יש להעלות אותה מחדש דרך החשבון הזה.', 'kivun' ),
			'rest_invalid_param'            => __( 'אחד הערכים שנשלחו אינו תקין.', 'kivun' ),
		);
		if ( '' !== $api_code && isset( $known[ $api_code ] ) ) {
			return $known[ $api_code ];
		}

		switch ( $code ) {
			case 401:
				return __( 'ההתחברות נכשלה (401). בדקו את שם המשתמש ואת סיסמת היישום.', 'kivun' );
			case 403:
				return '' !== $message
					? sprintf( /* translators: %s: server message. */ __( 'הפעולה נדחתה (403): %s', 'kivun' ), $message )
					: __( 'הפעולה נדחתה (403) — כנראה חוסר הרשאה לפריט או לשדה שנשלח.', 'kivun' );
			case 404:
				return __( 'הכתובת לא נמצאה (404). בדקו את כתובת ה-API.', 'kivun' );
			default:
				return '' !== $message
					? sprintf( /* translators: 1: HTTP code, 2: server message. */ __( 'שגיאה %1$d: %2$s', 'kivun' ), $code, $message )
					: sprintf( /* translators: %d: HTTP code. */ __( 'שגיאה %d מהשרת.', 'kivun' ), $code );
		}
	}

	// ── Diagnostics ───────────────────────────────────────────────────────────.

	/**
	 * Verify the credentials and report who they belong to.
	 *
	 * The roles matter beyond a green tick: on the jobs API a job_manager must
	 * not send region-country at all (it is derived from their profile and
	 * sending it is a 403), while an administrator or editor must send it.
	 * Reading the role here means the integration can follow the right rule
	 * instead of being told which one to assume.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function test_connection() {
		$result = self::request( 'GET', 'users/me', array( 'context' => 'edit' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$me = $result['data'];
		return array(
			'id'    => (int) ( $me['id'] ?? 0 ),
			'name'  => (string) ( $me['name'] ?? '' ),
			'slug'  => (string) ( $me['slug'] ?? '' ),
			'roles' => array_map( 'strval', (array) ( $me['roles'] ?? array() ) ),
		);
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────.

	/**
	 * Guard for the admin-only diagnostics.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( 'kivun_mercaz', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה.', 'kivun' ) ) );
		}

		// Test what is on screen, not what was last saved: otherwise the first
		// thing anyone does — fill the fields in and press the button — reports
		// that nothing is configured.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		self::use_credentials(
			sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['pass'] ?? '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * AJAX: verify the credentials.
	 *
	 * @return void
	 */
	public static function ajax_test(): void {
		self::guard();

		$result = self::test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$roles = $result['roles'];
		$note  = in_array( 'job_manager', $roles, true ) && ! array_intersect( array( 'administrator', 'editor' ), $roles )
			? __( 'תפקיד job_manager — המחוז ייקבע אוטומטית בצד השרת, ולא יישלח מכאן.', 'kivun' )
			: __( 'תפקיד ניהולי — ניתן לשלוח מחוז (region-country) במפורש.', 'kivun' );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: display name, 2: roles list. */
					__( 'מחובר בתור %1$s (תפקידים: %2$s)', 'kivun' ),
					$result['name'],
					implode( ', ', $roles ) ? implode( ', ', $roles ) : '—'
				),
				'note'    => $note,
				'roles'   => $roles,
			)
		);
	}
}
