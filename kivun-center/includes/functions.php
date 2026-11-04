<?php
/**
 * Shared helper functions for capacity and availability checks.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the visitor's saved message and form answers without making them editable.
 *
 * @param string $message Saved submission text, including answers from older leads.
 * @return string Escaped HTML for a disclosure, or a consistent empty indicator.
 */
function kivun_submission_details( string $message ): string {
	if ( '' === trim( $message ) ) {
		return '<span class="kivun-submission-empty" title="' . esc_attr( __( 'לא נמסרו פרטים נוספים.', 'kivun' ) ) . '">—</span>';
	}

	return '<details class="kivun-submission-details" open><summary>'
		. esc_html__( 'פרטי הפנייה', 'kivun' )
		. '</summary><div class="kivun-submission-details__body">'
		. nl2br( esc_html( $message ), false )
		. '</div></details>';
}

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
		'publish'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
		'library'   => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'leads'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'jobs'      => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
		'home'      => '<path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
		'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
		'trash'     => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
		'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
		'sparkle'   => '<path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="M18.4 5.6l-2.8 2.8"/><path d="M8.4 15.6l-2.8 2.8"/><path d="M18.4 18.4l-2.8-2.8"/><path d="M8.4 8.4L5.6 5.6"/>',
		'external'  => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
		'plus'      => '<path d="M12 5v14"/><path d="M5 12h14"/>',
		'edit'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
		// A box with its lid on, and the same box with something coming back
		// out of it. Drawn to the weight of 'edit' and 'trash' beside them,
		// since the three sit in one row and a stray line width reads as a
		// different kind of button.
		'archive'   => '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
		'restore'   => '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M12 18v-6"/><path d="m9 15 3-3 3 3"/>',
		'filter'    => '<path d="M3 4h18l-7 8v6l-4 2v-8Z"/>',
		// Drawn for the content console's headings: one icon per card, at the
		// same stroke weight as the set above so a row of them reads as one
		// family rather than as clip art from several places.
		'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'tag'       => '<path d="M20.6 13.4 12 22l-9-9V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
		'mail'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2.5 6.5 9.5 7 9.5-7"/>',
		'pin'       => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="m21 15-5-5L5 21"/>',
		'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h3l6 4V6L7 10H4a1 1 0 0 0-1 1Z"/><path d="M17 9a4 4 0 0 1 0 6"/><path d="M20 6a8 8 0 0 1 0 12"/>',
		'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
		'copy'      => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		'course'    => '<path d="M12 4 2 9l10 5 10-5-10-5Z"/><path d="M6 11.5V17c0 1.1 2.7 2.5 6 2.5s6-1.4 6-2.5v-5.5"/>',
		'landing'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M7 13h7"/><path d="M7 16.5h4"/>',
		'phone'     => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
		'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
		// The two ways a list can be read: as a grid of rows and columns, or as
		// a card for each row. Drawn to sit beside each other, so the difference
		// between them is the shape and not the detail.
		'table'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9.5h18"/><path d="M3 15h18"/><path d="M9.5 9.5V20"/>',
		'cards'     => '<rect x="3" y="3.5" width="18" height="7" rx="2"/><path d="M7 7h7"/><rect x="3" y="13.5" width="18" height="7" rx="2"/><path d="M7 17h7"/>',
		// The library's row actions, drawn to the "התכנים שלי" handoff — its
		// own Lucide shapes, path for path. Separate names rather than new
		// drawings of eye/copy/archive/trash above, which other screens use.
		'l-pencil'  => '<path d="M21.17 6.81a1 1 0 0 0-3.98-3.98L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5z"/><path d="M15 5l4 4"/>',
		'l-eye'     => '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
		'l-copy'    => '<rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
		'l-archive' => '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
		'l-trash'   => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
		'l-search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/>',
		// And the leads screen's, from the "לידים והרשמות" handoff.
		'l-adduser' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
		'l-filter'  => '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
		'l-columns' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/>',
		'l-table'   => '<path d="M3 3h18v18H3z M3 9h18 M3 15h18 M9 3v18"/>',
		'l-cards'   => '<path d="M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z"/>',
		'l-chev'    => '<path d="M6 9l6 6 6-6"/>',
		'l-check'   => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
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

/**
 * The handoff's filled, two-tone icon set.
 *
 * Every icon is a back shape at a fraction of the colour and a front shape
 * in full, both in currentColor, so one glyph sits on a tinted disc in red
 * and on a red disc in white without a second drawing. The path data is
 * the reference file's own (its `IC` object), copied as it stands: these
 * are what the tabs, the type tiles, the field labels and the section heads
 * are drawn with, and a redrawn approximation is how a console ends up
 * looking almost like its design.
 *
 * Separate from kivun_icon() on purpose. That set is stroke icons used all
 * over the plugin; changing a name there would redraw screens this design
 * does not cover.
 *
 * @param string $name    Icon name.
 * @param string $classes Optional extra CSS classes.
 * @return string Inline SVG markup, or '' for an unknown name.
 */
function kivun_duo_icon( string $name, string $classes = '' ): string {
	// [ back, front, back opacity ].
	$paths = array(
		'page'    => array( 'M3 7a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z', 'M6 4h12a3 3 0 0 1 3 3v2H3V7a3 3 0 0 1 3-3z M7 12h8v2H7z M7 15.5h5v2H7z' ),
		'cap'     => array( 'M6 12.2v4c0 1.7 2.7 3.3 6 3.3s6-1.6 6-3.3v-4l-6 3z', 'M12 3.8l10 5-10 5-10-5z M19.8 10h1.7v5.5h-1.7z' ),
		'people'  => array( 'M16.8 11a2.8 2.8 0 1 0 0-5.6 2.8 2.8 0 0 0 0 5.6z M15.4 13.2c.5-.1.9-.2 1.4-.2 2.9 0 5.2 1.9 5.2 4.4V19h-4.6v-.6c0-2-.8-3.8-2-5.2z', 'M9 11.6a3.6 3.6 0 1 0 0-7.2 3.6 3.6 0 0 0 0 7.2z M2 19.2C2 15.9 5.1 13.6 9 13.6s7 2.3 7 5.6v.8H2z' ),
		'cal'     => array( 'M3 9.5h18V18a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z', 'M6 4.5h12a3 3 0 0 1 3 3v2.5H3V7.5a3 3 0 0 1 3-3z M7 2.5h2V7H7z M15 2.5h2V7h-2z M7 13h3.2v3.2H7z' ),
		'pen'     => array( 'M4 20l1-4.2L15.3 5.5l3.2 3.2L8.2 19z', 'M15.3 5.5l1.7-1.7a1.6 1.6 0 0 1 2.2 0l1 1a1.6 1.6 0 0 1 0 2.2l-1.7 1.7z M4 20l1-4.2 3.2 3.2z' ),
		'grid'    => array( 'M13 4h5a2 2 0 0 1 2 2v5h-7z M4 13h7v7H6a2 2 0 0 1-2-2z', 'M6 4h5v7H4V6a2 2 0 0 1 2-2z M13 13h7v5a2 2 0 0 1-2 2h-5z' ),
		'mega'    => array( 'M7.5 15.5h3.2l1.2 4.8H8.6z M4 9.5a3 3 0 0 1 3-3h6v9H7a3 3 0 0 1-3-3z', 'M13 6.5l7-3.5v18l-7-3.5z' ),
		'phone'   => array( 'M14.5 3.2a6.3 6.3 0 0 1 6.3 6.3H19a4.5 4.5 0 0 0-4.5-4.5z', 'M6.6 3h3l1.6 4.5-2.3 1.6a11 11 0 0 0 6 6l1.6-2.3 4.5 1.6v3a2.6 2.6 0 0 1-2.8 2.6A17 17 0 0 1 4 5.8 2.6 2.6 0 0 1 6.6 3z' ),
		'clock'   => array( 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M11 7h2v5.2l3.2 2-1 1.6L11 13.3z' ),
		'tag'     => array( 'M3 12.2V5a2 2 0 0 1 2-2h7.2l8.3 8.3a2 2 0 0 1 0 2.8l-6.6 6.6a2 2 0 0 1-2.8 0z', 'M8 5.8a2.2 2.2 0 1 1 0 4.4 2.2 2.2 0 0 1 0-4.4z' ),
		'mail'    => array( 'M3 6.5A2.5 2.5 0 0 1 5.5 4h13A2.5 2.5 0 0 1 21 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z', 'M3.5 6.2l8.5 6 8.5-6v2.4l-8.5 6-8.5-6z' ),
		'pin'     => array( 'M12 2.5a7.5 7.5 0 0 1 7.5 7.5c0 5.2-7.5 11.5-7.5 11.5S4.5 15.2 4.5 10A7.5 7.5 0 0 1 12 2.5z', 'M12 7a3 3 0 1 1 0 6 3 3 0 0 1 0-6z' ),
		'img'     => array( 'M3 6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z', 'M8.5 6.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4z M3 17l5-5 4 4 3-3 6 6v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' ),
		'cta'     => array( 'M3 8a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z', 'M13 11l8 3-3.3 1.2L21 18.5 19.5 20l-3.3-3.3L15 20z M6 9h7v1.8H6z' ),
		'eye'     => array( 'M12 5c5 0 9 4.5 10 7-1 2.5-5 7-10 7S3 14.5 2 12c1-2.5 5-7 10-7z', 'M12 8.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7z' ),
		'wa'      => array( 'M12 2.5a9.5 9.5 0 0 0-8.2 14.3L2.5 21.5l4.8-1.3A9.5 9.5 0 1 0 12 2.5z', 'M9 7.5c.3 0 .6 0 .8.5l.9 2c.1.3 0 .6-.2.8l-.6.7c.8 1.5 2 2.7 3.5 3.5l.7-.6c.2-.2.5-.3.8-.2l2 .9c.4.2.5.5.5.8 0 1.2-1 2.1-2.3 2.1A8 8 0 0 1 7 9.8C7 8.5 7.9 7.5 9 7.5z' ),
		'case'    => array( 'M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7h-1.8V5.8h-2.4V7z M3 11.5h18v2H3z' ),
		'copy'    => array( 'M8 8h11a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z', 'M5 3h10a1 1 0 0 1 1 1v2H7a1 1 0 0 0-1 1v9H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z' ),
		// The AI head carries both small stars; the buttons carry one.
		'spark'   => array( 'M18.5 14l1 2.5 2.5 1-2.5 1-1 2.5-1-2.5-2.5-1 2.5-1z M5.5 2.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z', 'M11 4.5l1.9 5.1 5.1 1.9-5.1 1.9L11 18.5l-1.9-5.1L4 11.5l5.1-1.9z', '.45' ),
		'sparkle' => array( 'M18.5 14l1 2.5 2.5 1-2.5 1-1 2.5-1-2.5-2.5-1 2.5-1z', 'M11 4.5l1.9 5.1 5.1 1.9-5.1 1.9L11 18.5l-1.9-5.1L4 11.5l5.1-1.9z', '.5' ),
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return sprintf(
		'<svg class="kivun-duo %s" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="%s" fill-opacity="%s"/><path d="%s"/></svg>',
		esc_attr( $classes ),
		esc_attr( $paths[ $name ][0] ),
		esc_attr( $paths[ $name ][2] ?? '.35' ),
		esc_attr( $paths[ $name ][1] )
	);
}

/**
 * Show a date or time this site stored, in this site's own clock.
 *
 * Every datetime the plugin writes is local wall-clock — `current_time()` and
 * `wp_date()` both produce the site's time, not UTC — and it goes into the
 * column exactly as written, with no zone attached to say so.
 *
 * Reading one back with strtotime() therefore gets it wrong, and in a way
 * that looks almost right: WordPress sets PHP's own timezone to UTC, so a
 * naive string is taken as UTC, and wp_date() then renders that instant in
 * Jerusalem — three hours later than the thing that happened. A call placed
 * at 22:24 was shown as 01:24 the following day, which is how this was
 * finally noticed. By day alone it hid for much longer: only the hours after
 * nine in the evening cross midnight, so most of the day looked fine.
 *
 * So the stored string is read as what it is — a local reading — before it
 * is formatted.
 *
 * @param string $stored A datetime as stored, e.g. '2026-10-13 22:24:00'.
 * @param string $format Any date() format.
 * @return string The formatted date, or '' when there is nothing to show.
 */
function kivun_local_date( string $stored, string $format = 'd/m/Y H:i' ): string {
	$stored = trim( $stored );

	// MySQL's zero date is "no value", not the year nothing.
	if ( '' === $stored || 0 === strncmp( $stored, '0000-00-00', 10 ) ) {
		return '';
	}

	$when = date_create_immutable( $stored, wp_timezone() );

	return $when ? wp_date( $format, $when->getTimestamp() ) : '';
}
