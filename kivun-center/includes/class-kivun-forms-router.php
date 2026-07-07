<?php
/**
 * Global router for ALL Elementor Pro form submissions.
 *
 * Regardless of a form's own actions, every submission can be forwarded to a
 * single central email address and/or a single webhook (for a CRM), both
 * configured in one place under Kivun Center → Settings.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forwards every Elementor form submission to a central email and/or webhook.
 */
class Kivun_Forms_Router {

	/**
	 * Hook into Elementor's global "new record" event.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_record' ), 20, 2 );
	}

	/**
	 * Handle a new Elementor form submission.
	 *
	 * @param mixed $record  The Form_Record (submitted data).
	 * @param mixed $handler The Ajax_Handler (unused).
	 * @return void
	 */
	public static function on_record( $record, $handler ): void {
		unset( $handler );

		$email   = (string) Kivun_Admin_Settings::get( 'forms_router_email', '' );
		$webhook = (string) Kivun_Admin_Settings::get( 'forms_router_webhook', '' );
		if ( '' === trim( $email ) && '' === trim( $webhook ) ) {
			return;
		}

		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		// Collect submitted fields as label => value.
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
		$page_url = (string) wp_get_referer();

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

		if ( '' !== trim( $email ) && is_email( $email ) ) {
			self::send_email( $email, $form_name, $fields );
		}
		if ( '' !== trim( $webhook ) ) {
			self::send_webhook( $webhook, $form_name, $fields, $page_url );
		}
	}

	/**
	 * Email the submission to the central address.
	 *
	 * @param string $to        Destination email.
	 * @param string $form_name The form name.
	 * @param array  $fields    Submitted fields (label => value).
	 * @return void
	 */
	private static function send_email( string $to, string $form_name, array $fields ): void {
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

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Reply directly to the submitter when an email field is present.
		foreach ( $fields as $value ) {
			$value = is_array( $value ) ? reset( $value ) : $value;
			if ( is_string( $value ) && is_email( $value ) ) {
				$headers[] = 'Reply-To: ' . sanitize_email( $value );
				break;
			}
		}

		wp_mail( $to, $subject, $body, $headers );
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
}
