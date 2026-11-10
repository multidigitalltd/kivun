<?php
/**
 * Recurring workshops ("סדנה שוטפת").
 *
 * A workshop already took sign-ups past its date: they are kept for the
 * next cycle. What a recurring one adds is the day that next cycle opens,
 * said on the workshop's page and in the message after signing up.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The recurring workshop's cycle, notice and wiring.
 */
final class RecurringSessionTest extends TestCase {

	/**
	 * A clean workshop post.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once KIVUN_DIR . 'includes/class-kivun-workshops.php';
		Kivun_Test_State::$posts[51] = array(
			'post_type'   => 'kivun_session',
			'post_title'  => 'סדנת קורות חיים',
			'post_status' => 'publish',
		);
		Kivun_Test_State::$meta[51]  = array();
	}

	/**
	 * A date some days from today, as stored.
	 *
	 * @param int $days Days from today; negative for the past.
	 * @return string
	 */
	private function day( int $days ): string {
		return gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Set the workshop's dates.
	 *
	 * @param string $until     Last day of sign-up for this cycle.
	 * @param bool   $recurring Whether it is a recurring workshop.
	 * @param string $next      When the next cycle opens.
	 * @return void
	 */
	private function workshop( string $until, bool $recurring = false, string $next = '' ): void {
		Kivun_Test_State::$meta[51] = array(
			'_kivun_session_valid_until' => $until,
			'_kivun_session_recurring'   => $recurring ? '1' : '',
			'_kivun_session_next_open'   => $next,
		);
	}

	/**
	 * Before its date a cycle is open; after it, it is the next cycle's turn.
	 *
	 * @return void
	 */
	public function test_the_cycle_follows_the_date(): void {
		$this->workshop( $this->day( 30 ) );
		$this->assertSame( 'open', kivun_session_cycle( 51 )['state'] );

		$this->workshop( $this->day( -30 ) );
		$this->assertSame( 'next', kivun_session_cycle( 51 )['state'] );

		$this->workshop( '' );
		$this->assertSame( 'none', kivun_session_cycle( 51 )['state'] );
	}

	/**
	 * Only a recurring workshop names its next cycle, and only while that
	 * date is still ahead.
	 *
	 * @return void
	 */
	public function test_only_a_recurring_workshop_names_its_next_cycle(): void {
		$next = $this->day( 20 );

		$this->workshop( $this->day( -5 ), false, $next );
		$this->assertSame( '', kivun_session_cycle( 51 )['next'], 'a one-off workshop announces a next cycle' );

		$this->workshop( $this->day( -5 ), true, $next );
		$this->assertSame( $next, kivun_session_cycle( 51 )['next'] );

		// A next-cycle date already gone by is not announced.
		$this->workshop( $this->day( -5 ), true, $this->day( -1 ) );
		$this->assertSame( '', kivun_session_cycle( 51 )['next'] );
	}

	/**
	 * The page says until when, then when the next cycle opens.
	 *
	 * @return void
	 */
	public function test_the_page_says_where_sign_up_stands(): void {
		$until = $this->day( 10 );
		$this->workshop( $until, true, $this->day( 40 ) );
		$html = Kivun_Workshops::session_status_html( 51 );
		$this->assertStringContainsString( 'kivun-cycle--open', $html );
		$this->assertStringContainsString( 'ההרשמה למחזור הנוכחי פתוחה עד ' . kivun_display_date( $until ), $html );
		$this->assertStringContainsString( 'סדנה שוטפת', $html );

		$next = $this->day( 15 );
		$this->workshop( $this->day( -2 ), true, $next );
		$html = Kivun_Workshops::session_status_html( 51 );
		$this->assertStringContainsString( 'kivun-cycle--next', $html );
		$this->assertStringContainsString( 'המחזור הבא נפתח ב־' . kivun_display_date( $next ), $html );

		// A one-off workshop past its date still says sign-ups go on.
		$this->workshop( $this->day( -2 ) );
		$html = Kivun_Workshops::session_status_html( 51 );
		$this->assertStringContainsString( 'ההרשמה למחזור הנוכחי נסגרה', $html );
		$this->assertStringNotContainsString( 'סדנה שוטפת', $html );

		// No date, nothing to say; and nothing for what is not a workshop.
		$this->workshop( '' );
		$this->assertSame( '', Kivun_Workshops::session_status_html( 51 ) );
		$this->assertSame( '', Kivun_Workshops::session_status_html( 999 ) );
	}

	/**
	 * Dates are shown the way the site shows them.
	 *
	 * @return void
	 */
	public function test_dates_are_shown_day_first(): void {
		$this->assertSame( '01/12/2026', kivun_display_date( '2026-12-01' ) );
		$this->assertSame( '', kivun_display_date( 'not a date' ) );
	}

	/**
	 * The switch and the date reach the wizard, wp-admin and the save.
	 *
	 * @return void
	 */
	public function test_the_switch_is_wired_through(): void {
		$cc = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString( 'name="session_recurring" value="1" class="kivun-ses-switch"', $cc );
		$this->assertStringContainsString( 'name="session_next_open"', $cc );
		$this->assertStringContainsString( "'_kivun_session_recurring'   => empty( \$_POST['session_recurring'] ) ? '' : '1',", $cc );
		$this->assertStringContainsString( "'_kivun_session_next_open'   => empty( \$_POST['session_recurring'] ) ? '' : self::valid_date(", $cc, 'a one-off workshop keeps a next-cycle date' );

		$admin = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-admin.php' );
		$this->assertStringContainsString( 'name="_kivun_session_recurring"', $admin );
		$this->assertStringContainsString( "'_kivun_session_next_open'   => 'text',", $admin );

		$ws = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' );
		$this->assertStringContainsString( "add_shortcode( 'kivun_session_status'", $ws );
		$this->assertStringContainsString( "__( 'נרשמת למחזור הבא, שנפתח ב־%s. נעדכן אותך!', 'kivun' )", $ws );

		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString( '.kivun-cc-wizard .kivun-cc-sessrepeat:not(:has(.kivun-ses-switch:checked)) .kivun-cc-sessnext { display: none; }', $css );
	}
}
