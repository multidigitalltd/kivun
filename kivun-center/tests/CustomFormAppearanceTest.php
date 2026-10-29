<?php
/**
 * How a page's own form presents itself.
 *
 * This form is not a panel on a page of ours. It is dropped into a design
 * somebody else made, under a heading they wrote, so what it must not do is
 * announce itself: no title of its own, none of the plugin's chrome, and
 * nothing imposed that stops the page's styling reaching the fields.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The markup the visitor's browser is handed.
 */
final class CustomFormAppearanceTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Render the custom form for a page carrying these questions.
	 *
	 * @param array $fields   The questions.
	 * @param array $builtins The built-ins kept.
	 * @return string The markup.
	 */
	private function render( array $fields, array $builtins = array( 'email', 'city' ) ): string {
		Kivun_Test_State::$posts[9] = array( 'post_type' => 'kivun_workshop' );
		Kivun_Custom_Forms::save( 9, $fields, $builtins );

		$post_id   = 9;
		$post_type = 'kivun_workshop';

		ob_start();
		include KIVUN_DIR . 'templates/landing/custom-form.php';
		return (string) ob_get_clean();
	}

	/**
	 * The form brings no heading of its own.
	 *
	 * The design it sits in already has one. A second title under the
	 * client's own is what made it read as bolted on rather than part of the
	 * page.
	 *
	 * @return void
	 */
	public function test_the_form_has_no_heading_of_its_own(): void {
		$html = $this->render( array( array( 'label' => 'שאלה', 'type' => 'text' ) ) );

		$this->assertStringNotContainsString( 'kivun-interest__title', $html );
		$this->assertStringNotContainsString( 'kivun-interest__sub', $html );
		$this->assertStringNotContainsString( 'מתעניין/ת? נחזור אליך', $html );
	}

	/**
	 * And none of the plugin's panel.
	 *
	 * The modifier is what the stylesheet hangs the whole release on — the
	 * frame, the fill, the padding and the font family that would otherwise
	 * be inherited by every field below it.
	 *
	 * @return void
	 */
	public function test_the_form_asks_for_the_page_s_own_styling(): void {
		$html = $this->render( array( array( 'label' => 'שאלה', 'type' => 'text' ) ) );

		$this->assertStringContainsString( 'kivun-interest--plain', $html );
	}

	/**
	 * The fixed form keeps its heading, since nothing changed for it.
	 *
	 * It is still shown on the plugin's own pages, where there is no design
	 * around it supplying one.
	 *
	 * @return void
	 */
	public function test_the_fixed_form_is_untouched(): void {
		$post_id   = 9;
		$post_type = 'kivun_course';

		ob_start();
		include KIVUN_DIR . 'templates/courses/interest-form.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'מתעניין/ת? נחזור אליך', $html );
		$this->assertStringNotContainsString( 'kivun-interest--plain', $html );
	}

	/**
	 * A number question can say what it refuses.
	 *
	 * A number box drops a letter without a word — the key is pressed, no
	 * character appears, and nothing says why. The script needs somewhere to
	 * put the explanation, and the field needs to ask a phone for a numeric
	 * keypad.
	 *
	 * @return void
	 */
	public function test_a_number_question_can_explain_itself(): void {
		$html = $this->render( array( array( 'label' => 'כמה שנים?', 'type' => 'number' ) ) );

		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'inputmode="numeric"', $html );
		$this->assertStringContainsString( 'kivun-field-note', $html );
	}

	/**
	 * And a question that is not a number has no such note to fill.
	 *
	 * An empty live region on every text field would be read out by a screen
	 * reader for no reason.
	 *
	 * @return void
	 */
	public function test_only_a_number_question_carries_the_note(): void {
		$html = $this->render( array( array( 'label' => 'שם החברה', 'type' => 'text' ) ) );

		$this->assertStringNotContainsString( 'kivun-field-note', $html );
	}

	/**
	 * The stylesheet releases the font at the wrapper, not only the fields.
	 *
	 * `.kivun-interest` sets the plugin's own font family, so `font: inherit`
	 * on the fields inherits ours rather than the page's and the form keeps
	 * looking foreign while every other rule says it should not. Found in the
	 * browser, which is the only place it was visible.
	 *
	 * @return void
	 */
	public function test_the_font_chain_is_released_at_the_wrapper(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$at = strpos( $css, '.kivun-interest--plain {' );
		$this->assertNotFalse( $at, 'the plain wrapper rule moved' );
		$this->assertStringContainsString(
			'font-family: inherit',
			substr( $css, $at, 600 ),
			'the fields inherit the plugin font instead of the page font'
		);
	}
}
