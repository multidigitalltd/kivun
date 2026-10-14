<?php
/**
 * Reading a filter that may hold several choices.
 *
 * A question about the leads is usually about more than one of something —
 * two courses, three towns — so the content and settlement filters take a
 * list. One choice and several arrive through the same parameter, one as a
 * scalar and the rest as an array, and everything downstream builds SQL from
 * whatever comes back: a value that slips through unsanitised, or a zero
 * kept by accident, is an IN clause that matches nothing or worse.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the list-reading helpers behind the multi-choice filters.
 */
final class MultiFilterTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	// ── Ids ───────────────────────────────────────────────────────────────────

	/**
	 * Several ids come back in order.
	 *
	 * @return void
	 */
	public function test_several_ids_are_read(): void {
		$this->assertSame( array( 11, 12, 13 ), Kivun_Content_Creator::filter_ids( array( '11', 12, '13' ) ) );
	}

	/**
	 * One arriving on its own is still a list, so the rest of the code has a
	 * single shape to handle rather than two.
	 *
	 * @return void
	 */
	public function test_a_single_id_is_still_a_list(): void {
		$this->assertSame( array( 11 ), Kivun_Content_Creator::filter_ids( '11' ) );
		$this->assertSame( array( 11 ), Kivun_Content_Creator::filter_ids( 11 ) );
	}

	/**
	 * Nothing chosen is an empty list, which is what "all of them" looks like
	 * to the query — no condition at all.
	 *
	 * @return void
	 */
	public function test_nothing_chosen_is_an_empty_list(): void {
		$this->assertSame( array(), Kivun_Content_Creator::filter_ids( array() ) );
		$this->assertSame( array(), Kivun_Content_Creator::filter_ids( '' ) );
	}

	/**
	 * Zero is dropped rather than kept. It is what the old "all" option
	 * carried, and a list holding it would build `IN (0)` — a filter that
	 * matches nothing, which reads on screen as "there are no leads".
	 *
	 * @return void
	 */
	public function test_zero_does_not_become_a_filter_that_matches_nothing(): void {
		$this->assertSame( array(), Kivun_Content_Creator::filter_ids( array( '0' ) ) );
		$this->assertSame( array( 7 ), Kivun_Content_Creator::filter_ids( array( '0', '7' ) ) );
	}

	/**
	 * Only integers come out, whatever goes in — which is what makes the IN
	 * clause safe to build from them.
	 *
	 * Note what this does not claim: absint() reads the leading digits, so
	 * "1 OR 1=1" becomes the number 1 and "-3" becomes 3. Those are ordinary
	 * ids, not fragments of a query, and the point is only that nothing
	 * which is not a number survives to reach the SQL.
	 *
	 * @return void
	 */
	public function test_only_integers_come_out(): void {
		$read = Kivun_Content_Creator::filter_ids(
			array( '5', 'abc', '1 OR 1=1', '; DROP TABLE x', '-3', '7; --' )
		);

		foreach ( $read as $value ) {
			$this->assertIsInt( $value );
			$this->assertGreaterThan( 0, $value );
		}

		$this->assertContains( 5, $read );
		$this->assertSame( $read, array_values( array_unique( $read ) ) );
	}

	/**
	 * The same id twice is one id — a repeated value in an IN clause is
	 * noise, and it arrives easily from a hand-edited URL.
	 *
	 * @return void
	 */
	public function test_a_repeated_id_is_counted_once(): void {
		$this->assertSame( array( 4, 9 ), Kivun_Content_Creator::filter_ids( array( 4, 9, 4, '9' ) ) );
	}

	// ── Words ─────────────────────────────────────────────────────────────────

	/**
	 * Settlement names come back as given.
	 *
	 * @return void
	 */
	public function test_several_names_are_read(): void {
		$this->assertSame(
			array( 'ירושלים', 'בית שמש' ),
			Kivun_Content_Creator::filter_values( array( 'ירושלים', 'בית שמש' ) )
		);
	}

	/**
	 * One on its own is a list too.
	 *
	 * @return void
	 */
	public function test_a_single_name_is_still_a_list(): void {
		$this->assertSame( array( 'ירושלים' ), Kivun_Content_Creator::filter_values( 'ירושלים' ) );
	}

	/**
	 * Blanks are dropped. An empty string in the list would become `city = ''`
	 * inside the IN clause and quietly pull in every lead that never gave one.
	 *
	 * @return void
	 */
	public function test_blanks_are_dropped(): void {
		$this->assertSame( array( 'ירושלים' ), Kivun_Content_Creator::filter_values( array( '', '   ', 'ירושלים' ) ) );
		$this->assertSame( array(), Kivun_Content_Creator::filter_values( array( '', '  ' ) ) );
	}

	/**
	 * Markup does not survive, so a name from the query string cannot carry
	 * anything into the page that renders it back.
	 *
	 * @return void
	 */
	public function test_markup_does_not_survive(): void {
		$cleaned = Kivun_Content_Creator::filter_values( array( '<script>alert(1)</script>ירושלים' ) );

		$this->assertCount( 1, $cleaned );
		$this->assertStringNotContainsString( '<', $cleaned[0] );
	}

	/**
	 * And the same name twice is one name.
	 *
	 * @return void
	 */
	public function test_a_repeated_name_is_counted_once(): void {
		$this->assertSame( array( 'ירושלים' ), Kivun_Content_Creator::filter_values( array( 'ירושלים', 'ירושלים' ) ) );
	}

	// ── What the two build ────────────────────────────────────────────────────

	/**
	 * Both filters put one placeholder per value into the query rather than
	 * pasting the values in. These are names somebody typed into a form once,
	 * and a town with an apostrophe in it is not a reason to trust them.
	 *
	 * @return void
	 */
	public function test_the_in_clauses_are_built_from_placeholders(): void {
		foreach ( array(
			'includes/class-kivun-content-creator.php' => "array_fill( 0, count( \$city_filter ), '%s' )",
			'includes/class-kivun-export.php'          => "array_fill( 0, count( \$post_ids ), '%d' )",
		) as $file => $expected ) {
			$this->assertStringContainsString(
				$expected,
				(string) file_get_contents( KIVUN_DIR . $file ),
				"{$file} no longer builds its IN clause from placeholders"
			);
		}
	}
}
