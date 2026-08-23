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

/**
 * Inline SVG icon set for the management console and the jobs board.
 *
 * Emoji render in the OS colour palette, which fights the brand colours and
 * looks different on every device. These are stroke icons that inherit
 * currentColor, so they take the brand colour and stay consistent everywhere.
 *
 * @param string $name  Icon name.
 * @param string $classes Optional extra CSS classes.
 * @return string Escaped inline SVG markup, or '' for an unknown name.
 */
function kivun_icon( string $name, string $classes = '' ): string {
	$paths = array(
		'publish'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
		'library'  => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'leads'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'jobs'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
		'home'     => '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
		'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
		'trash'    => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
		'sparkle'  => '<path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="M18.4 5.6l-2.8 2.8"/><path d="M8.4 15.6l-2.8 2.8"/><path d="M18.4 18.4l-2.8-2.8"/><path d="M8.4 8.4L5.6 5.6"/>',
		'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
		'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
		'filter'   => '<path d="M3 4h18l-7 8v6l-4 2v-8Z"/>',
		'phone'    => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
		'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return sprintf(
		'<svg class="kivun-icon %s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
		esc_attr( $classes ),
		$paths[ $name ]
	);
}
