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
