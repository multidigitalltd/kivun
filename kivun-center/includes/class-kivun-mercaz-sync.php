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
			// Both of these are 'workshops' on their side. A סדנה is the one
			// that was meant: its fields are the ones the workshops branch
			// below reads. It was missing from this map, so not one has ever
			// been sent — and a דף נחיתה was going in its place, carrying a
			// date and an audience it keeps under other names, which arrived
			// empty every time.
			'kivun_session'  => 'workshops',
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
		add_action( 'wp_ajax_kivun_mercaz_push_all', array( __CLASS__, 'ajax_push_all' ) );
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

	/**
	 * The remote term id for a post's term in a local taxonomy.
	 *
	 * The two vocabularies are kept identical by name, so a term resolves
	 * directly. Nothing is translated on the way out.
	 *
	 * @param int    $post_id Post id.
	 * @param string $local   Local taxonomy.
	 * @param string $remote  Remote taxonomy rest_base.
	 * @return int Remote term id, or 0 when there is no match.
	 */
	private static function mapped_term( int $post_id, string $local, string $remote ): int {
		$name = self::local_term_name( $post_id, $local );
		return '' === $name ? 0 : self::resolve_term( $remote, $name, 'jobs' );
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

		// An ISO date is already what the API wants; read it first so the
		// day-first rule below never gets to misread one.
		if ( preg_match( '/\d{4}-\d{1,2}-\d{1,2}/', $raw, $iso ) ) {
			$stamp = strtotime( $iso[0] );
			return $stamp ? gmdate( 'Y-m-d', $stamp ) : '';
		}

		// These values are written for people to read, not for a parser: the
		// forms themselves suggest "15.9.2025 | 18:00" and
		// "ד׳ 15.9.2025 | 18:00–21:00". Take the date out of the sentence
		// before reading it — matching the whole string finds nothing in
		// either of those, and a date that parses to nothing is dropped
		// before the request with nothing said at either end.
		//
		// Day-first, because that is how a date reads in Hebrew and how both
		// of those examples are written. strtotime() reads a slash the
		// American way round, so it is not asked.
		if ( preg_match( '/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/', $raw, $m ) ) {
			$day   = (int) $m[1];
			$month = (int) $m[2];
			$year  = (int) $m[3];
			if ( $year < 100 ) {
				$year += 2000;
			}
			if ( checkdate( $month, $day, $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );
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
			'status' => in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private' ), true )
				? $post->post_status
				: 'draft',
		);

		// Scheduling is expressed as a future date on a published item, not as
		// a 'future' status — that is how their API documents it, and it also
		// avoids depending on 'future' being an accepted status value.
		if ( 'future' === $post->post_status ) {
			$body['status'] = 'publish';
			$body['date']   = get_post_time( 'Y-m-d\TH:i:s', false, $post );
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
				// A סדנה and a דף נחיתה both land here, and they keep the same
				// two values under different names. Asked for by what the post
				// actually is, so neither reads the other's empty keys.
				$who  = 'kivun_workshop' === $post->post_type ? '_kivun_ws_audience' : '_kivun_session_audience';
				$when = 'kivun_workshop' === $post->post_type ? '_kivun_ws_date' : '_kivun_session_date';

				$body['meta'] = array(
					'workshop_info' => self::as_html( $post->post_content ),
					// These two are stored as plain text on their side.
					'workshop_who'  => wp_strip_all_tags( $get( $who ) ),
					'workshop_when' => wp_strip_all_tags( $get( $when ) ),
					'workshop_date' => self::as_date( $get( $when ) ),
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
					// Their side shows an internal number of their own, which
					// tells nobody here which job it is. This is the number
					// that identifies it in our system, so a question about a
					// job can be traced back to the record it came from. The
					// post id is already that number, already unique, and
					// needs nobody to type it.
					'job_number'       => (string) $post->ID,
					'employer_email'   => $get( '_kivun_employer_email' ),
					'expires_at'       => self::as_date( $get( '_kivun_deadline' ) ),
					'salary_min'       => $salary['min'],
					'salary_max'       => $salary['max'],
					'work_hours'       => $get( '_kivun_work_hours' ),
					'experience_years' => $get( '_kivun_experience_years' ),
					'mefarsem'         => $get( '_kivun_company' ),
					'requirements'     => self::to_repeater( $get( '_kivun_requirements' ) ),
				);

				// The district is derived from the account's profile for a job
				// manager, and sending it is refused outright — so it is never
				// sent from here. An administrator would set it explicitly.
				$job_cat = self::mapped_term( $post->ID, 'kivun_job_field', 'job_cat' );
				if ( $job_cat ) {
					$body['job_cat'] = array( $job_cat );
				}

				$job_type = self::mapped_term( $post->ID, 'kivun_job_scope', 'job-type' );
				if ( $job_type ) {
					$body['job-type'] = array( $job_type );
				}

				// Features are multi-select, unlike the single-value taxonomies.
				$features = array();
				foreach ( (array) get_the_terms( $post->ID, 'kivun_job_feature' ) as $feature ) {
					if ( isset( $feature->name ) ) {
						$id = self::resolve_term( 'job_features', (string) $feature->name, 'jobs' );
						if ( $id ) {
							$features[] = $id;
						}
					}
				}
				if ( $features ) {
					$body['job_features'] = $features;
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

	// ── What a job must carry ─────────────────────────────────────────────────.

	/**
	 * The fields a job has to arrive with, and what to call them to somebody
	 * who has to go and fill one in.
	 *
	 * Their API accepts a job without any of these — it answers 201 and files
	 * it — but a job missing one is not findable: no settlement means it is
	 * absent from every search by place, and no type or field means it is
	 * absent from both filters. Of the 23 jobs already sent, all 23 arrived
	 * without a type and 17 without a settlement, and nothing said so at
	 * either end. So the check belongs here, before the request.
	 *
	 * @return array<string,string> Body key => Hebrew label.
	 */
	public static function job_required(): array {
		return array(
			'title'      => __( 'שם המשרה', 'kivun' ),
			'content'    => __( 'תיאור המשרה', 'kivun' ),
			'city'       => __( 'עיר', 'kivun' ),
			'job-type'   => __( 'סוג משרה', 'kivun' ),
			'job_number' => __( 'מספר משרה', 'kivun' ),
		);
	}

	/**
	 * The ones the site would like a job to carry. Not refused without them,
	 * because the client posts jobs the centre did not write and a blocked
	 * posting helps nobody, but worth saying out loud.
	 *
	 * @return array<string,string> Body key => Hebrew label.
	 */
	public static function job_recommended(): array {
		return array(
			'job_cat'    => __( 'תחום', 'kivun' ),
			'expires_at' => __( 'תאריך הסרה', 'kivun' ),
		);
	}

	/**
	 * What is missing, and which of the two reasons it is missing for.
	 *
	 * "חסר: סוג משרה" is true and useless: it does not say whether nobody
	 * chose a type on the job, or whether somebody chose one and the centre
	 * has no such name in its list. Those need opposite work — filling a
	 * field here, or agreeing on a word with them — and 50 jobs reporting
	 * the first sentence tell you nothing about which.
	 *
	 * So the value that was tried is named. A field nobody filled says so;
	 * a field that was filled and did not survive the mapping shows what it
	 * held, which is the thing to go and compare against their list.
	 *
	 * @param \WP_Post             $post   The job.
	 * @param array<string,mixed>  $body   The mapped request body.
	 * @param array<string,string> $fields Key => label; defaults to required.
	 * @return array<int,string> One readable line per missing field.
	 */
	public static function missing_detail( \WP_Post $post, array $body, array $fields = array() ): array {
		$fields = $fields ? $fields : self::job_required();

		// Where each of these comes from locally, so the value that was tried
		// can be shown. Anything not listed is plain post content, which is
		// either there or it is not.
		$sources = array(
			'city'     => array( 'meta', self::CITY_META ),
			'job-type' => array( 'term', 'kivun_job_scope' ),
			'job_cat'  => array( 'term', 'kivun_job_field' ),
		);

		$out  = array();
		$meta = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();

		foreach ( $fields as $key => $label ) {
			$value   = $body[ $key ] ?? ( $meta[ $key ] ?? null );
			$present = is_array( $value ) ? (bool) $value : '' !== trim( (string) $value );
			if ( $present ) {
				continue;
			}

			if ( ! isset( $sources[ $key ] ) ) {
				$out[] = $label;
				continue;
			}

			list( $kind, $from ) = $sources[ $key ];
			$local               = 'term' === $kind
				? self::local_term_name( $post->ID, $from )
				: trim( (string) get_post_meta( $post->ID, $from, true ) );

			$out[] = '' === $local
				? sprintf(
					/* translators: %s: field name, e.g. "סוג משרה". */
					__( '%s (לא נבחר במשרה)', 'kivun' ),
					$label
				)
				: sprintf(
					/* translators: 1: field name. 2: the value set locally. */
					__( '%1$s ("%2$s" — אין ערך כזה ברשימה של מרכז כיוון)', 'kivun' ),
					$label,
					$local
				);
		}

		return $out;
	}

	/**
	 * Which required fields a built body is missing.
	 *
	 * Read off the body rather than the post, so a value that was filled in
	 * locally but did not survive the mapping counts as missing — a settlement
	 * whose name does not exist in their vocabulary resolves to nothing and is
	 * dropped, and from the post alone that looks fine.
	 *
	 * @param array<string,mixed>  $body    The mapped request body.
	 * @param array<string,string> $fields Key => label; defaults to the
	 *                                     required set.
	 * @return array<int,string> The labels of what is missing.
	 */
	public static function missing_fields( array $body, array $fields = array() ): array {
		$fields = $fields ? $fields : self::job_required();
		$meta   = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();
		$absent = array();

		foreach ( $fields as $key => $label ) {
			// A taxonomy arrives as an array of remote term ids, everything
			// else as a string; both are missing when they are empty, and the
			// mapping drops an empty meta value altogether.
			$value   = $body[ $key ] ?? ( $meta[ $key ] ?? null );
			$present = is_array( $value ) ? (bool) $value : '' !== trim( (string) $value );

			if ( ! $present ) {
				$absent[] = $label;
			}
		}

		return $absent;
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

		// Refused here rather than sent and lost. Their API would take this
		// and answer 201, and the job would sit on their board findable by
		// nobody — which is how 23 of them got there.
		if ( 'jobs' === $remote_type ) {
			$missing = self::missing_fields( $body );
			if ( $missing ) {
				$error = new \WP_Error(
					'kivun_mercaz_incomplete',
					sprintf(
						/* translators: %s: field names, each with why it is missing. */
						__( 'המשרה לא שודרה — חסר: %s. משרה בלי השדות האלה נקלטת אצלם אבל לא נמצאת בחיפוש.', 'kivun' ),
						implode( '; ', self::missing_detail( $post, $body ) )
					)
				);
				update_post_meta( $post_id, self::LAST_ERROR, $error->get_error_message() );
				return $error;
			}
		}
		$remote_id = (int) get_post_meta( $post_id, self::REMOTE_ID, true );
		$path      = $remote_id ? $remote_type . '/' . $remote_id : $remote_type;

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

		// A 201 does not mean everything in the body was kept. Their API
		// returns the fields it could not accept in ckvn_warnings, and a push
		// that reported success while a field fell on the floor is the whole
		// reason this had to be audited by hand.
		$warnings = self::warnings( $result['data'] ?? array() );
		if ( $warnings ) {
			update_post_meta(
				$post_id,
				self::LAST_ERROR,
				sprintf(
					/* translators: %s: comma-separated field names returned by the API. */
					__( 'נשלח, אך השדות האלה לא נקלטו אצלם: %s', 'kivun' ),
					implode( ', ', $warnings )
				)
			);
		} else {
			delete_post_meta( $post_id, self::LAST_ERROR );
		}

		return array(
			'remote_id' => $new_id,
			'link'      => (string) ( $result['data']['link'] ?? '' ),
			'created'   => ! $remote_id,
			'warnings'  => $warnings,
		);
	}

	/**
	 * The field names their API reports it could not accept.
	 *
	 * Documented as ckvn_warnings on a job response: an empty array means
	 * everything was kept. The shape is not promised beyond that, so a list of
	 * strings and a map of field => reason are both read.
	 *
	 * @param mixed $data The decoded response body.
	 * @return array<int,string>
	 */
	public static function warnings( $data ): array {
		if ( ! is_array( $data ) || empty( $data['ckvn_warnings'] ) || ! is_array( $data['ckvn_warnings'] ) ) {
			return array();
		}

		// The field name is the part worth reading, and it is the key
		// wherever there is one. A map of field => number used to print the
		// number and throw the name away, which is how a real warning
		// reached the screen reading "לא נקלטו: 0" — true, unhelpful, and
		// impossible to act on.
		$out = array();
		foreach ( $data['ckvn_warnings'] as $key => $value ) {
			$field = is_numeric( $key ) ? '' : trim( (string) $key );
			// Only a string is a reason. A number beside a field name is a
			// code or a count, and reads as noise next to it.
			$why = is_string( $value ) ? trim( $value ) : '';

			// A numbered entry carries the field name as its value — but only
			// when that value is a string. A bare number in a numbered list
			// names nothing, and printing it is how "לא נקלטו: 0" happened.
			if ( '' === $field && is_string( $value ) ) {
				$field = trim( $value );
				$why   = '';
			}

			if ( '' === $field ) {
				continue;
			}

			$out[] = ( '' !== $why && $why !== $field ) ? $field . ' (' . $why . ')' : $field;
		}

		return $out;
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

		if ( ! empty( $result['warnings'] ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: comma-separated field names. */
						__( 'נשלח, אך השדות האלה לא נקלטו אצלם: %s', 'kivun' ),
						implode( ', ', $result['warnings'] )
					),
				)
			);
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

	// ── Sending the backlog ───────────────────────────────────────────────────.

	/**
	 * Send every job again.
	 *
	 * The jobs already on their board went up before any of this was checked,
	 * so they are sitting there without a number, a type or a settlement.
	 * Re-sending updates each in place — the remote id is remembered — rather
	 * than making a second copy of anything.
	 *
	 * Stops on a time budget rather than running until PHP kills it, and says
	 * how many it did not reach so a second press carries on.
	 *
	 * @return array{sent:int,failed:int,skipped:int,remaining:int,problems:array<int,string>}
	 */
	public static function push_all_jobs(): array {
		$jobs = get_posts(
			array(
				'post_type'        => 'kivun_job',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$report = array(
			'sent'      => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'problems'  => array(),
		);

		// Each job is at least one request to their server, and a slow reply
		// multiplies across the board. Rather than let PHP cut the run off
		// somewhere in the middle — which looks like a failure and hides how
		// much did go — it stops on its own and says how many are left. The
		// remote id is already saved for everything sent, so running it again
		// carries on from there instead of starting over.
		$started = microtime( true );
		$budget  = (float) apply_filters( 'kivun_mercaz_bulk_seconds', 45.0 );
		$jobs    = array_values( (array) $jobs );

		foreach ( $jobs as $index => $job_id ) {
			if ( $index && microtime( true ) - $started > $budget ) {
				$report['remaining'] = count( $jobs ) - $index;
				break;
			}

			$result = self::push( (int) $job_id );

			if ( is_wp_error( $result ) ) {
				++$report['failed'];
				// Named one by one: "12 failed" sends somebody hunting, while
				// the title and the missing field is the whole repair.
				$report['problems'][] = sprintf(
					'%s — %s',
					get_the_title( (int) $job_id ),
					$result->get_error_message()
				);
				continue;
			}

			if ( ! empty( $result['warnings'] ) ) {
				++$report['skipped'];
				$report['problems'][] = sprintf(
					/* translators: 1: job title, 2: comma-separated field names. */
					__( '%1$s — נשלחה, אך לא נקלטו: %2$s', 'kivun' ),
					get_the_title( (int) $job_id ),
					implode( ', ', $result['warnings'] )
				);
				continue;
			}

			++$report['sent'];
		}

		return $report;
	}

	/**
	 * AJAX: re-send every job.
	 *
	 * @return void
	 */
	public static function ajax_push_all(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		// A loop over every job on the board is not something an employer who
		// can edit their own posting should be able to set off.
		if ( ! current_user_can( 'manage_options' ) && ! Kivun_Employer::can_manage_all() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לשדר את כל המשרות.', 'kivun' ) ) );
		}
		if ( ! Kivun_Mercaz::configured() ) {
			wp_send_json_error( array( 'message' => __( 'החיבור למרכז כיוון לא הוגדר. השלימו את הפרטים בהגדרות.', 'kivun' ) ) );
		}

		$report = self::push_all_jobs();

		$message = sprintf(
			/* translators: 1: number sent, 2: number with problems. */
			__( 'שודרו %1$d משרות. %2$d דורשות טיפול.', 'kivun' ),
			(int) $report['sent'],
			(int) $report['failed'] + (int) $report['skipped']
		);

		if ( $report['remaining'] ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of jobs not reached. */
				__( 'נותרו %d שלא נשלחו הפעם — לחצו שוב כדי להמשיך מהן.', 'kivun' ),
				(int) $report['remaining']
			);
		}

		wp_send_json_success(
			array(
				'message'   => $message,
				'remaining' => (int) $report['remaining'],
				'problems'  => $report['problems'],
			)
		);
	}
}
