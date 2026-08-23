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
	 * Where the 015 webhook posts.
	 */
	const ROUTE_NAMESPACE = 'kivun/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );

		add_action( 'wp_ajax_kivun_save_phone', array( __CLASS__, 'ajax_save_number' ) );
		add_action( 'wp_ajax_kivun_delete_phone', array( __CLASS__, 'ajax_delete_number' ) );
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
	 * The advertising media a number can be published in.
	 *
	 * A managed list rather than free text: "עיתון בשבע" and "בשבע" typed on
	 * different days would split one channel across two rows in every report,
	 * and there is no way to merge them afterwards.
	 *
	 * @return array<string,string>
	 */
	public static function media(): array {
		return array(
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
	}

	/**
	 * The secret 015 must present. Generated once, on first use.
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
	 * The URL to paste into the 015 web-url template.
	 *
	 * @return string
	 */
	public static function webhook_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . '/call' ) . '?token=' . rawurlencode( self::token() );
	}

	/**
	 * Reduce a number to digits so the stored form and the reported form match.
	 *
	 * 015 may report a number as 0722345678, 972722345678 or +972-72-234-5678
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
		$media       = sanitize_key( wp_unslash( $_POST['media'] ?? '' ) );
		$label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$starts      = self::as_date( sanitize_text_field( wp_unslash( $_POST['starts_on'] ?? '' ) ) );
		$ends        = self::as_date( sanitize_text_field( wp_unslash( $_POST['ends_on'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $number_id || '' === $starts ) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור מספר ותאריך התחלה.', 'kivun' ) ) );
		}
		if ( ! isset( self::media()[ $media ] ) ) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור מדיה.', 'kivun' ) ) );
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
	 * Register the endpoint 015 posts each call to.
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
		$token = (string) $request->get_param( 'token' );
		if ( ! hash_equals( self::token(), $token ) ) {
			return new \WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		// The template is ours to define, so both encodings are accepted and
		// the field names are the ones configured in 015.
		$p = $request->get_params();

		$call_id = sanitize_text_field( (string) ( $p['callid'] ?? $p['uniqueid'] ?? '' ) );
		if ( '' === $call_id ) {
			return new \WP_REST_Response( array( 'error' => 'no call id' ), 400 );
		}

		// Only inbound calls are advertising responses; outbound and internal
		// legs on the same line are not.
		$direction = sanitize_text_field( (string) ( $p['direction'] ?? 'inbound' ) );
		if ( 'inbound' !== $direction && '' !== $direction ) {
			return new \WP_REST_Response( array( 'ignored' => 'direction' ), 200 );
		}

		// Which of our numbers was dialled. dnumber is the destination that
		// triggered the webhook; cnumber is the number as presented. They are
		// usually the same, but not on every route, so both are tried.
		$dialled = '';
		$number  = null;
		foreach ( array( 'dnumber', 'cnumber', 'extension' ) as $field ) {
			$candidate = self::tail( (string) ( $p[ $field ] ?? '' ) );
			if ( '' === $candidate ) {
				continue;
			}
			$found = self::find_number( $candidate );
			if ( $found ) {
				$dialled = sanitize_text_field( (string) $p[ $field ] );
				$number  = $found;
				break;
			}
			if ( '' === $dialled ) {
				$dialled = sanitize_text_field( (string) $p[ $field ] );
			}
		}

		$start = isset( $p['start'] ) ? (int) $p['start'] : 0;
		$start = $start > 0 ? $start : time();
		// 015 reports Unix timestamps; the table stores site time.
		$started_at = wp_date( 'Y-m-d H:i:s', $start );

		$talk = isset( $p['talktime'] ) ? (int) $p['talktime'] : 0;

		$row = array(
			'number_id'     => $number ? (int) $number->id : 0,
			'assignment_id' => $number ? self::assignment_on( (int) $number->id, substr( (string) $started_at, 0, 10 ) ) : 0,
			'call_id'       => $call_id,
			'dialled'       => $dialled,
			'caller'        => sanitize_text_field( (string) ( $p['callerid'] ?? $p['snumber'] ?? '' ) ),
			'caller_name'   => sanitize_text_field( (string) ( $p['callername'] ?? '' ) ),
			'answered'      => $talk > 0 ? 1 : 0,
			'total_time'    => isset( $p['totaltime'] ) ? absint( $p['totaltime'] ) : 0,
			'talk_time'     => max( 0, $talk ),
			'recording'     => esc_url_raw( (string) ( $p['recording'] ?? '' ) ),
			'started_at'    => $started_at,
		);

		global $wpdb;
		// A call can be reported more than once — several legs, or a retry after
		// a timeout. call_id is unique, so the second report updates the first
		// rather than counting the call twice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kivun_calls WHERE call_id = %s", $call_id ) );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->prefix . 'kivun_calls', $row, array( 'id' => (int) $existing ) );
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
