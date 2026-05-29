<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles workshop registrations and the shared "lead / interest" form
 * used by both workshops and courses.
 *
 * Lead flow: visitor submits interest → stored as type='lead' → email to admin.
 * Workshop flow: same as lead — rep calls back.
 */
class Kivun_Workshops {

	public static function init(): void {
		add_action( 'wp_ajax_kivun_submit_lead',        [ __CLASS__, 'ajax_submit_lead' ] );
		add_action( 'wp_ajax_nopriv_kivun_submit_lead', [ __CLASS__, 'ajax_submit_lead' ] );
	}

	/**
	 * Unified lead handler — covers:
	 *  - Course interest form  (post_type = kivun_course,    type = 'lead')
	 *  - Workshop registration (post_type = kivun_workshop,  type = 'workshop')
	 */
	public static function ajax_submit_lead(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$name    = sanitize_text_field( $_POST['name']    ?? '' );
		$email   = sanitize_email( $_POST['email']         ?? '' );
		$phone   = sanitize_text_field( $_POST['phone']   ?? '' );
		$message = sanitize_textarea_field( $_POST['message'] ?? '' );

		if ( ! $post_id || ! $name || ! $phone ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם וטלפון.', 'kivun' ) ] );
		}

		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, [ 'kivun_course', 'kivun_workshop' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'פוסט לא קיים.', 'kivun' ) ] );
		}

		$type = $post_type === 'kivun_workshop' ? 'workshop' : 'lead';

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'kivun_registrations',
			[
				'course_id'  => $post_id,
				'name'       => $name,
				'email'      => $email ?: '',
				'phone'      => $phone,
				'message'    => $message,
				'status'     => 'new_lead',
				'type'       => $type,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		Kivun_Mailer::send_lead_notification( $post_id, compact( 'name', 'email', 'phone', 'message' ), $type );

		$msg = $post_type === 'kivun_workshop'
			? __( 'ההרשמה לסדנה התקבלה! נציג יצור איתך קשר בהקדם.', 'kivun' )
			: __( 'פנייתך התקבלה! נציג יצור איתך קשר בהקדם לפרטים נוספים.', 'kivun' );

		wp_send_json_success( [ 'message' => $msg ] );
	}
}
