<?php
/**
 * Outbound webhook: notifies a CRM whenever a job goes live.
 *
 * The CRM is somebody else's system, so nothing here assumes it is fast, awake
 * or forgiving. A publish schedules the delivery instead of performing it, a
 * failure is retried with a widening gap, and every attempt is recorded on the
 * job itself so a missed sync can be seen and re-sent by hand.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends job events to the client's CRM.
 */
class Kivun_CRM_Webhook {

	/**
	 * Meta key holding the time of the last successful delivery.
	 */
	const SENT_AT = '_kivun_crm_sent';

	/**
	 * Meta key holding the last failure, so it can be shown and retried.
	 */
	const LAST_ERROR = '_kivun_crm_error';

	/**
	 * How many times one event is attempted before it is given up on.
	 */
	const MAX_ATTEMPTS = 4;

	/**
	 * The events this webhook sends.
	 *
	 * @return array<string,string> Event name => what it means.
	 */
	public static function events(): array {
		return array(
			'job.published' => __( 'משרה פורסמה — הופיעה באתר בפעם הראשונה, או חזרה להתפרסם.', 'kivun' ),
			'job.updated'   => __( 'משרה שכבר מפורסמת נערכה ונשמרה מחדש.', 'kivun' ),
			'job.closed'    => __( 'משרה ירדה מהאתר — נסגרה, הועברה לטיוטה או נמחקה.', 'kivun' ),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'kivun_crm_send_event', array( __CLASS__, 'send' ), 10, 3 );

		add_action( 'wp_ajax_kivun_crm_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'admin_post_kivun_crm_docs', array( __CLASS__, 'download_docs' ) );
		add_action( 'admin_post_kivun_crm_resend', array( __CLASS__, 'resend' ) );

		add_filter( 'manage_kivun_job_posts_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_kivun_job_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'resend_notice' ) );
	}

	// ── Configuration ─────────────────────────────────────────────────────────.

	/**
	 * The CRM endpoint the events are posted to.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		return trim( (string) Kivun_Admin_Settings::get( 'crm_webhook_url', '' ) );
	}

	/**
	 * The shared secret used to sign each request, or '' when unsigned.
	 *
	 * @return string
	 */
	public static function secret(): string {
		return trim( (string) Kivun_Admin_Settings::get( 'crm_webhook_secret', '' ) );
	}

	/**
	 * Whether an endpoint is configured at all.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return '' !== self::endpoint();
	}

	/**
	 * The extra headers the CRM asked for, typed one per line as "Name: value".
	 *
	 * Authentication schemes differ from CRM to CRM — a bearer token, an API
	 * key header, a tenant id — so they are configured rather than coded, and
	 * a new scheme does not need a new release.
	 *
	 * @return array<string,string>
	 */
	public static function extra_headers(): array {
		$raw     = (string) Kivun_Admin_Settings::get( 'crm_webhook_headers', '' );
		$headers = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$name                 = trim( $name );
			if ( '' !== $name ) {
				$headers[ $name ] = trim( $value );
			}
		}

		return $headers;
	}

	// ── Triggering ────────────────────────────────────────────────────────────.

	/**
	 * Decide which event a status change is, and queue it.
	 *
	 * @param string   $new_status The status being moved to.
	 * @param string   $old_status The status being moved from.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public static function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'kivun_job' !== $post->post_type || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		// An auto-draft becoming a draft is the editor opening a blank screen.
		if ( 'auto-draft' === $old_status && 'publish' !== $new_status ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			$event = 'publish' === $old_status ? 'job.updated' : 'job.published';
		} elseif ( 'publish' === $old_status ) {
			$event = 'job.closed';
		} else {
			return;
		}

		if ( 'job.updated' === $event && ! (bool) Kivun_Admin_Settings::get( 'crm_webhook_updates', true ) ) {
			return;
		}

		self::queue( $post->ID, $event );
	}

	/**
	 * Queue one event for delivery shortly after the save settles.
	 *
	 * The delay is not politeness: custom fields are written on save_post,
	 * which runs after this transition, so sending immediately would post a
	 * job stripped of everything but its title.
	 *
	 * @param int    $post_id The job.
	 * @param string $event   Event name.
	 * @return void
	 */
	public static function queue( int $post_id, string $event ): void {
		if ( ! self::configured() ) {
			return;
		}

		$args = array( $post_id, $event, 1 );
		if ( ! wp_next_scheduled( 'kivun_crm_send_event', $args ) ) {
			wp_schedule_single_event( time() + 20, 'kivun_crm_send_event', $args );
		}
	}

	// ── Payload ───────────────────────────────────────────────────────────────.

	/**
	 * The names of the terms a job carries in one taxonomy.
	 *
	 * @param int    $post_id  The job.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int,string>
	 */
	private static function term_names( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return array_values( array_map( static fn( $t ) => (string) $t->name, $terms ) );
	}

	/**
	 * Build the body sent for one job.
	 *
	 * Values are sent as they are stored, with two exceptions: the salary is
	 * also split into bounds because a CRM cannot filter on free text, and the
	 * description is offered as plain text beside its HTML because most CRMs
	 * show a note field rather than a web page.
	 *
	 * @param \WP_Post $post  The job.
	 * @param string   $event Event name.
	 * @return array<string,mixed>
	 */
	public static function payload( \WP_Post $post, string $event ): array {
		$get = static function ( string $key ) use ( $post ): string {
			return (string) get_post_meta( $post->ID, $key, true );
		};

		$html   = '' !== $get( '_kivun_description' ) ? $get( '_kivun_description' ) : $post->post_content;
		$salary = Kivun_Mercaz_Sync::split_salary( $get( '_kivun_salary' ) );

		$requirements = array();
		foreach ( Kivun_Mercaz_Sync::to_repeater( $get( '_kivun_requirements' ) ) as $item ) {
			$requirements[] = (string) $item['value'];
		}

		return array(
			'event'      => $event,
			'sent_at'    => wp_date( 'c' ),
			'site'       => home_url( '/' ),
			'delivery'   => wp_generate_uuid4(),
			'job'        => array(
				'id'               => $post->ID,
				'url'              => get_permalink( $post ),
				'title'            => get_the_title( $post ),
				'status'           => $post->post_status,
				'published_at'     => get_post_time( 'c', false, $post ),
				'updated_at'       => get_post_modified_time( 'c', false, $post ),
				'expires_at'       => $get( '_kivun_deadline' ),
				'company'          => $get( '_kivun_company' ),
				'city'             => $get( '_kivun_city' ),
				'description'      => Kivun_AI_Content::plain_text( $html, true ),
				'description_html' => $html,
				'requirements'     => $requirements,
				'salary'           => array(
					'text' => $get( '_kivun_salary' ),
					'min'  => $salary['min'],
					'max'  => $salary['max'],
				),
				'work_hours'       => $get( '_kivun_work_hours' ),
				'experience_years' => $get( '_kivun_experience_years' ),
				'field'            => self::term_names( $post->ID, 'kivun_job_field' ),
				'scope'            => self::term_names( $post->ID, 'kivun_job_scope' ),
				'features'         => self::term_names( $post->ID, 'kivun_job_feature' ),
				'contact_email'    => $get( '_kivun_employer_email' ),
			),
			'applicants' => self::applicant_count( $post->ID ),
		);
	}

	/**
	 * How many applications the job has collected so far.
	 *
	 * @param int $post_id The job.
	 * @return int
	 */
	private static function applicant_count( int $post_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kivun_applications WHERE job_id = %d", $post_id ) );
	}

	// ── Delivery ──────────────────────────────────────────────────────────────.

	/**
	 * POST one body to the CRM.
	 *
	 * @param array<string,mixed> $body  The payload.
	 * @param string              $event Event name.
	 * @return array{code:int}|\WP_Error
	 */
	public static function deliver( array $body, string $event ) {
		$url = self::endpoint();
		if ( '' === $url ) {
			return new \WP_Error( 'kivun_crm_unconfigured', __( 'לא הוגדרה כתובת וובהוק ל-CRM.', 'kivun' ) );
		}

		$json      = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$timestamp = (string) time();

		$headers = array(
			'Content-Type'      => 'application/json; charset=utf-8',
			'Accept'            => 'application/json',
			'X-Kivun-Event'     => $event,
			'X-Kivun-Delivery'  => (string) ( $body['delivery'] ?? '' ),
			'X-Kivun-Timestamp' => $timestamp,
		);

		// The signature covers the timestamp as well as the body, so a captured
		// request cannot be replayed later with its signature still valid.
		$secret = self::secret();
		if ( '' !== $secret ) {
			$headers['X-Kivun-Signature'] = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $json, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array_merge( $headers, self::extra_headers() ),
				'body'    => $json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$message = wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) );
			return new \WP_Error(
				'kivun_crm_http_' . $code,
				'' !== $message
					/* translators: 1: HTTP status code, 2: response body. */
					? sprintf( __( 'ה-CRM החזיר שגיאה %1$d: %2$s', 'kivun' ), $code, mb_substr( $message, 0, 300 ) )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'ה-CRM החזיר שגיאה %d.', 'kivun' ), $code ),
				array( 'status' => $code )
			);
		}

		return array( 'code' => $code );
	}

	/**
	 * Send one queued event, retrying a failure a few times before giving up.
	 *
	 * @param int    $post_id The job.
	 * @param string $event   Event name.
	 * @param int    $attempt Which attempt this is, from 1.
	 * @return array{code:int}|\WP_Error
	 */
	public static function send( int $post_id, string $event = 'job.published', int $attempt = 1 ) {
		$post = get_post( $post_id );
		if ( ! $post || 'kivun_job' !== $post->post_type ) {
			return new \WP_Error( 'kivun_crm_missing', __( 'המשרה לא נמצאה.', 'kivun' ) );
		}

		$result = self::deliver( self::payload( $post, $event ), $event );
		self::record( $event, $result );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, self::LAST_ERROR, $result->get_error_message() );

			// A refusal is not a hiccup: a wrong URL or a rejected token will
			// be refused just as firmly in ten minutes, so only a network
			// failure or a server-side error is worth trying again.
			$status    = (int) ( $result->get_error_data()['status'] ?? 0 );
			$temporary = 0 === $status || $status >= 500 || 429 === $status || 408 === $status;

			if ( $temporary && $attempt < self::MAX_ATTEMPTS ) {
				wp_schedule_single_event(
					time() + ( 300 * ( 2 ** ( $attempt - 1 ) ) ),
					'kivun_crm_send_event',
					array( $post_id, $event, $attempt + 1 )
				);
			}

			return $result;
		}

		update_post_meta( $post_id, self::SENT_AT, current_time( 'mysql' ) );
		delete_post_meta( $post_id, self::LAST_ERROR );

		return $result;
	}

	/**
	 * Remember the outcome of the last delivery, so the settings screen can say
	 * whether the integration is actually working rather than merely configured.
	 *
	 * @param string                    $event  Event name.
	 * @param array<string,mixed>|mixed $result Delivery result or WP_Error.
	 * @return void
	 */
	private static function record( string $event, $result ): void {
		update_option(
			'kivun_crm_last',
			array(
				'time'   => current_time( 'mysql' ),
				'event'  => $event,
				'ok'     => ! is_wp_error( $result ),
				'detail' => is_wp_error( $result )
					? $result->get_error_message()
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'התקבל בהצלחה (%d).', 'kivun' ), (int) ( $result['code'] ?? 0 ) ),
			),
			false
		);
	}

	// ── Documentation ─────────────────────────────────────────────────────────.

	/**
	 * The most recently published job, to document with real values.
	 *
	 * @return \WP_Post|null
	 */
	private static function sample_job(): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'        => 'kivun_job',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * The whole integration described as data, built from the code that sends
	 * it — so the example a CRM developer reads is the request they will get,
	 * and cannot drift away from it as the plugin changes.
	 *
	 * @return array<string,mixed>
	 */
	public static function docs(): array {
		$sample = self::sample_job();
		$body   = $sample
			? self::payload( $sample, 'job.published' )
			: self::example_payload();

		$signed = '' !== self::secret();

		return array(
			'_about'            => array(
				'title'       => 'Kivun — Job webhook',
				'description' => 'אתר כיוון שולח בקשת POST אחת לכל אירוע במשרה. הבקשה נשלחת מהשרת של האתר אל הכתובת שתיתנו, בפורמט JSON בקידוד UTF-8.',
				'site'        => home_url( '/' ),
				'generated'   => wp_date( 'c' ),
				'plugin'      => 'Kivun Center ' . KIVUN_VERSION,
			),
			'endpoint'          => array(
				'method'       => 'POST',
				'url'          => self::endpoint() ? self::endpoint() : 'https://YOUR-CRM.example.com/hooks/kivun-jobs',
				'content_type' => 'application/json; charset=utf-8',
				'timeout'      => '20 שניות. אם לא נענה בזמן — נחשב לכישלון וינוסה שוב.',
			),
			'events'            => self::events(),
			'headers'           => array_filter(
				array(
					'X-Kivun-Event'     => 'שם האירוע, זהה לשדה event בגוף הבקשה.',
					'X-Kivun-Delivery'  => 'מזהה חד-פעמי לכל שליחה (UUID). בניסיון חוזר הוא משתנה, ולכן אין להסתמך עליו לזיהוי כפילויות — לשם כך יש job.id יחד עם event.',
					'X-Kivun-Timestamp' => 'זמן השליחה, Unix time בשניות.',
					'X-Kivun-Signature' => $signed ? 'חתימת HMAC-SHA256, בפורמט sha256=<hex>. ראו signature למטה.' : null,
				)
			),
			'signature'         => $signed
				? array(
					'algorithm' => 'HMAC-SHA256',
					'payload'   => 'המחרוזת שנחתמת היא: <X-Kivun-Timestamp> + "." + גוף הבקשה הגולמי (raw body, לפני פענוח JSON).',
					'header'    => 'X-Kivun-Signature: sha256=<hex>',
					'verify'    => 'hash_hmac("sha256", $timestamp . "." . $raw_body, $secret) — והשוו בהשוואה בטוחת-זמן (hash_equals).',
					'note'      => 'הסוד נמסר בנפרד, לא במסמך הזה. מומלץ לדחות בקשה שה-timestamp שלה ישן מ-5 דקות.',
				)
				: 'לא הוגדר סוד חתימה. אם תרצו שהבקשות ייחתמו — מסרו לנו סוד ונגדיר אותו בצד שלנו.',
			'retries'           => array(
				'attempts' => self::MAX_ATTEMPTS,
				'when'     => 'רק על תקלת רשת, timeout, 408, 429 או שגיאת 5xx. תשובת 4xx אחרת נחשבת לדחייה ולא תנוסה שוב.',
				'backoff'  => '5 דקות, 10 דקות, 20 דקות.',
				'expected' => 'החזירו 200 (או כל 2xx) מיד עם קבלת הבקשה. עיבוד ארוך עדיף שיתבצע אצלכם ברקע.',
			),
			'example_request'   => $body,
			'curl'              => self::curl( $body, $signed ),
			'field_reference'   => array(
				'event'                => 'שם האירוע. אחד מהערכים ברשימת events.',
				'sent_at'              => 'זמן השליחה בתקן ISO-8601, בשעון ישראל.',
				'site'                 => 'כתובת האתר ששלח.',
				'delivery'             => 'מזהה השליחה, זהה לכותרת X-Kivun-Delivery.',
				'job.id'               => 'מזהה המשרה באתר כיוון. יציב לאורך זמן — זה המפתח לשמור אצלכם.',
				'job.url'              => 'הקישור הציבורי למשרה.',
				'job.title'            => 'שם המשרה.',
				'job.status'           => 'סטטוס המשרה באתר: publish, draft, private או pending.',
				'job.published_at'     => 'מועד הפרסום, ISO-8601.',
				'job.updated_at'       => 'מועד העדכון האחרון, ISO-8601.',
				'job.expires_at'       => 'תאריך אחרון להגשה, בפורמט YYYY-MM-DD. מחרוזת ריקה כשלא הוגדר.',
				'job.company'          => 'שם המעסיק.',
				'job.city'             => 'ישוב.',
				'job.description'      => 'תיאור המשרה כטקסט נקי, עם שורות.',
				'job.description_html' => 'אותו תיאור כ-HTML, למי שמציג עיצוב.',
				'job.requirements'     => 'מערך של דרישות, שורה לכל דרישה.',
				'job.salary.text'      => 'השכר כפי שנכתב, למשל "8,000-11,000 ₪".',
				'job.salary.min'       => 'הגבול התחתון כמספר במחרוזת, אם ניתן היה לחלץ.',
				'job.salary.max'       => 'הגבול העליון כמספר במחרוזת, אם ניתן היה לחלץ.',
				'job.work_hours'       => 'שעות העבודה כטקסט חופשי.',
				'job.experience_years' => 'שנות ניסיון נדרשות.',
				'job.field'            => 'תחום המשרה — מערך של שמות.',
				'job.scope'            => 'היקף המשרה — מערך של שמות.',
				'job.features'         => 'מאפיינים נוספים — מערך של שמות.',
				'job.contact_email'    => 'אימייל איש הקשר אצל המעסיק.',
				'applicants'           => 'כמה מועמדויות נקלטו למשרה עד רגע השליחה.',
			),
			'notes'             => array(
				'שדה שאין לו ערך נשלח כמחרוזת ריקה או כמערך ריק — הוא לא נעלם מהבקשה, כך שהמבנה קבוע.',
				'job.id יחד עם event הם מה שמזהה אירוע. אם קיבלתם פעמיים את אותו צירוף — זהו ניסיון חוזר, ואפשר להתעלם.',
				'עריכה של משרה מפורסמת שולחת job.updated. אפשר לכבות את זה אצלנו אם אתם מעדיפים לקבל רק פרסום וסגירה.',
				'המשרה נשלחת כ-20 שניות אחרי השמירה, כדי שכל השדות יהיו כתובים.',
			),
			'questions_for_you' => array(
				'1. מה כתובת ה-Webhook שאליה נשלח?',
				'2. איך מזדהים? (Bearer token, כותרת API key בשם מסוים, או חתימת HMAC בסוד משותף)',
				'3. מספיק לכם המבנה הזה, או שאתם צריכים שמות שדות אחרים / מבנה שטוח?',
				'4. לקבל גם job.updated ו-job.closed, או רק job.published?',
				'5. האם אתם מחזירים מזהה של הרשומה אצלכם בגוף התשובה? אם כן — נשמור אותו ליד המשרה.',
			),
		);
	}

	/**
	 * The same request written as a curl command, so it can be replayed against
	 * a CRM endpoint under construction without waiting for a real vacancy.
	 *
	 * @param array<string,mixed> $body   The payload.
	 * @param bool                $signed Whether requests carry a signature.
	 * @return string
	 */
	private static function curl( array $body, bool $signed ): string {
		$url  = self::endpoint() ? self::endpoint() : 'https://YOUR-CRM.example.com/hooks/kivun-jobs';
		$json = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$lines = array(
			"curl -X POST '" . $url . "' \\",
			"  -H 'Content-Type: application/json; charset=utf-8' \\",
			"  -H 'X-Kivun-Event: " . (string) $body['event'] . "' \\",
			"  -H 'X-Kivun-Delivery: " . (string) $body['delivery'] . "' \\",
			"  -H 'X-Kivun-Timestamp: " . time() . "' \\",
		);

		if ( $signed ) {
			$lines[] = "  -H 'X-Kivun-Signature: sha256=<hmac>' \\";
		}
		foreach ( array_keys( self::extra_headers() ) as $name ) {
			$lines[] = "  -H '" . $name . ": <value>' \\";
		}

		$lines[] = "  -d '" . str_replace( "'", "'\\''", $json ) . "'";

		return implode( "\n", $lines );
	}

	/**
	 * A payload with plausible values, for when the site has no published job
	 * yet and the documentation still needs to show a complete example.
	 *
	 * @return array<string,mixed>
	 */
	private static function example_payload(): array {
		return array(
			'event'      => 'job.published',
			'sent_at'    => wp_date( 'c' ),
			'site'       => home_url( '/' ),
			'delivery'   => wp_generate_uuid4(),
			'job'        => array(
				'id'               => 1234,
				'url'              => home_url( '/job/example/' ),
				'title'            => 'פקיד/ת קבלה',
				'status'           => 'publish',
				'published_at'     => wp_date( 'c' ),
				'updated_at'       => wp_date( 'c' ),
				'expires_at'       => '2026-12-31',
				'company'          => 'חברה לדוגמה',
				'city'             => 'ירושלים',
				'description'      => "קבלת קהל, מענה טלפוני וניהול יומן.\nעבודה בסביבה נעימה.",
				'description_html' => '<p>קבלת קהל, מענה טלפוני וניהול יומן.</p>',
				'requirements'     => array( 'ניסיון בשירות לקוחות', 'שליטה באופיס' ),
				'salary'           => array(
					'text' => '8,000-11,000 ₪',
					'min'  => '8000',
					'max'  => '11000',
				),
				'work_hours'       => '08:00-16:00',
				'experience_years' => '2',
				'field'            => array( 'אדמיניסטרציה' ),
				'scope'            => array( 'משרה מלאה' ),
				'features'         => array( 'עבודה מהבית' ),
				'contact_email'    => 'jobs@example.com',
			),
			'applicants' => 0,
		);
	}

	/**
	 * Serve the documentation as a JSON file to hand to the CRM's developers.
	 *
	 * @return void
	 */
	public static function download_docs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה.', 'kivun' ) );
		}
		check_admin_referer( 'kivun_crm_docs' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kivun-job-webhook.json"' );

		echo wp_json_encode( self::docs(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// ── Jobs list column ──────────────────────────────────────────────────────.

	/**
	 * Add a CRM column to the jobs list, so a job that never reached the CRM is
	 * visible where the jobs are, rather than only in a log.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function column( array $columns ): array {
		if ( ! self::configured() ) {
			return $columns;
		}
		$columns['kivun_crm'] = __( 'CRM', 'kivun' );
		return $columns;
	}

	/**
	 * Render the CRM column for one job.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id The job.
	 * @return void
	 */
	public static function column_value( string $column, int $post_id ): void {
		if ( 'kivun_crm' !== $column ) {
			return;
		}

		$sent  = (string) get_post_meta( $post_id, self::SENT_AT, true );
		$error = (string) get_post_meta( $post_id, self::LAST_ERROR, true );

		if ( '' !== $error ) {
			echo '<span style="color:#b32d2e">' . esc_html( $error ) . '</span><br>';
		} elseif ( '' !== $sent ) {
			echo esc_html( sprintf( /* translators: %s: date and time. */ __( 'נשלח ב-%s', 'kivun' ), wp_date( 'd/m/Y H:i', strtotime( $sent ) ) ) ) . '<br>';
		} else {
			echo '<span class="description">' . esc_html__( 'טרם נשלח', 'kivun' ) . '</span><br>';
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=kivun_crm_resend&post=' . $post_id ),
			'kivun_crm_resend_' . $post_id
		);
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'שליחה עכשיו', 'kivun' ) . '</a>';
	}

	/**
	 * Send one job to the CRM on request, and return to where the request came
	 * from with the outcome shown.
	 *
	 * @return void
	 */
	public static function resend(): void {
		$post_id = absint( wp_unslash( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
		check_admin_referer( 'kivun_crm_resend_' . $post_id );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לשלוח את המשרה הזו.', 'kivun' ) );
		}

		$result = self::send( $post_id, 'job.published' );
		$back   = add_query_arg(
			'kivun_crm_sent',
			is_wp_error( $result ) ? 'fail' : 'ok',
			wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=kivun_job' )
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Report the outcome of a manual send.
	 *
	 * @return void
	 */
	public static function resend_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of an outcome flag.
		$state = isset( $_GET['kivun_crm_sent'] ) ? sanitize_key( wp_unslash( $_GET['kivun_crm_sent'] ) ) : '';
		if ( '' === $state ) {
			return;
		}

		$last  = get_option( 'kivun_crm_last', array() );
		$class = 'ok' === $state ? 'notice-success' : 'notice-error';
		$text  = 'ok' === $state
			? __( 'המשרה נשלחה ל-CRM.', 'kivun' )
			: sprintf(
				/* translators: %s: error message from the CRM. */
				__( 'השליחה ל-CRM נכשלה: %s', 'kivun' ),
				is_array( $last ) ? (string) ( $last['detail'] ?? '' ) : ''
			);

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────.

	/**
	 * AJAX: send a test event, so the connection can be proven before a real
	 * job depends on it.
	 *
	 * @return void
	 */
	public static function ajax_test(): void {
		check_ajax_referer( 'kivun_crm', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה.', 'kivun' ) ) );
		}
		if ( ! self::configured() ) {
			wp_send_json_error( array( 'message' => __( 'מלאו את כתובת הוובהוק ושמרו, ואז אפשר לבדוק.', 'kivun' ) ) );
		}

		$sample = self::sample_job();
		$body   = $sample ? self::payload( $sample, 'job.published' ) : self::example_payload();
		// Marked so nobody at the CRM mistakes the test for a live vacancy.
		$body['test'] = true;

		$result = self::deliver( $body, 'job.published' );
		self::record( 'test', $result );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'נשלחה בקשת בדיקה וה-CRM אישר אותה (%d).', 'kivun' ),
					(int) $result['code']
				),
				'note'    => $sample
					/* translators: %s: job title. */
					? sprintf( __( 'נשלחה המשרה "%s" עם הסימון test: true.', 'kivun' ), get_the_title( $sample ) )
					: __( 'עדיין אין משרה מפורסמת באתר, ולכן נשלחה משרה לדוגמה עם הסימון test: true.', 'kivun' ),
			)
		);
	}
}
