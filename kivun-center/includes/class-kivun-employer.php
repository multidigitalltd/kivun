<?php
/**
 * Employer-facing AJAX handlers for posting and managing jobs and applications.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles employer registration, job CRUD, and application management via AJAX.
 */
class Kivun_Employer {

	/**
	 * Register the employer AJAX hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_post_job', array( __CLASS__, 'ajax_post_job' ) );
		add_action( 'wp_ajax_kivun_delete_job', array( __CLASS__, 'ajax_delete_job' ) );
		add_action( 'wp_ajax_kivun_update_job', array( __CLASS__, 'ajax_update_job' ) );
		add_action( 'wp_ajax_kivun_register_employer', array( __CLASS__, 'ajax_register_employer' ) );
		add_action( 'wp_ajax_nopriv_kivun_register_employer', array( __CLASS__, 'ajax_register_employer' ) );

		// Application management from the employer dashboard.
		add_action( 'wp_ajax_kivun_employer_update_app', array( __CLASS__, 'ajax_update_application' ) );
		add_action( 'wp_ajax_kivun_employer_app_note', array( __CLASS__, 'ajax_save_application_note' ) );
	}

	/**
	 * Application status labels, shared by the dashboard template, the AJAX
	 * validators and the admin CRM so the vocabulary stays in one place.
	 *
	 * @return array<string,string>
	 */
	public static function app_statuses(): array {
		return array(
			'new'       => __( 'חדש', 'kivun' ),
			'viewed'    => __( 'נצפה', 'kivun' ),
			'contacted' => __( 'נוצר קשר', 'kivun' ),
			'interview' => __( 'מוזמן לראיון', 'kivun' ),
			'hired'     => __( 'גויס ✓', 'kivun' ),
			'rejected'  => __( 'לא מתאים', 'kivun' ),
		);
	}

	// ── Post new job ──────────────────────────────────────────────────────────.

	/**
	 * Create a new job post from the employer dashboard.
	 *
	 * @return void
	 */
	public static function ajax_post_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$title        = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description  = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$company      = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$salary       = sanitize_text_field( wp_unslash( $_POST['salary'] ?? '' ) );
		$requirements = sanitize_textarea_field( wp_unslash( $_POST['requirements'] ?? '' ) );
		$deadline     = sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) );
		$scope        = sanitize_text_field( wp_unslash( $_POST['scope'] ?? '' ) );
		$region       = sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) );
		$field        = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );

		if ( ! $title || ! $description ) {
			wp_send_json_error( array( 'message' => __( 'כותרת ותיאור הם שדות חובה.', 'kivun' ) ) );
		}

		$job_id = wp_insert_post(
			array(
				'post_type'    => 'kivun_job',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $job_id ) ) {
			wp_send_json_error( array( 'message' => $job_id->get_error_message() ) );
		}

		// Employer email — stored privately, never exposed in frontend.
		$user = wp_get_current_user();
		update_post_meta( $job_id, '_kivun_employer_email', $user->user_email );
		update_post_meta( $job_id, '_kivun_company', $company );
		update_post_meta( $job_id, '_kivun_salary', $salary );
		update_post_meta( $job_id, '_kivun_requirements', $requirements );
		update_post_meta( $job_id, '_kivun_deadline', $deadline );

		if ( $scope ) {
			wp_set_object_terms( $job_id, $scope, 'kivun_job_scope' );
		}
		if ( $region ) {
			wp_set_object_terms( $job_id, $region, 'kivun_job_region' );
		}
		if ( $field ) {
			wp_set_object_terms( $job_id, $field, 'kivun_job_field' );
		}

		wp_send_json_success(
			array(
				'message' => __( 'המשרה פורסמה בהצלחה!', 'kivun' ),
				'job_id'  => $job_id,
			)
		);
	}

	// ── Update job ────────────────────────────────────────────────────────────.

	/**
	 * Update an existing job owned by the current employer.
	 *
	 * @return void
	 */
	public static function ajax_update_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$job_id = absint( wp_unslash( $_POST['job_id'] ?? 0 ) );
		self::verify_job_owner( $job_id );

		$title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$salary      = sanitize_text_field( wp_unslash( $_POST['salary'] ?? '' ) );
		$deadline    = sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) );

		wp_update_post(
			array(
				'ID'           => $job_id,
				'post_title'   => $title,
				'post_content' => $description,
			)
		);

		update_post_meta( $job_id, '_kivun_salary', $salary );
		update_post_meta( $job_id, '_kivun_deadline', $deadline );

		wp_send_json_success( array( 'message' => __( 'המשרה עודכנה.', 'kivun' ) ) );
	}

	// ── Delete job ────────────────────────────────────────────────────────────.

	/**
	 * Permanently delete a job owned by the current employer.
	 *
	 * @return void
	 */
	public static function ajax_delete_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$job_id = absint( wp_unslash( $_POST['job_id'] ?? 0 ) );
		self::verify_job_owner( $job_id );

		wp_delete_post( $job_id, true );

		wp_send_json_success( array( 'message' => __( 'המשרה נמחקה.', 'kivun' ) ) );
	}

	// ── Employer self-registration ────────────────────────────────────────────.

	/**
	 * Register a new employer account from the public form.
	 *
	 * @return void
	 */
	public static function ajax_register_employer(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'כבר מחובר/ת.', 'kivun' ) ) );
		}

		$name     = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
		$company  = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password is verified by wp_create_user, not stored as text.

		if ( ! $name || ! $company || ! is_email( $email ) || strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'נא למלא את כל השדות הנדרשים (סיסמה לפחות 8 תווים).', 'kivun' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'האימייל כבר רשום במערכת. נסה להתחבר.', 'kivun' ) ) );
		}

		$username = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) . '.' . wp_rand( 100, 999 ) );

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		$user = new WP_User( $user_id );
		$user->set_role( 'kivun_employer' );

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
			)
		);
		update_user_meta( $user_id, '_kivun_company', $company );
		update_user_meta( $user_id, '_kivun_phone', $phone );

		// Welcome email.
		$site = get_bloginfo( 'name' );
		wp_mail(
			$email,
			sprintf( 'ברוך הבא ל%s כמעסיק', $site ),
			sprintf(
				'<p>שלום %s,</p>
				<p>חשבון המעסיק שלך נוצר בהצלחה.</p>
				<p>כעת תוכל/י להתחבר ולפרסם משרות.<br>
				שם משתמש: %s</p>
				<p>בברכה, %s</p>',
				esc_html( $name ),
				esc_html( $email ),
				esc_html( $site )
			),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		wp_send_json_success( array( 'message' => __( 'החשבון נוצר! כעת תוכל/י להתחבר ולפרסם משרות.', 'kivun' ) ) );
	}

	// ── Application management (employer dashboard) ───────────────────────────.

	/**
	 * Update the status of an application owned by the current employer.
	 *
	 * @return void
	 */
	public static function ajax_update_application(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$app_id = absint( wp_unslash( $_POST['app_id'] ?? 0 ) );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );

		if ( ! array_key_exists( $status, self::app_statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'סטטוס לא תקין.', 'kivun' ) ) );
		}

		$app = self::verify_application_owner( $app_id );

		if ( $app->status === $status ) {
			wp_send_json_success( array( 'status' => $status ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'kivun_applications',
			array( 'status' => $status ),
			array( 'id' => $app_id ),
			array( '%s' ),
			array( '%d' )
		);

		/**
		 * Fires after an employer changes an application's status.
		 * Useful for notifying the applicant (e.g. interview invitation).
		 */
		do_action( 'kivun_application_status_changed', (int) $app_id, $status, $app->status, $app );

		wp_send_json_success( array( 'status' => $status ) );
	}

	/**
	 * Save an internal note on an application owned by the current employer.
	 *
	 * @return void
	 */
	public static function ajax_save_application_note(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$app_id = absint( wp_unslash( $_POST['app_id'] ?? 0 ) );
		$note   = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

		self::verify_application_owner( $app_id );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'kivun_applications',
			array( 'notes' => $note ),
			array( 'id' => $app_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success();
	}

	/**
	 * Fetch every application addressed to the current employer's jobs.
	 *
	 * @param int $user_id The employer user ID.
	 * @return array<int,object>
	 */
	public static function get_applications( int $user_id ): array {
		global $wpdb;

		if ( current_user_can( 'manage_options' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				"SELECT a.*, p.post_title AS job_title, p.post_author AS job_author
				 FROM {$wpdb->prefix}kivun_applications a
				 INNER JOIN {$wpdb->posts} p ON p.ID = a.job_id
				 WHERE p.post_type = 'kivun_job'
				 ORDER BY a.created_at DESC"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS job_title, p.post_author AS job_author
			 FROM {$wpdb->prefix}kivun_applications a
			 INNER JOIN {$wpdb->posts} p ON p.ID = a.job_id
			 WHERE p.post_type = 'kivun_job' AND p.post_author = %d
			 ORDER BY a.created_at DESC",
				$user_id
			)
		);
	}

	/**
	 * Count applications (and unread "new" ones) per job for the given employer.
	 *
	 * @param int $user_id The employer user ID.
	 * @return array<int,array{total:int,new:int}>  Keyed by job_id.
	 */
	public static function application_counts( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = current_user_can( 'manage_options' )
			? $wpdb->get_results(
				"SELECT a.job_id,
						COUNT(*) AS total,
						SUM( CASE WHEN a.status = 'new' THEN 1 ELSE 0 END ) AS new_count
				 FROM {$wpdb->prefix}kivun_applications a
				 INNER JOIN {$wpdb->posts} p ON p.ID = a.job_id
				 WHERE p.post_type = 'kivun_job'
				 GROUP BY a.job_id"
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.job_id,
						COUNT(*) AS total,
						SUM( CASE WHEN a.status = 'new' THEN 1 ELSE 0 END ) AS new_count
				 FROM {$wpdb->prefix}kivun_applications a
				 INNER JOIN {$wpdb->posts} p ON p.ID = a.job_id
				 WHERE p.post_type = 'kivun_job' AND p.post_author = %d
				 GROUP BY a.job_id",
					$user_id
				)
			);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ (int) $r->job_id ] = array(
				'total' => (int) $r->total,
				'new'   => (int) $r->new_count,
			);
		}
		return $counts;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────.

	/**
	 * Abort the request unless the current user is an employer or admin.
	 *
	 * @return void
	 */
	private static function require_employer(): void {
		if ( ! is_user_logged_in() || ( ! current_user_can( 'kivun_employer' ) && ! current_user_can( 'manage_options' ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לפעולה זו.', 'kivun' ) ) );
		}
	}

	/**
	 * Abort the request unless the current employer owns the given job.
	 *
	 * @param int $job_id The job post ID.
	 * @return void
	 */
	private static function verify_job_owner( int $job_id ): void {
		$post = get_post( $job_id );
		if (
			! $post ||
			'kivun_job' !== $post->post_type ||
			( get_current_user_id() !== (int) $post->post_author && ! current_user_can( 'manage_options' ) )
		) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל משרה זו.', 'kivun' ) ) );
		}
	}

	/**
	 * Ensure the current employer owns the job the application belongs to.
	 * Dies via wp_send_json_error() on failure; returns the application row.
	 *
	 * @param int $app_id The application ID.
	 * @return object
	 */
	private static function verify_application_owner( int $app_id ): object {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$app = $app_id
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}kivun_applications WHERE id = %d",
					$app_id
				)
			)
			: null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $app ) {
			wp_send_json_error( array( 'message' => __( 'ההגשה לא נמצאה.', 'kivun' ) ) );
		}

		$post = get_post( $app->job_id );
		if (
			! $post ||
			'kivun_job' !== $post->post_type ||
			( get_current_user_id() !== (int) $post->post_author && ! current_user_can( 'manage_options' ) )
		) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל הגשה זו.', 'kivun' ) ) );
		}

		return $app;
	}
}
