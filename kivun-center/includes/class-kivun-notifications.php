<?php
/**
 * Webhook notifications for leads, registrations, and CV applications.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook notifications — fires on every new lead, registration, or CV application.
 * Sends a fire-and-forget POST (JSON) to the configured webhook URL.
 * Compatible with n8n, Zapier, Make, and any HTTP endpoint.
 */
class Kivun_Notifications {

	/**
	 * Register hooks for notification events.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Hook into the existing AJAX handlers after DB insert.
		add_action( 'kivun_after_registration', array( __CLASS__, 'on_registration' ), 10, 2 );
		add_action( 'kivun_after_lead', array( __CLASS__, 'on_lead' ), 10, 2 );
		add_action( 'kivun_after_application', array( __CLASS__, 'on_application' ), 10, 2 );
	}

	/**
	 * Fire a webhook for a new course registration.
	 *
	 * @param int   $course_id The course post ID.
	 * @param array $data      The registration data.
	 * @return void
	 */
	public static function on_registration( int $course_id, array $data ): void {
		self::fire(
			array(
				'event'      => 'course_registration',
				'post_id'    => $course_id,
				'post_title' => get_the_title( $course_id ),
				'post_type'  => get_post_type( $course_id ),
				'name'       => $data['name'],
				'email'      => $data['email'],
				'phone'      => $data['phone'],
				'message'    => $data['message'] ?? '',
			)
		);
	}

	/**
	 * Fire a webhook for a new lead.
	 *
	 * @param int   $post_id The related post ID.
	 * @param array $data    The lead data.
	 * @return void
	 */
	public static function on_lead( int $post_id, array $data ): void {
		self::fire(
			array(
				'event'      => 'new_lead',
				'post_id'    => $post_id,
				'post_title' => get_the_title( $post_id ),
				'post_type'  => get_post_type( $post_id ),
				'name'       => $data['name'],
				'email'      => $data['email'],
				'phone'      => $data['phone'],
				'message'    => $data['message'] ?? '',
			)
		);
	}

	/**
	 * Fire a webhook for a new job application.
	 *
	 * @param int   $job_id The job post ID.
	 * @param array $data   The application data.
	 * @return void
	 */
	public static function on_application( int $job_id, array $data ): void {
		self::fire(
			array(
				'event'      => 'job_application',
				'post_id'    => $job_id,
				'post_title' => get_the_title( $job_id ),
				'name'       => $data['name'],
				'email'      => $data['email'],
				'phone'      => $data['phone'],
				'message'    => $data['message'] ?? '',
				'has_cv'     => ! empty( $data['cv_path'] ),
			)
		);
	}

	/**
	 * Send the payload to the configured webhook URL.
	 *
	 * @param array $payload The data to send.
	 * @return void
	 */
	private static function fire( array $payload ): void {
		$settings = get_option( 'kivun_settings', array() );
		$url      = trim( $settings['webhook_url'] ?? '' );

		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		$payload['site_url']  = get_site_url();
		$payload['timestamp'] = current_time( 'c' );

		wp_remote_post(
			$url,
			array(
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $payload ),
				'timeout'  => 5,
				'blocking' => false, // fire-and-forget — does not slow down user request.
			)
		);
	}
}
