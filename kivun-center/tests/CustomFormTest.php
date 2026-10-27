<?php
/**
 * The form a landing page was given.
 *
 * A page may carry questions of its own instead of the fixed set. What these
 * hold still is the boundary between the two kinds of field: the built-ins
 * are the lead's own columns and can be switched off but never replaced,
 * and everything else is a question whose answer is kept with its label.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Defining a form, and reading its answers back.
 */
final class CustomFormTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Give page 9 a form.
	 *
	 * @param array $fields   The questions.
	 * @param array $builtins The built-ins to keep.
	 * @return int The page id.
	 */
	private function page( array $fields, array $builtins = array( 'email', 'city' ) ): int {
		Kivun_Custom_Forms::save( 9, $fields, $builtins );
		return 9;
	}

	// ── Defining ─────────────────────────────────────────────────────────────

	/**
	 * A question keeps its label, its type and whether it is required.
	 *
	 * @return void
	 */
	public function test_a_question_is_stored_as_given(): void {
		$id = $this->page(
			array(
				array( 'label' => 'באיזה מסלול?', 'type' => 'select', 'required' => true, 'options' => array( 'חשמל', 'מכונות' ) ),
			)
		);

		$fields = Kivun_Custom_Forms::fields( $id );
		$this->assertCount( 1, $fields );
		$this->assertSame( 'באיזה מסלול?', $fields[0]['label'] );
		$this->assertSame( 'select', $fields[0]['type'] );
		$this->assertTrue( $fields[0]['required'] );
		$this->assertSame( array( 'חשמל', 'מכונות' ), $fields[0]['options'] );
	}

	/**
	 * A question with no label is not a question.
	 *
	 * Dropped rather than given one of ours: inventing a label would put a
	 * word we wrote into a form the client composed.
	 *
	 * @return void
	 */
	public function test_a_question_with_no_label_is_dropped(): void {
		$id = $this->page(
			array(
				array( 'label' => '   ', 'type' => 'text' ),
				array( 'label' => 'שאלה אמיתית', 'type' => 'text' ),
			)
		);

		$fields = Kivun_Custom_Forms::fields( $id );
		$this->assertCount( 1, $fields );
		$this->assertSame( 'שאלה אמיתית', $fields[0]['label'] );
	}

	/**
	 * A type nobody offers becomes a plain text box.
	 *
	 * @return void
	 */
	public function test_an_unknown_type_falls_back_to_text(): void {
		$id     = $this->page( array( array( 'label' => 'שאלה', 'type' => 'file_upload' ) ) );
		$fields = Kivun_Custom_Forms::fields( $id );

		$this->assertSame( 'text', $fields[0]['type'] );
	}

	/**
	 * A list to choose from with nothing in it becomes a plain question.
	 *
	 * A select with no options is a control that cannot be answered, and a
	 * required one would make the form impossible to submit.
	 *
	 * @return void
	 */
	public function test_a_choice_with_no_options_is_not_left_dead(): void {
		$id     = $this->page( array( array( 'label' => 'בחרו', 'type' => 'select', 'options' => array( '', '  ' ) ) ) );
		$fields = Kivun_Custom_Forms::fields( $id );

		$this->assertSame( 'text', $fields[0]['type'] );
	}

	/**
	 * Two questions never share a key.
	 *
	 * The key is what the answer arrives under; a shared one means the
	 * second question silently overwrites the first.
	 *
	 * @return void
	 */
	public function test_keys_are_unique(): void {
		$id = $this->page(
			array(
				array( 'key' => 'same', 'label' => 'ראשונה', 'type' => 'text' ),
				array( 'key' => 'same', 'label' => 'שנייה', 'type' => 'text' ),
			)
		);

		$fields = Kivun_Custom_Forms::fields( $id );
		$this->assertCount( 2, $fields );
		$this->assertNotSame( $fields[0]['key'], $fields[1]['key'] );
	}

	/**
	 * A form has a ceiling.
	 *
	 * @return void
	 */
	public function test_a_form_cannot_grow_without_end(): void {
		$many = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$many[] = array( 'label' => 'שאלה ' . $i, 'type' => 'text' );
		}

		$this->assertCount( Kivun_Custom_Forms::MAX_FIELDS, Kivun_Custom_Forms::sanitize_fields( $many ) );
	}

	/**
	 * Only real built-ins are kept, and name and phone are not among the
	 * switchable ones.
	 *
	 * A lead with no phone is a row the rota cannot act on, so the choice is
	 * never offered.
	 *
	 * @return void
	 */
	public function test_the_built_ins_are_a_closed_set(): void {
		$id = $this->page( array(), array( 'city', 'phone', 'not_a_field' ) );

		$this->assertSame( array( 'city' ), Kivun_Custom_Forms::builtins( $id ) );
		$this->assertArrayNotHasKey( 'phone', Kivun_Custom_Forms::optional_builtins() );
		$this->assertArrayNotHasKey( 'name', Kivun_Custom_Forms::optional_builtins() );
	}

	/**
	 * A page nobody has edited shows the form it always showed.
	 *
	 * @return void
	 */
	public function test_an_untouched_page_keeps_the_fixed_form(): void {
		$this->assertFalse( Kivun_Custom_Forms::enabled( 404 ) );
		$this->assertSame( array(), Kivun_Custom_Forms::fields( 404 ) );
		$this->assertSame( array_keys( Kivun_Custom_Forms::optional_builtins() ), Kivun_Custom_Forms::builtins( 404 ) );
	}

	/**
	 * A definition mangled in the database does not take the page down.
	 *
	 * @return void
	 */
	public function test_a_broken_definition_reads_as_no_form(): void {
		Kivun_Test_State::$meta[9][ Kivun_Custom_Forms::FIELDS ] = 'not json at all';

		$this->assertSame( array(), Kivun_Custom_Forms::fields( 9 ) );
	}

	/**
	 * Hebrew survives the trip into the database.
	 *
	 * update_post_meta() unslashes what it is given and JSON is made of
	 * backslashes, so a Hebrew label encoded as \u05de arrives as literal
	 * u05de unless the whole definition is slashed first. Found in review —
	 * and missed here until the stubs were made to unslash the way WordPress
	 * does.
	 *
	 * @return void
	 */
	public function test_hebrew_survives_being_stored(): void {
		$id = $this->page( array( array( 'label' => 'באיזה מסלול אתם מתעניינים?', 'type' => 'text' ) ) );

		$fields = Kivun_Custom_Forms::fields( $id );
		$this->assertCount( 1, $fields, 'the definition came back unreadable' );
		$this->assertSame( 'באיזה מסלול אתם מתעניינים?', $fields[0]['label'] );
	}

	/**
	 * And so does a label with a quotation mark in it.
	 *
	 * This is the worse half of the same bug: an unescaped quote does not
	 * merely garble the label, it makes the whole definition unparseable —
	 * so the form empties itself and its required questions quietly stop
	 * being required.
	 *
	 * @return void
	 */
	public function test_a_quote_in_a_label_does_not_empty_the_form(): void {
		$id = $this->page(
			array(
				array( 'label' => 'מה דעתך על "המסלול המזורז"?', 'type' => 'text', 'required' => true ),
				array( 'label' => 'שאלה שנייה', 'type' => 'text' ),
			)
		);

		$fields = Kivun_Custom_Forms::fields( $id );
		$this->assertCount( 2, $fields, 'one bad quote took the whole form with it' );
		$this->assertSame( 'מה דעתך על "המסלול המזורז"?', $fields[0]['label'] );

		// And the question is still required, which is the part that would
		// have gone unnoticed.
		$this->assertInstanceOf( WP_Error::class, Kivun_Custom_Forms::collect( $id, array() ) );
	}

	/**
	 * A backslash in a label is not multiplied each time it is saved.
	 *
	 * Slashing on the way in is right only if nothing slashes twice.
	 *
	 * @return void
	 */
	public function test_saving_twice_does_not_breed_backslashes(): void {
		$label = 'מסלול א\\ב';

		$id    = $this->page( array( array( 'label' => $label, 'type' => 'text' ) ) );
		$once  = Kivun_Custom_Forms::fields( $id );

		Kivun_Custom_Forms::save( $id, $once, array() );
		$twice = Kivun_Custom_Forms::fields( $id );

		$this->assertSame( $once[0]['label'], $twice[0]['label'] );
	}

	// ── Answering ────────────────────────────────────────────────────────────

	/**
	 * An answer is kept with the question it answers.
	 *
	 * @return void
	 */
	public function test_an_answer_is_kept_with_its_question(): void {
		$id     = $this->page( array( array( 'key' => 'track', 'label' => 'מסלול', 'type' => 'select', 'options' => array( 'חשמל' ) ) ) );
		$answers = Kivun_Custom_Forms::collect( $id, array( 'track' => 'חשמל' ) );

		$this->assertSame( array( array( 'key' => 'track', 'label' => 'מסלול', 'value' => 'חשמל' ) ), $answers );
	}

	/**
	 * A required question is enforced on the server.
	 *
	 * `required` in the markup is a convenience; anything posting straight
	 * to the endpoint never saw it.
	 *
	 * @return void
	 */
	public function test_a_required_question_is_enforced_here(): void {
		$id     = $this->page( array( array( 'key' => 'track', 'label' => 'מסלול', 'type' => 'text', 'required' => true ) ) );
		$result = Kivun_Custom_Forms::collect( $id, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'מסלול', $result->get_error_message(), 'the refusal does not say which question' );
	}

	/**
	 * An answer the question never offered is not stored.
	 *
	 * Without this the value would be whatever was typed into the request,
	 * and a report grouping by it grows a row nobody can explain.
	 *
	 * @return void
	 */
	public function test_an_answer_that_was_never_offered_is_refused(): void {
		$id      = $this->page( array( array( 'key' => 'track', 'label' => 'מסלול', 'type' => 'select', 'options' => array( 'חשמל' ) ) ) );
		$answers = Kivun_Custom_Forms::collect( $id, array( 'track' => 'משהו אחר לגמרי' ) );

		$this->assertSame( array(), $answers );
	}

	/**
	 * A multiple choice keeps every box that was ticked.
	 *
	 * @return void
	 */
	public function test_a_multiple_choice_keeps_every_pick(): void {
		$id      = $this->page( array( array( 'key' => 'i', 'label' => 'מעניין אותי', 'type' => 'checkbox', 'options' => array( 'א', 'ב', 'ג' ) ) ) );
		$answers = Kivun_Custom_Forms::collect( $id, array( 'i' => array( 'א', 'ג', 'מומצא' ) ) );

		$this->assertSame( 'א, ג', $answers[0]['value'], 'an option nobody offered was kept' );
	}

	/**
	 * A question nobody answered leaves no empty line behind.
	 *
	 * @return void
	 */
	public function test_an_unanswered_question_is_simply_absent(): void {
		$id      = $this->page( array( array( 'key' => 'note', 'label' => 'הערה', 'type' => 'text' ) ) );
		$answers = Kivun_Custom_Forms::collect( $id, array( 'note' => '   ' ) );

		$this->assertSame( array(), $answers );
	}

	/**
	 * The answers read back the way the leads table already reads a message.
	 *
	 * @return void
	 */
	public function test_the_answers_read_as_the_lead_s_own_lines(): void {
		$lines = Kivun_Custom_Forms::lines(
			array(
				array( 'label' => 'מסלול', 'value' => 'חשמל' ),
				array( 'label' => 'ניסיון', 'value' => 'עד שנתיים' ),
			),
			'מעדיפה אחה״צ'
		);

		$this->assertSame( "מעדיפה אחה״צ\nמסלול: חשמל\nניסיון: עד שנתיים", $lines );
	}
}
