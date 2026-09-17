<?php
/**
 * CSV export of registrations and job applications.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles CSV exports for registrations and applications.
 */
class Kivun_Export {

	/**
	 * Register the admin-post export handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_kivun_export_csv', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle an export request and dispatch by type.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_admin_referer( 'kivun_export' );

		$type    = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer above.
		$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer above.

		// Optional publisher scope, used when a manager is "acting as" a publisher.
		$employer_id = absint( wp_unslash( $_GET['employer'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer above.

		// Each export type carries its own rule, so a user who is authorised for
		// one (say an editor exporting leads) is not judged by the other's.
		if ( 'registrations' === $type ) {
			// Course/lead enquiries — content managers and administrators.
			if ( ! Kivun_Content_Creator::can_manage_leads() ) {
				wp_die( 'Unauthorized' );
			}
			self::export_registrations( $post_id );
			return;
		}

		if ( 'applications' === $type ) {
			// Whole board for admins and the jobs-board manager; a plain
			// employer is restricted to applications for their own jobs.
			if ( Kivun_Employer::can_manage_all() ) {
				self::export_applications( $post_id, $employer_id );
				return;
			}
			if ( ! current_user_can( 'kivun_employer' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
				wp_die( 'Unauthorized' );
			}
			self::export_applications( $post_id, get_current_user_id() );
			return;
		}

		if ( 'calls' === $type ) {
			// The call screen's own rule: whoever runs the tracking numbers.
			if ( ! Kivun_Phones::can_manage() ) {
				wp_die( 'Unauthorized' );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer above.
			self::export_calls( Kivun_Phones::filters( wp_unslash( $_GET ) ) );
			return;
		}

		wp_die( 'Invalid type' );
	}

	// ── Calls CSV ─────────────────────────────────────────────────────────────.

	/**
	 * Stream the tracked calls as a CSV download.
	 *
	 * Takes the same filters the screen was showing, so what downloads is what
	 * was on screen — a report of one campaign's calls, not the whole log with
	 * the filtering left to whoever opens the file.
	 *
	 * @param array<string,mixed> $filters From Kivun_Phones::filters().
	 * @return void
	 */
	private static function export_calls( array $filters ): void {
		// Everything matching, not one page of it — an export that stopped at
		// the page boundary would quietly under-report.
		$log = Kivun_Phones::calls( $filters, 200, 1 );

		self::send_headers( 'calls-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM for Excel.
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs

		// The answer columns only where the switchboard reports a talk time. It
		// is worked out from that, so without it every row would read "לא" and
		// the file would say something untrue about every call in it.
		$knows = Kivun_Phones::has_answer_data();

		$headings = array( 'מועד', 'מתקשר', 'שם המתקשר', 'חייג אל', 'שם המספר', 'קמפיין', 'מדיה' );
		if ( $knows ) {
			$headings[] = 'נענתה';
			$headings[] = 'משך שיחה (שניות)';
			$headings[] = 'זמן דיבור (שניות)';
		}

		fputcsv( $out, $headings );

		$page  = 1;
		$found = (int) $log['found'];
		do {
			foreach ( $log['rows'] as $call ) {
				$line = array(
					$call->started_at,
					$call->caller,
					$call->caller_name,
					$call->number ? $call->number : $call->dialled,
					$call->number_label,
					$call->campaign_label,
					$call->media ? Kivun_Phones::media_label( (string) $call->media ) : '',
				);

				if ( $knows ) {
					$line[] = empty( $call->answered ) ? 'לא' : 'כן';
					$line[] = $call->total_time;
					$line[] = $call->talk_time;
				}

				fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $line ) );
			}

			++$page;
			$log = ( $page - 1 ) * 200 < $found ? Kivun_Phones::calls( $filters, 200, $page ) : array( 'rows' => array() );
		} while ( $log['rows'] );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ── Registrations CSV ─────────────────────────────────────────────────────.

	/**
	 * Stream registrations as a CSV download.
	 *
	 * @param int $post_id Optional course ID to filter by, 0 for all.
	 * @return void
	 */
	private static function export_registrations( int $post_id ): void {
		global $wpdb;

		$where = $post_id
			? $wpdb->prepare( 'WHERE r.course_id = %d', $post_id )
			: '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT r.*, p.post_title AS post_name
			 FROM {$wpdb->prefix}kivun_registrations r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id
			 $where
			 ORDER BY r.created_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$filename = $post_id
			? 'registrations-' . $post_id . '-' . gmdate( 'Ymd' ) . '.csv'
			: 'registrations-all-' . gmdate( 'Ymd' ) . '.csv';

		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM for Excel.
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs

		fputcsv( $out, array( 'ID', 'קורס / סדנה', 'מקור', 'שם', 'אימייל', 'טלפון', 'עיר', 'מגדר', 'אישור דיוור', 'סוג', 'הערות', 'הערות פנימיות', 'סטטוס', 'תאריך' ) );

		$type_labels = array(
			'registration' => 'הרשמה',
			'lead'         => 'מתעניין',
			'workshop'     => 'דף נחיתה',
			'session'      => 'סדנה',
			'event'        => 'אירוע',
			'form'         => 'טופס',
		);

		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$r['id'],
						$r['post_name'],
						$r['source'] ?? '',
						$r['name'],
						$r['email'],
						$r['phone'],
						$r['city'] ?? '',
						$r['gender'] ?? '',
						empty( $r['marketing_consent'] ) ? 'לא' : 'כן',
						$type_labels[ $r['type'] ] ?? $r['type'],
						$r['message'],
						$r['notes'],
						$r['status'],
						$r['created_at'],
					)
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ── Applications CSV ──────────────────────────────────────────────────────.

	/**
	 * Stream job applications as a CSV download.
	 *
	 * @param int $post_id   Optional job ID to filter by, 0 for all.
	 * @param int $author_id Optional author ID to restrict to an employer's own jobs.
	 * @return void
	 */
	private static function export_applications( int $post_id, int $author_id = 0 ): void {
		global $wpdb;

		$conds = array();
		if ( $post_id ) {
			$conds[] = $wpdb->prepare( 'a.job_id = %d', $post_id );
		}
		// Restrict to a single employer's own jobs (frontend export).
		if ( $author_id ) {
			$conds[] = $wpdb->prepare( 'p.post_author = %d', $author_id );
		}
		$where = $conds ? 'WHERE ' . implode( ' AND ', $conds ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT a.*, p.post_title AS job_name
			 FROM {$wpdb->prefix}kivun_applications a
			 LEFT JOIN {$wpdb->posts} p ON p.ID = a.job_id
			 $where
			 ORDER BY a.created_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$filename = $post_id
			? 'applications-' . $post_id . '-' . gmdate( 'Ymd' ) . '.csv'
			: 'applications-all-' . gmdate( 'Ymd' ) . '.csv';

		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs

		fputcsv( $out, array( 'ID', 'משרה', 'שם', 'אימייל', 'טלפון', 'מכתב', 'קובץ קו"ח', 'הערות פנימיות', 'סטטוס', 'תאריך' ) );

		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$r['id'],
						$r['job_name'],
						$r['applicant_name'],
						$r['applicant_email'],
						$r['applicant_phone'],
						$r['message'],
						$r['cv_file'] ? basename( $r['cv_file'] ) : '',
						$r['notes'],
						$r['status'],
						$r['created_at'],
					)
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralise CSV formula injection: a leading =, +, -, @, tab or CR can be
	 * executed as a formula by spreadsheet software, so prefix such values
	 * with an apostrophe.
	 *
	 * @param mixed $value The raw cell value.
	 * @return string The safe cell value.
	 */
	private static function csv_safe( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Emit the HTTP headers for a CSV file download.
	 *
	 * @param string $filename The download filename.
	 * @return void
	 */
	private static function send_headers( string $filename ): void {
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	// ── URL builders (used by admin metaboxes) ────────────────────────────────.

	/**
	 * Build a nonced export URL for use in admin metaboxes.
	 *
	 * @param string $type        The export type ('registrations' or 'applications').
	 * @param int    $post_id     Optional post ID to scope the export.
	 * @param int    $employer_id Optional publisher ID to scope applications to.
	 * @return string
	 */
	public static function url( string $type, int $post_id = 0, int $employer_id = 0 ): string {
		$args = array(
			'action'  => 'kivun_export_csv',
			'type'    => $type,
			'post_id' => $post_id,
		);
		if ( $employer_id ) {
			$args['employer'] = $employer_id;
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			'kivun_export'
		);
	}
}
