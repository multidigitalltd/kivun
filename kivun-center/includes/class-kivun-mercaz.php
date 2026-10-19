<?php
/**
 * Client for the Mercaz Kivun REST API.
 *
 * Both the content API and the jobs API are plain WordPress REST endpoints
 * behind HTTP Basic auth with an Application Password. This class owns the
 * transport, the credentials, and the diagnostics — the field mapping for each
 * content type is built on top of it.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Mercaz Kivun site.
 */
class Kivun_Mercaz {

	/**
	 * The Mercaz Kivun API base. It is fixed, so it is not asked for: the jobs
	 * documentation is published on a staging host but the API itself is not.
	 * Override with the kivun_mercaz_base_url filter if that ever changes.
	 */
	const DEFAULT_BASE = 'https://mercaz-kivun.co.il/wp-json/wp/v2';

	/**
	 * Credentials to use instead of the stored ones, for testing values that
	 * are typed into the settings form but not saved yet.
	 *
	 * @var array{user:string,pass:string}|null
	 */
	private static $override;

	/**
	 * Use these credentials for the rest of the request.
	 *
	 * @param string $user Username.
	 * @param string $pass Application password.
	 * @return void
	 */
	public static function use_credentials( string $user, string $pass ): void {
		$user = trim( $user );
		$pass = trim( $pass );

		self::$override = ( '' !== $user && '' !== $pass )
			? array(
				'user' => $user,
				'pass' => $pass,
			)
			: null;
	}

	/**
	 * Register the admin-side AJAX handlers.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_mercaz_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_kivun_mercaz_vocab', array( __CLASS__, 'ajax_vocab' ) );
		add_action( 'wp_ajax_kivun_mercaz_adopt', array( __CLASS__, 'ajax_adopt' ) );
		add_action( 'wp_ajax_kivun_mercaz_sync_vocab', array( __CLASS__, 'ajax_sync_vocab' ) );

		// Their list is the list. Pulled nightly so a value they add on Sunday
		// is choosable here on Monday, rather than discovered by a job being
		// refused weeks later.
		add_action( 'kivun_daily', array( __CLASS__, 'sync_vocabularies' ) );

		// The term screens are where somebody would rename one of these by
		// hand, in good faith, and quietly stop its jobs from sending.
		foreach ( self::vocabularies() as $kivun_vocab_taxonomy ) {
			add_action( $kivun_vocab_taxonomy . '_pre_add_form', array( __CLASS__, 'vocabulary_notice' ) );
		}
	}

	/**
	 * The API base for a given content type.
	 *
	 * @param string $type Content type key.
	 * @return string Base URL with no trailing slash, or '' when unconfigured.
	 */
	public static function base_url( string $type = '' ): string {
		$saved = trim( (string) Kivun_Admin_Settings::get( 'mercaz_url', '' ) );
		$base  = '' !== $saved ? $saved : self::DEFAULT_BASE;

		/**
		 * Filter the API base, per content type.
		 *
		 * @param string $base The base URL.
		 * @param string $type Content type key.
		 */
		return untrailingslashit( (string) apply_filters( 'kivun_mercaz_base_url', $base, $type ) );
	}

	/**
	 * Whether credentials and a base URL are present.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return '' !== trim( (string) Kivun_Admin_Settings::get( 'mercaz_user', '' ) )
			&& '' !== trim( (string) Kivun_Admin_Settings::get( 'mercaz_pass', '' ) );
	}

	/**
	 * Perform a request against the API.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path below the base, e.g. 'jobs' or 'users/me'.
	 * @param array<string,mixed>      $query  Query parameters.
	 * @param array<string,mixed>|null $body   JSON body, or null for none.
	 * @param string                   $type   Content type key, to pick the right base URL.
	 * @return array<string,mixed>|\WP_Error Decoded body on success.
	 */
	public static function request( string $method, string $path, array $query = array(), $body = null, string $type = '' ) {
		$base = self::base_url( $type );
		if ( '' === $base ) {
			return new \WP_Error( 'kivun_mercaz_unconfigured', __( 'לא הוגדרה כתובת ל-API של מרכז כיוון.', 'kivun' ) );
		}

		$user = self::$override ? self::$override['user'] : trim( (string) Kivun_Admin_Settings::get( 'mercaz_user', '' ) );
		$pass = self::$override ? self::$override['pass'] : trim( (string) Kivun_Admin_Settings::get( 'mercaz_pass', '' ) );
		if ( '' === $user || '' === $pass ) {
			return new \WP_Error( 'kivun_mercaz_unconfigured', __( 'לא הוגדרו פרטי התחברות ל-API של מרכז כיוון.', 'kivun' ) );
		}

		$url = $base . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				// Application passwords are shown with spaces; the server accepts
				// them either way, but stripping avoids a copy-paste failure.
				'Authorization' => 'Basic ' . base64_encode( $user . ':' . str_replace( ' ', '', $pass ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by HTTP Basic auth.
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'kivun_mercaz_http_' . $code,
				self::friendly_error( $code, $data ),
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		return array(
			'data'    => $data,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Turn an API failure into something a content manager can act on.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $data Decoded response body.
	 * @return string
	 */
	private static function friendly_error( int $code, $data ): string {
		$api_code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
		$message  = is_array( $data ) && isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '';

		// The documented refusals, named so the cause is obvious.
		$known = array(
			'rest_cannot_edit_others'       => __( 'התוכן שייך למשתמש אחר — רק היוצר שלו יכול לערוך אותו.', 'kivun' ),
			'ckvn_invalid_content_category' => __( 'מאמר חייב לשאת בדיוק קטגוריה אחת מהשתיים המותרות.', 'kivun' ),
			'ckvn_forbidden_media'          => __( 'התמונה שייכת למשתמש אחר. יש להעלות אותה מחדש דרך החשבון הזה.', 'kivun' ),
			'rest_invalid_param'            => __( 'אחד הערכים שנשלחו אינו תקין.', 'kivun' ),
		);
		if ( '' !== $api_code && isset( $known[ $api_code ] ) ) {
			return $known[ $api_code ];
		}

		switch ( $code ) {
			case 401:
				return __( 'ההתחברות נכשלה (401). בדקו את שם המשתמש ואת סיסמת היישום.', 'kivun' );
			case 403:
				return '' !== $message
					? sprintf( /* translators: %s: server message. */ __( 'הפעולה נדחתה (403): %s', 'kivun' ), $message )
					: __( 'הפעולה נדחתה (403) — כנראה חוסר הרשאה לפריט או לשדה שנשלח.', 'kivun' );
			case 404:
				return __( 'הכתובת לא נמצאה (404). בדקו את כתובת ה-API.', 'kivun' );
			default:
				return '' !== $message
					? sprintf( /* translators: 1: HTTP code, 2: server message. */ __( 'שגיאה %1$d: %2$s', 'kivun' ), $code, $message )
					: sprintf( /* translators: %d: HTTP code. */ __( 'שגיאה %d מהשרת.', 'kivun' ), $code );
		}
	}

	// ── Diagnostics ───────────────────────────────────────────────────────────.

	/**
	 * Verify the credentials and report who they belong to.
	 *
	 * The roles matter beyond a green tick: on the jobs API a job_manager must
	 * not send region-country at all (it is derived from their profile and
	 * sending it is a 403), while an administrator or editor must send it.
	 * Reading the role here means the integration can follow the right rule
	 * instead of being told which one to assume.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function test_connection() {
		$result = self::request( 'GET', 'users/me', array( 'context' => 'edit' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$me = $result['data'];
		return array(
			'id'    => (int) ( $me['id'] ?? 0 ),
			'name'  => (string) ( $me['name'] ?? '' ),
			'slug'  => (string) ( $me['slug'] ?? '' ),
			'roles' => array_map( 'strval', (array) ( $me['roles'] ?? array() ) ),
		);
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────.

	/**
	 * Guard for the admin-only diagnostics.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( 'kivun_mercaz', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה.', 'kivun' ) ) );
		}

		// Test what is on screen, not what was last saved: otherwise the first
		// thing anyone does — fill the fields in and press the button — reports
		// that nothing is configured.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		self::use_credentials(
			sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['pass'] ?? '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * AJAX: verify the credentials.
	 *
	 * @return void
	 */
	public static function ajax_test(): void {
		self::guard();

		$result = self::test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$roles = $result['roles'];
		$note  = in_array( 'job_manager', $roles, true ) && ! array_intersect( array( 'administrator', 'editor' ), $roles )
			? __( 'תפקיד job_manager — המחוז ייקבע אוטומטית בצד השרת, ולא יישלח מכאן.', 'kivun' )
			: __( 'תפקיד ניהולי — ניתן לשלוח מחוז (region-country) במפורש.', 'kivun' );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: display name, 2: roles list. */
					__( 'מחובר בתור %1$s (תפקידים: %2$s)', 'kivun' ),
					$result['name'],
					implode( ', ', $roles ) ? implode( ', ', $roles ) : '—'
				),
				'note'    => $note,
				'roles'   => $roles,
			)
		);
	}

	/**
	 * Say, on the screen where these are edited, where they come from.
	 *
	 * A value here is matched to the centre by name. Renaming one by hand
	 * looks like tidying and is not: the jobs under it stop being accepted,
	 * and nothing on this screen would have said so.
	 *
	 * @return void
	 */
	public static function vocabulary_notice(): void {
		$when = (string) get_option( 'kivun_mercaz_vocab_synced', '' );

		printf(
			'<div class="notice notice-warning inline" style="margin:12px 0;padding:8px 12px"><p><strong>%s</strong><br>%s%s</p></div>',
			esc_html__( 'הרשימה הזו נשאבת מאתר כיוון הראשי — אין לשנות אותה כאן.', 'kivun' ),
			esc_html__( 'המשרות מותאמות אצלם לפי שם הערך, כך ששינוי שם כאן גורם למשרות שתויגו בו להידחות — בלי שום הודעה. ערך חדש או שם שהשתנה אצלם נקלטים כאן אוטומטית מדי לילה.', 'kivun' ),
			'' !== $when
				? '<br>' . esc_html(
					sprintf(
						/* translators: %s: date and time of the last sync. */
						__( 'סונכרן לאחרונה: %s', 'kivun' ),
						kivun_local_date( $when )
					)
				)
				: ''
		);
	}

	/**
	 * The term meta holding the centre's own id for one of our terms.
	 *
	 * Kept so a rename on their side follows through to ours instead of
	 * arriving as a stranger and leaving the old name behind beside it.
	 */
	const REMOTE_TERM = '_kivun_mercaz_term_id';

	/**
	 * The two vocabularies a job is matched on: theirs => ours.
	 *
	 * @return array<string,string>
	 */
	public static function vocabularies(): array {
		return array(
			'job-type' => 'kivun_job_scope',
			'job_cat'  => 'kivun_job_field',
		);
	}

	/**
	 * Every term in one of their vocabularies, as id => name.
	 *
	 * Paged through to the end. Their API caps a page at 100, and reading
	 * only the first one would leave later values missing here and
	 * unavailable to adopt — with nothing to show they exist.
	 *
	 * @param string $remote Their taxonomy rest_base.
	 * @return array<int,string>|\WP_Error
	 */
	public static function remote_terms( string $remote ) {
		$out  = array();
		$page = 1;

		do {
			$result = self::request(
				'GET',
				$remote,
				array(
					'per_page' => 100,
					'page'     => $page,
				),
				null,
				'jobs'
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! is_array( $result['data'] ?? null ) ) {
				return new \WP_Error(
					'kivun_mercaz_vocab',
					__( 'מרכז כיוון החזיר תשובה שלא ניתן לקרוא.', 'kivun' )
				);
			}

			foreach ( $result['data'] as $row ) {
				if ( ! empty( $row['id'] ) && ! empty( $row['name'] ) ) {
					$out[ (int) $row['id'] ] = trim( (string) $row['name'] );
				}
			}

			$pages = (int) ( $result['headers']['x-wp-totalpages'] ?? 0 );
			++$page;
		} while ( $pages > 0 && $page <= $pages && $page <= 20 );

		return $out;
	}

	/**
	 * Bring both vocabularies into line with the centre's.
	 *
	 * Their list is the list: a value they do not have is a value that gets
	 * dropped on the way out, so keeping our own wording was never a choice
	 * between two vocabularies — it was a choice between theirs and nothing.
	 *
	 * Three things happen and a fourth deliberately does not:
	 *
	 * - A value of theirs we do not have is added.
	 * - A value we already hold under their id is renamed when they rename it.
	 * - A value whose name matches theirs is adopted, id and all, so it stops
	 *   being a coincidence and starts being the same term.
	 * - A value of ours they have dropped is left alone. Deleting it would
	 *   untag every job carrying it, and an untagged job is a worse problem
	 *   than a value that no longer sends.
	 *
	 * @return array<string,array<string,int>>|\WP_Error What changed, per
	 *                                                  vocabulary.
	 */
	public static function sync_vocabularies() {
		$done = array();

		foreach ( self::vocabularies() as $remote => $taxonomy ) {
			$counts = self::sync_vocabulary( $remote, $taxonomy );
			if ( is_wp_error( $counts ) ) {
				// Not stamped, not reported as done. A sync that could not
				// read their list changed nothing, and saying otherwise
				// leaves somebody reading "סונכרן לאחרונה: היום" off a
				// screen whose list is weeks stale.
				return $counts;
			}
			$done[ $remote ] = $counts;
		}

		update_option( 'kivun_mercaz_vocab_synced', current_time( 'mysql' ) );

		return $done;
	}

	/**
	 * Bring one vocabulary into line.
	 *
	 * @param string $remote   Their taxonomy rest_base.
	 * @param string $taxonomy Our taxonomy.
	 * @return array{added:int,renamed:int,linked:int,merged:int}|\WP_Error
	 */
	private static function sync_vocabulary( string $remote, string $taxonomy ) {
		$out = array(
			'added'   => 0,
			'renamed' => 0,
			'linked'  => 0,
			'merged'  => 0,
		);

		$theirs = self::remote_terms( $remote );
		if ( is_wp_error( $theirs ) ) {
			return $theirs;
		}

		// Ours, indexed by the centre's id where we already know it.
		$mine      = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		$by_remote = array();
		if ( ! is_wp_error( $mine ) ) {
			foreach ( $mine as $term ) {
				$id = (int) get_term_meta( $term->term_id, self::REMOTE_TERM, true );
				if ( $id ) {
					$by_remote[ $id ] = $term;
				}
			}
		}

		foreach ( $theirs as $id => $name ) {
			if ( ! $id || '' === $name ) {
				continue;
			}

			// Already linked: follow a rename, and otherwise leave it be.
			if ( isset( $by_remote[ $id ] ) ) {
				if ( $by_remote[ $id ]->name !== $name ) {
					// Where they rename one value to another we already
					// hold, the rename is refused and the jobs would sit on
					// the old name still being rejected — while the sync
					// counted it as done. Merged instead, which is what
					// their rename means.
					$clash = get_term_by( 'name', $name, $taxonomy );
					if ( $clash && ! is_wp_error( $clash ) && (int) $clash->term_id !== (int) $by_remote[ $id ]->term_id ) {
						self::merge_term( $taxonomy, (int) $by_remote[ $id ]->term_id, (int) $clash->term_id );
						update_term_meta( (int) $clash->term_id, self::REMOTE_TERM, $id );
						++$out['merged'];
						continue;
					}

					$renamed = wp_update_term( (int) $by_remote[ $id ]->term_id, $taxonomy, array( 'name' => $name ) );
					if ( ! is_wp_error( $renamed ) ) {
						++$out['renamed'];
					}
				}
				continue;
			}

			// Same name, no link yet — the two were the same term all along.
			$existing = get_term_by( 'name', $name, $taxonomy );
			if ( $existing && ! is_wp_error( $existing ) ) {
				update_term_meta( (int) $existing->term_id, self::REMOTE_TERM, $id );
				++$out['linked'];
				continue;
			}

			$made = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $made ) ) {
				update_term_meta( (int) $made['term_id'], self::REMOTE_TERM, $id );
				++$out['added'];
			}
		}

		return $out;
	}

	/**
	 * AJAX: pull both vocabularies now rather than waiting for the night.
	 *
	 * @return void
	 */
	public static function ajax_sync_vocab(): void {
		self::guard();

		$done = self::sync_vocabularies();
		if ( is_wp_error( $done ) ) {
			wp_send_json_error( array( 'message' => $done->get_error_message() ) );
		}

		$added  = 0;
		$named  = 0;
		$tied   = 0;
		$merged = 0;
		foreach ( $done as $counts ) {
			$added  += $counts['added'];
			$named  += $counts['renamed'];
			$tied   += $counts['linked'];
			$merged += $counts['merged'];
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: added. 2: renamed. 3: linked. 4: merged. */
					__( 'הסתיים: %1$d ערכים נוספו, %2$d שמות עודכנו, %3$d חוברו לערך אצלם, %4$d מוזגו.', 'kivun' ),
					$added,
					$named,
					$tied,
					$merged
				),
			)
		);
	}

	/**
	 * AJAX: rename one of our values to the centre's name for it.
	 *
	 * The match is by name, so adopting their word is the whole fix — and
	 * renaming a term keeps every post already tagged with it, which is why
	 * 33 jobs move on one rename and nobody re-tags anything.
	 *
	 * Which of their words a value means is a judgement and stays one: the
	 * caller sends the choice a person made from their list. This end only
	 * refuses a name that is not on it, so a typed or stale value cannot
	 * rename a term to something the centre will reject all over again.
	 *
	 * @return void
	 */
	public static function ajax_adopt(): void {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by guard().
		$remote  = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$term_id = absint( wp_unslash( $_POST['term_id'] ?? 0 ) );
		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$known = array(
			'job-type' => 'kivun_job_scope',
			'job_cat'  => 'kivun_job_field',
		);
		if ( ! isset( $known[ $remote ] ) || ! $term_id || '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'בקשה לא תקינה.', 'kivun' ) ) );
		}

		$taxonomy = $known[ $remote ];
		$term     = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'הערך לא נמצא.', 'kivun' ) ) );
		}

		// Checked against their live list rather than taken on trust. A name
		// that is not on it would rename the term to something they reject
		// just the same, and the screen would say it was adopted.
		$fetched = self::remote_terms( $remote );
		if ( is_wp_error( $fetched ) ) {
			wp_send_json_error( array( 'message' => $fetched->get_error_message() ) );
		}

		$theirs = array_values( $fetched );
		if ( ! in_array( $name, $theirs, true ) ) {
			wp_send_json_error( array( 'message' => __( 'הערך הזה לא קיים ברשימה של מרכז כיוון.', 'kivun' ) ) );
		}

		// Two of ours can mean one of theirs, and then adopting the second is
		// a merge rather than a rename: the jobs move across and the emptied
		// term goes, because leaving it would offer a value that is about to
		// be rejected again.
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing && (int) $existing->term_id !== (int) $term->term_id ) {
			$moved = self::merge_term( $taxonomy, (int) $term->term_id, (int) $existing->term_id );
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: number of jobs moved. 2: the adopted name. */
						__( '%1$d משרות הועברו אל "%2$s", והערך הישן הוסר.', 'kivun' ),
						$moved,
						$name
					),
				)
			);
		}

		$updated = wp_update_term( (int) $term->term_id, $taxonomy, array( 'name' => $name ) );
		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
		}

		// Linked to their id as well as their name, so when they reword it
		// later the change follows instead of arriving as a new value with
		// ours left stranded beside it.
		$their_id = (int) array_search( $name, $fetched, true );
		if ( $their_id ) {
			update_term_meta( (int) $term->term_id, self::REMOTE_TERM, $their_id );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: the old name. 2: the adopted name. */
					__( '"%1$s" נקרא מעכשיו "%2$s". המשרות שתויגו בו עברו איתו.', 'kivun' ),
					$term->name,
					$name
				),
			)
		);
	}

	/**
	 * Move every job from one term to another and remove the emptied one.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param int    $from     The term being given up.
	 * @param int    $to       The term they join.
	 * @return int How many jobs moved.
	 */
	private static function merge_term( string $taxonomy, int $from, int $to ): int {
		$ids = get_posts(
			array(
				'post_type'              => 'kivun_job',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'              => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $from,
					),
				),
			)
		);

		foreach ( (array) $ids as $id ) {
			wp_set_object_terms( (int) $id, array( $to ), $taxonomy, false );
		}

		wp_delete_term( $from, $taxonomy );

		return count( (array) $ids );
	}

	/**
	 * How many jobs ride on one term, counting the ones that are queued to
	 * go as well as the ones already published.
	 *
	 * Not $term->count: WordPress keeps that for published posts only, while
	 * a draft, a pending job and a scheduled one are all pushed to the centre
	 * too. A value blocking five drafts would report zero and read as the
	 * least urgent line on the screen, which is the opposite of true.
	 *
	 * @param string $taxonomy Local taxonomy.
	 * @param int    $term_id  Term id.
	 * @return int
	 */
	private static function jobs_on_term( string $taxonomy, int $term_id ): int {
		$ids = get_posts(
			array(
				'post_type'              => 'kivun_job',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'              => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		return count( (array) $ids );
	}

	/**
	 * AJAX: the centre's own list of values, beside ours.
	 *
	 * A job is matched to their vocabulary by name, and a name they do not
	 * have is dropped — so "משרה מלאה" can block fifty jobs while every one
	 * of them looks correctly filled in here. Nothing on either screen says
	 * what they would have accepted instead, and guessing at it by pushing
	 * and reading the refusals is how an afternoon goes.
	 *
	 * Their list and ours, side by side, answers it in one look.
	 *
	 * @return void
	 */
	public static function ajax_vocab(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard().
		$remote = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );

		// Only the vocabularies a job is matched against: this is a window
		// onto their list, not a way to read anything by name.
		$known = array(
			'job-type' => 'kivun_job_scope',
			'job_cat'  => 'kivun_job_field',
		);
		if ( ! isset( $known[ $remote ] ) ) {
			wp_send_json_error( array( 'message' => __( 'רשימה לא מוכרת.', 'kivun' ) ) );
		}

		$fetched = self::remote_terms( $remote );
		if ( is_wp_error( $fetched ) ) {
			wp_send_json_error( array( 'message' => $fetched->get_error_message() ) );
		}

		$theirs = array_values( $fetched );

		// Ours, with how many jobs carry each: a name used once and a name
		// used fifty times are the same line otherwise, and they are not the
		// same problem.
		$ours  = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $known[ $remote ],
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$ours[] = array(
					'id'      => (int) $term->term_id,
					'name'    => (string) $term->name,
					'jobs'    => self::jobs_on_term( $known[ $remote ], (int) $term->term_id ),
					'matches' => in_array( (string) $term->name, $theirs, true ),
				);
			}
		}

		wp_send_json_success(
			array(
				'theirs' => $theirs,
				'ours'   => $ours,
			)
		);
	}
}
