<?php
/**
 * Job listings filtering and CV application handling.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles job AJAX filtering and CV application submissions.
 */
class Kivun_Jobs {

	/**
	 * Registers AJAX hooks for job filtering and applications.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_filter_jobs', array( __CLASS__, 'ajax_filter' ) );
		add_action( 'wp_ajax_nopriv_kivun_filter_jobs', array( __CLASS__, 'ajax_filter' ) );

		add_action( 'wp_ajax_kivun_submit_application', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_nopriv_kivun_submit_application', array( __CLASS__, 'ajax_apply' ) );

		// Authenticated CV download (logged-in only; permission re-checked).
		add_action( 'admin_post_kivun_download_cv', array( __CLASS__, 'download_cv' ) );

		add_filter( 'the_content', array( __CLASS__, 'append_single_job_content' ) );
		add_filter( 'the_title', array( __CLASS__, 'suppress_duplicate_title' ), 10, 2 );
	}

	/**
	 * Whether the built-in single job design (hero, details and appended apply
	 * form) is active. On by default; developers can disable it with the
	 * kivun_single_job_design filter when building the page manually
	 * (e.g. with an Elementor Single template).
	 *
	 * @return bool
	 */
	private static function single_design_enabled(): bool {
		return (bool) apply_filters( 'kivun_single_job_design', true );
	}

	/**
	 * Hide the theme/page-builder post title on the single job page, since the
	 * Kivun design already renders the title in its hero. Only affects the main
	 * heading of the current job; the design itself uses the raw title so it is
	 * unaffected. Opt out via the kivun_hide_duplicate_job_title filter.
	 *
	 * @param string $title   The post title.
	 * @param int    $post_id The post ID (0 in some legacy callers).
	 * @return string The title, or '' when suppressed.
	 */
	public static function suppress_duplicate_title( $title, $post_id = 0 ) {
		if (
			'' !== $title
			&& ! is_admin()
			&& is_singular( 'kivun_job' )
			&& is_main_query()
			&& in_the_loop()
			&& get_queried_object_id() === (int) $post_id
			&& apply_filters( 'kivun_single_job_append', self::single_design_enabled() )
			&& apply_filters( 'kivun_hide_duplicate_job_title', self::single_design_enabled() )
		) {
			return '';
		}
		return $title;
	}

	/**
	 * Build the authenticated download URL for an application's CV.
	 *
	 * @param int $app_id The application row id.
	 * @return string Nonce-protected admin-post URL.
	 */
	public static function cv_url( int $app_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'kivun_download_cv',
					'app'    => $app_id,
				),
				admin_url( 'admin-post.php' )
			),
			'kivun_download_cv_' . $app_id
		);
	}

	/**
	 * Stream a CV file to authorised users only: a site admin, the employer
	 * who owns the job, or the applicant who submitted it. Prevents the PII in
	 * CVs from being fetched by guessing the upload URL (IDOR).
	 *
	 * @return void
	 */
	public static function download_cv(): void {
		$app_id = absint( $_GET['app'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.

		if ( ! $app_id || ! is_user_logged_in() ) {
			wp_die( esc_html__( 'אין הרשאה.', 'kivun' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kivun_download_cv_' . $app_id );

		global $wpdb;
		$app = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_applications WHERE id = %d", $app_id )
		);

		if ( ! $app || ! $app->cv_file || ! file_exists( $app->cv_file ) ) {
			wp_die( esc_html__( 'הקובץ לא נמצא.', 'kivun' ), '', array( 'response' => 404 ) );
		}

		$user_id  = get_current_user_id();
		$job      = get_post( $app->job_id );
		$is_owner = $job && (int) $job->post_author === $user_id;
		$is_self  = (int) $app->user_id === $user_id && $user_id > 0;

		if ( ! current_user_can( 'manage_options' ) && ! $is_owner && ! $is_self ) {
			wp_die( esc_html__( 'אין הרשאה לצפות בקובץ זה.', 'kivun' ), '', array( 'response' => 403 ) );
		}

		$type = wp_check_filetype( $app->cv_file );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $app->cv_file ) ) . '"' );
		header( 'Content-Length: ' . filesize( $app->cv_file ) );
		readfile( $app->cv_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Append the job details and CV application form to the single job page,
	 * so the board's "apply" button can deep-link to the full job and submit.
	 *
	 * Runs only on the main single kivun_job view; opt out via the
	 * `kivun_single_job_append` filter (e.g. when building the page manually).
	 *
	 * @param string $content The post content.
	 * @return string The content with the job details + form appended.
	 */
	public static function append_single_job_content( string $content ): string {
		if ( ! is_singular( 'kivun_job' ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}
		if ( ! apply_filters( 'kivun_single_job_append', self::single_design_enabled() ) ) {
			return $content;
		}

		$job_id = get_the_ID();

		ob_start();
		kivun_get_template(
			'jobs/single.php',
			array(
				'job_id'  => $job_id,
				'content' => $content,
			)
		);
		$design = ob_get_clean();

		// The editor body is embedded inside the design (see single.php), so it
		// is intentionally not output a second time here.
		return self::breadcrumbs( $job_id ) . $design;
	}

	/**
	 * Build an accessible breadcrumb trail (with BreadcrumbList schema) for the
	 * single job page: Home › Jobs board › Job title.
	 *
	 * @param int $job_id The current job ID.
	 * @return string The breadcrumb markup, or '' when disabled.
	 */
	public static function breadcrumbs( int $job_id ): string {
		if ( ! apply_filters( 'kivun_job_breadcrumbs', true, $job_id ) ) {
			return '';
		}

		$job_post  = get_post( $job_id );
		$job_title = $job_post ? $job_post->post_title : '';

		$trail = array(
			array(
				'label' => __( 'בית', 'kivun' ),
				'url'   => home_url( '/' ),
			),
			array(
				'label' => __( 'לוח המשרות', 'kivun' ),
				'url'   => get_post_type_archive_link( 'kivun_job' ),
			),
			array(
				'label' => $job_title,
				'url'   => '',
			),
		);

		$items = '';
		$pos   = 1;
		$last  = count( $trail ) - 1;

		foreach ( $trail as $index => $crumb ) {
			$is_last = ( $index === $last );
			$inner   = '';

			if ( ! $is_last && $crumb['url'] ) {
				$inner = sprintf(
					'<a itemprop="item" href="%s"><span itemprop="name">%s</span></a>',
					esc_url( $crumb['url'] ),
					esc_html( $crumb['label'] )
				);
			} else {
				$inner = sprintf(
					'<span itemprop="name" aria-current="page">%s</span>',
					esc_html( $crumb['label'] )
				);
			}

			$items .= sprintf(
				'<li class="kivun-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">%s<meta itemprop="position" content="%d"></li>',
				$inner,
				$pos
			);
			++$pos;
		}

		return sprintf(
			'<nav class="kivun-breadcrumbs" aria-label="%s"><ol class="kivun-breadcrumbs__list" itemscope itemtype="https://schema.org/BreadcrumbList">%s</ol></nav>',
			esc_attr__( 'פירורי לחם', 'kivun' ),
			$items
		);
	}

	// ── Filter ────────────────────────────────────────────────────────────────.

	/**
	 * Handles the AJAX request that filters job listings.
	 *
	 * @return void
	 */
	public static function ajax_filter(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$scope  = absint( $_POST['scope'] ?? 0 );
		$region = absint( $_POST['region'] ?? 0 );
		$field  = absint( $_POST['field'] ?? 0 );
		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$paged  = max( 1, absint( $_POST['paged'] ?? 1 ) );

		$args = array(
			'post_type'      => 'kivun_job',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$tax_query = array();

		if ( $scope ) {
			$tax_query[] = array(
				'taxonomy' => 'kivun_job_scope',
				'field'    => 'term_id',
				'terms'    => $scope,
			);
		}
		if ( $region ) {
			$tax_query[] = array(
				'taxonomy' => 'kivun_job_region',
				'field'    => 'term_id',
				'terms'    => $region,
			);
		}
		if ( $field ) {
			$tax_query[] = array(
				'taxonomy' => 'kivun_job_field',
				'field'    => 'term_id',
				'terms'    => $field,
			);
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

		wp_send_json_success(
			array(
				'html'      => $html,
				'count'     => $query->found_posts,
				'max_pages' => $query->max_num_pages,
				'paged'     => $paged,
			)
		);
	}

	// ── CV Application ────────────────────────────────────────────────────────.

	/**
	 * Handles the AJAX request that submits a CV application.
	 *
	 * @return void
	 */
	public static function ajax_apply(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::verify_turnstile() ) {
			wp_send_json_error( array( 'message' => __( 'אימות האבטחה נכשל. נסו שוב.', 'kivun' ) ) );
		}

		$job_id  = absint( $_POST['job_id'] ?? 0 );
		$name    = sanitize_text_field( wp_unslash( $_POST['applicant_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['applicant_email'] ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['applicant_phone'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

		if ( ! $job_id || ! $name || ! $phone || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'נא למלא שם, טלפון ואימייל תקין.', 'kivun' ) ) );
		}
		if ( get_post_type( $job_id ) !== 'kivun_job' ) {
			wp_send_json_error( array( 'message' => __( 'משרה לא קיימת.', 'kivun' ) ) );
		}

		// Duplicate check.
		if ( self::already_applied( $job_id, $email, $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'כבר הגשת מועמדות למשרה זו.', 'kivun' ) ) );
		}

		$cv_path = self::handle_cv_upload();
		if ( is_wp_error( $cv_path ) ) {
			wp_send_json_error( array( 'message' => $cv_path->get_error_message() ) );
		}
		if ( ! $cv_path ) {
			wp_send_json_error( array( 'message' => __( 'נא לצרף קובץ קורות חיים.', 'kivun' ) ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'kivun_applications',
			array(
				'job_id'          => $job_id,
				'user_id'         => get_current_user_id(),
				'applicant_name'  => $name,
				'applicant_email' => $email,
				'applicant_phone' => $phone,
				'cv_file'         => $cv_path ?? '',
				'message'         => $message,
				'status'          => 'new',
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Never report success on a failed write (was silently masking errors).
		if ( false === $inserted ) {
			$db_error = $wpdb->last_error;
			do_action( 'kivun_application_insert_failed', $db_error, $job_id );

			$message = __( 'שמירת ההגשה נכשלה. אנא נסו שוב או פנו אלינו.', 'kivun' );
			// Surface the real reason to administrators testing the form.
			if ( $db_error && current_user_can( 'manage_options' ) ) {
				$message .= ' [' . $db_error . ']';
			}
			wp_send_json_error( array( 'message' => $message ) );
		}

		$employer_email = get_post_meta( $job_id, '_kivun_employer_email', true );
		if ( $employer_email ) {
			Kivun_Mailer::send_application(
				$employer_email,
				get_the_title( $job_id ),
				compact( 'name', 'email', 'phone', 'message' ) + array( 'cv_path' => $cv_path )
			);
		}

		// Reassure the applicant that their CV arrived.
		Kivun_Mailer::send_application_confirmation(
			$email,
			$name,
			get_the_title( $job_id ),
			(string) get_post_meta( $job_id, '_kivun_company', true )
		);
		do_action( 'kivun_after_application', $job_id, compact( 'name', 'email', 'phone', 'message' ) + array( 'cv_path' => $cv_path ) );

		wp_send_json_success(
			array(
				'message' => __( 'קורות החיים נשלחו בהצלחה למפרסם המשרה', 'kivun' ),
			)
		);
	}

	/**
	 * Verify the Cloudflare Turnstile token. Returns true when Turnstile is not
	 * configured (feature disabled), otherwise validates the token server-side.
	 *
	 * @return bool
	 */
	private static function verify_turnstile(): bool {
		$secret = (string) Kivun_Admin_Settings::get( 'turnstile_secret_key' );
		if ( '' === $secret ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_apply() before this runs.
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( '' === $token ) {
			return false;
		}

		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $remote_ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	/**
	 * Checks whether an application already exists for the job.
	 *
	 * @param int    $job_id The job post ID.
	 * @param string $email  The applicant email address.
	 * @param string $phone  The applicant phone number.
	 * @return bool True when a matching application already exists.
	 */
	private static function already_applied( int $job_id, string $email, string $phone ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_applications
			 WHERE job_id = %d AND (applicant_email = %s OR applicant_phone = %s) LIMIT 1",
				$job_id,
				$email,
				$phone
			)
		);
	}

	/**
	 * Handles the uploaded CV file and validates its type and size.
	 *
	 * @return string|null|\WP_Error Uploaded file path, null when no file, or WP_Error on failure.
	 */
	private static function handle_cv_upload() {
		// Nonce is verified in ajax_apply() before this method is called.
		if ( empty( $_FILES['cv_file']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			return null;
		}

		$allowed = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		// Validate by the real file extension/content — never trust the
		// browser-supplied MIME type, which is easily spoofed.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified above; file name sanitized below.
		$filename = sanitize_file_name( wp_unslash( $_FILES['cv_file']['name'] ) );
		$check    = wp_check_filetype( $filename, $allowed );

		if ( empty( $check['ext'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error( 'bad_type', __( 'סוג קובץ לא נתמך. יש לשלוח PDF או Word בלבד.', 'kivun' ) );
		}

		if ( isset( $_FILES['cv_file']['size'] ) && (int) $_FILES['cv_file']['size'] > 5 * MB_IN_BYTES ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			return new WP_Error( 'too_large', __( 'הקובץ גדול מ-5MB.', 'kivun' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Store CVs in a protected sub-directory, not the public uploads root.
		add_filter( 'upload_dir', array( __CLASS__, 'cv_upload_dir' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified above; wp_handle_upload() validates and sanitizes the file, restricted to the allowlist below.
		$file   = $_FILES['cv_file'];
		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $allowed,
			)
		);
		remove_filter( 'upload_dir', array( __CLASS__, 'cv_upload_dir' ) );

		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		return $upload['file'];
	}

	/**
	 * Redirect CV uploads into a private uploads sub-directory and ensure it is
	 * protected from direct web access.
	 *
	 * @param array $dirs The WordPress upload directory data.
	 * @return array The adjusted upload directory data.
	 */
	public static function cv_upload_dir( array $dirs ): array {
		$subdir = '/kivun-cv';
		$path   = $dirs['basedir'] . $subdir;

		self::protect_dir( $path );

		$dirs['subdir'] = $subdir;
		$dirs['path']   = $path;
		$dirs['url']    = $dirs['baseurl'] . $subdir;

		return $dirs;
	}

	/**
	 * Create a directory (if needed) and drop guards that block direct web
	 * access to its files on Apache/LiteSpeed servers.
	 *
	 * @param string $path Absolute directory path.
	 * @return void
	 */
	private static function protect_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time static guard file; WP_Filesystem is unavailable this early in the request.
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
		}

		$index = $path . '/index.html';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-time empty index to prevent directory listing; safe static write.
			file_put_contents( $index, '' );
		}
	}
}
