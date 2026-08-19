<?php
/**
 * Pushes local content to the Mercaz Kivun site.
 *
 * Maps each local post type onto the remote endpoint documented for it, and
 * remembers the remote id so a second push updates rather than duplicates.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps and sends courses, workshops, events and jobs to Mercaz Kivun.
 */
class Kivun_Mercaz_Sync {

	/**
	 * Meta key holding the id of the matching record on the remote site.
	 */
	const REMOTE_ID = '_kivun_mercaz_id';

	/**
	 * Meta key holding the time of the last successful push.
	 */
	const SYNCED_AT = '_kivun_mercaz_synced';

	/**
	 * Meta key holding the last failure, so it can be shown and retried.
	 */
	const LAST_ERROR = '_kivun_mercaz_error';

	/**
	 * Meta key holding the chosen settlement name (resolved to a remote term
	 * id at push time, so a term renumbering on their side cannot strand us).
	 */
	const CITY_META = '_kivun_city';

	/**
	 * Local post type => remote endpoint.
	 *
	 * @return array<string,string>
	 */
	public static function type_map(): array {
		return array(
			'kivun_course'   => 'courses',
			'kivun_workshop' => 'workshops',
			'kivun_event'    => 'events',
			'kivun_job'      => 'jobs',
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_mercaz_push', array( __CLASS__, 'ajax_push' ) );
		add_action( 'wp_ajax_kivun_city_search', array( __CLASS__, 'ajax_city_search' ) );

		// Pushing happens on a scheduled tick rather than inline: a save should
		// not wait on someone else's server, and a slow or unreachable API
		// would otherwise look like the editor hanging.
		add_action( 'save_post', array( __CLASS__, 'queue_push' ), 20, 2 );
		add_action( 'kivun_mercaz_push_event', array( __CLASS__, 'push' ) );
	}

	/**
	 * Queue a post for pushing after it is saved.
	 *
	 * @param int      $post_id The post id.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public static function queue_push( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( self::type_map()[ $post->post_type ] ) ) {
			return;
		}
		// auto-draft and trash are not content anyone meant to publish.
		if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			return;
		}
		if ( '' === trim( (string) $post->post_title ) || ! Kivun_Mercaz::configured() ) {
			return;
		}
		if ( ! (bool) Kivun_Admin_Settings::get( 'mercaz_auto', false ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'kivun_mercaz_push_event', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 20, 'kivun_mercaz_push_event', array( $post_id ) );
		}
	}

	/**
	 * AJAX: search the remote settlement vocabulary for the autocomplete.
	 *
	 * @return void
	 */
	public static function ajax_city_search(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'cities' => array() ) );
		}

		$cache_key = 'kivun_city_q_' . md5( $term );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( array( 'cities' => $cached ) );
		}

		$result = Kivun_Mercaz::request(
			'GET',
			'city',
			array(
				'search'   => $term,
				'per_page' => 20,
			)
		);

		if ( is_wp_error( $result ) || ! is_array( $result['data'] ) ) {
			wp_send_json_success( array( 'cities' => array() ) );
		}

		$names = array();
		foreach ( $result['data'] as $city ) {
			if ( ! empty( $city['name'] ) ) {
				$names[] = (string) $city['name'];
			}
		}

		set_transient( $cache_key, $names, DAY_IN_SECONDS );
		wp_send_json_success( array( 'cities' => $names ) );
	}

	// ── Term resolution ───────────────────────────────────────────────────────.

	/**
	 * Resolve a term name to the remote site's term id.
	 *
	 * Term ids are database row ids and differ between the two sites, so a
	 * local id is meaningless there. Names are matched instead, and the result
	 * is cached — the settlement vocabulary alone holds 1272 terms and the
	 * documentation asks callers not to page through it.
	 *
	 * @param string $taxonomy Remote taxonomy rest_base, e.g. 'city'.
	 * @param string $name     The term name to look for.
	 * @param string $type     Content type key, to pick the right base URL.
	 * @return int The remote term id, or 0 when not found.
	 */
	public static function resolve_term( string $taxonomy, string $name, string $type = '' ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}

		$cache_key = 'kivun_mercaz_term_' . md5( $taxonomy . '|' . $name );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$result = Kivun_Mercaz::request(
			'GET',
			$taxonomy,
			array(
				'search'   => $name,
				'per_page' => 20,
			),
			null,
			$type
		);

		if ( is_wp_error( $result ) || ! is_array( $result['data'] ) ) {
			return 0;
		}

		// A search is a substring match, so prefer an exact name before
		// settling for the first hit — "בית שמש" must not resolve to
		// "בית שמש הצעירה" just because it sorted first.
		$id = 0;
		foreach ( $result['data'] as $term ) {
			if ( isset( $term['name'] ) && $term['name'] === $name ) {
				$id = (int) $term['id'];
				break;
			}
		}
		if ( ! $id && isset( $result['data'][0]['id'] ) ) {
			$id = (int) $result['data'][0]['id'];
		}

		// Cache misses briefly too: a name that does not exist should not be
		// re-queried on every push, but should recover once it is added.
		set_transient( $cache_key, $id, $id ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
		return $id;
	}

	/**
	 * The first term name attached to a post in a local taxonomy.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Local taxonomy.
	 * @return string
	 */
	private static function local_term_name( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	// ── Mapping ───────────────────────────────────────────────────────────────.

	/**
	 * Turn a newline-separated list into the repeater shape the API expects:
	 * an object keyed item-0, item-1, … each holding { "value": "…" }.
	 *
	 * @param string $text Raw multi-line text.
	 * @return array<string,array<string,string>>
	 */
	public static function to_repeater( string $text ): array {
		$out   = array();
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$index = 0;

		foreach ( (array) $lines as $line ) {
			// Bullet characters are how these lists are typed locally; they are
			// markup here, not content.
			$line = trim( preg_replace( '/^\s*[-–—•*]\s*/u', '', (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			$out[ 'item-' . $index ] = array( 'value' => $line );
			++$index;
		}

		return $out;
	}

	/**
	 * Split a free-text salary into the two numeric bounds the API stores.
	 *
	 * @param string $salary Local salary text, e.g. "8,000-11,000 ₪".
	 * @return array{min:string,max:string}
	 */
	public static function split_salary( string $salary ): array {
		if ( ! preg_match_all( '/\d[\d,\.]*/', $salary, $matches ) ) {
			return array(
				'min' => '',
				'max' => '',
			);
		}

		$numbers = array_map(
			static function ( $n ) {
				return (string) (int) str_replace( array( ',', '.' ), '', $n );
			},
			$matches[0]
		);

		return array(
			'min' => $numbers[0],
			'max' => isset( $numbers[1] ) ? $numbers[1] : '',
		);
	}

	/**
	 * A date as the API wants it (Y-m-d), or '' when the value is not a date.
	 *
	 * @param string $raw Local value.
	 * @return string
	 */
	private static function as_date( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Day-first is how dates read in Hebrew, but strtotime() reads a slash
		// as the American month-first order, so "31/12/2026" is not a date to
		// it and would be dropped without a word. Try the local order first.
		foreach ( array( 'd/m/Y', 'd.m.Y', 'd-m-Y' ) as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( $format, $raw );
			if ( $parsed && $parsed->format( $format ) === $raw ) {
				return $parsed->format( 'Y-m-d' );
			}
		}

		$stamp = strtotime( $raw );
		return $stamp ? gmdate( 'Y-m-d', $stamp ) : '';
	}

	/**
	 * Wrap plain text in a paragraph. Several remote fields were authored in a
	 * rich-text editor and hold HTML; plain text is accepted but renders
	 * inconsistently beside the existing content.
	 *
	 * @param string $text Text that may already be HTML.
	 * @return string
	 */
	private static function as_html( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		return preg_match( '/<[a-z][\s\S]*>/i', $text ) ? $text : '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Build the request body for a post.
	 *
	 * @param \WP_Post $post The local post.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function map( \WP_Post $post ) {
		$types = self::type_map();
		if ( ! isset( $types[ $post->post_type ] ) ) {
			return new \WP_Error( 'kivun_mercaz_type', __( 'סוג התוכן אינו נתמך בשליחה.', 'kivun' ) );
		}

		$remote_type = $types[ $post->post_type ];
		$get         = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $key, true );
		};

		$body = array(
			'title'  => get_the_title( $post ),
			'status' => in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true )
				? $post->post_status
				: 'draft',
		);

		// A scheduled local post must carry its date, or it publishes at once.
		if ( 'future' === $post->post_status ) {
			$body['date'] = get_post_time( 'Y-m-d\TH:i:s', false, $post );
		}

		// Settlement: optional in the API, but a record without one is missing
		// from every distance search, which is the main way people browse.
		$city = self::resolve_term( 'city', $get( self::CITY_META ), $remote_type );
		if ( $city ) {
			$body['city'] = array( $city );
		}

		switch ( $remote_type ) {
			case 'courses':
				$body['meta'] = array(
					'course_info' => self::as_html( $post->post_content ),
					'course_who'  => self::as_html( $get( '_kivun_target_audience' ) ),
					'course_when' => self::as_html( $get( '_kivun_schedule' ) ),
					'end_date'    => self::as_date( $get( '_kivun_schedule' ) ),
				);
				break;

			case 'workshops':
				$body['meta'] = array(
					'workshop_info' => self::as_html( $post->post_content ),
					// These two are stored as plain text on their side.
					'workshop_who'  => wp_strip_all_tags( $get( '_kivun_session_audience' ) ),
					'workshop_when' => wp_strip_all_tags( $get( '_kivun_session_date' ) ),
					'workshop_date' => self::as_date( $get( '_kivun_session_date' ) ),
				);
				break;

			case 'events':
				$body['meta'] = array(
					'event_dec'   => self::as_html( $post->post_content ),
					'event_who'   => self::as_html( $get( '_kivun_event_audience' ) ),
					'event_when'  => self::as_html( $get( '_kivun_event_time' ) ),
					'event_where' => self::as_html( $get( '_kivun_event_location' ) ),
					'event_date'  => self::as_date( $get( '_kivun_event_date' ) ),
					'link_event'  => $get( '_kivun_event_url' ),
				);
				break;

			case 'jobs':
				$salary = self::split_salary( $get( '_kivun_salary' ) );

				$body['content'] = self::as_html( '' !== $get( '_kivun_description' ) ? $get( '_kivun_description' ) : $post->post_content );
				$body['meta']    = array(
					'employer_email' => $get( '_kivun_employer_email' ),
					'expires_at'     => self::as_date( $get( '_kivun_deadline' ) ),
					'salary_min'     => $salary['min'],
					'salary_max'     => $salary['max'],
					'requirements'   => self::to_repeater( $get( '_kivun_requirements' ) ),
				);

				// The district is derived from the account's profile for a job
				// manager, and sending it is refused outright — so it is never
				// sent from here. An administrator would set it explicitly.
				$job_cat = self::resolve_term( 'job_cat', self::local_term_name( $post->ID, 'kivun_job_field' ), 'jobs' );
				if ( $job_cat ) {
					$body['job_cat'] = array( $job_cat );
				}

				$job_type = self::resolve_term( 'job-type', self::local_term_name( $post->ID, 'kivun_job_scope' ), 'jobs' );
				if ( $job_type ) {
					$body['job-type'] = array( $job_type );
				}
				break;
		}

		// Empty values are dropped: sending "" would overwrite whatever is
		// already on the remote record with nothing.
		if ( isset( $body['meta'] ) ) {
			$body['meta'] = array_filter(
				$body['meta'],
				static function ( $value ) {
					return is_array( $value ) ? (bool) $value : '' !== trim( (string) $value );
				}
			);
		}

		return $body;
	}

	// ── Push ──────────────────────────────────────────────────────────────────.

	/**
	 * Send one post, creating it remotely the first time and updating it after.
	 *
	 * @param int $post_id Local post id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function push( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'kivun_mercaz_missing', __( 'התוכן לא נמצא.', 'kivun' ) );
		}

		$types = self::type_map();
		if ( ! isset( $types[ $post->post_type ] ) ) {
			return new \WP_Error( 'kivun_mercaz_type', __( 'סוג התוכן אינו נתמך בשליחה.', 'kivun' ) );
		}

		$body = self::map( $post );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$remote_type = $types[ $post->post_type ];
		$remote_id   = (int) get_post_meta( $post_id, self::REMOTE_ID, true );
		$path        = $remote_id ? $remote_type . '/' . $remote_id : $remote_type;

		$result = Kivun_Mercaz::request( 'POST', $path, array(), $body, $remote_type );

		// A record deleted on their side leaves a stale id here; recreate it
		// rather than failing every push from then on.
		if ( is_wp_error( $result ) && $remote_id && 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			delete_post_meta( $post_id, self::REMOTE_ID );
			$result    = Kivun_Mercaz::request( 'POST', $remote_type, array(), $body, $remote_type );
			$remote_id = 0;
		}

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, self::LAST_ERROR, $result->get_error_message() );
			return $result;
		}

		$new_id = (int) ( $result['data']['id'] ?? 0 );
		if ( $new_id ) {
			update_post_meta( $post_id, self::REMOTE_ID, $new_id );
		}
		update_post_meta( $post_id, self::SYNCED_AT, current_time( 'mysql' ) );
		delete_post_meta( $post_id, self::LAST_ERROR );

		return array(
			'remote_id' => $new_id,
			'link'      => (string) ( $result['data']['link'] ?? '' ),
			'created'   => ! $remote_id,
		);
	}

	/**
	 * AJAX: push one post on request.
	 *
	 * @return void
	 */
	public static function ajax_push(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לשלוח את התוכן הזה.', 'kivun' ) ) );
		}
		if ( ! Kivun_Mercaz::configured() ) {
			wp_send_json_error( array( 'message' => __( 'החיבור למרכז כיוון לא הוגדר. השלימו את הפרטים בהגדרות.', 'kivun' ) ) );
		}

		$result = self::push( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => $result['created']
					? __( 'נשלח ונוצר במרכז כיוון.', 'kivun' )
					: __( 'עודכן במרכז כיוון.', 'kivun' ),
				'link'    => $result['link'],
			)
		);
	}
}
