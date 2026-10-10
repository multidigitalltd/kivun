<?php
/**
 * The rota that shares candidates between coordinators.
 *
 * The client's shares are a half each for men and a quarter/three-quarters for
 * women. Getting that wrong is invisible — nobody notices an unfair split until
 * somebody counts a month later — so it is checked over a long run rather than
 * by eye.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Coordinators.
 */
final class CoordinatorsTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Put a roster into settings, the way the admin screen stores it.
	 *
	 * @param string $male   Lines for the male roster.
	 * @param string $female Lines for the female roster.
	 * @return void
	 */
	private function roster( string $male = '', string $female = '' ): void {
		Kivun_Test_State::$options['kivun_settings'] = array(
			'coordinators_male'   => $male,
			'coordinators_female' => $female,
		);
	}

	/**
	 * Deal out a run of candidates and count where they landed.
	 *
	 * @param string $gender The gender submitted.
	 * @param int    $times  How many candidates.
	 * @return array<string,int> Address => how many they got.
	 */
	private function deal( string $gender, int $times ): array {
		$tally = array();
		for ( $i = 0; $i < $times; $i++ ) {
			$picked = Kivun_Coordinators::pick( $gender );
			if ( $picked ) {
				$tally[ $picked['email'] ] = ( $tally[ $picked['email'] ] ?? 0 ) + 1;
			}
		}

		// Sorted by address: the test is about the shares, not about who
		// happened to be dealt to first.
		ksort( $tally );

		return $tally;
	}

	/**
	 * A gender word is matched on what people actually pick, not an exact string.
	 *
	 * @return void
	 */
	public function test_gender_words_are_recognised(): void {
		foreach ( array( 'גבר', 'זכר', 'בחור', 'male', 'Male', 'בן' ) as $word ) {
			$this->assertSame( 'male', Kivun_Coordinators::group_of( $word ), $word );
		}

		foreach ( array( 'אישה', 'אשה', 'נקבה', 'בחורה', 'female', 'בת' ) as $word ) {
			$this->assertSame( 'female', Kivun_Coordinators::group_of( $word ), $word );
		}
	}

	/**
	 * "בן/בת" contains a male word, so the female test has to come first.
	 *
	 * @return void
	 */
	public function test_a_combined_label_is_read_as_female(): void {
		$this->assertSame( 'female', Kivun_Coordinators::group_of( 'בן/בת' ) );
	}

	/**
	 * Nothing recognisable means nobody is picked, rather than a default.
	 *
	 * @return void
	 */
	public function test_an_unknown_gender_picks_nobody(): void {
		$this->roster( 'a@example.test = 1' );

		$this->assertSame( '', Kivun_Coordinators::group_of( 'לא רוצה לומר' ) );
		$this->assertSame( array(), Kivun_Coordinators::pick( 'לא רוצה לומר' ) );
	}

	/**
	 * Two equal shares split a long run down the middle.
	 *
	 * @return void
	 */
	public function test_equal_shares_split_in_half(): void {
		$this->roster( "yehudaf@kivun.test = 1\nroim@kivun.test = 1" );

		$this->assertSame(
			array(
				'roim@kivun.test'    => 50,
				'yehudaf@kivun.test' => 50,
			),
			$this->deal( 'גבר', 100 )
		);
	}

	/**
	 * A quarter and three quarters, written either way round.
	 *
	 * @return void
	 */
	public function test_quarter_and_three_quarters(): void {
		$this->roster( '', "avigaila@kivun.test = 1/4\ndasi@kivun.test = 3/4" );

		$this->assertSame(
			array(
				'avigaila@kivun.test' => 25,
				'dasi@kivun.test'     => 75,
			),
			$this->deal( 'אשה', 100 )
		);
	}

	/**
	 * The point of the smooth rota: the larger share does not arrive as a
	 * block. Three in a row on one desk is what a naive queue would produce.
	 *
	 * @return void
	 */
	public function test_the_bigger_share_is_spread_not_bunched(): void {
		$this->roster( '', "small@kivun.test = 1\nbig@kivun.test = 3" );

		$order = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$order[] = Kivun_Coordinators::pick( 'אשה' )['email'];
		}

		// Never four of the same in a row, and the small share appears in every
		// group of four rather than all at the end.
		$this->assertStringNotContainsString(
			'big,big,big,big',
			implode( ',', array_map( static fn( $e ) => explode( '@', $e )[0], $order ) )
		);

		foreach ( array_chunk( $order, 4 ) as $chunk ) {
			$this->assertContains( 'small@kivun.test', $chunk, 'every four should include the quarter share' );
		}
	}

	/**
	 * The two groups keep separate books; dealing to one does not move the other.
	 *
	 * @return void
	 */
	public function test_the_groups_are_counted_separately(): void {
		$this->roster( "m1@kivun.test = 1\nm2@kivun.test = 1", "f1@kivun.test = 1\nf2@kivun.test = 1" );

		$this->deal( 'גבר', 7 );

		$this->assertSame(
			array(
				'f1@kivun.test' => 5,
				'f2@kivun.test' => 5,
			),
			$this->deal( 'אשה', 10 )
		);
	}

	/**
	 * A line may carry a name and a phone for the letter's sign-off, in any
	 * order, and the older address-only lines keep working.
	 *
	 * @return void
	 */
	public function test_a_line_may_name_the_coordinator(): void {
		$this->roster( 'יהודה, yehudaf@kivun.test, 052-1234567 = 1' );

		$picked = Kivun_Coordinators::pick( 'גבר' );

		$this->assertSame( 'yehudaf@kivun.test', $picked['email'] );
		$this->assertSame( 'יהודה', $picked['name'] );
		$this->assertSame( '052-1234567', $picked['phone'] );
	}

	/**
	 * The parts are told apart by what they look like, so the order they are
	 * typed in does not matter.
	 *
	 * @return void
	 */
	public function test_the_order_of_the_parts_does_not_matter(): void {
		$this->roster( '052-1234567, yehudaf@kivun.test, יהודה = 1' );

		$picked = Kivun_Coordinators::pick( 'גבר' );

		$this->assertSame( 'yehudaf@kivun.test', $picked['email'] );
		$this->assertSame( 'יהודה', $picked['name'] );
		$this->assertSame( '052-1234567', $picked['phone'] );
	}

	/**
	 * An empty roster is a working state, not an error: it means the split is
	 * switched off.
	 *
	 * @return void
	 */
	public function test_an_empty_roster_picks_nobody(): void {
		$this->roster( '', '' );

		$this->assertSame( array(), Kivun_Coordinators::pick( 'גבר' ) );
		$this->assertSame( array(), Kivun_Coordinators::recipients( 'אשה' ) );
	}

	/**
	 * A line without an address is not a coordinator and must not take a turn.
	 *
	 * @return void
	 */
	public function test_a_line_with_no_address_is_ignored(): void {
		$this->roster( "just a note\nreal@kivun.test = 1" );

		$this->assertSame( array( 'real@kivun.test' => 4 ), $this->deal( 'גבר', 4 ) );
	}

	/**
	 * Someone taken off the roster loses their credit, and a newcomer starts
	 * level rather than owed a backlog of everything they missed.
	 *
	 * @return void
	 */
	public function test_a_newcomer_does_not_arrive_owed_a_backlog(): void {
		$this->roster( 'first@kivun.test = 1' );
		$this->deal( 'גבר', 20 );

		$this->roster( "first@kivun.test = 1\nsecond@kivun.test = 1" );
		$tally = $this->deal( 'גבר', 10 );

		$this->assertSame( 5, $tally['second@kivun.test'] ?? 0 );
	}

	/**
	 * Recipients is the address list the router sends to.
	 *
	 * @return void
	 */
	public function test_recipients_returns_one_address(): void {
		$this->roster( 'only@kivun.test = 1' );

		$this->assertSame( array( 'only@kivun.test' ), Kivun_Coordinators::recipients( 'גבר' ) );
	}
}
