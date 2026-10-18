<?php
/**
 * Short links for campaign URLs.
 *
 * A campaign link carries its UTM parameters in the query string, which is
 * what every analytics tool reads and also what makes it unshareable: a
 * Hebrew campaign name comes out percent-encoded, and a link that should fit
 * in a message runs to three lines of it.
 *
 * So the long URL stays — it is what the visitor is sent to, and Google
 * Analytics and the rest go on seeing the campaign exactly as before — and
 * what gets shared is a short code that redirects to it.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds, resolves and counts short campaign links.
 */
class Kivun_Short_Links {

	/**
	 * The path short links live under, as in /go/ab3k.
	 */
	const BASE = 'go';

	/**
	 * The query variable the rewrite rule fills.
	 */
	const VAR = 'kivun_go';

	/**
	 * How long a generated code is.
	 */
	const LENGTH = 5;

	/**
	 * The characters a generated code is built from.
	 *
	 * No 0/o, 1/l/i or 5/s: these get read off a screen, written on a slide
	 * and said down a phone, and a code nobody can transcribe is no shorter
	 * than the link it replaced. Lower case only, for the same reason.
	 */
	const ALPHABET = '23467889abcdefghjkmnpqrtuvwxyz';

	/**
	 * Register the route and the handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_route' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		// Early, and before anything renders: this request has no page of its
		// own, and a theme that got as far as sending output would make the
		// redirect impossible.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * Teach WordPress the /go/<code> path.
	 *
	 * @return void
	 */
	public static function add_route(): void {
		add_rewrite_rule(
			'^' . self::BASE . '/([^/]+)/?$',
			'index.php?' . self::VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Allow the query variable through.
	 *
	 * @param array<int,string> $vars Registered query vars.
	 * @return array<int,string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::VAR;

		return $vars;
	}

	// ── Codes ─────────────────────────────────────────────────────────────────

	/**
	 * Tidy a code somebody typed into something that can live in a URL.
	 *
	 * Kept deliberately narrow. A code is going into a link that gets pasted
	 * into messages and printed on paper, so it holds lower-case latin
	 * letters, digits and hyphens and nothing else — Hebrew here would
	 * percent-encode into exactly the mess this feature exists to avoid.
	 *
	 * @param string $raw What was typed.
	 * @return string The usable code, or '' when nothing usable is left.
	 */
	public static function clean_slug( string $raw ): string {
		$slug = strtolower( trim( $raw ) );
		$slug = str_replace( array( ' ', '_' ), '-', $slug );
		$slug = (string) preg_replace( '/[^a-z0-9-]/', '', $slug );
		$slug = (string) preg_replace( '/-{2,}/', '-', $slug );
		$slug = trim( $slug, '-' );

		return substr( $slug, 0, 64 );
	}

	/**
	 * A code nobody chose.
	 *
	 * @return string
	 */
	public static function random_slug(): string {
		$alphabet = self::ALPHABET;
		$last     = strlen( $alphabet ) - 1;
		$slug     = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$slug .= $alphabet[ wp_rand( 0, $last ) ];
		}

		return $slug;
	}

	/**
	 * Whether a code is already spoken for.
	 *
	 * @param string $slug    The code.
	 * @param int    $link_id A link allowed to keep its own code.
	 * @return bool
	 */
	public static function taken( string $slug, int $link_id = 0 ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}kivun_campaign_links WHERE slug = %s AND id <> %d LIMIT 1",
				$slug,
				$link_id
			)
		);
	}

	/**
	 * Settle on a code for a link: the one asked for when it is free, and
	 * otherwise one that is.
	 *
	 * A wanted code that is taken gets a suffix rather than being refused,
	 * because the person naming it is mid-save and a link without a code is
	 * the thing they were trying to avoid.
	 *
	 * @param string $wanted  A code somebody typed, or '' for any.
	 * @param int    $link_id A link allowed to keep its own code.
	 * @return string
	 */
	public static function resolve_slug( string $wanted, int $link_id = 0 ): string {
		$wanted = self::clean_slug( $wanted );

		if ( '' !== $wanted ) {
			if ( ! self::taken( $wanted, $link_id ) ) {
				return $wanted;
			}

			for ( $n = 2; $n < 100; $n++ ) {
				$try = substr( $wanted, 0, 60 ) . '-' . $n;
				if ( ! self::taken( $try, $link_id ) ) {
					return $try;
				}
			}
		}

		// Random codes are short, so a collision is not impossible — it is
		// just rare. Tried a few times, then lengthened rather than looped
		// forever on a table that happens to be full of them.
		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$try = self::random_slug();
			if ( ! self::taken( $try, $link_id ) ) {
				return $try;
			}
		}

		return self::random_slug() . self::random_slug();
	}

	/**
	 * The code to store when a link is saved.
	 *
	 * An edit that names no code keeps the one the link already has. This is
	 * the whole difference between a short link and a long one: the code has
	 * been sent to people. Minting a fresh one every time somebody reworded
	 * the WhatsApp message would quietly break every link already out there,
	 * and nothing on screen would say so.
	 *
	 * @param string $wanted  A code somebody typed, or '' for any.
	 * @param int    $link_id The link being saved, or 0 when it is new.
	 * @return string
	 */
	public static function slug_for_save( string $wanted, int $link_id = 0 ): string {
		if ( '' === self::clean_slug( $wanted ) && $link_id > 0 ) {
			$existing = self::slug_of( $link_id );
			if ( '' !== $existing ) {
				return $existing;
			}
		}

		return self::resolve_slug( $wanted, $link_id );
	}

	/**
	 * The code a link already has.
	 *
	 * @param int $link_id The link.
	 * @return string
	 */
	public static function slug_of( int $link_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT slug FROM {$wpdb->prefix}kivun_campaign_links WHERE id = %d",
				$link_id
			)
		);
	}

	/**
	 * The public address of a code.
	 *
	 * @param string $slug The code.
	 * @return string The URL, or '' when there is no code.
	 */
	public static function url_for( string $slug ): string {
		$slug = trim( $slug );

		return '' === $slug ? '' : home_url( '/' . self::BASE . '/' . rawurlencode( $slug ) );
	}

	// ── Following one ─────────────────────────────────────────────────────────

	/**
	 * Send a visitor on to the campaign URL behind the code.
	 *
	 * @return void
	 */
	public static function maybe_redirect(): void {
		$slug = self::clean_slug( (string) get_query_var( self::VAR ) );
		if ( '' === $slug ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$link = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, final_url, target_url FROM {$wpdb->prefix}kivun_campaign_links WHERE slug = %s LIMIT 1",
				$slug
			)
		);

		$destination = $link ? trim( (string) ( $link->final_url ? $link->final_url : $link->target_url ) ) : '';

		if ( '' === $destination ) {
			self::not_found();
			return;
		}

		self::count_click( (int) $link->id );

		// A cached redirect would send every later visitor to wherever the
		// first one went and count none of them, so this response says in
		// every dialect the caches here speak that it is not to be kept.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		// 302, not 301: a campaign link is re-pointed often, and a permanent
		// redirect is remembered by the browser long after the campaign that
		// justified it — with no way to tell the browser otherwise.
		wp_redirect( $destination, 302, 'Kivun' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The destination is a campaign URL set by staff, and may legitimately be off-site.
		exit;
	}

	/**
	 * Answer a code that leads nowhere with a real 404.
	 *
	 * Returning from template_redirect is not enough. The rewrite has already
	 * turned the URL into index.php?kivun_go=…, and that query variable asks
	 * for no content of its own, so WordPress goes on to render the front
	 * page and answers 200 — a mistyped or retired code would quietly look
	 * like the home page rather than like a broken link, and a crawler would
	 * file it as a duplicate of the front page.
	 *
	 * @return void
	 */
	private static function not_found(): void {
		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Record that a code was followed.
	 *
	 * Counted in the database rather than in a cache, because the number is
	 * read beside the leads that came from the same link and the two should
	 * not disagree about which run they describe.
	 *
	 * @param int $link_id The link.
	 * @return void
	 */
	private static function count_click( int $link_id ): void {
		if ( $link_id <= 0 ) {
			return;
		}

		global $wpdb;

		// Incremented in SQL rather than read-then-written, so two clicks
		// landing together still count as two.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}kivun_campaign_links SET clicks = clicks + 1 WHERE id = %d",
				$link_id
			)
		);
	}
}
