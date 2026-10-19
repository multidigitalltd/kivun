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

		$type = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer above.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; each value is cast to an integer here.
		$raw_ids  = wp_unslash( $_GET['post_id'] ?? 0 );
		$post_ids = array_values( array_filter( array_map( 'absint', is_array( $raw_ids ) ? $raw_ids : array( $raw_ids ) ) ) );
		$post_id  = $post_ids[0] ?? 0;

		// Optional publisher scope, used when a manager is "acting as" a publisher.
		$employer_id = absint( wp_unslash( $_GET['employer'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer above.

		// Each export type carries its own rule, so a user who is authorised for
		// one (say an editor exporting leads) is not judged by the other's.
		if ( 'registrations' === $type ) {
			// Course/lead enquiries — content managers and administrators.
			if ( ! Kivun_Content_Creator::can_manage_leads() ) {
				wp_die( 'Unauthorized' );
			}
			self::export_registrations( $post_ids, self::date_range() );
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

		if ( 'campaigns' === $type ) {
			// The same rule as the campaigns screen itself: this report is
			// that screen's numbers, counted.
			if ( ! Kivun_Campaigns::can_manage() ) {
				wp_die( 'Unauthorized' );
			}
			self::export_campaigns( self::date_range() );
			return;
		}

		if ( 'numbers' === $type ) {
			if ( ! Kivun_Phones::can_manage() ) {
				wp_die( 'Unauthorized' );
			}
			self::export_numbers( self::date_range() );
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

	/**
	 * The date range asked for, as two Y-m-d strings or ''.
	 *
	 * A report of everything ever is the one report nobody wants: "how did
	 * last month go" is the question, and until now the answer was to export
	 * all of it and cut it in Excel.
	 *
	 * @return array{from:string,to:string}
	 */
	private static function date_range(): array {
		$read = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified by the caller.
			$raw = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
		};

		return array(
			'from' => $read( 'from' ),
			'to'   => $read( 'to' ),
		);
	}

	/**
	 * The other cuts a report can take, beside the dates.
	 *
	 * @return array{type:string,campaign:string,campaign_id:int}
	 */
	private static function slices(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified by the caller.
		return array(
			'type'        => sanitize_key( wp_unslash( $_GET['ctype'] ?? '' ) ),
			'campaign'    => sanitize_text_field( wp_unslash( $_GET['campaign'] ?? '' ) ),
			'campaign_id' => absint( wp_unslash( $_GET['campaign_id'] ?? 0 ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The range as SQL against a datetime column, and the values for it.
	 *
	 * The closing day is included whole: somebody asking for a month that
	 * ends on the 31st means the 31st, not everything up to its midnight.
	 *
	 * @param array{from:string,to:string} $range  From date_range().
	 * @param string                       $column Qualified column name.
	 * @return array{sql:string,args:array<int,string>}
	 */
	private static function range_sql( array $range, string $column ): array {
		$sql  = '';
		$args = array();

		if ( '' !== $range['from'] ) {
			$sql   .= " AND {$column} >= %s";
			$args[] = $range['from'] . ' 00:00:00';
		}
		if ( '' !== $range['to'] ) {
			$sql   .= " AND {$column} <= %s";
			$args[] = $range['to'] . ' 23:59:59';
		}

		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * The range as something to put in a file name.
	 *
	 * @param array{from:string,to:string} $range From date_range().
	 * @return string
	 */
	private static function range_slug( array $range ): string {
		if ( '' === $range['from'] && '' === $range['to'] ) {
			return 'all';
		}

		return ( $range['from'] ? $range['from'] : 'start' ) . '_' . ( $range['to'] ? $range['to'] : gmdate( 'Y-m-d' ) );
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
	 * @param array<int,int>               $post_ids Course IDs to filter by; empty for all.
	 * @param array{from:string,to:string} $range    Optional date range on the lead.
	 * @return void
	 */
	private static function export_registrations( array $post_ids, array $range = array() ): void {
		global $wpdb;

		$range = $range ? $range : array(
			'from' => '',
			'to'   => '',
		);

		$where = 'WHERE 1=1';
		$args  = array();
		if ( $post_ids ) {
			$marks  = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
			$where .= " AND r.course_id IN ( {$marks} )";
			$args   = array_merge( $args, $post_ids );
		}

		$period = self::range_sql( $range, 'r.created_at' );
		$where .= $period['sql'];
		$args   = array_merge( $args, $period['args'] );

		$slice = self::slices();
		if ( '' !== $slice['type'] ) {
			$where .= ' AND r.type = %s';
			$args[] = $slice['type'];
		}
		if ( '' !== $slice['campaign'] ) {
			// Matched on the campaign's own utm value, which is what a lead
			// carries — the campaign's display name is not on the lead.
			$where .= ' AND r.utm_campaign = %s';
			$args[] = $slice['campaign'];
		}

		$sql = "SELECT r.*, p.post_title AS post_name
			 FROM {$wpdb->prefix}kivun_registrations r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id
			 {$where}
			 ORDER BY r.created_at DESC";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$args ? $wpdb->prepare( $sql, $args ) : $sql,
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$filename = ( $post_ids ? 'registrations-' . implode( '-', $post_ids ) : 'registrations' )
			. '-' . self::range_slug( $range ) . '.csv';

		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM for Excel.
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs

		// Where a lead came from is a question about the advertising, and the
		// reader who is not shown that column on screen is not handed it in a
		// spreadsheet either — otherwise the restriction is decoration.
		$show_source = Kivun_Content_Creator::can_see_lead_source();

		$headings = array( 'ID', 'קורס / סדנה' );
		if ( $show_source ) {
			// Each UTM value in a column of its own. They were already stored
			// that way and only ever left as one line of prose, which is
			// readable and cannot be summed, sorted or pivoted — the three
			// things a spreadsheet is opened for.
			$headings = array_merge( $headings, array( 'מקור', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content' ) );
		}
		$headings = array_merge( $headings, array( 'שם', 'אימייל', 'טלפון', 'עיר', 'מגדר', 'אישור דיוור', 'סוג', 'הערות', 'הערות פנימיות', 'סטטוס', 'תאריך' ) );

		fputcsv( $out, $headings );

		$type_labels = array(
			'registration' => 'הרשמה',
			'lead'         => 'מתעניין',
			'workshop'     => 'דף נחיתה',
			'session'      => 'סדנה',
			'event'        => 'אירוע',
			'form'         => 'טופס',
		);

		foreach ( $rows as $r ) {
			$line = array( $r['id'], $r['post_name'] );
			if ( $show_source ) {
				$line[] = $r['source'] ?? '';
				$line[] = $r['utm_source'] ?? '';
				$line[] = $r['utm_medium'] ?? '';
				$line[] = $r['utm_campaign'] ?? '';
				$line[] = $r['utm_content'] ?? '';
			}

			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array_merge(
						$line,
						array(
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

	// ── Campaigns CSV ─────────────────────────────────────────────────────────.

	/**
	 * Stream one row per campaign link: what it cost to look at, and what
	 * came back.
	 *
	 * A row per link rather than per campaign, because the link is the unit
	 * somebody is paid against — one campaign with four publishers under it
	 * is four questions, not one. The campaign name rides on every row, so
	 * the file pivots either way in a spreadsheet.
	 *
	 * The lead count is the same join the screen shows, so the report and
	 * the screen cannot disagree. Clicks are counted on the short link, so a
	 * link whose address was shared without going through it reads zero
	 * entries and still shows its leads.
	 *
	 * @param array{from:string,to:string} $range Date range, on the lead.
	 * @return void
	 */
	private static function export_campaigns( array $range ): void {
		$campaigns = Kivun_Campaigns::all();
		$links     = Kivun_Campaigns::links_by_campaign();
		$leads     = Kivun_Campaigns::link_lead_counts( $range['from'], $range['to'] );

		$only = self::slices()['campaign_id'];
		if ( $only ) {
			$campaigns = array_values(
				array_filter(
					$campaigns,
					static function ( $camp ) use ( $only ) {
						return (int) $camp->id === $only;
					}
				)
			);
		}

		self::send_headers( 'campaigns-' . self::range_slug( $range ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs

		fputcsv(
			$out,
			array(
				'קמפיין',
				'utm_campaign',
				'בארכיון',
				'מפרסם / קישור',
				'utm_source',
				'utm_medium',
				'utm_content',
				'קישור קצר',
				'כניסות',
				'לידים',
				'אחוז המרה',
				'נוצר',
			)
		);

		foreach ( $campaigns as $camp ) {
			foreach ( $links[ (int) $camp->id ] ?? array() as $link ) {
				$clicks = (int) ( $link->clicks ?? 0 );
				$count  = (int) ( $leads[ (int) $link->id ] ?? 0 );

				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'csv_safe' ),
						array(
							$camp->label,
							$camp->utm_campaign,
							Kivun_Campaigns::is_archived( $camp ) ? 'כן' : 'לא',
							$link->label,
							$link->utm_source,
							$link->utm_medium,
							$link->utm_content,
							Kivun_Short_Links::url_for( (string) ( $link->slug ?? '' ) ),
							$clicks,
							$count,
							// Left empty rather than shown as 0% where nobody
							// has clicked: a rate out of nothing is not zero,
							// it is unknown, and 0% reads as a verdict.
							$clicks > 0 ? round( $count / $clicks * 100, 1 ) . '%' : '',
							$link->created_at ?? '',
						)
					)
				);
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ── Tracking numbers CSV ──────────────────────────────────────────────────.

	/**
	 * Stream one row per tracking number assignment, with its calls counted.
	 *
	 * The call log already exports in full; this is the other question —
	 * not "which calls came in" but "what did each number bring", which is
	 * what a number is rented for.
	 *
	 * @param array{from:string,to:string} $range Date range, on the call.
	 * @return void
	 */
	private static function export_numbers( array $range ): void {
		global $wpdb;

		$period = self::range_sql( $range, 'k.started_at' );

		$sql = "SELECT a.id, n.number, n.label AS number_label, a.label AS assignment_label,
			       a.media, c.label AS campaign_label, c.utm_campaign, a.created_at,
			       COUNT( k.id ) AS calls,
			       SUM( CASE WHEN k.answered = 1 THEN 1 ELSE 0 END ) AS answered,
			       SUM( k.talk_time ) AS talk_time
			 FROM {$wpdb->prefix}kivun_phone_assignments a
			 LEFT JOIN {$wpdb->prefix}kivun_phone_numbers n ON n.id = a.number_id
			 LEFT JOIN {$wpdb->prefix}kivun_campaigns c ON c.id = a.campaign_id
			 LEFT JOIN {$wpdb->prefix}kivun_calls k ON k.assignment_id = a.id{$period['sql']}
			 GROUP BY a.id
			 ORDER BY n.number ASC, a.created_at DESC";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$period['args'] ? $wpdb->prepare( $sql, $period['args'] ) : $sql,
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		self::send_headers( 'numbers-' . self::range_slug( $range ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs

		// The answered columns only where the switchboard reports a talk
		// time. "Answered" is worked out from it, so on a switchboard that
		// sends only the start of a call every row would read as missed —
		// and a report that says every call went unanswered is not a
		// cautious report, it is a wrong one. The call log already draws
		// this line; the same line belongs here.
		$knows = Kivun_Phones::has_answer_data();

		$headings = array( 'מספר', 'שם המספר', 'שיוך', 'מדיה', 'קמפיין', 'utm_campaign', 'שיחות' );
		if ( $knows ) {
			$headings = array_merge( $headings, array( 'נענו', 'לא נענו', 'דקות שיחה' ) );
		}
		$headings[] = 'נוצר';

		fputcsv( $out, $headings );

		foreach ( (array) $rows as $r ) {
			$calls    = (int) $r['calls'];
			$answered = (int) $r['answered'];

			$line = array(
				$r['number'],
				$r['number_label'],
				$r['assignment_label'],
				$r['media'],
				$r['campaign_label'],
				$r['utm_campaign'],
				$calls,
			);

			if ( $knows ) {
				$line[] = $answered;
				$line[] = $calls - $answered;
				// Minutes, because an hour of calls in seconds is a number
				// nobody reads.
				$line[] = round( (int) $r['talk_time'] / 60, 1 );
			}

			$line[] = $r['created_at'];

			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $line ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
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
	public static function url( string $type, $post_id = 0, int $employer_id = 0 ): string {
		// A list as well as a single id, so the button can carry the same
		// choice the table is filtered by. A download narrower or wider than
		// what is on screen is worse than no button: it is read as the
		// screen, and nothing on it says otherwise.
		$ids = array_values( array_filter( array_map( 'absint', is_array( $post_id ) ? $post_id : array( $post_id ) ) ) );

		$args = array(
			'action'  => 'kivun_export_csv',
			'type'    => $type,
			'post_id' => 1 === count( $ids ) ? $ids[0] : $ids,
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
