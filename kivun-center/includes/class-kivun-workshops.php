<?php
/**
 * Workshop registration and shared lead form AJAX handlers.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles workshop registrations and the shared "lead / interest" form
 * used by both workshops and courses.
 *
 * Lead flow: visitor submits interest → stored as type='lead' → email to admin.
 * Workshop flow: same as lead — rep calls back.
 */
class Kivun_Workshops {

	/**
	 * Register the lead submission AJAX hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_submit_lead', array( __CLASS__, 'ajax_submit_lead' ) );
		add_action( 'wp_ajax_nopriv_kivun_submit_lead', array( __CLASS__, 'ajax_submit_lead' ) );
	}

	/**
	 * Unified lead handler — covers:
	 *  - Course interest form  (post_type = kivun_course,    type = 'lead')
	 *  - Workshop registration (post_type = kivun_workshop,  type = 'workshop')
	 *
	 * @return void
	 */
	public static function ajax_submit_lead(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$data    = array(
			'name'    => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'   => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'   => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'message' => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
		);

		$result = self::save_lead( $post_id, $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$msg = 'kivun_workshop' === get_post_type( $post_id )
			? __( 'ההרשמה התקבלה! נציג יצור איתך קשר בהקדם.', 'kivun' )
			: __( 'פנייתך התקבלה! נציג יצור איתך קשר בהקדם לפרטים נוספים.', 'kivun' );

		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Validate and store a lead/registration: capacity (workshops/landing pages),
	 * de-duplication, DB row, mailer, and the kivun_after_lead hook.
	 *
	 * Shared by the built-in AJAX form and the Elementor Forms lead action so
	 * both paths run the exact same pipeline.
	 *
	 * @param int   $post_id The course or landing-page (kivun_workshop) ID.
	 * @param array $data    Associative data: name, email, phone, message.
	 * @return true|\WP_Error True on success, WP_Error with a user message on failure.
	 */
	public static function save_lead( int $post_id, array $data ) {
		$name    = sanitize_text_field( $data['name'] ?? '' );
		$email   = sanitize_email( $data['email'] ?? '' );
		$phone   = sanitize_text_field( $data['phone'] ?? '' );
		$message = sanitize_textarea_field( $data['message'] ?? '' );

		if ( ! $post_id || ! $name || ! $phone ) {
			return new \WP_Error( 'kivun_invalid', __( 'נא למלא שם וטלפון.', 'kivun' ) );
		}

		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'kivun_course', 'kivun_workshop' ), true ) ) {
			return new \WP_Error( 'kivun_no_post', __( 'פוסט לא קיים.', 'kivun' ) );
		}

		// Capacity check for landing pages / workshops.
		if ( 'kivun_workshop' === $post_type && kivun_is_full( $post_id ) ) {
			return new \WP_Error( 'kivun_full', __( 'מצטערים, ההרשמה מלאה כרגע. השאירו פרטים ונעדכן אם ייפתח מקום.', 'kivun' ) );
		}

		$type = 'kivun_workshop' === $post_type ? 'workshop' : 'lead';

		// Duplicate check — phone is mandatory for leads, use it as primary key.
		global $wpdb;
		$duplicate = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_registrations
			 WHERE course_id = %d AND (phone = %s OR (email != '' AND email = %s)) LIMIT 1",
				$post_id,
				$phone,
				$email ? $email : '__no_email__'
			)
		);
		if ( $duplicate ) {
			return new \WP_Error( 'kivun_duplicate', __( 'פנייתך כבר התקבלה — נציג יחזור אליך בהקדם.', 'kivun' ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'kivun_registrations',
			array(
				'course_id'  => $post_id,
				'name'       => $name,
				'email'      => $email ? $email : '',
				'phone'      => $phone,
				'message'    => $message,
				'status'     => 'new_lead',
				'type'       => $type,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		Kivun_Mailer::send_lead_notification( $post_id, compact( 'name', 'email', 'phone', 'message' ), $type );
		do_action( 'kivun_after_lead', $post_id, compact( 'name', 'email', 'phone', 'message' ) );

		return true;
	}
}
