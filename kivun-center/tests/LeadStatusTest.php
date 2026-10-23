<?php
/**
 * Telling a lead nobody has reached from one somebody has dealt with.
 *
 * Every lead is written with a status the moment it arrives — the forms write
 * 'new_lead', a course registration takes the column's 'pending' — so the
 * label under the dropdown said "ליד חדש" on every row, including the ones
 * worked through yesterday. A column that reads the same whatever happened
 * answers nothing, which is the one question it was added for: has this one
 * been handled?
 *
 * So the field stays empty until a person sets it. What that costs is the
 * thing these tests hold still: the statuses the system writes are still the
 * vocabulary — the filters, the export and the dashboard count read them —
 * they are only not offered as a choice, and clearing one puts the row back
 * where it was rather than leaving a blank column behind.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the untouched-status rule and the control built from it.
 */
final class LeadStatusTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();

		// These hold the control staff are given. Whoever may not write to
		// the row is shown the answer without it, which has tests of its own.
		kivun_test_set_caps( array( 'edit_posts' ) );
	}

	/**
	 * The leads control, as the console renders it.
	 *
	 * @param string $current The stored status.
	 * @return string
	 */
	private function leads_control( string $current ): string {
		return Kivun_Admin::status_select( 'registrations', 7, $current, Kivun_Admin::reg_statuses() );
	}

	// ── The two questions, which are not the same question ────────────────────

	/**
	 * What is worth showing. The three the system writes when a lead arrives
	 * say only that a lead arrived, so the field stays empty; everything else
	 * says something, and is shown.
	 *
	 * @return void
	 */
	public function test_what_is_worth_showing(): void {
		foreach ( array( '', 'pending', 'new', 'new_lead' ) as $status ) {
			$this->assertFalse(
				Kivun_Admin::is_shown( 'registrations', $status ),
				"'{$status}' is written when the lead arrives and says nothing about it"
			);
		}

		foreach ( array( 'contacted', 'no_answer', 'interested', 'enrolled', 'closed', 'not_relevant', 'next_cycle' ) as $status ) {
			$this->assertTrue(
				Kivun_Admin::is_shown( 'registrations', $status ),
				"'{$status}' says something the reader needs"
			);
		}
	}

	/**
	 * What counts as nobody having dealt with it, which is the other
	 * question. A waiting-list sign-up is written by the form too, before
	 * anybody here has seen it — so it is shown, because the visitor asked
	 * for it, and it is still waiting for somebody to ring them.
	 *
	 * Read as handled, every waiting-list sign-up would have been quietly
	 * missing from the list of leads to call, which is the list the filter
	 * exists to produce.
	 *
	 * @return void
	 */
	public function test_a_waiting_list_sign_up_is_shown_and_still_waiting(): void {
		$this->assertTrue( Kivun_Admin::is_shown( 'registrations', 'next_cycle' ) );
		$this->assertContains( 'next_cycle', Kivun_Admin::untouched_statuses( 'registrations' ) );
		$this->assertContains( 'next_cycle', Kivun_Admin::filter_status_values( array( Kivun_Admin::UNTOUCHED_FILTER ) ) );

		// Shown means shown: the field names it and so does the label.
		$html = $this->leads_control( 'next_cycle' );
		$this->assertStringContainsString( "<option value=\"next_cycle\" selected='selected'>", $html );
		$this->assertStringContainsString( 'data-status="next_cycle">למחזור הבא</span>', $html );
	}

	/**
	 * It is the form that writes it, so this test is only true while that is
	 * what the form writes.
	 *
	 * @return void
	 */
	public function test_the_sign_up_form_still_writes_it(): void {
		$this->assertStringContainsString(
			"\$status = \$is_next_cycle ? 'next_cycle' : 'new_lead';",
			(string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' ),
			'the waiting-list status is written somewhere else now, so check it is still nobody\'s decision'
		);
	}

	/**
	 * Each status the rules name is one the vocabulary knows, so a renamed
	 * key cannot quietly stop matching and put "ליד חדש" back on every row.
	 *
	 * @return void
	 */
	public function test_the_hidden_statuses_are_real_statuses(): void {
		$known = Kivun_Admin::reg_statuses();

		foreach ( Kivun_Admin::untouched_statuses( 'registrations' ) as $status ) {
			if ( '' === $status ) {
				continue;
			}
			$this->assertArrayHasKey( $status, $known, "{$status} is named by the rules but is not a status" );
		}
	}

	/**
	 * A CV application is worked on the employer's dashboard too, which has a
	 * status list of its own. Emptying one of the two screens and not the
	 * other would have them disagree about the same application, so the rule
	 * stops at the leads table.
	 *
	 * @return void
	 */
	public function test_applications_are_left_as_they_were(): void {
		$this->assertTrue( Kivun_Admin::is_shown( 'applications', 'new' ) );

		$html = Kivun_Admin::status_select( 'applications', 3, 'new', Kivun_Employer::app_statuses() );

		$this->assertStringContainsString( ">חדש<", $html );
		$this->assertStringContainsString( 'data-status="new">חדש<', $html );
	}

	/**
	 * And they are not offered the empty option, which the handler refuses to
	 * write for anything but a lead: a choice that can only answer 400 and
	 * put itself back is worse than no choice at all.
	 *
	 * @return void
	 */
	public function test_applications_are_not_offered_a_choice_that_cannot_work(): void {
		$html = Kivun_Admin::status_select( 'applications', 3, 'new', Kivun_Employer::app_statuses() );

		$this->assertStringNotContainsString( '<option value=""', $html );
		$this->assertStringContainsString(
			"\$clearing && 'registrations' !== \$table",
			(string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-admin.php' ),
			'the handler no longer refuses it, so the two have stopped agreeing'
		);
	}

	// ── What the control shows ────────────────────────────────────────────────

	/**
	 * A lead nobody has reached: the field is empty, and nothing is claimed
	 * underneath it.
	 *
	 * @return void
	 */
	public function test_an_untouched_lead_says_nothing(): void {
		foreach ( array( 'new_lead', 'pending', 'new', '' ) as $stored ) {
			$html = $this->leads_control( $stored );

			$this->assertStringContainsString(
				"<option value=\"\" selected='selected'></option>",
				$html,
				"a lead stored as '{$stored}' should show an empty field"
			);
			$this->assertStringContainsString(
				'<span class="kivun-status-badge" data-status=""></span>',
				$html,
				"a lead stored as '{$stored}' should carry no label"
			);
		}
	}

	/**
	 * And they are not on the list either, so picking one cannot be a way of
	 * saying something the screen then refuses to show.
	 *
	 * @return void
	 */
	public function test_the_statuses_the_system_writes_are_not_offered(): void {
		$html = $this->leads_control( 'new_lead' );

		foreach ( array( 'pending', 'new', 'new_lead' ) as $status ) {
			$this->assertStringNotContainsString( "<option value=\"{$status}\"", $html );
		}

		$this->assertStringContainsString( '<option value="contacted"', $html );
		$this->assertStringContainsString( '<option value="closed"', $html );
	}

	/**
	 * Once somebody has chosen, the choice is in the field and named under
	 * it. Which is what the label was for.
	 *
	 * @return void
	 */
	public function test_a_chosen_status_is_shown_both_ways(): void {
		$html = $this->leads_control( 'closed' );

		$this->assertStringContainsString( "<option value=\"closed\" selected='selected'>", $html );
		$this->assertStringContainsString( '<span class="kivun-status-badge" data-status="closed">נסגר ✓</span>', $html );
		$this->assertStringNotContainsString( "<option value=\"\" selected='selected'>", $html );
	}

	/**
	 * The empty option stays on the list after a status is set: a row marked
	 * by mistake needs a way back, and unticking is not one a dropdown has.
	 *
	 * @return void
	 */
	public function test_the_empty_option_is_always_there(): void {
		$this->assertStringContainsString( '<option value=""', $this->leads_control( 'closed' ) );
	}

	/**
	 * The label element is in the row whether or not it has anything to say.
	 * The script writes the chosen status into it without reloading, so a
	 * row that arrived without one still needs somewhere to put it; the
	 * stylesheet hides it while it is empty.
	 *
	 * @return void
	 */
	public function test_the_empty_label_is_hidden_rather_than_absent(): void {
		$this->assertStringContainsString( 'class="kivun-status-badge"', $this->leads_control( 'new_lead' ) );

		foreach ( array( 'assets/css/admin.css', 'assets/css/frontend.css' ) as $sheet ) {
			$this->assertStringContainsString(
				'.kivun-status-badge:empty { display: none; }',
				(string) file_get_contents( KIVUN_DIR . $sheet ),
				"{$sheet} would show an empty pill under every untouched lead"
			);
		}
	}

	/**
	 * The field is named for a screen reader, which it was not when it had a
	 * word in it to read.
	 *
	 * @return void
	 */
	public function test_the_empty_field_still_has_a_name(): void {
		$this->assertStringContainsString( 'aria-label="סטטוס"', $this->leads_control( 'new_lead' ) );
	}

	// ── Clearing one ──────────────────────────────────────────────────────────

	/**
	 * Clearing puts back the status the row would have been captured with,
	 * rather than emptying the column: the dashboard counts new leads by that
	 * word and the filter finds them by it, and a row holding nothing would
	 * have dropped out of both.
	 *
	 * @return void
	 */
	public function test_clearing_returns_the_status_the_row_arrived_with(): void {
		$this->assertSame( 'pending', Kivun_Admin::capture_status( 'registration' ) );

		foreach ( array( 'lead', 'form', 'jobs_board', 'workshop', 'session', 'event' ) as $type ) {
			$this->assertSame( 'new_lead', Kivun_Admin::capture_status( $type ) );
		}
	}

	/**
	 * And what goes back in the column is a status the screen knows, or the
	 * row would read as blank in every list that looks the value up by name.
	 *
	 * @return void
	 */
	public function test_what_clearing_writes_is_part_of_the_vocabulary(): void {
		$known = Kivun_Admin::reg_statuses();

		foreach ( array( 'registration', 'lead' ) as $type ) {
			$this->assertArrayHasKey( Kivun_Admin::capture_status( $type ), $known );
		}
	}

	/**
	 * A call that nobody picked up.
	 *
	 * Only ever set by hand, so unlike a waiting-list sign-up it means
	 * somebody has dealt with the lead: it comes off "לא טופלו" and is
	 * looked for on its own, which is the list of people to try again.
	 *
	 * @return void
	 */
	public function test_a_call_that_went_unanswered(): void {
		$this->assertArrayHasKey( 'no_answer', Kivun_Admin::reg_statuses() );
		$this->assertSame( 'אין מענה', Kivun_Admin::reg_statuses()['no_answer'] );

		$this->assertTrue( Kivun_Admin::is_shown( 'registrations', 'no_answer' ) );
		$this->assertNotContains( 'no_answer', Kivun_Admin::untouched_statuses( 'registrations' ) );
		$this->assertArrayHasKey( 'no_answer', Kivun_Admin::filter_statuses() );
		$this->assertSame( array( 'no_answer' ), Kivun_Admin::filter_status_values( array( 'no_answer' ) ) );

		$html = $this->leads_control( 'no_answer' );
		$this->assertStringContainsString( "<option value=\"no_answer\" selected='selected'>אין מענה<", $html );
		$this->assertStringContainsString( 'data-status="no_answer">אין מענה</span>', $html );
	}

	/**
	 * Every status that reaches the screen has a colour of its own.
	 *
	 * A new one without a rule does not look broken — it falls through to
	 * the neutral grey and sits in the column reading as "finished, sort
	 * of", which is the one thing the colours are there to prevent. The
	 * stylesheet is the only place that knows, so it is asked here.
	 *
	 * @return void
	 */
	public function test_every_status_on_screen_has_a_colour(): void {
		$sheets = array();
		foreach ( array( 'assets/css/admin.css', 'assets/css/frontend.css' ) as $sheet ) {
			$sheets[ $sheet ] = (string) file_get_contents( KIVUN_DIR . $sheet );
		}

		foreach ( array_keys( Kivun_Admin::reg_statuses() ) as $status ) {
			if ( ! Kivun_Admin::is_shown( 'registrations', (string) $status ) ) {
				continue;
			}
			foreach ( $sheets as $sheet => $css ) {
				$this->assertStringContainsString(
					".kivun-status-badge[data-status=\"{$status}\"]",
					$css,
					"{$sheet} has no colour for {$status}, so it would read as a finished one"
				);
			}
		}
	}

	// ── Finding the ones nobody has dealt with ────────────────────────────────

	/**
	 * The filter asks the plain question once, rather than offering the three
	 * words the same answer is stored as. Which of them a row holds depends
	 * on where it came from — a course registration is 'pending', a form
	 * enquiry is 'new_lead' — and that is a fact about our plumbing, not
	 * about the lead.
	 *
	 * @return void
	 */
	public function test_the_filter_offers_one_untouched_choice(): void {
		$offered = Kivun_Admin::filter_statuses();

		$this->assertSame( 'לא טופלו', $offered[ Kivun_Admin::UNTOUCHED_FILTER ] );
		$this->assertSame(
			Kivun_Admin::UNTOUCHED_FILTER,
			array_key_first( $offered ),
			'the question being asked most often belongs at the top of the list'
		);

		foreach ( array( 'pending', 'new', 'new_lead' ) as $status ) {
			$this->assertArrayNotHasKey( $status, $offered );
		}

		$this->assertArrayHasKey( 'closed', $offered );
		$this->assertArrayHasKey( 'not_relevant', $offered );
	}

	/**
	 * And it expands back into the words the rows actually hold, or it would
	 * match nothing: no lead is stored as "untouched".
	 *
	 * @return void
	 */
	public function test_the_untouched_choice_becomes_the_stored_words(): void {
		$values = Kivun_Admin::filter_status_values( array( Kivun_Admin::UNTOUCHED_FILTER ) );

		foreach ( array( 'pending', 'new', 'new_lead' ) as $status ) {
			$this->assertContains( $status, $values );
		}
		$this->assertNotContains( Kivun_Admin::UNTOUCHED_FILTER, $values );
	}

	/**
	 * Chosen with others, it adds its words to theirs — "not dealt with, or
	 * closed" is a question somebody asks.
	 *
	 * @return void
	 */
	public function test_untouched_can_be_chosen_alongside_a_real_status(): void {
		$values = Kivun_Admin::filter_status_values( array( Kivun_Admin::UNTOUCHED_FILTER, 'closed' ) );

		$this->assertContains( 'new_lead', $values );
		$this->assertContains( 'closed', $values );
		$this->assertSame( $values, array_values( array_unique( $values ) ) );
	}

	/**
	 * Nothing the screen does not offer reaches the query. A hand-edited URL
	 * asking for a status that does not exist narrows to nothing rather than
	 * being passed through.
	 *
	 * @return void
	 */
	public function test_only_offered_values_survive(): void {
		$this->assertSame( array(), Kivun_Admin::filter_status_values( array( 'bogus', "'; DROP TABLE x --", '1' ) ) );
		$this->assertSame( array(), Kivun_Admin::filter_status_values( array() ) );
		$this->assertSame( array( 'closed' ), Kivun_Admin::filter_status_values( array( 'closed', 'bogus' ) ) );
	}

	/**
	 * The list the console filters by is this one, and the words it sends to
	 * the database come from here — not from the row control's vocabulary,
	 * which has neither the question nor the statuses that answer it.
	 *
	 * @return void
	 */
	public function test_the_console_filters_through_these(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( '$filter_statuses = Kivun_Admin::filter_statuses();', $source );
		$this->assertStringContainsString( 'Kivun_Admin::filter_status_values( $status_filter )', $source );
		$this->assertStringContainsString( 'foreach ( $filter_statuses as $sv => $sl )', $source );
	}

	/**
	 * Cleared, the row holds 'new_lead' again — and the field must not then
	 * show it, or clearing would put the word straight back on screen. The
	 * answer the browser is given says which.
	 *
	 * @return void
	 */
	public function test_the_answer_says_whether_to_show_what_was_stored(): void {
		$this->assertFalse( Kivun_Admin::is_shown( 'registrations', 'new_lead' ) );
		$this->assertTrue( Kivun_Admin::is_shown( 'registrations', 'contacted' ) );

		$this->assertStringContainsString(
			"'chosen' => self::is_shown( \$table, (string) \$stored )",
			(string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-admin.php' ),
			'the handler no longer tells the browser whether the stored status is a decision'
		);
		$this->assertStringContainsString(
			'false === res.data.chosen',
			(string) file_get_contents( KIVUN_DIR . 'assets/js/admin-crm.js' ),
			'the script no longer reads it, so a cleared lead would show the word it was reset to'
		);
	}
}
