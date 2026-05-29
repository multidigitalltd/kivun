<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Jobs {

	public static function init(): void {
		add_action( 'wp_ajax_kivun_filter_jobs',        [ __CLASS__, 'ajax_filter' ] );
		add_action( 'wp_ajax_nopriv_kivun_filter_jobs', [ __CLASS__, 'ajax_filter' ] );

		add_action( 'wp_ajax_kivun_submit_application',        [ __CLASS__, 'ajax_apply' ] );
		add_action( 'wp_ajax_nopriv_kivun_submit_application', [ __CLASS__, 'ajax_apply' ] );
	}

	// ── Filter ────────────────────────────────────────────────────────────────

	public static function ajax_filter(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$scope  = sanitize_text_field( $_POST['scope']  ?? '' );
		$region = sanitize_text_field( $_POST['region'] ?? '' );
		$field  = sanitize_text_field( $_POST['field']  ?? '' );
		$search = sanitize_text_field( $_POST['search'] ?? '' );
		$paged  = max( 1, absint( $_POST['paged'] ?? 1 ) );

		$args = [
			'post_type'      => 'kivun_job',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$tax_query = [];

		if ( $scope ) {
			$tax_query[] = [ 'taxonomy' => 'kivun_job_scope',   'field' => 'slug', 'terms' => $scope ];
		}
		if ( $region ) {
			$tax_query[] = [ 'taxonomy' => 'kivun_job_region',  'field' => 'slug', 'terms' => $region ];
		}
		if ( $field ) {
			$tax_query[] = [ 'taxonomy' => 'kivun_job_field',   'field' => 'slug', 'terms' => $field ];
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		ob_start();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				kivun_get_template( 'jobs/card.php' );
			}
			wp_reset_postdata();
		} else {
			echo '<p class="kivun-no-results">' . esc_html__( 'לא נמצאו משרות תואמות.', 'kivun' ) . '</p>';
		}
		$html = ob_get_clean();

		wp_send_json_success( [
			'html'       => $html,
			'count'      => $query->found_posts,
			'max_pages'  => $query->max_num_pages,
			'paged'      => $paged,
		] );
	}

	// ── CV Application ────────────────────────────────────────────────────────

	public static function ajax_apply(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$job_id  = absint( $_POST['job_id'] ?? 0 );
		$name    = sanitize_text_field( $_POST['applicant_name']  ?? '' );
		$email   = sanitize_email( $_POST['applicant_email']       ?? '' );
		$phone   = sanitize_text_field( $_POST['applicant_phone']  ?? '' );
		$message = sanitize_textarea_field( $_POST['message']      ?? '' );

		if ( ! $job_id || ! $name || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם ואימייל תקין.', 'kivun' ) ] );
		}
		if ( get_post_type( $job_id ) !== 'kivun_job' ) {
			wp_send_json_error( [ 'message' => __( 'משרה לא קיימת.', 'kivun' ) ] );
		}

		// Duplicate check
		if ( self::already_applied( $job_id, $email, $phone ) ) {
			wp_send_json_error( [ 'message' => __( 'כבר הגשת מועמדות למשרה זו.', 'kivun' ) ] );
		}

		$cv_path = self::handle_cv_upload();
		if ( is_wp_error( $cv_path ) ) {
			wp_send_json_error( [ 'message' => $cv_path->get_error_message() ] );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'kivun_applications',
			[
				'job_id'          => $job_id,
				'user_id'         => get_current_user_id(),
				'applicant_name'  => $name,
				'applicant_email' => $email,
				'applicant_phone' => $phone,
				'cv_file'         => $cv_path ?? '',
				'message'         => $message,
				'status'          => 'new',
				'created_at'      => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$employer_email = get_post_meta( $job_id, '_kivun_employer_email', true );
		if ( $employer_email ) {
			Kivun_Mailer::send_application(
				$employer_email,
				get_the_title( $job_id ),
				compact( 'name', 'email', 'phone', 'message' ) + [ 'cv_path' => $cv_path ]
			);
		}
		do_action( 'kivun_after_application', $job_id, compact( 'name', 'email', 'phone', 'message' ) + [ 'cv_path' => $cv_path ] );

		wp_send_json_success( [
			'message' => __( 'קורות החיים נשלחו! נחזור אליך בהקדם.', 'kivun' ),
		] );
	}

	private static function already_applied( int $job_id, string $email, string $phone ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}kivun_applications
			 WHERE job_id = %d AND (applicant_email = %s OR applicant_phone = %s) LIMIT 1",
			$job_id, $email, $phone
		) );
	}

	/** @return string|null|\WP_Error */
	private static function handle_cv_upload() {
		if ( empty( $_FILES['cv_file']['name'] ) ) {
			return null;
		}

		$mime  = $_FILES['cv_file']['type'] ?? '';
		$allowed = [
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		];

		if ( ! in_array( $mime, $allowed, true ) ) {
			return new WP_Error( 'bad_type', __( 'סוג קובץ לא נתמך. יש לשלוח PDF או Word בלבד.', 'kivun' ) );
		}

		if ( $_FILES['cv_file']['size'] > 5 * MB_IN_BYTES ) {
			return new WP_Error( 'too_large', __( 'הקובץ גדול מ-5MB.', 'kivun' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload( $_FILES['cv_file'], [ 'test_form' => false ] );

		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		return $upload['file'];
	}
}
