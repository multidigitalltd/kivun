<?php
/**
 * UTM campaigns and their tracking links.
 *
 * Kivun_Utm captures utm_* parameters on arrival and folds them into each
 * lead's "source" column. This is the other half: building the tagged links,
 * and reporting how many leads each one produced.
 *
 * A campaign is a container — one promotion, one event — and holds many links
 * beneath it, typically one per publisher pushing it. That way the campaign
 * total and the per-publisher breakdown come from the same place.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, stores and reports on UTM campaigns and their links.
 */
class Kivun_Campaigns {

	/**
	 * Register the AJAX handlers.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_save_campaign', array( __CLASS__, 'ajax_save_campaign' ) );
		add_action( 'wp_ajax_kivun_delete_campaign', array( __CLASS__, 'ajax_delete_campaign' ) );
		add_action( 'wp_ajax_kivun_save_campaign_link', array( __CLASS__, 'ajax_save_link' ) );
		add_action( 'wp_ajax_kivun_delete_campaign_link', array( __CLASS__, 'ajax_delete_link' ) );
		add_action( 'wp_ajax_kivun_campaign_whatsapp', array( __CLASS__, 'ajax_whatsapp' ) );
		add_action( 'wp_ajax_kivun_save_link_whatsapp', array( __CLASS__, 'ajax_save_whatsapp' ) );
	}

	/**
	 * Who may build and see campaigns. Campaign data is marketing reporting
	 * rather than personal data, but it sits beside the leads, so it follows
	 * the same bar as the leads CRM.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Kivun_Content_Creator::can_manage_leads();
	}

	/**
	 * Common utm_source values, offered as suggestions so the same channel is
	 * not recorded three different ways ("Facebook" / "facebook" / "FB"),
	 * which would split one campaign across three rows in every report.
	 *
	 * @return array<string,string>
	 */
	public static function sources(): array {
		return array(
			'facebook'   => 'Facebook',
			'instagram'  => 'Instagram',
			'google'     => 'Google',
			'whatsapp'   => 'WhatsApp',
			'newsletter' => __( 'ניוזלטר', 'kivun' ),
			'sms'        => 'SMS',
			'telegram'   => 'Telegram',
			'linkedin'   => 'LinkedIn',
			'youtube'    => 'YouTube',
			'print'      => __( 'פרסום מודפס', 'kivun' ),
			'partner'    => __( 'שותף / ארגון', 'kivun' ),
		);
	}

	/**
	 * Common utm_medium values.
	 *
	 * @return array<string,string>
	 */
	public static function mediums(): array {
		return array(
			'cpc'      => __( 'קמפיין ממומן (cpc)', 'kivun' ),
			'organic'  => __( 'אורגני', 'kivun' ),
			'social'   => __( 'רשתות חברתיות', 'kivun' ),
			'email'    => __( 'אימייל', 'kivun' ),
			'sms'      => 'SMS',
			'banner'   => __( 'באנר', 'kivun' ),
			'referral' => __( 'הפניה מאתר אחר', 'kivun' ),
			'qr'       => __( 'קוד QR', 'kivun' ),
		);
	}

	/**
	 * Build a tagged URL. Existing query parameters on the target are kept and
	 * any utm_* already present is replaced, so re-tagging a link is safe.
	 *
	 * @param string               $target The destination URL.
	 * @param array<string,string> $utm    The utm_* values (unprefixed keys).
	 * @return string
	 */
	public static function build_url( string $target, array $utm ): string {
		$args = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $key ) {
			if ( ! empty( $utm[ $key ] ) ) {
				$args[ 'utm_' . $key ] = rawurlencode( $utm[ $key ] );
			}
		}
		if ( ! $args ) {
			return $target;
		}

		$target = remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ), $target );
		return add_query_arg( $args, $target );
	}

	/**
	 * Normalise a UTM value: lower-case, spaces to hyphens, no stray
	 * punctuation. Analytics tools treat "Summer 26" and "summer-26" as two
	 * campaigns, so the value is cleaned before it is ever stored.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	public static function clean_value( string $raw ): string {
		$raw = sanitize_text_field( $raw );
		$raw = str_replace( array( ' ', '_' ), '-', trim( $raw ) );
		// Hebrew is valid in a URL, so only strip characters that break parsing.
		$raw = preg_replace( '/[^\p{L}\p{N}\-\.]+/u', '', $raw );
		$raw = preg_replace( '/-{2,}/', '-', (string) $raw );
		return mb_strtolower( trim( (string) $raw, '-' ) );
	}

	/**
	 * A stored link's arrival label — the exact string Kivun_Utm writes into a
	 * lead's source column when someone arrives through it.
	 *
	 * @param array<string,string> $utm Source, medium, campaign and content.
	 * @return string
	 */
	private static function utm_label( array $utm ): string {
		$parts = array();
		foreach ( array( 'source', 'medium', 'campaign', 'content' ) as $key ) {
			if ( ! empty( $utm[ $key ] ) ) {
				$parts[] = $utm[ $key ];
			}
		}
		return implode( ' / ', $parts );
	}

	/**
	 * Reduce a promo to the plain text WhatsApp actually sends.
	 *
	 * Real tags are removed by sanitize_textarea_field(), but one that arrives
	 * entity-encoded is invisible to it, and neither belongs in a message. The
	 * check runs first so that a promo merely mentioning a "<" is left exactly
	 * as it was written — stripping is for markup, not for punctuation.
	 *
	 * @param string $text The submitted or stored message.
	 * @return string
	 */
	public static function clean_promo( string $text ): string {
		return preg_match( '#</?[a-z][^>]*>|&lt;/?[a-z]#i', $text )
			? Kivun_AI_Content::plain_text( $text, true )
			: $text;
	}

	/**
	 * A destination only has to be a real http(s) address. wp_http_validate_url()
	 * is deliberately not used: it vets URLs for outbound server requests and
	 * rejects private hosts and non-standard ports, which are perfectly valid
	 * destinations for a marketing link.
	 *
	 * @param string $target The URL to check.
	 * @return bool
	 */
	private static function valid_target( string $target ): bool {
		$parsed = wp_parse_url( $target );
		return (bool) $target
			&& ! empty( $parsed['host'] )
			&& in_array( strtolower( $parsed['scheme'] ?? '' ), array( 'http', 'https' ), true );
	}

	/**
	 * Shared guard for every campaign write.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל קמפיינים.', 'kivun' ) ) );
		}
	}

	// ── Campaigns ─────────────────────────────────────────────────────────────.

	/**
	 * Create or update a campaign — the container the links hang from.
	 *
	 * @return void
	 */
	public static function ajax_save_campaign(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$name   = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$slug   = self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ?? '' ) ) );
		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $slug ) {
			$slug = self::clean_value( $name );
		}
		if ( '' === $name ) {
			$name = $slug;
		}

		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'יש לתת שם לקמפיין.', 'kivun' ) ) );
		}
		if ( ! self::valid_target( $target ) ) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור יעד תקין לקמפיין (כתובת מלאה שמתחילה ב-http/https).', 'kivun' ) ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_campaigns WHERE utm_campaign = %s AND id <> %d",
				$slug,
				$id
			)
		);
		if ( $exists ) {
			wp_send_json_error( array( 'message' => __( 'כבר קיים קמפיין בשם הזה.', 'kivun' ) ) );
		}

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_campaigns WHERE id = %d", $id ) );
			if ( ! $current ) {
				wp_send_json_error( array( 'message' => __( 'הקמפיין לא נמצא.', 'kivun' ) ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update(
				$wpdb->prefix . 'kivun_campaigns',
				array(
					'label'        => $name,
					'target_url'   => $target,
					'utm_campaign' => $slug,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $ok ) {
				wp_send_json_error( array( 'message' => __( 'עדכון הקמפיין נכשל.', 'kivun' ) ) );
			}

			// The identifier is part of every link built under the campaign, so
			// renaming it rewrites them all — otherwise the links keep pointing
			// at a campaign name that no longer exists.
			if ( (string) $current->utm_campaign !== $slug ) {
				self::rebuild_links( $id, $slug );
			}

			wp_send_json_success( array( 'message' => __( 'הקמפיין עודכן.', 'kivun' ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$wpdb->prefix . 'kivun_campaigns',
			array(
				'label'        => $name,
				'target_url'   => $target,
				'final_url'    => '',
				'utm_source'   => '',
				'utm_medium'   => '',
				'utm_campaign' => $slug,
				'utm_term'     => '',
				'utm_content'  => '',
				'created_by'   => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => __( 'שמירת הקמפיין נכשלה.', 'kivun' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'הקמפיין נוצר.', 'kivun' ) ) );
	}

	/**
	 * Rewrite every link under a campaign after its identifier changed.
	 *
	 * @param int    $campaign_id The campaign.
	 * @param string $slug        The new utm_campaign value.
	 * @return void
	 */
	private static function rebuild_links( int $campaign_id, string $slug ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$links = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_campaign_links WHERE campaign_id = %d", $campaign_id )
		);

		foreach ( (array) $links as $link ) {
			$utm = array(
				'source'   => (string) $link->utm_source,
				'medium'   => (string) $link->utm_medium,
				'campaign' => $slug,
				'term'     => (string) $link->utm_term,
				'content'  => (string) $link->utm_content,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'kivun_campaign_links',
				array(
					'final_url' => self::build_url( (string) $link->target_url, $utm ),
					'utm_label' => self::utm_label( $utm ),
				),
				array( 'id' => (int) $link->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Delete a campaign and the links under it. The leads they produced are
	 * untouched — they carry their own source label.
	 *
	 * @return void
	 */
	public static function ajax_delete_campaign(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_campaign_links', array( 'campaign_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_campaigns', array( 'id' => $id ), array( '%d' ) );

		wp_send_json_success();
	}

	// ── Links ─────────────────────────────────────────────────────────────────.

	/**
	 * Add a tracking link to a campaign, or update an existing one.
	 *
	 * @return void
	 */
	public static function ajax_save_link(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$id          = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$campaign_id = absint( wp_unslash( $_POST['campaign_id'] ?? 0 ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_campaigns WHERE id = %d", $campaign_id ) );
		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'הקמפיין לא נמצא.', 'kivun' ) ) );
		}

		$label    = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$whatsapp = self::clean_promo( sanitize_textarea_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ) );
		$target   = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		if ( ! self::valid_target( $target ) ) {
			$target = (string) $campaign->target_url;
		}

		$utm = array(
			'source'   => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_source'] ?? '' ) ) ),
			'medium'   => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_medium'] ?? '' ) ) ),
			'campaign' => (string) $campaign->utm_campaign,
			'term'     => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_term'] ?? '' ) ) ),
			'content'  => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_content'] ?? '' ) ) ),
		);

		if ( '' === $utm['source'] ) {
			wp_send_json_error( array( 'message' => __( 'יש להזין מקור לקישור.', 'kivun' ) ) );
		}
		if ( ! self::valid_target( $target ) ) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור יעד תקין לקישור.', 'kivun' ) ) );
		}

		// Two links in one campaign that resolve to the same arrival label are
		// indistinguishable in the leads table — their counts would merge.
		$utm_label = self::utm_label( $utm );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$clash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_campaign_links WHERE campaign_id = %d AND utm_label = %s AND id <> %d",
				$campaign_id,
				$utm_label,
				$id
			)
		);
		if ( $clash ) {
			wp_send_json_error(
				array(
					'message' => __( 'כבר קיים קישור זהה בקמפיין. הוסיפו "מזהה פרסום" כדי להבדיל ביניהם.', 'kivun' ),
				)
			);
		}

		$data   = array(
			'campaign_id' => $campaign_id,
			'label'       => '' !== $label ? $label : $utm['source'],
			'target_url'  => $target,
			'final_url'   => self::build_url( $target, $utm ),
			'utm_source'  => $utm['source'],
			'utm_medium'  => $utm['medium'],
			'utm_term'    => $utm['term'],
			'utm_content' => $utm['content'],
			'utm_label'   => $utm_label,
			'whatsapp'    => $whatsapp,
		);
		$format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update(
				$wpdb->prefix . 'kivun_campaign_links',
				$data,
				array(
					'id'          => $id,
					'campaign_id' => $campaign_id,
				),
				$format,
				array( '%d', '%d' )
			);

			if ( false === $ok ) {
				wp_send_json_error( array( 'message' => __( 'עדכון הקישור נכשל.', 'kivun' ) ) );
			}

			wp_send_json_success( array( 'message' => __( 'הקישור עודכן.', 'kivun' ) ) );
		}

		$data['created_by'] = get_current_user_id();
		$format[]           = '%d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( $wpdb->prefix . 'kivun_campaign_links', $data, $format );

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => __( 'שמירת הקישור נכשלה.', 'kivun' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'הקישור נוסף.', 'kivun' ) ) );
	}

	/**
	 * Save just the WhatsApp message of one link.
	 *
	 * Rewording a promo is the most frequent change of all, and it changes
	 * nothing about the link itself — so it is saved on its own rather than by
	 * resubmitting the whole link and rebuilding its URL.
	 *
	 * @return void
	 */
	public static function ajax_save_whatsapp(): void {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.
		$id   = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$text = self::clean_promo( sanitize_textarea_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'הקישור לא נמצא.', 'kivun' ) ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$wpdb->prefix . 'kivun_campaign_links',
			array( 'whatsapp' => $text ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => __( 'שמירת ההודעה נכשלה.', 'kivun' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'ההודעה נשמרה.', 'kivun' ) ) );
	}

	/**
	 * Delete a single tracking link.
	 *
	 * @return void
	 */
	public static function ajax_delete_link(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_campaign_links', array( 'id' => $id ), array( '%d' ) );

		wp_send_json_success();
	}

	// ── WhatsApp promo per link ───────────────────────────────────────────────.

	/**
	 * The content fields behind a campaign's destination.
	 *
	 * The campaign points at a page, and that page is usually one of ours, so
	 * its own title and details are the material a promo should be written
	 * from. When the destination is not a local post — an external page, say —
	 * only the campaign name is known, and the promo is written from that.
	 *
	 * @param object $campaign The campaign row.
	 * @return array<string,string>
	 */
	private static function target_fields( $campaign ): array {
		$fields = array(
			'type'     => '',
			'title'    => (string) $campaign->label,
			'short'    => '',
			'audience' => '',
			'duration' => '',
			'cost'     => '',
			'date'     => '',
			'location' => '',
		);

		$post_id = url_to_postid( (string) $campaign->target_url );
		if ( ! $post_id ) {
			return $fields;
		}

		$type_keys = array(
			'kivun_workshop' => 'landing',
			'kivun_course'   => 'course',
			'kivun_session'  => 'session',
			'kivun_event'    => 'event',
		);
		$post_type = (string) get_post_type( $post_id );

		$meta = static function ( $key ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, $key, true );
		};

		$fields['type']  = $type_keys[ $post_type ] ?? '';
		$fields['title'] = get_the_title( $post_id );

		// Each type keeps the same information under its own keys, so the first
		// one that holds a value is the one this post uses.
		foreach ( array(
			'short'    => array( '_kivun_lp_short', '_kivun_session_short', '_kivun_event_short' ),
			'audience' => array( '_kivun_target_audience', '_kivun_ws_audience', '_kivun_session_audience', '_kivun_event_audience' ),
			'duration' => array( '_kivun_duration', '_kivun_ws_duration', '_kivun_session_duration' ),
			'cost'     => array( '_kivun_price', '_kivun_lp_cost', '_kivun_session_cost', '_kivun_event_cost' ),
			'date'     => array( '_kivun_schedule', '_kivun_ws_date', '_kivun_session_date', '_kivun_event_time' ),
			'location' => array( '_kivun_session_location', '_kivun_ws_location', '_kivun_event_location' ),
		) as $field => $keys ) {
			foreach ( $keys as $key ) {
				$value = $meta( $key );
				if ( '' !== trim( $value ) ) {
					$fields[ $field ] = $value;
					break;
				}
			}
		}

		if ( '' === trim( $fields['short'] ) ) {
			$fields['short'] = (string) get_the_excerpt( $post_id );
		}

		// These come straight out of rich-text fields and hold markup. A promo
		// is plain text — WhatsApp has no tags — and the markup does more than
		// show through: handed to the model as if it were prose, it comes back
		// imitated, so the message it writes is wrapped in tags of its own.
		foreach ( $fields as $key => $value ) {
			if ( 'type' !== $key ) {
				$fields[ $key ] = Kivun_AI_Content::plain_text( (string) $value );
			}
		}

		return $fields;
	}

	/**
	 * Draft a WhatsApp promo carrying one link's own tracked URL.
	 *
	 * The point of writing it here rather than in the content editor is the
	 * link: a promo sent by one publisher has to carry that publisher's URL, or
	 * every message leads to the same untagged page and the campaign cannot
	 * tell them apart.
	 *
	 * @return void
	 */
	public static function ajax_whatsapp(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$campaign_id = absint( wp_unslash( $_POST['campaign_id'] ?? 0 ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_campaigns WHERE id = %d", $campaign_id ) );
		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'הקמפיין לא נמצא.', 'kivun' ) ) );
		}

		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		if ( ! self::valid_target( $target ) ) {
			$target = (string) $campaign->target_url;
		}

		$utm = array(
			'source'   => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_source'] ?? '' ) ) ),
			'medium'   => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_medium'] ?? '' ) ) ),
			'campaign' => (string) $campaign->utm_campaign,
			'content'  => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_content'] ?? '' ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $utm['source'] ) {
			wp_send_json_error( array( 'message' => __( 'יש להזין מקור לפני יצירת ההודעה, כדי שהקישור יהיה מסומן.', 'kivun' ) ) );
		}

		$fields        = self::target_fields( $campaign );
		$fields['url'] = self::build_url( $target, $utm );

		if ( '' === trim( (string) Kivun_Admin_Settings::get( 'openai_api_key', '' ) ) ) {
			wp_send_json_success(
				array(
					'text'   => Kivun_AI_Content::whatsapp_template( $fields ),
					'url'    => $fields['url'],
					'source' => 'template',
				)
			);
		}

		$text = Kivun_AI_Content::generate_whatsapp( $fields );
		if ( is_wp_error( $text ) ) {
			// A failed call should still leave something usable in the box.
			wp_send_json_success(
				array(
					'text'   => Kivun_AI_Content::whatsapp_template( $fields ),
					'url'    => $fields['url'],
					'source' => 'template',
					'notice' => $text->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'text'   => $text,
				'url'    => $fields['url'],
				'source' => 'ai',
			)
		);
	}

	// ── Reporting ─────────────────────────────────────────────────────────────.

	/**
	 * All campaigns, newest first.
	 *
	 * @return array<int,object>
	 */
	public static function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kivun_campaigns ORDER BY created_at DESC, id DESC" );
	}

	/**
	 * Every link, grouped by the campaign it belongs to.
	 *
	 * @return array<int,array<int,object>>
	 */
	public static function links_by_campaign(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kivun_campaign_links ORDER BY id ASC" );

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->campaign_id ][] = $row;
		}
		return $out;
	}

	/**
	 * How many leads arrived through each link.
	 *
	 * Leads now record their utm_* values in columns of their own, so a link is
	 * matched on the values themselves — campaign, source, medium and content —
	 * rather than on the rendered "a / b / c" label they used to be compared
	 * by. That label dropped empty values, so its parts could not be told
	 * apart with certainty, and it changed whenever a link was edited.
	 *
	 * Leads captured before those columns existed are still matched the old
	 * way, on the label at the end of their source text.
	 *
	 * @return array<int,int> Lead count keyed by link id.
	 */
	public static function link_lead_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT l.id AS link_id, COUNT(r.id) AS total
			 FROM {$wpdb->prefix}kivun_campaign_links l
			 INNER JOIN {$wpdb->prefix}kivun_campaigns c ON c.id = l.campaign_id
			 LEFT JOIN {$wpdb->prefix}kivun_registrations r ON (
			     (
			       r.utm_campaign <> ''
			       AND r.utm_campaign = c.utm_campaign
			       AND r.utm_source   = l.utm_source
			       AND r.utm_medium   = l.utm_medium
			       AND r.utm_content  = l.utm_content
			     )
			     OR (
			       r.utm_campaign = ''
			       AND l.utm_label <> ''
			       AND r.source LIKE CONCAT('%UTM: ', l.utm_label)
			     )
			 )
			 GROUP BY l.id"
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->link_id ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * How many leads a campaign brought in total, counted on the campaign
	 * itself rather than by summing its links.
	 *
	 * The two can differ, and the difference is worth seeing: a lead that
	 * arrived tagged with the campaign but through a link that was since
	 * edited or deleted belongs to the campaign and to no row under it. Summing
	 * the rows alone would quietly lose it.
	 *
	 * @return array<int,int> Lead count keyed by campaign id.
	 */
	public static function campaign_lead_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT c.id AS campaign_id, COUNT(r.id) AS total
			 FROM {$wpdb->prefix}kivun_campaigns c
			 LEFT JOIN {$wpdb->prefix}kivun_registrations r
			   ON r.utm_campaign <> '' AND r.utm_campaign = c.utm_campaign
			 GROUP BY c.id"
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->campaign_id ] = (int) $row->total;
		}
		return $counts;
	}
}
