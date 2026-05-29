<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Employer {

	public static function init(): void {
		add_action( 'wp_ajax_kivun_post_job',   [ __CLASS__, 'ajax_post_job' ] );
		add_action( 'wp_ajax_kivun_delete_job', [ __CLASS__, 'ajax_delete_job' ] );
		add_action( 'wp_ajax_kivun_update_job', [ __CLASS__, 'ajax_update_job' ] );
	}

	// ── Post new job ──────────────────────────────────────────────────────────

	public static function ajax_post_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$title        = sanitize_text_field( $_POST['title']        ?? '' );
		$description  = wp_kses_post( $_POST['description']         ?? '' );
		$company      = sanitize_text_field( $_POST['company']       ?? '' );
		$salary       = sanitize_text_field( $_POST['salary']        ?? '' );
		$requirements = sanitize_textarea_field( $_POST['requirements'] ?? '' );
		$deadline     = sanitize_text_field( $_POST['deadline']      ?? '' );
		$scope        = sanitize_text_field( $_POST['scope']         ?? '' );
		$region       = sanitize_text_field( $_POST['region']        ?? '' );
		$field        = sanitize_text_field( $_POST['field']         ?? '' );

		if ( ! $title || ! $description ) {
			wp_send_json_error( [ 'message' => __( 'כותרת ותיאור הם שדות חובה.', 'kivun' ) ] );
		}

		$job_id = wp_insert_post( [
			'post_type'    => 'kivun_job',
			'post_title'   => $title,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
		], true );

		if ( is_wp_error( $job_id ) ) {
			wp_send_json_error( [ 'message' => $job_id->get_error_message() ] );
		}

		// Employer email — stored privately, never exposed in frontend
		$user  = wp_get_current_user();
		update_post_meta( $job_id, '_kivun_employer_email', $user->user_email );
		update_post_meta( $job_id, '_kivun_company',        $company );
		update_post_meta( $job_id, '_kivun_salary',         $salary );
		update_post_meta( $job_id, '_kivun_requirements',   $requirements );
		update_post_meta( $job_id, '_kivun_deadline',       $deadline );

		if ( $scope )  wp_set_object_terms( $job_id, $scope,  'kivun_job_scope' );
		if ( $region ) wp_set_object_terms( $job_id, $region, 'kivun_job_region' );
		if ( $field )  wp_set_object_terms( $job_id, $field,  'kivun_job_field' );

		wp_send_json_success( [
			'message' => __( 'המשרה פורסמה בהצלחה!', 'kivun' ),
			'job_id'  => $job_id,
		] );
	}

	// ── Update job ────────────────────────────────────────────────────────────

	public static function ajax_update_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$job_id = absint( $_POST['job_id'] ?? 0 );
		self::verify_job_owner( $job_id );

		$title       = sanitize_text_field( $_POST['title']       ?? '' );
		$description = wp_kses_post( $_POST['description']        ?? '' );
		$salary      = sanitize_text_field( $_POST['salary']      ?? '' );
		$deadline    = sanitize_text_field( $_POST['deadline']     ?? '' );

		wp_update_post( [
			'ID'           => $job_id,
			'post_title'   => $title,
			'post_content' => $description,
		] );

		update_post_meta( $job_id, '_kivun_salary',   $salary );
		update_post_meta( $job_id, '_kivun_deadline', $deadline );

		wp_send_json_success( [ 'message' => __( 'המשרה עודכנה.', 'kivun' ) ] );
	}

	// ── Delete job ────────────────────────────────────────────────────────────

	public static function ajax_delete_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$job_id = absint( $_POST['job_id'] ?? 0 );
		self::verify_job_owner( $job_id );

		wp_delete_post( $job_id, true );

		wp_send_json_success( [ 'message' => __( 'המשרה נמחקה.', 'kivun' ) ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function require_employer(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'kivun_employer' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'אין לך הרשאה לפעולה זו.', 'kivun' ) ] );
		}
	}

	private static function verify_job_owner( int $job_id ): void {
		$post = get_post( $job_id );
		if (
			! $post ||
			$post->post_type !== 'kivun_job' ||
			( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) )
		) {
			wp_send_json_error( [ 'message' => __( 'אין לך הרשאה לנהל משרה זו.', 'kivun' ) ] );
		}
	}
}
