<?php
/**
 * Recording which coordinator a lead went to.
 *
 * The rota picked somebody, the mail went out, and nothing anywhere said who.
 * The only trace was a single option holding the last routing result, which
 * the next submission overwrote — so a split nobody could see after the fact
 * could not be checked, and the first real question about it ("who got this
 * one?") had no answer at all.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the remembered coordinator and how it is shown.
 */
final class LeadHandlerTest extends TestCase {

	/**
	 * Start each test with an empty site and the rosters the client uses.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
		Kivun_Coordinators::forget_current();
		Kivun_Test_State::$options['kivun_settings'] = array(
			'coordinators_male'   => "יהודה, yehudaf@kivun.test = 1\nroim@kivun.test = 1",
			'coordinators_female' => "dasi@kivun.test = 1\navigaila@kivun.test = 3",
		);
	}

	/**
	 * And leave nothing behind for the next one.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Kivun_Coordinators::forget_current();
	}

	// ── Remembering the choice ────────────────────────────────────────────────

	/**
	 * Nothing is remembered before anything is chosen.
	 *
	 * @return void
	 */
	public function test_nothing_is_remembered_to_begin_with(): void {
		$this->assertSame( array(), Kivun_Coordinators::current() );
	}

	/**
	 * A pick is remembered, so whoever files the lead afterwards can record
	 * the same person the mail went to.
	 *
	 * @return void
	 */
	public function test_a_pick_is_remembered(): void {
		$chosen = Kivun_Coordinators::pick( 'אשה' );

		$this->assertNotSame( array(), $chosen );
		$this->assertSame( $chosen['email'], Kivun_Coordinators::current()['email'] );
	}

	/**
	 * The point of remembering: reading it back must not consult the rota.
	 * Asking a second time would answer with the *next* person and record a
	 * turn nobody took, quietly skewing the very split it is reporting on.
	 *
	 * @return void
	 */
	public function test_reading_it_back_does_not_move_the_rota(): void {
		$first = Kivun_Coordinators::pick( 'אשה' );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( $first['email'], Kivun_Coordinators::current()['email'] );
		}

		// The next real submission gets whoever was due all along.
		Kivun_Coordinators::forget_current();
		$state_before = Kivun_Test_State::$options[ Kivun_Coordinators::STATE ] ?? array();
		Kivun_Coordinators::current();

		$this->assertSame( $state_before, Kivun_Test_State::$options[ Kivun_Coordinators::STATE ] ?? array() );
	}

	/**
	 * A submission with no gender places nobody, and remembers nobody —
	 * exactly the case the client hit, where the lead reached only the
	 * general address.
	 *
	 * @return void
	 */
	public function test_no_gender_remembers_nobody(): void {
		$this->assertSame( array(), Kivun_Coordinators::pick( '' ) );
		$this->assertSame( array(), Kivun_Coordinators::current() );
	}

	/**
	 * A failed pick does not wipe out a good one made earlier in the same
	 * request.
	 *
	 * @return void
	 */
	public function test_a_later_blank_does_not_erase_the_choice(): void {
		$chosen = Kivun_Coordinators::pick( 'גבר' );
		Kivun_Coordinators::pick( '' );

		$this->assertSame( $chosen['email'], Kivun_Coordinators::current()['email'] );
	}

	// ── Showing it ────────────────────────────────────────────────────────────

	/**
	 * A name when the roster gives one.
	 *
	 * @return void
	 */
	public function test_a_name_is_shown_when_the_roster_has_one(): void {
		$this->assertSame( 'יהודה', Kivun_Coordinators::label_for( 'yehudaf@kivun.test' ) );
	}

	/**
	 * The address otherwise — still an answer to "who got this", which is the
	 * question being asked. The client's own rosters are addresses only.
	 *
	 * @return void
	 */
	public function test_the_address_is_shown_when_there_is_no_name(): void {
		$this->assertSame( 'dasi@kivun.test', Kivun_Coordinators::label_for( 'dasi@kivun.test' ) );
	}

	/**
	 * Somebody since taken off the roster still shows: they did handle it,
	 * and that is a matter of record.
	 *
	 * @return void
	 */
	public function test_someone_no_longer_on_the_roster_still_shows(): void {
		$this->assertSame( 'gone@kivun.test', Kivun_Coordinators::label_for( 'gone@kivun.test' ) );
	}

	/**
	 * Nothing stored shows as nothing, so the screen can tell that apart from
	 * a lead that was placed.
	 *
	 * @return void
	 */
	public function test_nothing_stored_shows_as_nothing(): void {
		$this->assertSame( '', Kivun_Coordinators::label_for( '' ) );
		$this->assertSame( '', Kivun_Coordinators::label_for( '   ' ) );
	}

	// ── The row that gets written ─────────────────────────────────────────────

	/**
	 * $wpdb->insert() matches values to placeholders by position alone, so a
	 * column added in the middle of the row without one beside it shifts
	 * every format after it onto the wrong value — and $wpdb writes it
	 * without complaining. Adding `coordinator` did exactly that.
	 *
	 * @return void
	 */
	public function test_every_column_written_has_a_placeholder(): void {
		foreach ( array( 'class-kivun-lead-capture.php', 'class-kivun-workshops.php' ) as $file ) {
			$source = (string) file_get_contents( KIVUN_DIR . 'includes/' . $file );

			$this->assertStringNotContainsString(
				"\$fmt = array( '%d'",
				$source,
				"{$file} writes the placeholder list by hand, and it will drift from the row the next time a column is added"
			);
			$this->assertStringContainsString( 'foreach ( array_keys( $row ) as $column )', $source, $file );
		}
	}

	// ── Whichever order the two halves run in ─────────────────────────────────

	/**
	 * Both paths that file a lead report it the same way, so neither is left
	 * without a coordinator because it happens to run on the other side of
	 * the router. Assuming one order is what shipped a release where the
	 * column stayed empty on the only form that mattered.
	 *
	 * @return void
	 */
	public function test_both_paths_that_file_a_lead_report_it(): void {
		foreach ( array( 'class-kivun-lead-capture.php', 'class-kivun-workshops.php' ) as $file ) {
			$this->assertStringContainsString(
				'Kivun_Coordinators::attach_to_lead(',
				(string) file_get_contents( KIVUN_DIR . 'includes/' . $file ),
				"{$file} files a lead without saying which coordinator took it"
			);
		}
	}

	/**
	 * The lead filed first, the coordinator chosen after — the Elementor form
	 * action's order, and the one that was broken.
	 *
	 * @return void
	 */
	public function test_a_lead_filed_before_the_choice_is_stamped(): void {
		Kivun_Coordinators::attach_to_lead( 77 );
		$chosen = Kivun_Coordinators::pick( 'אשה' );

		$this->assertSame(
			array(
				'table' => 'wp_kivun_registrations',
				'data'  => array( 'coordinator' => $chosen['email'] ),
				'where' => array( 'id' => 77 ),
			),
			end( Kivun_Test_State::$updates )
		);
	}

	/**
	 * And the other way round — the plugin's own capture, which runs after
	 * the router.
	 *
	 * @return void
	 */
	public function test_a_lead_filed_after_the_choice_is_stamped(): void {
		$chosen = Kivun_Coordinators::pick( 'גבר' );
		Kivun_Coordinators::attach_to_lead( 88 );

		$this->assertSame(
			array(
				'table' => 'wp_kivun_registrations',
				'data'  => array( 'coordinator' => $chosen['email'] ),
				'where' => array( 'id' => 88 ),
			),
			end( Kivun_Test_State::$updates )
		);
	}

	/**
	 * A lead the rota placed nobody for is left alone rather than stamped
	 * with an empty string, so "never asked" stays distinguishable.
	 *
	 * @return void
	 */
	public function test_a_lead_with_no_coordinator_is_not_touched(): void {
		Kivun_Coordinators::attach_to_lead( 99 );
		Kivun_Coordinators::pick( '' );

		$this->assertSame( array(), Kivun_Test_State::$updates );
	}
}
