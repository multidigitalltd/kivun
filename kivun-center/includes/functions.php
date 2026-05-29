<?php
defined( 'ABSPATH' ) || exit;

/**
 * Returns the number of remaining spots for a course or workshop.
 * Returns null if no capacity limit is set.
 */
function kivun_get_spots_left( int $post_id ): ?int {
	$capacity = (int) get_post_meta( $post_id, '_kivun_capacity',    true )
		     ?: (int) get_post_meta( $post_id, '_kivun_ws_capacity', true );

	if ( ! $capacity ) {
		return null;
	}

	global $wpdb;
	$confirmed = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}kivun_registrations
		 WHERE course_id = %d AND status NOT IN ('not_relevant','closed')",
		$post_id
	) );

	return max( 0, $capacity - $confirmed );
}

/**
 * Returns true if a course/workshop is fully booked.
 */
function kivun_is_full( int $post_id ): bool {
	$left = kivun_get_spots_left( $post_id );
	return $left !== null && $left === 0;
}
