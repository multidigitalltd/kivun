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

		// Employers may export applications, restricted to their own jobs.
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( 'applications' !== $type || ! current_user_can( 'kivun_employer' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom plugin capability.
				wp_die( 'Unauthorized' );
			}
			self::export_applications( $post_id, get_current_user_id() );
			return;
		}

		match ( $type ) {
			'registrations' => self::export_registrations( $post_id ),
			'applications'  => self::export_applications( $post_id ),
			default         => wp_die( 'Invalid type' ),
		};
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

		fputcsv( $out, array( 'ID', 'קורס / סדנה', 'שם', 'אימייל', 'טלפון', 'סוג', 'הערות', 'הערות פנימיות', 'סטטוס', 'תאריך' ) );

		$type_labels = array(
			'registration' => 'הרשמה',
			'lead'         => 'מתעניין',
			'workshop'     => 'סדנה',
		);

		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['id'],
					$r['post_name'],
					$r['name'],
					$r['email'],
					$r['phone'],
					$type_labels[ $r['type'] ] ?? $r['type'],
					$r['message'],
					$r['notes'],
					$r['status'],
					$r['created_at'],
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
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
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
	 * @param string $type    The export type ('registrations' or 'applications').
	 * @param int    $post_id Optional post ID to scope the export.
	 * @return string
	 */
	public static function url( string $type, int $post_id = 0 ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'kivun_export_csv',
					'type'    => $type,
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'kivun_export'
		);
	}
}
