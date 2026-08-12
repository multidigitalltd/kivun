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
		add_action( 'wp_ajax_kivun_renew_job', array( __CLASS__, 'ajax_renew_job' ) );
		add_action( 'wp_ajax_kivun_register_employer', array( __CLASS__, 'ajax_register_employer' ) );
		add_action( 'wp_ajax_nopriv_kivun_register_employer', array( __CLASS__, 'ajax_register_employer' ) );

		// Jobs-board manager: create and manage publishers on their behalf.
		add_action( 'wp_ajax_kivun_create_employer', array( __CLASS__, 'ajax_create_employer' ) );
		add_action( 'wp_ajax_kivun_update_employer', array( __CLASS__, 'ajax_update_employer' ) );
		add_action( 'wp_ajax_kivun_send_employer_login', array( __CLASS__, 'ajax_send_employer_login' ) );
		add_action( 'wp_ajax_kivun_toggle_employer', array( __CLASS__, 'ajax_toggle_employer' ) );

		// A disabled publisher must not be able to log in.
		add_filter( 'authenticate', array( __CLASS__, 'block_disabled_login' ), 30 );

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

	/**
	 * Whether the current user may manage the WHOLE jobs board — every employer's
	 * jobs and applications. True for site admins and the "jobs board manager"
	 * role (kivun_manage_jobs capability).
	 *
	 * @return bool
	 */
	public static function can_manage_all(): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
		return current_user_can( 'manage_options' ) || current_user_can( 'kivun_manage_jobs' );
	}

	/**
	 * Every publisher account (users whose role is "מעסיק" / kivun_employer).
	 * Used by the manager's "act as" switcher and the post-on-behalf selector.
	 * Managers themselves are a different role and are intentionally excluded.
	 *
	 * @param bool $include_disabled Whether to include disabled publishers.
	 * @return array<int,WP_User>
	 */
	public static function get_employers( bool $include_disabled = true ): array {
		$employers = get_users(
			array(
				'role'    => 'kivun_employer',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		if ( $include_disabled ) {
			return $employers;
		}

		return array_values(
			array_filter(
				$employers,
				static fn( $user ) => ! get_user_meta( $user->ID, '_kivun_disabled', true )
			)
		);
	}

	/**
	 * Resolve which publisher a manager is currently acting on behalf of.
	 * Only managers may "act as" someone; the id must belong to a real
	 * publisher. Returns 0 ("all publishers" / self) otherwise.
	 *
	 * @param int $requested Requested employer user ID.
	 * @return int
	 */
	public static function acting_as( int $requested ): int {
		if ( ! self::can_manage_all() || $requested < 1 ) {
			return 0;
		}
		$user = get_userdata( $requested );
		if ( ! $user || ! in_array( 'kivun_employer', (array) $user->roles, true ) ) {
			return 0;
		}
		return $requested;
	}

	/**
	 * The user whose jobs/applications a request is scoped to.
	 * A manager with no explicit target sees the whole board (0); a manager
	 * "acting as" a publisher, or a plain employer, is scoped to that user.
	 *
	 * @param int $user_id        Current user ID.
	 * @param int $scope_employer Employer the manager is acting as (0 = all).
	 * @return int  Author ID to filter by, or 0 for "no filter / whole board".
	 */
	private static function scope_author( int $user_id, int $scope_employer ): int {
		if ( self::can_manage_all() ) {
			return $scope_employer > 0 ? $scope_employer : 0;
		}
		return $user_id;
	}

	/**
	 * Resolve the publisher a job should belong to for the current request.
	 * Managers must pick a real publisher (posted as `employer_id`); everyone
	 * else owns their own jobs. Dies via wp_send_json_error() on an invalid or
	 * missing manager selection, so the caller can rely on a valid WP_User.
	 *
	 * @param int $current_author Existing owner, when editing. A disabled
	 *                            publisher may stay the owner of a job they
	 *                            already have, but cannot receive new ones.
	 * @return WP_User
	 */
	private static function resolve_job_author( int $current_author = 0 ): WP_User {
		if ( self::can_manage_all() ) {
			$employer_id = absint( wp_unslash( $_POST['employer_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling AJAX handler.
			$employer    = $employer_id ? get_userdata( $employer_id ) : false;

			if ( ! $employer || ! in_array( 'kivun_employer', (array) $employer->roles, true ) ) {
				wp_send_json_error( array( 'message' => __( 'יש לבחור מפרסם עבור המשרה.', 'kivun' ) ) );
			}
			if (
				$employer->ID !== $current_author &&
				get_user_meta( $employer->ID, '_kivun_disabled', true )
			) {
				wp_send_json_error( array( 'message' => __( 'המפרסם מושבת. יש להפעיל אותו לפני פרסום משרות בשמו.', 'kivun' ) ) );
			}
			return $employer;
		}

		return wp_get_current_user();
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
		$requirements = wp_kses_post( wp_unslash( $_POST['requirements'] ?? '' ) );
		$scope        = sanitize_text_field( wp_unslash( $_POST['scope'] ?? '' ) );
		$region       = sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) );
		$field        = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );
		$deadline     = self::sanitize_deadline( sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) ) );

		if ( ! $title || ! $description ) {
			wp_send_json_error( array( 'message' => __( 'כותרת ותיאור הם שדות חובה.', 'kivun' ) ) );
		}

		// A manager publishes on behalf of a chosen publisher, so the job is
		// owned by that publisher and application emails reach them — not the
		// manager. A plain employer always owns their own jobs.
		$author = self::resolve_job_author();

		$job_id = wp_insert_post(
			array(
				'post_type'    => 'kivun_job',
				'post_title'   => $title,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_author'  => $author->ID,
			),
			true
		);

		if ( is_wp_error( $job_id ) ) {
			wp_send_json_error( array( 'message' => $job_id->get_error_message() ) );
		}

		// Employer email — stored privately, never exposed in frontend. This is
		// the address the "new application" notification is sent to.
		update_post_meta( $job_id, '_kivun_employer_email', $author->user_email );
		update_post_meta( $job_id, '_kivun_description', $description );
		update_post_meta( $job_id, '_kivun_company', $company );
		update_post_meta( $job_id, '_kivun_salary', $salary );
		update_post_meta( $job_id, '_kivun_requirements', $requirements );
		self::save_deadline( $job_id, $deadline );

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

		$title        = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description  = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$company      = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$salary       = sanitize_text_field( wp_unslash( $_POST['salary'] ?? '' ) );
		$requirements = wp_kses_post( wp_unslash( $_POST['requirements'] ?? '' ) );
		$scope        = sanitize_text_field( wp_unslash( $_POST['scope'] ?? '' ) );
		$region       = sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) );
		$field        = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );

		if ( ! $title || ! $description ) {
			wp_send_json_error( array( 'message' => __( 'כותרת ותיאור הם שדות חובה.', 'kivun' ) ) );
		}

		$post_update = array(
			'ID'         => $job_id,
			'post_title' => $title,
		);

		// A manager may reassign the job to another publisher; keep the private
		// notification email in sync so applications reach the new owner.
		if ( self::can_manage_all() ) {
			$author                     = self::resolve_job_author( (int) get_post_field( 'post_author', $job_id ) );
			$post_update['post_author'] = $author->ID;
			update_post_meta( $job_id, '_kivun_employer_email', $author->user_email );
		}

		wp_update_post( $post_update );

		update_post_meta( $job_id, '_kivun_description', $description );
		update_post_meta( $job_id, '_kivun_company', $company );
		update_post_meta( $job_id, '_kivun_salary', $salary );
		update_post_meta( $job_id, '_kivun_requirements', $requirements );
		self::save_deadline( $job_id, self::sanitize_deadline( sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) ) ) );

		wp_set_object_terms( $job_id, $scope ? $scope : array(), 'kivun_job_scope' );
		wp_set_object_terms( $job_id, $region ? $region : array(), 'kivun_job_region' );
		wp_set_object_terms( $job_id, $field ? $field : array(), 'kivun_job_field' );

		wp_send_json_success( array( 'message' => __( 'המשרה עודכנה.', 'kivun' ) ) );
	}

	/**
	 * Validate a closing date from a date input. Returns '' for anything that is
	 * not a real Y-m-d date, which means "no deadline".
	 *
	 * @param string $raw The raw posted value.
	 * @return string A Y-m-d date, or ''.
	 */
	private static function sanitize_deadline( string $raw ): string {
		$raw = sanitize_text_field( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
		return ( $date && $date->format( 'Y-m-d' ) === $raw ) ? $raw : '';
	}

	/**
	 * Persist a job's closing date. Clearing it, or setting a future one, also
	 * clears the "expired" marker so the daily cron can act on it again.
	 *
	 * @param int    $job_id   The job post ID.
	 * @param string $deadline A Y-m-d date, or '' for no deadline.
	 * @return void
	 */
	private static function save_deadline( int $job_id, string $deadline ): void {
		if ( '' === $deadline ) {
			delete_post_meta( $job_id, '_kivun_deadline' );
		} else {
			update_post_meta( $job_id, '_kivun_deadline', $deadline );
		}

		if ( '' === $deadline || $deadline >= current_time( 'Y-m-d' ) ) {
			delete_post_meta( $job_id, '_kivun_expired' );
		}
	}

	// ── Renew job ─────────────────────────────────────────────────────────────.

	/**
	 * Re-publish an expired job with a new closing date, in one click.
	 *
	 * @return void
	 */
	public static function ajax_renew_job(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );
		self::require_employer();

		$job_id = absint( wp_unslash( $_POST['job_id'] ?? 0 ) );
		self::verify_job_owner( $job_id );

		$deadline = self::sanitize_deadline( sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) ) );
		if ( '' === $deadline ) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור תאריך סגירה חדש.', 'kivun' ) ) );
		}
		if ( $deadline < current_time( 'Y-m-d' ) ) {
			wp_send_json_error( array( 'message' => __( 'תאריך הסגירה חייב להיות עתידי.', 'kivun' ) ) );
		}

		self::save_deadline( $job_id, $deadline );
		wp_update_post(
			array(
				'ID'          => $job_id,
				'post_status' => 'publish',
			)
		);

		wp_send_json_success( array( 'message' => __( 'המשרה חודשה ופורסמה מחדש.', 'kivun' ) ) );
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

		$username = self::unique_username( $name );

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

	// ── Manager: create a publisher on their behalf ──────────────────────────.

	/**
	 * Create a new publisher account from the jobs-board manager's dashboard.
	 * No password is required — the manager runs the account. Optionally emails
	 * the publisher a "set your password" link so they can log in themselves.
	 *
	 * @return void
	 */
	public static function ajax_create_employer(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage_all() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה להוסיף מפרסמים.', 'kivun' ) ) );
		}

		$name       = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
		$company    = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$send_login = ! empty( $_POST['send_login'] );

		if ( ! $name || ! $company || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'נא למלא שם, שם חברה ואימייל תקין.', 'kivun' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'האימייל כבר רשום במערכת.', 'kivun' ) ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_username( $name ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name,
				'role'         => 'kivun_employer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		update_user_meta( $user_id, '_kivun_company', $company );
		update_user_meta( $user_id, '_kivun_phone', $phone );

		if ( $send_login ) {
			self::mail_set_password( (int) $user_id );
		}

		wp_send_json_success(
			array(
				'message'  => $send_login
					? __( 'המפרסם נוסף ונשלח אליו מייל להגדרת סיסמה.', 'kivun' )
					: __( 'המפרסם נוסף בהצלחה.', 'kivun' ),
				'employer' => array(
					'id'    => (int) $user_id,
					'label' => $company ? $company . ' — ' . $name : $name,
				),
			)
		);
	}

	/**
	 * Update an existing publisher's contact details. The email address matters
	 * most: it is where new-application notifications are delivered, so changing
	 * it also re-points every job that publisher owns.
	 *
	 * @return void
	 */
	public static function ajax_update_employer(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage_all() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לערוך מפרסמים.', 'kivun' ) ) );
		}

		$employer = self::require_employer_target();

		$name    = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
		$company = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( ! $name || ! $company || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'נא למלא שם, שם חברה ואימייל תקין.', 'kivun' ) ) );
		}

		// is_email() passed, so a match here is a different user's address.
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== $employer->ID ) {
			wp_send_json_error( array( 'message' => __( 'האימייל כבר רשום למשתמש אחר.', 'kivun' ) ) );
		}

		$updated = wp_update_user(
			array(
				'ID'           => $employer->ID,
				'user_email'   => $email,
				'display_name' => $name,
			)
		);

		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
		}

		update_user_meta( $employer->ID, '_kivun_company', $company );
		update_user_meta( $employer->ID, '_kivun_phone', $phone );

		// Keep each job's private notification address in sync with the owner.
		$jobs = get_posts(
			array(
				'post_type'      => 'kivun_job',
				'author'         => $employer->ID,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $jobs as $job_id ) {
			update_post_meta( $job_id, '_kivun_employer_email', $email );
		}

		wp_send_json_success( array( 'message' => __( 'פרטי המפרסם עודכנו.', 'kivun' ) ) );
	}

	/**
	 * Email an existing publisher a fresh set-password link, so they can log in
	 * and manage their own jobs when they want to.
	 *
	 * @return void
	 */
	public static function ajax_send_employer_login(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage_all() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לפעולה זו.', 'kivun' ) ) );
		}

		$employer = self::require_employer_target();

		if ( get_user_meta( $employer->ID, '_kivun_disabled', true ) ) {
			wp_send_json_error( array( 'message' => __( 'המפרסם מושבת — יש להפעיל אותו לפני שליחת קישור התחברות.', 'kivun' ) ) );
		}

		self::mail_set_password( $employer->ID );

		wp_send_json_success( array( 'message' => __( 'נשלח מייל להגדרת סיסמה.', 'kivun' ) ) );
	}

	/**
	 * Disable or re-enable a publisher. A disabled publisher cannot log in and
	 * is hidden from the "post on behalf" selector, but every job, application
	 * and history record they own is left untouched.
	 *
	 * @return void
	 */
	public static function ajax_toggle_employer(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage_all() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לפעולה זו.', 'kivun' ) ) );
		}

		$employer = self::require_employer_target();
		$disable  = ! empty( $_POST['disable'] );

		if ( $disable ) {
			update_user_meta( $employer->ID, '_kivun_disabled', '1' );
		} else {
			delete_user_meta( $employer->ID, '_kivun_disabled' );
		}

		wp_send_json_success(
			array(
				'message'  => $disable
					? __( 'המפרסם הושבת. המשרות וההגשות שלו נשמרו.', 'kivun' )
					: __( 'המפרסם הופעל מחדש.', 'kivun' ),
				'disabled' => $disable,
			)
		);
	}

	/**
	 * Block a disabled publisher from logging in.
	 *
	 * @param mixed $user Authentication result so far.
	 * @return mixed WP_User, WP_Error, or null.
	 */
	public static function block_disabled_login( $user ) {
		if ( $user instanceof WP_User && get_user_meta( $user->ID, '_kivun_disabled', true ) ) {
			return new WP_Error(
				'kivun_employer_disabled',
				__( 'החשבון הושבת. יש לפנות למנהל לוח המשרות.', 'kivun' )
			);
		}
		return $user;
	}

	/**
	 * Read and validate the `employer_id` of the publisher an action targets.
	 * Dies via wp_send_json_error() when it is missing or not a publisher.
	 *
	 * @return WP_User
	 */
	private static function require_employer_target(): WP_User {
		$employer_id = absint( wp_unslash( $_POST['employer_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling AJAX handler.
		$employer    = $employer_id ? get_userdata( $employer_id ) : false;

		if ( ! $employer || ! in_array( 'kivun_employer', (array) $employer->roles, true ) ) {
			wp_send_json_error( array( 'message' => __( 'המפרסם לא נמצא.', 'kivun' ) ) );
		}

		return $employer;
	}

	/**
	 * Email a publisher a one-time link to set their own password and log in.
	 *
	 * @param int $user_id The publisher user ID.
	 * @return void
	 */
	private static function mail_set_password( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);
		$site      = get_bloginfo( 'name' );

		wp_mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( 'הוקם עבורך חשבון מפרסם ב%s', 'kivun' ), $site ),
			sprintf(
				/* translators: 1: publisher name, 2: site name, 3: set-password URL, 4: username. */
				'<p>שלום %1$s,</p>
				<p>מנהל לוח המשרות של %2$s הקים עבורך חשבון מפרסם.</p>
				<p>להגדרת סיסמה והתחברות לאזור הניהול: <a href="%3$s">%3$s</a></p>
				<p>שם המשתמש שלך: %4$s</p>',
				esc_html( $user->display_name ),
				esc_html( $site ),
				esc_url( $reset_url ),
				esc_html( $user->user_login )
			),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Build a unique, sanitised username from a display name.
	 *
	 * @param string $name Display name to derive the login from.
	 * @return string
	 */
	private static function unique_username( string $name ): string {
		$base = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ), true );
		if ( '' === $base ) {
			$base = 'employer';
		}

		$username = $base . '.' . wp_rand( 100, 999 );
		while ( username_exists( $username ) ) {
			$username = $base . '.' . wp_rand( 1000, 9999 );
		}

		return $username;
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
	 * @param int $user_id        The employer user ID.
	 * @param int $scope_employer When a manager is acting as one publisher, that
	 *                            publisher's ID; 0 = whole board (managers only).
	 * @return array<int,object>
	 */
	public static function get_applications( int $user_id, int $scope_employer = 0 ): array {
		global $wpdb;

		$author = self::scope_author( $user_id, $scope_employer );

		if ( 0 === $author ) {
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
				$author
			)
		);
	}

	/**
	 * Count applications (and unread "new" ones) per job for the given employer.
	 *
	 * @param int $user_id        The employer user ID.
	 * @param int $scope_employer When a manager is acting as one publisher, that
	 *                            publisher's ID; 0 = whole board (managers only).
	 * @return array<int,array{total:int,new:int}>  Keyed by job_id.
	 */
	public static function application_counts( int $user_id, int $scope_employer = 0 ): array {
		global $wpdb;

		$author = self::scope_author( $user_id, $scope_employer );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = 0 === $author
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
					$author
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
			( get_current_user_id() !== (int) $post->post_author && ! self::can_manage_all() )
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
			( get_current_user_id() !== (int) $post->post_author && ! self::can_manage_all() )
		) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל הגשה זו.', 'kivun' ) ) );
		}

		return $app;
	}
}
