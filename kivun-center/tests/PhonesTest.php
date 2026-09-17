<?php
/**
 * Call tracking: matching a dialled number, and reading the provider's dates.
 *
 * Both of these have gone wrong on this site in ways nobody could see from the
 * screen. A landline that did not match meant calls filed against no number at
 * all; a date read the American way round put a call in December instead of
 * September, which only shows up as a campaign that looks quiet.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Phones.
 */
final class PhonesTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Reach a private method, so the parsing can be tested without a database.
	 *
	 * @param string $method The method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		$reflected = new ReflectionMethod( Kivun_Phones::class, $method );
		$reflected->setAccessible( true );
		return $reflected->invoke( null, ...$args );
	}

	/**
	 * The same number written every way a provider might send it reduces to one
	 * form. This is what decides whether a call is credited to a campaign.
	 *
	 * @dataProvider same_number_provider
	 * @param string $written How the number arrived.
	 * @param string $stored  How the site has it.
	 * @return void
	 */
	public function test_the_same_number_matches_however_it_is_written( string $written, string $stored ): void {
		$this->assertSame(
			$this->call( 'canonical', $stored ),
			$this->call( 'canonical', $written ),
			sprintf( '%s should match %s', $written, $stored )
		);
	}

	/**
	 * Forms of the same number.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function same_number_provider(): array {
		return array(
			'national and international' => array( '972731234567', '0731234567' ),
			'plus prefix'                => array( '+972731234567', '0731234567' ),
			'spaces'                     => array( '073 123 4567', '0731234567' ),
			'dashes'                     => array( '073-123-4567', '0731234567' ),
			'mobile international'       => array( '972501112222', '0501112222' ),
			'jerusalem landline'         => array( '97226456222', '026456222' ),
		);
	}

	/**
	 * Two genuinely different numbers must not collapse into one, or every call
	 * would be credited to whichever was checked first.
	 *
	 * @return void
	 */
	public function test_different_numbers_stay_different(): void {
		$this->assertNotSame(
			$this->call( 'canonical', '0731234567' ),
			$this->call( 'canonical', '0731234568' )
		);

		$this->assertNotSame(
			$this->call( 'canonical', '026456222' ),
			$this->call( 'canonical', '036456222' )
		);
	}

	/**
	 * Israeli dates are day first. PHP reads a slashed date the American way
	 * unless told, which put calls three months out.
	 *
	 * @return void
	 */
	public function test_a_slashed_date_is_read_day_first(): void {
		$parsed = $this->call( 'call_time', '12/09/2026 14:30:00' );

		$this->assertStringStartsWith( '2026-09-12', $parsed, 'the 12th of September, not the 9th of December' );
	}

	/**
	 * A date that is unambiguous either way still has to come out right.
	 *
	 * @return void
	 */
	public function test_an_unambiguous_slashed_date_is_read_correctly(): void {
		$this->assertStringStartsWith( '2026-09-25', $this->call( 'call_time', '25/09/2026 08:05:00' ) );
	}

	/**
	 * The ISO form the REST layer prefers.
	 *
	 * @return void
	 */
	public function test_an_iso_date_is_read_correctly(): void {
		$this->assertStringStartsWith( '2026-09-12', $this->call( 'call_time', '2026-09-12 14:30:00' ) );
	}

	/**
	 * Nothing usable still has to produce a storable time rather than an empty
	 * column, or the call disappears from every date-filtered report.
	 *
	 * @return void
	 */
	public function test_an_unreadable_date_still_produces_a_time(): void {
		$parsed = $this->call( 'call_time', 'not a date at all' );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $parsed );
	}

	// ── Filters ───────────────────────────────────────────────────────────────

	/**
	 * A campaign is read from the request.
	 *
	 * @return void
	 */
	public function test_a_campaign_filter_is_read(): void {
		$this->assertSame(
			array( 'campaign_id' => 10 ),
			Kivun_Phones::filters( array( 'kivun_call_campaign' => '10' ) )
		);
	}

	/**
	 * "Not answered" is the value zero, which is falsy — it has to survive being
	 * checked, or the filter silently means "everything".
	 *
	 * @return void
	 */
	public function test_the_not_answered_filter_survives_being_zero(): void {
		$filters = Kivun_Phones::filters( array( 'kivun_call_answered' => '0' ) );

		$this->assertArrayHasKey( 'answered', $filters );
		$this->assertSame( 0, $filters['answered'] );
	}

	/**
	 * Anything other than yes or no is not a choice at all.
	 *
	 * @return void
	 */
	public function test_a_nonsense_answered_filter_is_dropped(): void {
		$this->assertSame( array(), Kivun_Phones::filters( array( 'kivun_call_answered' => 'maybe' ) ) );
	}

	/**
	 * A backwards range returns nothing at all, which reads as a bug, so it is
	 * swapped rather than obeyed.
	 *
	 * @return void
	 */
	public function test_a_backwards_date_range_is_swapped(): void {
		$filters = Kivun_Phones::filters(
			array(
				'kivun_call_from' => '2026-09-30',
				'kivun_call_to'   => '2026-09-01',
			)
		);

		$this->assertSame( '2026-09-01', $filters['from'] );
		$this->assertSame( '2026-09-30', $filters['to'] );
	}

	/**
	 * A date that is not one is ignored rather than passed to the database.
	 *
	 * @dataProvider bad_date_provider
	 * @param string $value What arrived.
	 * @return void
	 */
	public function test_a_bad_date_is_ignored( string $value ): void {
		$this->assertSame( array(), Kivun_Phones::filters( array( 'kivun_call_from' => $value ) ) );
	}

	/**
	 * Values that are not dates.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function bad_date_provider(): array {
		return array(
			'day first'     => array( '31/09/2026' ),
			'no such day'   => array( '2026-02-31' ),
			'words'         => array( 'yesterday' ),
			'sql injection' => array( "2026-09-01' OR '1'='1" ),
		);
	}

	/**
	 * A media value the site does not offer is not a filter.
	 *
	 * @return void
	 */
	public function test_an_unknown_media_is_dropped(): void {
		$this->assertSame( array(), Kivun_Phones::filters( array( 'kivun_call_media' => 'carrier-pigeon' ) ) );
	}

	/**
	 * Nothing asked for is no filter at all, which is how the screen knows it is
	 * showing everything.
	 *
	 * @return void
	 */
	public function test_an_empty_request_produces_no_filters(): void {
		$this->assertSame( array(), Kivun_Phones::filters( array() ) );
	}
}
