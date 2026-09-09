<?php
/**
 * Call tracking for virtual phone numbers.
 *
 * A pool of virtual numbers all forward to one line. Each number is advertised
 * in one place for a period, then recycled for something else, so a number on
 * its own says nothing — the pairing of number AND date is what identifies the
 * advertisement a call came from.
 *
 * Assignments are therefore dated, and every call is stamped with the
 * assignment that was live when it arrived. Re-pointing a number tomorrow
 * cannot rewrite what last month's calls are credited to.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Numbers, their dated assignments, and the calls attributed to them.
 */
class Kivun_Phones {

	/**
	 * Where the telephony provider's webhook posts.
	 */
	const ROUTE_NAMESPACE = 'kivun/v1';

	/**
	 * What each provider calls the handful of fields that matter here.
	 *
	 * Only two are actually needed: the number that was dialled, which says
	 * which advertisement the call answers, and the caller's number. The rest
	 * are read when a provider sends them and skipped when it does not, so a
	 * switchboard configured to report only the start of a call is enough.
	 *
	 * Names are matched lower-cased. Adding a provider means adding its names
	 * here, not another branch in the handler.
	 *
	 * @var array<string,array<int,string>>
	 */
	const FIELDS = array(
		'call_id'     => array( 'callid', 'uniqueid', 'id' ),
		'direction'   => array( 'direction' ),
		'dialled'     => array( 'ddi', 'dnumber', 'cnumber', 'extension' ),
		'caller'      => array( 'cli', 'callerid', 'snumber' ),
		'caller_name' => array( 'callername' ),
		'talk_time'   => array( 'billsec', 'talktime' ),
		'total_time'  => array( 'duration', 'totaltime' ),
		'recording'   => array( 'dlink', 'dlinkdirect', 'recording' ),
		'started_at'  => array( 'startat', 'start' ),
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );

		add_action( 'wp_ajax_kivun_save_phone', array( __CLASS__, 'ajax_save_number' ) );
		add_action( 'wp_ajax_kivun_delete_phone', array( __CLASS__, 'ajax_delete_number' ) );
		add_action( 'wp_ajax_kivun_import_phones', array( __CLASS__, 'ajax_import' ) );
		add_action( 'wp_ajax_kivun_save_phone_assignment', array( __CLASS__, 'ajax_save_assignment' ) );
		add_action( 'wp_ajax_kivun_delete_phone_assignment', array( __CLASS__, 'ajax_delete_assignment' ) );
	}

	/**
	 * Who may manage number tracking — the same bar as the leads CRM, since
	 * calls are the offline half of the same reporting.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Kivun_Content_Creator::can_manage_leads();
	}

	/**
	 * Suggested advertising media, offered as autocomplete rather than as a
	 * closed list — the medium is a label on a report, not a key anything is
	 * matched by, so an unlisted one should not be a dead end.
	 *
	 * @return array<int,string>
	 */
	public static function media(): array {
		return array(
			__( 'עיתון', 'kivun' ),
			__( 'רדיו', 'kivun' ),
			__( 'שלט חוצות', 'kivun' ),
			__( 'פלייר / חלוקה', 'kivun' ),
			__( 'דיוור לתיבות', 'kivun' ),
			__( 'מגזין / עלון', 'kivun' ),
			__( 'פרסום על אוטובוסים', 'kivun' ),
			__( 'שילוט במקום', 'kivun' ),
			__( 'פרסום דיגיטלי', 'kivun' ),
			__( 'מדריך טלפונים', 'kivun' ),
			__( 'חסות / שיתוף פעולה', 'kivun' ),
		);
	}

	/**
	 * How a stored medium should read.
	 *
	 * Assignments saved before the field was free text hold a key rather than
	 * a label, so those are translated back; anything else is shown as typed.
	 *
	 * @param string $stored The stored value.
	 * @return string
	 */
	public static function media_label( string $stored ): string {
		$legacy = array(
			'newspaper'   => __( 'עיתון', 'kivun' ),
			'radio'       => __( 'רדיו', 'kivun' ),
			'billboard'   => __( 'שלט חוצות', 'kivun' ),
			'flyer'       => __( 'פלייר / חלוקה', 'kivun' ),
			'mailbox'     => __( 'דיוור לתיבות', 'kivun' ),
			'magazine'    => __( 'מגזין / עלון', 'kivun' ),
			'bus'         => __( 'פרסום על אוטובוסים', 'kivun' ),
			'sign'        => __( 'שילוט במקום', 'kivun' ),
			'digital'     => __( 'פרסום דיגיטלי', 'kivun' ),
			'phonebook'   => __( 'מדריך טלפונים', 'kivun' ),
			'sponsorship' => __( 'חסות / שיתוף פעולה', 'kivun' ),
			'other'       => __( 'אחר', 'kivun' ),
		);
		return $legacy[ $stored ] ?? $stored;
	}

	/**
	 * The secret the provider must present. Generated once, on first use.
	 *
	 * @return string
	 */
	public static function token(): string {
		$token = (string) get_option( 'kivun_calls_token', '' );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( 'kivun_calls_token', $token, false );
		}
		return $token;
	}

	/**
	 * The URL to paste into the provider's webhook settings.
	 *
	 * @return string
	 */
	public static function webhook_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . '/call' ) . '?token=' . rawurlencode( self::token() );
	}

	/**
	 * Reduce a number to digits so the stored form and the reported form match.
	 *
	 * A provider may report a number as 0722345678, 972722345678 or +972-72-234-5678
	 * depending on the route. Comparing only the last nine digits sidesteps the
	 * country code and the leading zero without guessing which form is in use.
	 *
	 * @param string $number Any phone number.
	 * @return string Digits only.
	 */
	public static function normalise( string $number ): string {
		return preg_replace( '/\D+/', '', $number ) ?? '';
	}

	/**
	 * The comparable tail of a number — the last nine digits.
	 *
	 * @param string $number Any phone number.
	 * @return string
	 */
	public static function tail( string $number ): string {
		$digits = self::normalise( $number );
		return mb_substr( $digits, -9 );
	}

	// ── Reading ───────────────────────────────────────────────────────────────.

	/**
	 * Every tracked number.
	 *
	 * @return array<int,object>
	 */
	public static function numbers(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kivun_phone_numbers ORDER BY is_active DESC, number ASC" );
	}

	/**
	 * Assignments grouped by number, newest period first.
	 *
	 * @return array<int,array<int,object>>
	 */
	public static function assignments_by_number(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT a.*, c.label AS campaign_label
			 FROM {$wpdb->prefix}kivun_phone_assignments a
			 LEFT JOIN {$wpdb->prefix}kivun_campaigns c ON c.id = a.campaign_id
			 ORDER BY a.starts_on DESC, a.id DESC"
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->number_id ][] = $row;
		}
		return $out;
	}

	/**
	 * Call counts per assignment.
	 *
	 * @return array<int,array{total:int,answered:int}>
	 */
	public static function call_counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT assignment_id, COUNT(*) AS total, SUM(answered) AS answered
			 FROM {$wpdb->prefix}kivun_calls
			 GROUP BY assignment_id"
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->assignment_id ] = array(
				'total'    => (int) $row->total,
				'answered' => (int) $row->answered,
			);
		}
		return $out;
	}

	/**
	 * The assignment covering a number on a given date.
	 *
	 * @param int    $number_id The number.
	 * @param string $date      Y-m-d.
	 * @return int Assignment id, or 0 when the number was unassigned that day.
	 */
	public static function assignment_on( int $number_id, string $date ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_phone_assignments
				 WHERE number_id = %d AND starts_on <= %s AND ( ends_on IS NULL OR ends_on >= %s )
				 ORDER BY starts_on DESC LIMIT 1",
				$number_id,
				$date,
				$date
			)
		);
	}

	// ── Writing ───────────────────────────────────────────────────────────────.

	/**
	 * Shared guard for the management actions.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל מספרי מעקב.', 'kivun' ) ) );
		}
	}

	/**
	 * Add a tracked number.
	 *
	 * @return void
	 */
	public static function ajax_save_number(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$number = self::normalise( sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) ) );
		$label  = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( mb_strlen( $number ) < 7 ) {
			wp_send_json_error( array( 'message' => __( 'יש להזין מספר טלפון תקין.', 'kivun' ) ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kivun_phone_numbers WHERE number = %s", $number ) );
		if ( $exists ) {
			wp_send_json_error( array( 'message' => __( 'המספר כבר קיים ברשימה.', 'kivun' ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'kivun_phone_numbers',
			array(
				'number' => $number,
				'label'  => $label,
			),
			array( '%s', '%s' )
		);

		wp_send_json_success( array( 'message' => __( 'המספר נוסף.', 'kivun' ) ) );
	}

	/**
	 * Import numbers from a CSV file.
	 *
	 * @return void
	 */
	public static function ajax_import(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.
		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'לא נבחר קובץ.', 'kivun' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Path from PHP's own upload handling.
		$tmp = $_FILES['file']['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'העלאת הקובץ נכשלה.', 'kivun' ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an upload, not a remote file.
		$raw = (string) file_get_contents( $tmp );
		if ( '' === trim( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'הקובץ ריק.', 'kivun' ) ) );
		}

		$rows = self::parse_csv( $raw );
		if ( ! $rows ) {
			wp_send_json_error( array( 'message' => __( 'לא נמצאו שורות בקובץ.', 'kivun' ) ) );
		}

		global $wpdb;
		$added   = 0;
		$skipped = 0;
		$invalid = 0;

		foreach ( $rows as $cells ) {
			// The number is whichever cell looks like one, so a file with an
			// index column, or with the label first, still imports.
			$number = '';
			$label  = '';
			foreach ( $cells as $cell ) {
				$digits = self::normalise( $cell );
				if ( '' === $number && mb_strlen( $digits ) >= 7 ) {
					$number = $digits;
					continue;
				}
				// A label is a cell with words in it. Anything else in the row —
				// a row number, a blank spacer column — is not one.
				if ( '' === $label && preg_match( '/\p{L}/u', $cell ) ) {
					$label = mb_substr( trim( $cell ), 0, 190 );
				}
			}

			if ( '' === $number ) {
				++$invalid;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kivun_phone_numbers WHERE number = %s", $number ) );
			if ( $exists ) {
				++$skipped;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'kivun_phone_numbers',
				array(
					'number' => $number,
					'label'  => $label,
				),
				array( '%s', '%s' )
			);
			++$added;
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: added, 2: skipped, 3: invalid. */
					__( 'יובאו %1$d מספרים. %2$d כבר היו קיימים, %3$d שורות לא הכילו מספר.', 'kivun' ),
					$added,
					$skipped,
					$invalid
				),
				'added'   => $added,
			)
		);
	}

	/**
	 * Convert a Hebrew CSV to UTF-8.
	 *
	 * Not every mbstring build knows the Windows-1255 name — asking it for one
	 * it does not have is a fatal error on PHP 8 — so the encodings it reports
	 * are tried first and iconv is the fallback. If neither can do it the bytes
	 * are returned unchanged: the numbers are ASCII digits and still import
	 * correctly, and only the labels come through garbled.
	 *
	 * @param string $raw The file's bytes.
	 * @return string
	 */
	private static function to_utf8( string $raw ): string {
		$known = mb_list_encodings();

		foreach ( array( 'Windows-1255', 'CP1255', 'ISO-8859-8' ) as $encoding ) {
			if ( in_array( $encoding, $known, true ) ) {
				$converted = mb_convert_encoding( $raw, 'UTF-8', $encoding );
				if ( is_string( $converted ) && mb_check_encoding( $converted, 'UTF-8' ) ) {
					return $converted;
				}
			}
		}

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'CP1255', 'UTF-8//IGNORE', $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unsupported charset warns; the fallback below handles it.
			if ( is_string( $converted ) && '' !== $converted ) {
				return $converted;
			}
		}

		return $raw;
	}

	/**
	 * Read a CSV export into rows of cells.
	 *
	 * Written against what spreadsheets actually produce rather than the ideal
	 * file: Excel on a Hebrew system writes windows-1255 and separates with a
	 * semicolon, and its UTF-8 export carries a byte-order mark that would
	 * otherwise become part of the first value.
	 *
	 * @param string $raw The uploaded file's contents.
	 * @return array<int,array<int,string>>
	 */
	public static function parse_csv( string $raw ): array {
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw;

		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = self::to_utf8( $raw );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = is_array( $lines ) ? $lines : array();
		$lines = array_values( array_filter( $lines, static fn( $line ) => '' !== trim( (string) $line ) ) );
		if ( ! $lines ) {
			return array();
		}

		// Whichever separator appears more often across the file is the one in
		// use; guessing from the first line alone misreads a header.
		$sample    = implode( "\n", array_slice( $lines, 0, 5 ) );
		$delimiter = substr_count( $sample, ';' ) > substr_count( $sample, ',' ) ? ';' : ',';

		$rows = array();
		foreach ( $lines as $line ) {
			$cells = str_getcsv( (string) $line, $delimiter );
			if ( $cells ) {
				$rows[] = array_map( static fn( $cell ) => sanitize_text_field( (string) $cell ), $cells );
			}
		}

		// A header row holds no number, so it falls out on its own when the
		// rows are scanned — nothing has to be assumed about its wording.
		return $rows;
	}

	/**
	 * Remove a number, its assignments, and detach its calls.
	 *
	 * @return void
	 */
	public static function ajax_delete_number(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// The calls themselves are kept: they happened, and deleting a number
		// from the list is a housekeeping act, not a reason to lose history.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_phone_assignments', array( 'number_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_phone_numbers', array( 'id' => $id ), array( '%d' ) );

		wp_send_json_success();
	}

	/**
	 * Assign a number to a campaign and medium for a period.
	 *
	 * @return void
	 */
	public static function ajax_save_assignment(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$number_id   = absint( wp_unslash( $_POST['number_id'] ?? 0 ) );
		$campaign_id = absint( wp_unslash( $_POST['campaign_id'] ?? 0 ) );
		$media       = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( wp_unslash( $_POST['media'] ?? '' ) ) ) ?? '' );
		$label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$starts      = self::as_date( sanitize_text_field( wp_unslash( $_POST['starts_on'] ?? '' ) ) );
		$ends        = self::as_date( sanitize_text_field( wp_unslash( $_POST['ends_on'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $number_id || '' === $starts ) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור מספר ותאריך התחלה.', 'kivun' ) ) );
		}
		if ( '' === $media ) {
			wp_send_json_error( array( 'message' => __( 'יש להזין מדיה.', 'kivun' ) ) );
		}
		if ( '' !== $ends && $ends < $starts ) {
			wp_send_json_error( array( 'message' => __( 'תאריך הסיום מוקדם מתאריך ההתחלה.', 'kivun' ) ) );
		}

		global $wpdb;

		// Two live assignments on one number would make a call ambiguous, and
		// the report would have to pick one arbitrarily. Refuse instead.
		$clash_end = '' !== $ends ? $ends : '9999-12-31';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$clash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_phone_assignments
				 WHERE number_id = %d
				   AND starts_on <= %s
				   AND COALESCE( ends_on, '9999-12-31' ) >= %s
				 LIMIT 1",
				$number_id,
				$clash_end,
				$starts
			)
		);
		if ( $clash ) {
			wp_send_json_error(
				array(
					'message' => __( 'התקופה חופפת לשיוך קיים על אותו מספר. סיימו את הקודם או בחרו תאריכים אחרים.', 'kivun' ),
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'kivun_phone_assignments',
			array(
				'number_id'   => $number_id,
				'campaign_id' => $campaign_id,
				'media'       => $media,
				'label'       => $label,
				'starts_on'   => $starts,
				'ends_on'     => '' !== $ends ? $ends : null,
				'created_by'  => get_current_user_id(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		$assignment_id = (int) $wpdb->insert_id;

		// Calls already recorded in this period arrived before anyone said what
		// the number was for. Now that it is known, credit them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}kivun_calls
				 SET assignment_id = %d
				 WHERE number_id = %d AND assignment_id = 0
				   AND DATE( started_at ) >= %s
				   AND DATE( started_at ) <= %s",
				$assignment_id,
				$number_id,
				$starts,
				'' !== $ends ? $ends : '9999-12-31'
			)
		);

		wp_send_json_success( array( 'message' => __( 'השיוך נשמר.', 'kivun' ) ) );
	}

	/**
	 * Delete an assignment. Calls credited to it become unattributed rather
	 * than being re-credited to whatever else covers those dates.
	 *
	 * @return void
	 */
	public static function ajax_delete_assignment(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'kivun_calls', array( 'assignment_id' => 0 ), array( 'assignment_id' => $id ), array( '%d' ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_phone_assignments', array( 'id' => $id ), array( '%d' ) );

		wp_send_json_success();
	}

	/**
	 * A Y-m-d date, or '' when the value is not one.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function as_date( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $raw );
		return ( $parsed && $parsed->format( 'Y-m-d' ) === $raw ) ? $raw : '';
	}

	// ── Webhook ───────────────────────────────────────────────────────────────.

	/**
	 * Register the endpoint the provider posts each call to.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/call',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_call' ),
				// The token is the credential; anyone holding it may post.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Record one incoming call.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public static function handle_call( \WP_REST_Request $request ): \WP_REST_Response {
		// The token may travel in the URL or in an Authorization header —
		// providers differ in what they let you set, and a header keeps the
		// secret out of access logs where one is offered.
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			$auth  = (string) $request->get_header( 'authorization' );
			$token = trim( (string) preg_replace( '/^Bearer\s+/i', '', $auth ) );
		}
		if ( ! hash_equals( self::token(), $token ) ) {
			return new \WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$p    = self::normalise_params( $request->get_params() );
		$pick = static function ( array $names ) use ( $p ): string {
			foreach ( $names as $name ) {
				if ( isset( $p[ $name ] ) && ! is_array( $p[ $name ] ) && '' !== trim( (string) $p[ $name ] ) ) {
					return (string) $p[ $name ];
				}
			}
			return '';
		};

		$call_id = sanitize_text_field( $pick( self::FIELDS['call_id'] ) );
		if ( '' === $call_id ) {
			return new \WP_REST_Response( array( 'error' => 'no call id' ), 400 );
		}

		// Only inbound calls are advertising responses; outbound and internal
		// legs on the same line are not. A provider that reports no direction
		// at all is taken at face value: its virtual numbers only ring inwards.
		$direction = strtolower( sanitize_text_field( $pick( self::FIELDS['direction'] ) ) );
		if ( '' !== $direction && 'inbound' !== $direction && 'in' !== $direction ) {
			return new \WP_REST_Response( array( 'ignored' => 'direction' ), 200 );
		}

		// Which of our numbers was dialled. Providers name this differently and
		// some report it twice — as routed and as presented — so every known
		// name is tried and the first that matches a number we track wins.
		$dialled = '';
		$number  = null;
		foreach ( self::FIELDS['dialled'] as $field ) {
			$raw = isset( $p[ $field ] ) && ! is_array( $p[ $field ] ) ? (string) $p[ $field ] : '';
			if ( '' === trim( $raw ) ) {
				continue;
			}
			$found = self::find_number( self::tail( $raw ) );
			if ( $found ) {
				$dialled = sanitize_text_field( $raw );
				$number  = $found;
				break;
			}
			if ( '' === $dialled ) {
				$dialled = sanitize_text_field( $raw );
			}
		}

		$raw_start  = $pick( self::FIELDS['started_at'] );
		$started_at = self::call_time( $raw_start );

		$talk  = (int) $pick( self::FIELDS['talk_time'] );
		$total = (int) $pick( self::FIELDS['total_time'] );

		$row = array(
			'number_id'     => $number ? (int) $number->id : 0,
			'assignment_id' => $number ? self::assignment_on( (int) $number->id, substr( $started_at, 0, 10 ) ) : 0,
			'call_id'       => $call_id,
			'dialled'       => $dialled,
			'caller'        => sanitize_text_field( $pick( self::FIELDS['caller'] ) ),
			'caller_name'   => sanitize_text_field( $pick( self::FIELDS['caller_name'] ) ),
			'answered'      => $talk > 0 ? 1 : 0,
			'total_time'    => max( 0, $total ),
			'talk_time'     => max( 0, $talk ),
			'recording'     => esc_url_raw( $pick( self::FIELDS['recording'] ) ),
			'started_at'    => $started_at,
		);

		global $wpdb;
		// A call can be reported more than once — several legs, a start and an
		// end, or a retry after a timeout. call_id is unique, so a later report
		// updates the first rather than counting the call twice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kivun_calls WHERE call_id = %s", $call_id ) );

		if ( $existing ) {
			// A later report carries less than the first — an end event names
			// no number and often no start time — so only what it actually
			// says is written. Otherwise the number dialled, and with it the
			// advertisement the call is credited to, would be wiped by the
			// second half of the same call.
			$update = array_filter(
				$row,
				static function ( $value ) {
					return '' !== $value && 0 !== $value;
				}
			);
			// The start time is what dates the call, and the assignment is
			// chosen by that date. Neither may be moved by a report that never
			// mentioned a time — a call beginning at 23:59 would otherwise be
			// re-credited to whatever the number advertised the next day.
			if ( '' === $raw_start ) {
				unset( $update['started_at'], $update['assignment_id'] );
			}

			if ( $update ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->prefix . 'kivun_calls', $update, array( 'id' => (int) $existing ) );
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $wpdb->prefix . 'kivun_calls', $row );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'matched' => (bool) $number,
			),
			200
		);
	}

	/**
	 * Lower-case every key of the posted payload.
	 *
	 * Providers capitalise their fields differently — "Cli", "cli", "CLI" —
	 * and the operator can often rename them, so nothing is matched on case.
	 *
	 * @param array<string,mixed> $params The posted parameters.
	 * @return array<string,mixed>
	 */
	private static function normalise_params( array $params ): array {
		$out = array();
		foreach ( $params as $key => $value ) {
			$out[ strtolower( (string) $key ) ] = $value;
		}
		return $out;
	}

	/**
	 * A reported call time as the table stores it: site time, 'Y-m-d H:i:s'.
	 *
	 * @param string $raw Unix timestamp, or a date the provider formatted.
	 * @return string
	 */
	private static function call_time( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return wp_date( 'Y-m-d H:i:s' );
		}

		if ( ctype_digit( $raw ) ) {
			return wp_date( 'Y-m-d H:i:s', (int) $raw );
		}

		// A written-out time is local to the switchboard. WordPress pins PHP
		// to UTC, so reading it without saying which zone it is in would date
		// every call by the offset — three hours, here.
		try {
			$when = new \DateTimeImmutable( $raw, wp_timezone() );
		} catch ( \Exception $e ) {
			return wp_date( 'Y-m-d H:i:s' );
		}

		return $when->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Find a tracked number by its comparable tail.
	 *
	 * @param string $tail Last nine digits.
	 * @return object|null
	 */
	private static function find_number( string $tail ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_phone_numbers WHERE RIGHT( number, 9 ) = %s LIMIT 1", $tail ) );
	}
}
