<?php
/**
 * UTM campaign link builder.
 *
 * Kivun_Utm already captures utm_* parameters on arrival and folds them into
 * each lead's "source" column. This is the other half: building the tagged
 * links in the first place, keeping them in one place, and reporting how many
 * leads each one actually produced.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, stores and reports on UTM campaign links.
 */
class Kivun_Campaigns {

	/**
	 * Register the AJAX handlers.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_save_campaign', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_kivun_delete_campaign', array( __CLASS__, 'ajax_delete' ) );
	}

	/**
	 * Who may build and see campaign links. Campaign data is marketing
	 * reporting rather than personal data, but it sits beside the leads, so it
	 * follows the same bar as the leads CRM.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Kivun_Content_Creator::can_manage_leads();
	}

	/**
	 * Common utm_source values, offered as a list so the same channel is not
	 * recorded three different ways ("Facebook" / "facebook" / "FB"), which
	 * would split one campaign across three rows in every report.
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

	// ── Save ──────────────────────────────────────────────────────────────────.

	/**
	 * Store a campaign link.
	 *
	 * @return void
	 */
	public static function ajax_save(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל קמפיינים.', 'kivun' ) ) );
		}

		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		$label  = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );

		$utm = array(
			'source'   => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_source'] ?? '' ) ) ),
			'medium'   => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_medium'] ?? '' ) ) ),
			'campaign' => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ?? '' ) ) ),
			'term'     => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_term'] ?? '' ) ) ),
			'content'  => self::clean_value( sanitize_text_field( wp_unslash( $_POST['utm_content'] ?? '' ) ) ),
		);

		// wp_http_validate_url() is meant for outbound requests: it rejects
		// private hosts and non-standard ports, which are perfectly valid
		// destinations for a marketing link (staging sites, local domains).
		// A stored link only needs a real scheme and host.
		$parsed = wp_parse_url( $target );
		if (
			! $target ||
			empty( $parsed['host'] ) ||
			! in_array( strtolower( $parsed['scheme'] ?? '' ), array( 'http', 'https' ), true )
		) {
			wp_send_json_error( array( 'message' => __( 'יש לבחור יעד תקין לקישור (כתובת מלאה שמתחילה ב-http/https).', 'kivun' ) ) );
		}
		if ( '' === $utm['source'] || '' === $utm['campaign'] ) {
			wp_send_json_error( array( 'message' => __( 'מקור ושם קמפיין הם שדות חובה.', 'kivun' ) ) );
		}

		$final = self::build_url( $target, $utm );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$wpdb->prefix . 'kivun_campaigns',
			array(
				'label'        => $label ? $label : $utm['campaign'],
				'target_url'   => $target,
				'final_url'    => $final,
				'utm_source'   => $utm['source'],
				'utm_medium'   => $utm['medium'],
				'utm_campaign' => $utm['campaign'],
				'utm_term'     => $utm['term'],
				'utm_content'  => $utm['content'],
				'created_by'   => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $ok ) {
			wp_send_json_error( array( 'message' => __( 'שמירת הקמפיין נכשלה.', 'kivun' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'הקמפיין נשמר.', 'kivun' ),
				'url'     => $final,
			)
		);
	}

	/**
	 * Delete a stored campaign. The leads it produced are untouched — they
	 * carry their own source label.
	 *
	 * @return void
	 */
	public static function ajax_delete(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה לנהל קמפיינים.', 'kivun' ) ) );
		}

		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			wp_send_json_error();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'kivun_campaigns', array( 'id' => $id ), array( '%d' ) );

		wp_send_json_success();
	}

	// ── Reporting ─────────────────────────────────────────────────────────────.

	/**
	 * All stored campaigns, newest first.
	 *
	 * @return array<int,object>
	 */
	public static function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kivun_campaigns ORDER BY created_at DESC" );
	}

	/**
	 * How many leads arrived through each campaign.
	 *
	 * Kivun_Utm writes the arrival label into the lead's source column as
	 * "UTM: source / medium / campaign", so the campaign name is matched
	 * inside that string. Counting per campaign name (rather than per stored
	 * row) means a link shared before it was saved here is still counted.
	 *
	 * @return array<string,int> Keyed by utm_campaign.
	 */
	public static function lead_counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT c.utm_campaign AS name, COUNT(r.id) AS total
			 FROM {$wpdb->prefix}kivun_campaigns c
			 LEFT JOIN {$wpdb->prefix}kivun_registrations r
			   ON r.source LIKE CONCAT('%UTM: %', c.utm_campaign, '%')
			 GROUP BY c.utm_campaign"
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row->name ] = (int) $row->total;
		}
		return $counts;
	}
}
