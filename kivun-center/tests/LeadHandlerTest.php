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

	// ── Handing one over by hand ──────────────────────────────────────────────

	/**
	 * Drive the assignment handler as the screen does.
	 *
	 * @param int    $id      The lead row.
	 * @param string $handler The address picked, or '' for nobody.
	 * @param string $before  What the row holds now.
	 * @return Kivun_Test_Json
	 */
	private function assign( int $id, string $handler, string $before = '' ): Kivun_Test_Json {
		global $wpdb;

		// Reads answer with what the column holds now, which is the write if
		// one has happened. A double that answered with the old value either
		// way would let the handler report the wrong person to the screen and
		// still pass — and the screen believes the answer over what was
		// picked.
		$wpdb->var_handler = static function ( string $sql ) use ( $before, $id ) {
			if ( ! str_contains( $sql, 'coordinator' ) ) {
				return null;
			}

			foreach ( array_reverse( Kivun_Test_State::$updates ) as $write ) {
				if ( array( 'id' => $id ) === $write['where'] && isset( $write['data']['coordinator'] ) ) {
					return $write['data']['coordinator'];
				}
			}

			return $before;
		};

		$_POST = array(
			'id'      => (string) $id,
			'handler' => $handler,
		);

		try {
			Kivun_Admin::ajax_assign_handler();
		} catch ( Kivun_Test_Json $answer ) {
			return $answer;
		} finally {
			$_POST             = array();
			$wpdb->var_handler = null;
		}

		$this->fail( 'the handler answered nothing at all' );
	}

	/**
	 * The field offers everyone on both rosters, by name.
	 *
	 * @return void
	 */
	public function test_the_field_offers_every_coordinator(): void {
		$html = Kivun_Admin::handler_select( 5, '' );

		$this->assertStringContainsString( '<option value="yehudaf@kivun.test"', $html );
		$this->assertStringContainsString( '>יהודה<', $html );
		$this->assertStringContainsString( '<option value="dasi@kivun.test"', $html );
		$this->assertStringContainsString( '<option value="avigaila@kivun.test"', $html );
	}

	/**
	 * A lead the rota could not place shows nobody, and that is the selected
	 * option rather than the first name on the list — which is the whole
	 * point: the field is empty because nobody has it, and picking somebody
	 * is a deliberate act.
	 *
	 * @return void
	 */
	public function test_an_unplaced_lead_selects_nobody(): void {
		$this->assertStringContainsString(
			"<option value=\"\" selected='selected'>",
			Kivun_Admin::handler_select( 5, '' )
		);
	}

	/**
	 * Whoever has it is the one showing.
	 *
	 * @return void
	 */
	public function test_the_coordinator_on_the_row_is_the_one_selected(): void {
		$this->assertStringContainsString(
			"<option value=\"dasi@kivun.test\" selected='selected'>",
			Kivun_Admin::handler_select( 5, 'dasi@kivun.test' )
		);
	}

	/**
	 * Somebody since taken off the roster is still offered, and still
	 * selected. A control with no option matching its row shows the first
	 * one instead — so the lead would read as somebody else's, and the next
	 * save in that row would make it true.
	 *
	 * @return void
	 */
	public function test_a_coordinator_who_has_left_still_shows(): void {
		$html = Kivun_Admin::handler_select( 5, 'gone@kivun.test' );

		$this->assertStringContainsString( "<option value=\"gone@kivun.test\" selected='selected'>", $html );
		$this->assertStringNotContainsString( "<option value=\"\" selected='selected'>", $html );
	}

	/**
	 * Assigning writes the address to the lead's own column.
	 *
	 * @return void
	 */
	public function test_assigning_writes_the_coordinator(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );

		$answer = $this->assign( 42, 'dasi@kivun.test' );

		$this->assertTrue( $answer->ok );
		$this->assertSame(
			array(
				'table' => 'wp_kivun_registrations',
				'data'  => array( 'coordinator' => 'dasi@kivun.test' ),
				'where' => array( 'id' => 42 ),
			),
			end( Kivun_Test_State::$updates )
		);

		// Read back and reported, rather than echoed from what was asked
		// for: the screen trusts this over what was picked.
		$this->assertSame( 'dasi@kivun.test', $answer->data['handler'] );
	}

	/**
	 * And taking a lead off somebody writes the empty column back, so it
	 * returns to the list of ones nobody has.
	 *
	 * @return void
	 */
	public function test_a_lead_can_be_taken_off_somebody(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );

		$answer = $this->assign( 42, '', 'dasi@kivun.test' );

		$this->assertTrue( $answer->ok );
		$this->assertSame( array( 'coordinator' => '' ), end( Kivun_Test_State::$updates )['data'] );
		$this->assertSame( '', $answer->data['handler'] );
		$this->assertSame( '', $answer->data['label'] );
	}

	/**
	 * An address nobody on the rosters has is refused rather than written.
	 * The column feeds the sign-off on the candidate's letter and the
	 * question of who had which lead, and a typed-in address answers both
	 * with something that is not a person here.
	 *
	 * @return void
	 */
	public function test_somebody_who_is_not_a_coordinator_is_refused(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );

		$answer = $this->assign( 42, 'stranger@example.com' );

		$this->assertFalse( $answer->ok );
		$this->assertSame( 400, $answer->status );
		$this->assertSame( array(), Kivun_Test_State::$updates );
	}

	/**
	 * Except the one already on the row: a coordinator who has left is still
	 * the value the field is showing, and re-picking what is already there
	 * is not an error.
	 *
	 * @return void
	 */
	public function test_the_address_already_on_the_row_is_allowed(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );

		$answer = $this->assign( 42, 'gone@kivun.test', 'gone@kivun.test' );

		$this->assertTrue( $answer->ok );
	}

	/**
	 * Reading the leads is not enough on its own — but it is what the leads
	 * reader has, and working a lead means saying who is working it. The
	 * same rule as the status beside it.
	 *
	 * @return void
	 */
	public function test_who_may_hand_a_lead_over(): void {
		kivun_test_set_caps( array() );
		$refused = $this->assign( 42, 'dasi@kivun.test' );

		$this->assertFalse( $refused->ok );
		$this->assertSame( 403, $refused->status );
		$this->assertSame( array(), Kivun_Test_State::$updates );

		kivun_test_set_caps( array( 'edit_posts' ) );
		$this->assertTrue( $this->assign( 42, 'dasi@kivun.test' )->ok );
	}

	/**
	 * A row that is not there is said to be not there, rather than reported
	 * as saved.
	 *
	 * @return void
	 */
	public function test_a_deleted_row_is_not_reported_as_saved(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );

		global $wpdb;
		$wpdb->var_handler = static fn () => null;
		$_POST             = array(
			'id'      => '42',
			'handler' => 'dasi@kivun.test',
		);

		try {
			Kivun_Admin::ajax_assign_handler();
			$this->fail( 'the handler answered nothing at all' );
		} catch ( Kivun_Test_Json $answer ) {
			$this->assertFalse( $answer->ok );
			$this->assertSame( 404, $answer->status );
			$this->assertSame( array(), Kivun_Test_State::$updates );
		} finally {
			$_POST             = array();
			$wpdb->var_handler = null;
		}
	}
}
