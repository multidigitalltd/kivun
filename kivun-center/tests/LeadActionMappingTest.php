<?php
/**
 * Resolving a form's standard fields when nobody renamed them.
 *
 * Elementor gives every field a generated id — `field_1112454` — and keeps it
 * unless somebody renames it. The lead action's defaults are readable names
 * (`phone`, `email`), so on a form nobody mapped they match nothing, the phone
 * reads empty, and every submission failed on "נא למלא שם וטלפון": a setting,
 * blamed on the visitor, who then sends the form again.
 *
 * The field ids here are the real ones from the jobs board form.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the type-based fallback in Kivun_Form_Details.
 */
final class LeadActionMappingTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * The jobs board form exactly as it submits today: only "name" renamed,
	 * two email fields (one of them a duplicate nobody removed), the rest on
	 * their generated ids.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function board_form(): array {
		return array(
			'name'           => array( 'title' => 'שם מלא', 'type' => 'text', 'value' => 'שרה לוי' ),
			'field_1112454'  => array( 'title' => 'טלפון', 'type' => 'tel', 'value' => '050-1234567' ),
			'field_c037a49'  => array( 'title' => 'אימייל', 'type' => 'email', 'value' => 'sara@example.test' ),
			'field_fc24ac3'  => array( 'title' => 'אימייל', 'type' => 'email', 'value' => 'sara@example.test' ),
			'field_ceb2f01'  => array( 'title' => 'מהו התחום שאתם מתעניינים בו?', 'type' => 'text', 'value' => 'הנהלת חשבונות' ),
			'field_ffe3950'  => array( 'title' => 'מגדר', 'type' => 'radio', 'value' => 'אשה' ),
			'field_6ff8c9b'  => array( 'title' => 'מאשר/ת לקבל פרסומים למייל', 'type' => 'acceptance', 'value' => 'on' ),
			'field_77eea56'  => array( 'title' => 'תנאי שימוש', 'type' => 'acceptance', 'value' => 'on' ),
		);
	}

	/**
	 * The reported failure: the action looks for "phone", the form has no such
	 * field, and the phone comes back empty.
	 *
	 * @return void
	 */
	public function test_the_default_mapping_finds_no_phone(): void {
		$this->assertSame( '', Kivun_Form_Details::mapped_value( $this->board_form(), 'phone' ) );
	}

	/**
	 * And the form's own type says which field it is.
	 *
	 * @return void
	 */
	public function test_the_phone_is_found_by_its_type(): void {
		$found = Kivun_Form_Details::first_of_type( $this->board_form(), 'tel' );

		$this->assertSame( 'field_1112454', $found );
		$this->assertSame( '050-1234567', Kivun_Form_Details::mapped_value( $this->board_form(), $found ) );
	}

	/**
	 * Two email fields on the page — the duplicate nobody has removed yet —
	 * resolve to one address rather than to nothing.
	 *
	 * @return void
	 */
	public function test_a_duplicated_email_field_still_resolves(): void {
		$found = Kivun_Form_Details::first_of_type( $this->board_form(), 'email' );

		$this->assertSame( 'field_c037a49', $found );
		$this->assertSame( 'sara@example.test', Kivun_Form_Details::mapped_value( $this->board_form(), $found ) );
	}

	/**
	 * A field of that type left blank is not a usable fallback — falling back
	 * to an empty answer would be the same failure wearing a different hat.
	 *
	 * @return void
	 */
	public function test_an_empty_field_is_not_a_fallback(): void {
		$form = $this->board_form();
		$form['field_1112454']['value'] = '';

		$this->assertSame( '', Kivun_Form_Details::first_of_type( $form, 'tel' ) );
	}

	/**
	 * A form with no field of that type at all says so, rather than guessing.
	 *
	 * @return void
	 */
	public function test_no_field_of_that_type_finds_nothing(): void {
		$this->assertSame( '', Kivun_Form_Details::first_of_type( $this->board_form(), 'number' ) );
	}

	/**
	 * The fallback never overrules a mapping that was configured and found.
	 *
	 * @return void
	 */
	public function test_a_configured_id_is_not_second_guessed(): void {
		$form = $this->board_form();
		$form['my_phone'] = array( 'title' => 'טלפון נוסף', 'type' => 'tel', 'value' => '02-6456222' );

		// Configured and present: used as-is, whatever the type scan would say.
		$this->assertSame( '02-6456222', Kivun_Form_Details::mapped_value( $form, 'my_phone' ) );
	}

	/**
	 * Hidden fields are still not answers, so a hidden one cannot be picked up
	 * by the type scan either.
	 *
	 * @return void
	 */
	public function test_a_hidden_field_is_not_picked_up(): void {
		$form = array(
			'tracking' => array( 'title' => '', 'type' => 'hidden', 'value' => '972500000000' ),
		);

		$this->assertSame( '', Kivun_Form_Details::first_of_type( $form, 'hidden' ) );
	}
}
