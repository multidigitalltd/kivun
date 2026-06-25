<?php
/**
 * Course registration AJAX handlers.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles free course registration submissions.
 */
class Kivun_Courses {

	/**
	 * Register the course AJAX hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_register_course', array( __CLASS__, 'ajax_register' ) );
		add_action( 'wp_ajax_nopriv_kivun_register_course', array( __CLASS__, 'ajax_register' ) );
	}

	/**
	 * Handle free course registration form submission.
	 *
	 * Paid courses redirect to WooCommerce — handled client-side after this check.
	 *
	 * @return void
	 */
	public static function ajax_register(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$course_id = absint( wp_unslash( $_POST['course_id'] ?? 0 ) );
		$data      = array(
			'name'    => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'   => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'   => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'message' => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
		);

		$result = self::register_free( $course_id, $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'ההרשמה התקבלה! ניצור איתך קשר בהקדם.', 'kivun' ),
			)
		);
	}

	/**
	 * Save a free course registration: validate, enforce capacity and
	 * de-duplication, store the row, notify, and fire the action hook.
	 *
	 * Shared by the built-in AJAX form and the Elementor Forms action so both
	 * paths run the exact same pipeline.
	 *
	 * @param int   $course_id The course post ID.
	 * @param array $data      Associative data: name, email, phone, message (raw or sanitized).
	 * @return true|\WP_Error True on success, WP_Error with a user message on failure.
	 */
	public static function register_free( int $course_id, array $data ) {
		$name    = sanitize_text_field( $data['name'] ?? '' );
		$email   = sanitize_email( $data['email'] ?? '' );
		$phone   = sanitize_text_field( $data['phone'] ?? '' );
		$message = sanitize_textarea_field( $data['message'] ?? '' );

		if ( ! $course_id || ! $name || ! is_email( $email ) ) {
			return new \WP_Error( 'kivun_invalid', __( 'נא למלא שם ואימייל תקין.', 'kivun' ) );
		}

		if ( get_post_type( $course_id ) !== 'kivun_course' ) {
			return new \WP_Error( 'kivun_no_course', __( 'קורס לא קיים.', 'kivun' ) );
		}

		// Capacity check.
		if ( kivun_is_full( $course_id ) ) {
			return new \WP_Error( 'kivun_full', __( 'מצטערים, הקורס מלא. ניתן להשאיר פרטים לרשימת המתנה.', 'kivun' ) );
		}

		// Prevent duplicate registration (by email or phone).
		if ( self::already_registered( $course_id, $email, $phone ) ) {
			return new \WP_Error( 'kivun_duplicate', __( 'כבר נרשמת לקורס זה.', 'kivun' ) );
		}

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'kivun_registrations',
			array(
				'course_id'  => $course_id,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'message'    => $message,
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		Kivun_Mailer::send_course_registration( $course_id, compact( 'name', 'email', 'phone', 'message' ) );
		do_action( 'kivun_after_registration', $course_id, compact( 'name', 'email', 'phone', 'message' ) );

		return true;
	}

	/**
	 * Check whether a registration already exists for the given course.
	 *
	 * @param int    $course_id The course post ID.
	 * @param string $email     The registrant email address.
	 * @param string $phone     The registrant phone number.
	 * @return bool True when a matching registration already exists.
	 */
	private static function already_registered( int $course_id, string $email, string $phone = '' ): bool {
		global $wpdb;

		if ( $phone ) {
			return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}kivun_registrations
				 WHERE course_id = %d AND type IN ('registration','confirmed') AND (email = %s OR phone = %s) LIMIT 1",
					$course_id,
					$email,
					$phone
				)
			);
		}

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_registrations
			 WHERE course_id = %d AND type IN ('registration','confirmed') AND email = %s LIMIT 1",
				$course_id,
				$email
			)
		);
	}
}
