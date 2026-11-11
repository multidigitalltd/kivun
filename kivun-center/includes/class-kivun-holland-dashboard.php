<?php
/**
 * The Holland questionnaire's own dashboard: who took it, how far they got,
 * their code, and the printable report.
 *
 * Kept apart from the content-management console on purpose — the client
 * asked for the questionnaire's results to be managed on a screen of their
 * own, not among the site's leads. The console and the wp-admin leads table
 * leave Holland rows out; this is where they are.
 *
 * Put [kivun_holland_dashboard] on a page of its own. Like the questionnaire,
 * it is drawn without the site's header and footer.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * The [kivun_holland_dashboard] shortcode.
 */
class Kivun_Holland_Dashboard {

	/**
	 * Rows to a page.
	 */
	const PER_PAGE = 25;

	/**
	 * Where the dashboard's page is remembered, for the link in the email.
	 */
	const PAGE_OPTION = 'kivun_holland_dashboard_page';

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'kivun_holland_dashboard', array( __CLASS__, 'render' ) );
	}

	/**
	 * The dashboard's address, once it has been opened on its page.
	 *
	 * @return string '' when there is none.
	 */
	public static function url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		return (string) get_permalink( $page_id );
	}

	/**
	 * What the dashboard was asked for: the search, the state and the page.
	 *
	 * @return array{q:string,state:string,page:int}
	 */
	public static function request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only filter on a page; nothing is changed.
		$state = sanitize_key( wp_unslash( $_GET['hl_state'] ?? '' ) );
		$out   = array(
			'q'     => trim( sanitize_text_field( wp_unslash( $_GET['hl_q'] ?? '' ) ) ),
			'state' => in_array( $state, array( 'done', 'open' ), true ) ? $state : '',
			'page'  => max( 1, absint( wp_unslash( $_GET['hl_page'] ?? 1 ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $out;
	}

	/**
	 * The WHERE clause for a search and a state.
	 *
	 * The name and the phone are columns of their own. The ID number is the
	 * first line of the row's summary ("תעודת זהות: 123456782"), so a search
	 * of digits is matched there, at every place inside the nine digits — and
	 * only there, so it does not catch a score further down.
	 *
	 * @param string $q     The search.
	 * @param string $state '', 'done' or 'open'.
	 * @return string
	 */
	public static function where( string $q, string $state ): string {
		global $wpdb;

		$conds = array( $wpdb->prepare( 'type = %s', Kivun_Holland::LEAD_TYPE ) );

		$done = '%' . $wpdb->esc_like( '{"key":"holland_done","value":"1"}' ) . '%';
		if ( 'done' === $state ) {
			$conds[] = $wpdb->prepare( 'answers LIKE %s', $done );
		} elseif ( 'open' === $state ) {
			$conds[] = $wpdb->prepare( 'answers NOT LIKE %s', $done );
		}

		if ( '' !== $q ) {
			$any    = array( $wpdb->prepare( 'name LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' ) );
			$digits = (string) preg_replace( '/\D+/', '', $q );
			if ( '' !== $digits ) {
				$any[] = $wpdb->prepare( 'phone LIKE %s', '%' . $wpdb->esc_like( $digits ) . '%' );
			}
			if ( '' !== $digits && strlen( $digits ) <= 9 ) {
				$head = $wpdb->esc_like( __( 'תעודת זהות', 'kivun' ) . ': ' );
				$room = 9 - strlen( $digits );
				for ( $skip = 0; $skip <= $room; $skip++ ) {
					$any[] = $wpdb->prepare( 'message LIKE %s', $head . str_repeat( '_', $skip ) . $wpdb->esc_like( $digits ) . '%' );
				}
			}
			$conds[] = '( ' . implode( ' OR ', $any ) . ' )';
		}

		return 'WHERE ' . implode( ' AND ', $conds );
	}

	/**
	 * One row as the dashboard shows it.
	 *
	 * @param object $row A lead row: id, name, phone, created_at, answers.
	 * @return array<string,mixed>
	 */
	public static function row( $row ): array {
		$stored   = Kivun_Holland::read_stored( (string) ( $row->answers ?? '' ) );
		$answered = Kivun_Holland::answered( $stored['responses'] );
		$code     = $stored['done'] ? Kivun_Holland::score( $stored['responses'] )['code'] : '';
		$started  = (string) ( $row->created_at ?? '' );

		return array(
			'id'       => (int) ( $row->id ?? 0 ),
			'name'     => (string) ( $row->name ?? '' ),
			'phone'    => (string) ( $row->phone ?? '' ),
			'id_no'    => $stored['id'],
			'started'  => '' !== $started ? kivun_local_date( $started, 'j.n.Y' ) : '',
			'finished' => '' !== $stored['done_at'] ? kivun_local_date( $stored['done_at'], 'j.n.Y' ) : '',
			'done'     => $stored['done'],
			'answered' => $answered,
			'code'     => $code,
			'report'   => $stored['done'] ? Kivun_Holland::report_url( (int) ( $row->id ?? 0 ) ) : '',
		);
	}

	/**
	 * The shortcode.
	 *
	 * @return string
	 */
	public static function render(): string {
		Kivun_Core::no_cache();
		wp_enqueue_style( 'kivun-holland' );

		if ( ! is_user_logged_in() ) {
			return self::template(
				'gate',
				array(
					'message' => __( 'יש להתחבר כדי לצפות בתוצאות השאלונים.', 'kivun' ),
					'login'   => wp_login_url( (string) get_permalink() ),
				)
			);
		}
		if ( ! Kivun_Content_Creator::can_manage_leads() ) {
			return self::template(
				'gate',
				array(
					'message' => __( 'אין לך הרשאה לצפות בתוצאות השאלונים.', 'kivun' ),
					'login'   => '',
				)
			);
		}

		// Remembered, so the email to the representative can link here.
		$page_id = (int) get_the_ID();
		if ( $page_id > 0 && (int) get_option( self::PAGE_OPTION, 0 ) !== $page_id ) {
			update_option( self::PAGE_OPTION, $page_id, false );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'kivun_registrations';
		$req   = self::request();
		$where = self::where( $req['q'], $req['state'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared parts above.
		$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$pages = max( 1, (int) ceil( $found / self::PER_PAGE ) );
		$page  = min( $req['page'], $pages );
		$rows  = $wpdb->get_results(
			"SELECT id, name, phone, created_at, answers FROM {$table} {$where} ORDER BY created_at DESC, id DESC "
			. $wpdb->prepare( 'LIMIT %d OFFSET %d', self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE )
		);
		$every = self::where( '', '' );
		$ended = self::where( '', 'done' );
		$all   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$every}" );
		$done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$ended}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::template(
			'list',
			array(
				'rows'   => array_map( array( __CLASS__, 'row' ), (array) $rows ),
				'req'    => $req,
				'page'   => $page,
				'pages'  => $pages,
				'found'  => $found,
				'stats'  => array(
					'all'  => $all,
					'done' => $done,
					'open' => max( 0, $all - $done ),
				),
				'base'   => (string) get_permalink(),
				'export' => wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'kivun_export_csv',
							'type'   => 'registrations',
							'ctype'  => Kivun_Holland::LEAD_TYPE,
						),
						admin_url( 'admin-post.php' )
					),
					'kivun_export'
				),
				'total'  => Kivun_Holland::total(),
				'colors' => Kivun_Holland::type_colors(),
				'logo'   => Kivun_Holland::logo_url(),
			)
		);
	}

	/**
	 * Draw the dashboard's template.
	 *
	 * @param string              $view 'gate' or 'list'.
	 * @param array<string,mixed> $vars What the template expects.
	 * @return string
	 */
	private static function template( string $view, array $vars ): string {
		ob_start();
		kivun_get_template( 'holland/dashboard.php', array_merge( $vars, array( 'view' => $view ) ) );
		return Kivun_Content_Creator::flatten_output( (string) ob_get_clean() );
	}
}
