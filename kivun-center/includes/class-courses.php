<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Courses {

	public static function init(): void {
		add_action( 'wp_ajax_kivun_register_course',        [ __CLASS__, 'ajax_register' ] );
		add_action( 'wp_ajax_nopriv_kivun_register_course', [ __CLASS__, 'ajax_register' ] );
	}

	/**
	 * Handle free course registration form submission.
	 * Paid courses redirect to WooCommerce — handled client-side after this check.
	 */
	public static function ajax_register(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$name      = sanitize_text_field( $_POST['name'] ?? '' );
		$email     = sanitize_email( $_POST['email'] ?? '' );
		$phone     = sanitize_text_field( $_POST['phone'] ?? '' );
		$message   = sanitize_textarea_field( $_POST['message'] ?? '' );

		if ( ! $course_id || ! $name || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם ואימייל תקין.', 'kivun' ) ] );
		}

		if ( get_post_type( $course_id ) !== 'kivun_course' ) {
			wp_send_json_error( [ 'message' => __( 'קורס לא קיים.', 'kivun' ) ] );
		}

		// Capacity check
		if ( kivun_is_full( $course_id ) ) {
			wp_send_json_error( [ 'message' => __( 'מצטערים, הקורס מלא. ניתן להשאיר פרטים לרשימת המתנה.', 'kivun' ) ] );
		}

		// Prevent duplicate registration (by email or phone)
		if ( self::already_registered( $course_id, $email, $phone ) ) {
			wp_send_json_error( [ 'message' => __( 'כבר נרשמת לקורס זה.', 'kivun' ) ] );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'kivun_registrations',
			[
				'course_id'  => $course_id,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'message'    => $message,
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		Kivun_Mailer::send_course_registration( $course_id, compact( 'name', 'email', 'phone', 'message' ) );
		do_action( 'kivun_after_registration', $course_id, compact( 'name', 'email', 'phone', 'message' ) );

		wp_send_json_success( [
			'message' => __( 'ההרשמה התקבלה! ניצור איתך קשר בהקדם.', 'kivun' ),
		] );
	}

	private static function already_registered( int $course_id, string $email, string $phone = '' ): bool {
		global $wpdb;

		if ( $phone ) {
			return (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_registrations
				 WHERE course_id = %d AND type IN ('registration','confirmed') AND (email = %s OR phone = %s) LIMIT 1",
				$course_id, $email, $phone
			) );
		}

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}kivun_registrations
			 WHERE course_id = %d AND type IN ('registration','confirmed') AND email = %s LIMIT 1",
			$course_id, $email
		) );
	}
}
