<?php
/**
 * Shared helper functions for capacity and availability checks.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the number of remaining spots for a course or workshop.
 *
 * Returns null if no capacity limit is set.
 *
 * @param int $post_id The course or workshop post ID.
 * @return int|null Remaining spots, or null when no capacity limit is set.
 */
function kivun_get_spots_left( int $post_id ): ?int {
	$capacity = (int) get_post_meta( $post_id, '_kivun_capacity', true );
	$capacity = $capacity ? $capacity : (int) get_post_meta( $post_id, '_kivun_ws_capacity', true );

	if ( ! $capacity ) {
		return null;
	}

	global $wpdb;
	$confirmed = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations
		 WHERE course_id = %d AND status NOT IN ('not_relevant','closed')",
			$post_id
		)
	);

	return max( 0, $capacity - $confirmed );
}

/**
 * Returns true if a course/workshop is fully booked.
 *
 * @param int $post_id The course or workshop post ID.
 * @return bool True when the course or workshop is full.
 */
function kivun_is_full( int $post_id ): bool {
	$left = kivun_get_spots_left( $post_id );
	return null !== $left && 0 === $left;
}

/**
 * Whether a workshop/session is currently open for registration.
 *
 * Registration closes once the validity date (_kivun_session_valid_until) has
 * passed; setting a new future date reopens it. No date = always open.
 *
 * @param int $post_id The session (kivun_session) post ID.
 * @return bool True when registration is open.
 */
function kivun_session_registration_open( int $post_id ): bool {
	$valid_until = (string) get_post_meta( $post_id, '_kivun_session_valid_until', true );
	if ( '' === trim( $valid_until ) ) {
		return true;
	}

	// The date field stores Y-m-d; compare as site-local date strings (the
	// deadline day is inclusive).
	return current_time( 'Y-m-d' ) <= $valid_until;
}

/**
 * Whether an event is still open for registration.
 *
 * Registration closes permanently once the event date (_kivun_event_date) has
 * passed — unlike sessions, an event does not reopen. No date = always open.
 *
 * @param int $post_id The event (kivun_event) post ID.
 * @return bool True when registration is open.
 */
function kivun_event_registration_open( int $post_id ): bool {
	$event_date = (string) get_post_meta( $post_id, '_kivun_event_date', true );
	if ( '' === trim( $event_date ) ) {
		return true;
	}
	// The date field stores Y-m-d; the event day itself is still open.
	return current_time( 'Y-m-d' ) <= $event_date;
}

/**
 * Event registration mode: 'form' (on-site form) or 'external' (button/link).
 *
 * @param int $post_id The event post ID.
 * @return string
 */
function kivun_event_mode( int $post_id ): string {
	$mode = (string) get_post_meta( $post_id, '_kivun_event_mode', true );
	return 'external' === $mode ? 'external' : 'form';
}

/**
 * Per-type storage map for the shared content fields.
 *
 * Each content type stores the same logical field under a different key; this
 * is the single source of truth for where each one lives ('excerpt' means the
 * native post_excerpt rather than a meta key).
 *
 * @param string $post_type The post type.
 * @return array<string,string> Shared field => storage key (or 'excerpt').
 */
function kivun_content_field_map( string $post_type ): array {
	switch ( $post_type ) {
		case 'kivun_course':
			return array(
				'short'    => 'excerpt',
				'audience' => '_kivun_target_audience',
				'duration' => '_kivun_duration',
				'cost'     => '_kivun_price',
				'date'     => '_kivun_schedule',
			);
		case 'kivun_session':
			return array(
				'short'    => '_kivun_session_short',
				'audience' => '_kivun_session_audience',
				'duration' => '_kivun_session_duration',
				'cost'     => '_kivun_session_cost',
				'date'     => '_kivun_session_date',
			);
		case 'kivun_event':
			return array(
				'short'    => '_kivun_event_short',
				'audience' => '_kivun_event_audience',
				'duration' => '_kivun_event_time',
				'cost'     => '_kivun_event_cost',
				'date'     => '_kivun_event_date',
			);
		case 'kivun_workshop':
			return array(
				'short'    => '_kivun_lp_short',
				'audience' => '_kivun_ws_audience',
				'duration' => '_kivun_ws_duration',
				'cost'     => '_kivun_lp_cost',
				'date'     => '_kivun_ws_date',
			);
	}
	return array();
}

/**
 * Resolve a shared content field to its stored value for any Kivun type.
 *
 * @param int    $post_id The post ID.
 * @param string $field   Shared field key (short/audience/duration/cost/date).
 * @return string The stored value (may contain HTML for 'short').
 */
function kivun_get_field( int $post_id, string $field ): string {
	$map = kivun_content_field_map( get_post_type( $post_id ) );
	if ( ! isset( $map[ $field ] ) ) {
		return '';
	}
	if ( 'excerpt' === $map[ $field ] ) {
		return (string) get_post_field( 'post_excerpt', $post_id );
	}
	return (string) get_post_meta( $post_id, $map[ $field ], true );
}

/**
 * Unified "short description" for any Kivun content type.
 *
 * @param int $post_id The post ID.
 * @return string The short description (may contain HTML).
 */
function kivun_get_short( int $post_id ): string {
	return kivun_get_field( $post_id, 'short' );
}
