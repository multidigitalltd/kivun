<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Export {

	public static function init(): void {
		add_action( 'admin_post_kivun_export_csv', [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		check_admin_referer( 'kivun_export' );

		$type    = sanitize_key( $_GET['type']    ?? '' );
		$post_id = absint( $_GET['post_id']       ?? 0 );

		// Employers may export applications, restricted to their own jobs.
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( $type !== 'applications' || ! current_user_can( 'kivun_employer' ) ) {
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

	// ── Registrations CSV ─────────────────────────────────────────────────────

	private static function export_registrations( int $post_id ): void {
		global $wpdb;

		$where = $post_id
			? $wpdb->prepare( 'WHERE r.course_id = %d', $post_id )
			: '';

		$rows = $wpdb->get_results(
			"SELECT r.*, p.post_title AS post_name
			 FROM {$wpdb->prefix}kivun_registrations r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id
			 $where
			 ORDER BY r.created_at DESC",
			ARRAY_A
		);

		$filename = $post_id
			? 'registrations-' . $post_id . '-' . date( 'Ymd' ) . '.csv'
			: 'registrations-all-' . date( 'Ymd' ) . '.csv';

		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM for Excel
		fputs( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, [ 'ID', 'קורס / סדנה', 'שם', 'אימייל', 'טלפון', 'סוג', 'הערות', 'הערות פנימיות', 'סטטוס', 'תאריך' ] );

		$type_labels = [ 'registration' => 'הרשמה', 'lead' => 'מתעניין', 'workshop' => 'סדנה' ];

		foreach ( $rows as $r ) {
			fputcsv( $out, [
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
			] );
		}

		fclose( $out );
		exit;
	}

	// ── Applications CSV ──────────────────────────────────────────────────────

	private static function export_applications( int $post_id, int $author_id = 0 ): void {
		global $wpdb;

		$conds = [];
		if ( $post_id ) {
			$conds[] = $wpdb->prepare( 'a.job_id = %d', $post_id );
		}
		// Restrict to a single employer's own jobs (frontend export).
		if ( $author_id ) {
			$conds[] = $wpdb->prepare( 'p.post_author = %d', $author_id );
		}
		$where = $conds ? 'WHERE ' . implode( ' AND ', $conds ) : '';

		$rows = $wpdb->get_results(
			"SELECT a.*, p.post_title AS job_name
			 FROM {$wpdb->prefix}kivun_applications a
			 LEFT JOIN {$wpdb->posts} p ON p.ID = a.job_id
			 $where
			 ORDER BY a.created_at DESC",
			ARRAY_A
		);

		$filename = $post_id
			? 'applications-' . $post_id . '-' . date( 'Ymd' ) . '.csv'
			: 'applications-all-' . date( 'Ymd' ) . '.csv';

		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, [ 'ID', 'משרה', 'שם', 'אימייל', 'טלפון', 'מכתב', 'קובץ קו"ח', 'הערות פנימיות', 'סטטוס', 'תאריך' ] );

		foreach ( $rows as $r ) {
			fputcsv( $out, [
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
			] );
		}

		fclose( $out );
		exit;
	}

	private static function send_headers( string $filename ): void {
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	// ── URL builders (used by admin metaboxes) ────────────────────────────────

	public static function url( string $type, int $post_id = 0 ): string {
		return wp_nonce_url(
			add_query_arg( [
				'action'  => 'kivun_export_csv',
				'type'    => $type,
				'post_id' => $post_id,
			], admin_url( 'admin-post.php' ) ),
			'kivun_export'
		);
	}
}
