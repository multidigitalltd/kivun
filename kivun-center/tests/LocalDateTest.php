<?php
/**
 * Showing a stored datetime in the site's own clock.
 *
 * Everything this plugin writes is local wall-clock — current_time() and
 * wp_date() both give the site's time — and it lands in the column with no
 * zone attached to say so. Reading one back with strtotime() therefore gets
 * it wrong, because WordPress sets PHP's own timezone to UTC: the naive
 * string is taken as UTC and then rendered in Jerusalem, three hours after
 * the thing it describes.
 *
 * It hid for a long time because it is almost right. Shown as a date alone
 * only the evening hours cross midnight, so most of the day looked correct;
 * shown with the time it was three hours out always, which reads as a clock
 * nobody set rather than as a bug. It surfaced when a call placed at 22:24
 * was listed at 01:24 the following day.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/functions.php';

/**
 * Tests for kivun_local_date().
 */
final class LocalDateTest extends TestCase {

	/**
	 * Start each test with the site on Jerusalem time.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * PHP is pinned to UTC, as WordPress pins it. If this ever stops being
	 * true the rest of this file proves nothing, so it is checked rather
	 * than assumed.
	 *
	 * @return void
	 */
	public function test_the_test_runs_the_way_wordpress_does(): void {
		$this->assertSame( 'UTC', date_default_timezone_get() );
		$this->assertSame( 'Asia/Jerusalem', wp_timezone()->getName() );
	}

	/**
	 * The reported symptom, to the minute: a call placed at 22:24 on the
	 * 13th, listed as the 14th at 01:24.
	 *
	 * @return void
	 */
	public function test_the_evening_call_keeps_its_own_day_and_hour(): void {
		$this->assertSame( '13/10/2026 22:24', kivun_local_date( '2026-10-13 22:24:00' ) );
	}

	/**
	 * And the old way really did produce that, so the test is pinned to the
	 * bug rather than to a guess about it.
	 *
	 * @return void
	 */
	public function test_the_old_way_produced_the_reported_value(): void {
		$this->assertSame( '14/10/2026 01:24', wp_date( 'd/m/Y H:i', strtotime( '2026-10-13 22:24:00' ) ) );
	}

	/**
	 * Every hour of the day survives the round trip. The three hours after
	 * nine in the evening are the ones that used to change date.
	 *
	 * @return void
	 */
	public function test_no_hour_of_the_day_is_moved(): void {
		for ( $hour = 0; $hour < 24; $hour++ ) {
			$stored = sprintf( '2026-10-13 %02d:30:00', $hour );

			$this->assertSame(
				sprintf( '13/10/2026 %02d:30', $hour ),
				kivun_local_date( $stored ),
				$stored
			);
		}
	}

	/**
	 * Across the turn of the year too, where a shifted hour moves the month
	 * and the year with it.
	 *
	 * @return void
	 */
	public function test_the_last_hour_of_the_year_stays_in_it(): void {
		$this->assertSame( '31/12/2026 23:59', kivun_local_date( '2026-12-31 23:59:00' ) );
	}

	/**
	 * A date alone is still that date, including the evening hours that used
	 * to roll over.
	 *
	 * @return void
	 */
	public function test_a_date_alone_is_the_day_it_says(): void {
		$this->assertSame( '13/10/2026', kivun_local_date( '2026-10-13 21:00:00', 'd/m/Y' ) );
		$this->assertSame( '13/10/2026', kivun_local_date( '2026-10-13 23:59:59', 'd/m/Y' ) );
		$this->assertSame( '13/10/2026', kivun_local_date( '2026-10-13', 'd/m/Y' ) );
	}

	/**
	 * Winter, when Jerusalem is two hours ahead rather than three — the
	 * offset is read from the zone rather than written into the code.
	 *
	 * @return void
	 */
	public function test_the_offset_follows_the_season(): void {
		$this->assertSame( '15/01/2026 22:24', kivun_local_date( '2026-01-15 22:24:00' ) );
	}

	/**
	 * A site in another timezone gets its own clock, not Jerusalem's.
	 *
	 * @return void
	 */
	public function test_another_site_gets_its_own_clock(): void {
		Kivun_Test_State::$timezone = 'America/New_York';

		$this->assertSame( '13/10/2026 22:24', kivun_local_date( '2026-10-13 22:24:00' ) );
	}

	// ── Nothing to show ───────────────────────────────────────────────────────

	/**
	 * An empty column shows as nothing rather than as the first of January
	 * 1970, which is what a bare format of an empty value gives.
	 *
	 * @return void
	 */
	public function test_an_empty_value_shows_nothing(): void {
		$this->assertSame( '', kivun_local_date( '' ) );
		$this->assertSame( '', kivun_local_date( '   ' ) );
	}

	/**
	 * MySQL's zero date means "no value", not the year nothing.
	 *
	 * @return void
	 */
	public function test_the_zero_date_shows_nothing(): void {
		$this->assertSame( '', kivun_local_date( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', kivun_local_date( '0000-00-00' ) );
	}

	/**
	 * And something that is not a date at all shows nothing rather than
	 * today — a row with a broken value should look broken, not current.
	 *
	 * @return void
	 */
	public function test_nonsense_shows_nothing(): void {
		$this->assertSame( '', kivun_local_date( 'לא תאריך' ) );
	}

	// ── That the call sites were actually changed ─────────────────────────────

	/**
	 * The pattern is gone from the plugin. It was in thirteen places, and
	 * one left behind would be a screen still three hours out.
	 *
	 * @return void
	 */
	public function test_no_stored_datetime_is_read_with_strtotime_any_more(): void {
		$found = array();

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( KIVUN_DIR ) );
		foreach ( $files as $file ) {
			$path = (string) $file;

			if ( 'php' !== pathinfo( $path, PATHINFO_EXTENSION ) ) {
				continue;
			}
			if ( false !== strpos( $path, '/vendor/' ) || false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/node_modules/' ) ) {
				continue;
			}
			if ( preg_match( "/wp_date\( *'[^']*', *strtotime\(/", (string) file_get_contents( $path ) ) ) {
				$found[] = str_replace( KIVUN_DIR, '', $path );
			}
		}

		$this->assertSame( array(), $found, 'these read a stored datetime as UTC and show it three hours late' );
	}
}
