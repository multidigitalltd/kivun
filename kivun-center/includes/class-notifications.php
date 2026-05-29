<?php
defined( 'ABSPATH' ) || exit;

/**
 * Webhook notifications — fires on every new lead, registration, or CV application.
 * Sends a fire-and-forget POST (JSON) to the configured webhook URL.
 * Compatible with n8n, Zapier, Make, and any HTTP endpoint.
 */
class Kivun_Notifications {

	public static function init(): void {
		// Hook into the existing AJAX handlers after DB insert
		add_action( 'kivun_after_registration', [ __CLASS__, 'on_registration' ], 10, 2 );
		add_action( 'kivun_after_lead',         [ __CLASS__, 'on_lead' ],         10, 2 );
		add_action( 'kivun_after_application',  [ __CLASS__, 'on_application' ],  10, 2 );
	}

	public static function on_registration( int $course_id, array $data ): void {
		self::fire( [
			'event'      => 'course_registration',
			'post_id'    => $course_id,
			'post_title' => get_the_title( $course_id ),
			'post_type'  => get_post_type( $course_id ),
			'name'       => $data['name'],
			'email'      => $data['email'],
			'phone'      => $data['phone'],
			'message'    => $data['message'] ?? '',
		] );
	}

	public static function on_lead( int $post_id, array $data ): void {
		self::fire( [
			'event'      => 'new_lead',
			'post_id'    => $post_id,
			'post_title' => get_the_title( $post_id ),
			'post_type'  => get_post_type( $post_id ),
			'name'       => $data['name'],
			'email'      => $data['email'],
			'phone'      => $data['phone'],
			'message'    => $data['message'] ?? '',
		] );
	}

	public static function on_application( int $job_id, array $data ): void {
		self::fire( [
			'event'      => 'job_application',
			'post_id'    => $job_id,
			'post_title' => get_the_title( $job_id ),
			'name'       => $data['name'],
			'email'      => $data['email'],
			'phone'      => $data['phone'],
			'message'    => $data['message'] ?? '',
			'has_cv'     => ! empty( $data['cv_path'] ),
		] );
	}

	private static function fire( array $payload ): void {
		$settings = get_option( 'kivun_settings', [] );
		$url      = trim( $settings['webhook_url'] ?? '' );

		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		$payload['site_url']   = get_site_url();
		$payload['timestamp']  = current_time( 'c' );

		wp_remote_post( $url, [
			'headers'  => [ 'Content-Type' => 'application/json' ],
			'body'     => wp_json_encode( $payload ),
			'timeout'  => 5,
			'blocking' => false, // fire-and-forget — does not slow down user request
		] );
	}
}
