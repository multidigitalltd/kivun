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
	 * Create a campaign — the container the links hang from.
	 *
	 * @return void
	 */
	public static function ajax_save_campaign(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$name   = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$slug   = self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ?? '' ) ) );
		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );

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
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kivun_campaigns WHERE utm_campaign = %s", $slug ) );
		if ( $exists ) {
			wp_send_json_error( array( 'message' => __( 'כבר קיים קמפיין בשם הזה.', 'kivun' ) ) );
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
	 * Add a tracking link to a campaign.
	 *
	 * @return void
	 */
	public static function ajax_save_link(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() verifies the nonce.

		$campaign_id = absint( wp_unslash( $_POST['campaign_id'] ?? 0 ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kivun_campaigns WHERE id = %d", $campaign_id ) );
		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'הקמפיין לא נמצא.', 'kivun' ) ) );
		}

		$label  = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
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
				"SELECT id FROM {$wpdb->prefix}kivun_campaign_links WHERE campaign_id = %d AND utm_label = %s",
				$campaign_id,
				$utm_label
			)
		);
		if ( $clash ) {
			wp_send_json_error(
				array(
					'message' => __( 'כבר קיים קישור זהה בקמפיין. הוסיפו "מזהה פרסום" כדי להבדיל ביניהם.', 'kivun' ),
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$wpdb->prefix . 'kivun_campaign_links',
			array(
				'campaign_id' => $campaign_id,
				'label'       => '' !== $label ? $label : $utm['source'],
				'target_url'  => $target,
				'final_url'   => self::build_url( $target, $utm ),
				'utm_source'  => $utm['source'],
				'utm_medium'  => $utm['medium'],
				'utm_term'    => $utm['term'],
				'utm_content' => $utm['content'],
				'utm_label'   => $utm_label,
				'created_by'  => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => __( 'שמירת הקישור נכשלה.', 'kivun' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'הקישור נוסף.', 'kivun' ) ) );
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
	 * Kivun_Utm appends its label to the end of the lead's source column, so
	 * the stored value always ENDS with the arrival label. Matching on that
	 * suffix keeps two links apart even when one label is a prefix of another
	 * (the same source and medium, distinguished only by utm_content).
	 *
	 * @return array<int,int> Lead count keyed by link id.
	 */
	public static function link_lead_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT l.id AS link_id, COUNT(r.id) AS total
			 FROM {$wpdb->prefix}kivun_campaign_links l
			 LEFT JOIN {$wpdb->prefix}kivun_registrations r
			   ON r.source LIKE CONCAT('%UTM: ', l.utm_label)
			 WHERE l.utm_label <> ''
			 GROUP BY l.id"
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->link_id ] = (int) $row->total;
		}
		return $counts;
	}
}
