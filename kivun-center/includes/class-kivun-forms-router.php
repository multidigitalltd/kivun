<?php
/**
 * Global router for ALL Elementor Pro form submissions.
 *
 * Every submission is forwarded to a single central email address and/or a
 * webhook (for a CRM), configured under Kivun Center → Settings. When the form
 * is submitted from a specific course, workshop (session) or landing page that
 * has its own "אימייל לקבלת הלידים" set, that per-post address overrides the
 * central one.
 *
 * Two entry points feed the same routing routine:
 *  1. Elementor's global `elementor_pro/forms/new_record` — covers every form.
 *  2. The plugin's own `kivun_after_lead` / `kivun_after_registration` hooks —
 *     a reliable fallback for the course/session/landing forms, so leads reach
 *     the central inbox even if the Elementor global hook doesn't reach us.
 * A per-request guard makes sure each submission is routed exactly once.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forwards form submissions to a central email and/or webhook.
 */
class Kivun_Forms_Router {

	/**
	 * Whether the current request's submission was already routed.
	 *
	 * @var bool
	 */
	private static bool $routed = false;

	/**
	 * Register the routing hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_record' ), 20, 2 );
		add_action( 'kivun_after_lead', array( __CLASS__, 'on_kivun_lead' ), 20, 2 );
		add_action( 'kivun_after_registration', array( __CLASS__, 'on_kivun_lead' ), 20, 2 );
	}

	/**
	 * Handle Elementor's global "new record" event.
	 *
	 * @param mixed $record  The Form_Record (submitted data).
	 * @param mixed $handler The Ajax_Handler (unused).
	 * @return void
	 */
	public static function on_record( $record, $handler ): void {
		unset( $handler );

		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$page_url = (string) wp_get_referer();

		$raw    = $record->get( 'fields' );
		$fields = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $field ) {
				$label            = ( is_array( $field ) && ! empty( $field['title'] ) ) ? $field['title'] : $id;
				$value            = ( is_array( $field ) && isset( $field['value'] ) ) ? $field['value'] : '';
				$fields[ $label ] = $value;
			}
		}

		$form_name = '';
		if ( method_exists( $record, 'get_form_settings' ) ) {
			$form_name = (string) $record->get_form_settings( 'form_name' );
		}

		/**
		 * Allow skipping the global router for a specific submission.
		 *
		 * @param bool   $skip      Whether to skip. Default false.
		 * @param array  $fields    The submitted fields (label => value).
		 * @param string $form_name The Elementor form name.
		 */
		if ( apply_filters( 'kivun_forms_router_skip', false, $fields, $form_name ) ) {
			return;
		}

		self::route( self::email_for_post( self::post_from_url( $page_url ) ), $form_name, $fields, $page_url );
	}

	/**
	 * Fallback: route a lead/registration captured by the plugin's own pipeline.
	 *
	 * @param int   $post_id The course/session/landing post ID.
	 * @param array $data    Lead data (name, phone, email, city, gender, message).
	 * @return void
	 */
	public static function on_kivun_lead( $post_id, $data ): void {
		if ( self::$routed ) {
			return;
		}
		$data   = is_array( $data ) ? $data : array();
		$fields = self::fields_from_data( $data );
		self::route( self::email_for_post( (int) $post_id ), 'Kivun', $fields, (string) get_permalink( (int) $post_id ) );
	}

	/**
	 * Send the submission to the resolved email and/or the webhook — once.
	 *
	 * @param string $email     Destination email (may be empty).
	 * @param string $form_name The form name.
	 * @param array  $fields    Submitted fields (label => value).
	 * @param string $page_url  The page the form was submitted from.
	 * @return void
	 */
	private static function route( string $email, string $form_name, array $fields, string $page_url ): void {
		if ( self::$routed ) {
			return;
		}

		$webhook = (string) Kivun_Admin_Settings::get( 'forms_router_webhook', '' );
		if ( '' === trim( $email ) && '' === trim( $webhook ) ) {
			self::record( 'no-destination', '' );
			return;
		}

		self::$routed = true;

		$result = 'skipped';
		if ( '' !== trim( $email ) && is_email( $email ) ) {
			$result = self::send_email( $email, $form_name, $fields ) ? 'sent' : 'failed';
		} elseif ( '' !== trim( $email ) ) {
			$result = 'invalid-email';
		}

		if ( '' !== trim( $webhook ) ) {
			self::send_webhook( $webhook, $form_name, $fields, $page_url );
		}

		self::record( $result, $email );
	}

	/**
	 * Resolve a page URL to its post ID.
	 *
	 * @param string $page_url The URL.
	 * @return int The post ID, or 0.
	 */
	private static function post_from_url( string $page_url ): int {
		return '' !== $page_url ? (int) url_to_postid( $page_url ) : 0;
	}

	/**
	 * Decide which email should receive the submission for a given post.
	 *
	 * A specific course/session/landing page email overrides the central one.
	 *
	 * @param int $post_id The post ID (0 = none).
	 * @return string The destination email (may be empty).
	 */
	private static function email_for_post( int $post_id ): string {
		if ( $post_id && in_array( get_post_type( $post_id ), array( 'kivun_course', 'kivun_workshop', 'kivun_session' ), true ) ) {
			$specific = (string) get_post_meta( $post_id, '_kivun_contact_email', true );
			if ( '' !== trim( $specific ) && is_email( $specific ) ) {
				return $specific;
			}
		}
		return (string) Kivun_Admin_Settings::get( 'forms_router_email', '' );
	}

	/**
	 * Build a label => value field list from plugin lead data.
	 *
	 * @param array $data Lead data.
	 * @return array<string,string>
	 */
	private static function fields_from_data( array $data ): array {
		$labels = array(
			'name'    => __( 'שם', 'kivun' ),
			'phone'   => __( 'טלפון', 'kivun' ),
			'email'   => __( 'אימייל', 'kivun' ),
			'city'    => __( 'עיר', 'kivun' ),
			'gender'  => __( 'מגדר', 'kivun' ),
			'message' => __( 'הודעה', 'kivun' ),
		);
		$fields = array();
		foreach ( $labels as $key => $label ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$fields[ $label ] = (string) $data[ $key ];
			}
		}
		return $fields;
	}

	/**
	 * Email the submission to the resolved address.
	 *
	 * @param string $to        Destination email.
	 * @param string $form_name The form name.
	 * @param array  $fields    Submitted fields (label => value).
	 * @return bool Whether wp_mail accepted the message.
	 */
	private static function send_email( string $to, string $form_name, array $fields ): bool {
		$site    = get_bloginfo( 'name' );
		$subject = $form_name
			? sprintf( '[%s] טופס חדש: %s', $site, $form_name )
			: sprintf( '[%s] הגשת טופס חדשה', $site );

		$rows = '';
		foreach ( $fields as $label => $value ) {
			$rows .= sprintf(
				'<li><strong>%s:</strong> %s</li>',
				esc_html( (string) $label ),
				nl2br( esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) )
			);
		}

		$body = sprintf(
			'<p>%s</p><ul>%s</ul>',
			esc_html__( 'התקבלה הגשת טופס חדשה באתר.', 'kivun' ),
			$rows
		);

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			// A real From address on the site's own domain improves deliverability.
			sprintf( 'From: %s <%s>', $site, get_option( 'admin_email' ) ),
		);

		// Reply directly to the submitter when an email field is present.
		foreach ( $fields as $value ) {
			$value = is_array( $value ) ? reset( $value ) : $value;
			if ( is_string( $value ) && is_email( $value ) ) {
				$headers[] = 'Reply-To: ' . sanitize_email( $value );
				break;
			}
		}

		$sent = wp_mail( $to, $subject, $body, $headers );
		self::log( sprintf( 'wp_mail to %s: %s', $to, $sent ? 'accepted' : 'FAILED' ) );
		return (bool) $sent;
	}

	/**
	 * POST the submission to the configured webhook (JSON).
	 *
	 * @param string $url       Webhook URL.
	 * @param string $form_name The form name.
	 * @param array  $fields    Submitted fields (label => value).
	 * @param string $page_url  The page the form was submitted from.
	 * @return void
	 */
	private static function send_webhook( string $url, string $form_name, array $fields, string $page_url ): void {
		$payload = array(
			'event'     => 'elementor_form_submission',
			'form_name' => $form_name,
			'page_url'  => $page_url,
			'site'      => home_url(),
			'fields'    => $fields,
		);

		wp_remote_post(
			$url,
			array(
				'timeout'  => 10,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Store the last routing activity so admins can see it in Settings.
	 *
	 * @param string $result The outcome (sent/failed/no-destination/…).
	 * @param string $email  The resolved destination email.
	 * @return void
	 */
	private static function record( string $result, string $email ): void {
		update_option(
			'kivun_forms_router_last',
			array(
				'time'   => current_time( 'mysql' ),
				'result' => $result,
				'email'  => $email,
			),
			false
		);
		self::log( sprintf( 'activity: %s -> %s', $result, $email ) );
	}

	/**
	 * Write a diagnostic line to the PHP error log when debugging is enabled.
	 *
	 * Enable with WP_DEBUG, or force via the `kivun_forms_router_debug` filter.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	private static function log( string $message ): void {
		$debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		if ( ! apply_filters( 'kivun_forms_router_debug', $debug ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in diagnostic logging.
		error_log( '[Kivun Forms Router] ' . $message );
	}
}
