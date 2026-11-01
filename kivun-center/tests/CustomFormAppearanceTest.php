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
	 * Render the fixed form, as a page of the plugin's own shows it.
	 *
	 * @return string The markup.
	 */
	private function fixed(): string {
		$post_id   = 9;
		$post_type = 'kivun_course';

		ob_start();
		include KIVUN_DIR . 'templates/courses/interest-form.php';
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
	 * A tickbox still shows a keyboard user where they are.
	 *
	 * A text field shows focus by thickening its underline, which a tickbox
	 * has not got — and three separate rules had each taken away a different
	 * part of what would otherwise have shown it: the shared rule drops the
	 * browser's own outline, the plain rule drops the replacement shadow, and
	 * the tickbox rule drops the border. Nothing was left to follow. Found in
	 * review and reproduced in a browser before it was believed.
	 *
	 * @return void
	 */
	public function test_a_tickbox_keeps_a_focus_indicator(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$at = strpos( $css, '.kivun-interest--plain .kivun-form-row input[type="checkbox"]:focus-visible' );
		$this->assertNotFalse( $at, 'choice inputs in the plain form have no focus indicator' );
		$this->assertStringContainsString(
			'outline:',
			substr( $css, $at, 400 ),
			'the focus rule exists but sets no outline'
		);
	}

	/**
	 * Gender is two buttons, and only two.
	 *
	 * Held for both lead forms at once rather than one each: they are the
	 * same question, and the rota that splits candidates by it reads whatever
	 * either of them sends. A third option nobody is offered on one form and
	 * is on the other is a column of answers that cannot be compared.
	 *
	 * @return void
	 */
	public function test_gender_is_two_buttons_and_only_two(): void {
		$forms = array(
			'landing/custom-form.php'   => $this->render( array(), array( 'gender' ) ),
			'courses/interest-form.php' => $this->fixed(),
		);

		foreach ( $forms as $name => $html ) {
			$this->assertSame(
				2,
				substr_count( $html, 'type="radio" name="gender"' ),
				$name . ' does not offer gender as exactly two buttons'
			);
			$this->assertStringNotContainsString( '<option value="אחר"', $html, $name . ' still offers a third' );
			$this->assertStringNotContainsString( 'select name="gender"', $html, $name . ' still uses a menu' );
		}
	}

	/**
	 * Both values still reach a rota group.
	 *
	 * Changing what the form sends is only safe while the rota recognises it;
	 * a value it cannot place leaves the lead with no coordinator, and
	 * nothing says so. ("אחר" was exactly that, which is part of why it goes.)
	 *
	 * @return void
	 */
	public function test_both_values_still_reach_a_rota_group(): void {
		$this->assertSame( 'male', Kivun_Coordinators::group_of( 'זכר' ) );
		$this->assertSame( 'female', Kivun_Coordinators::group_of( 'נקבה' ) );
	}

	/**
	 * The builder's preview offers what the form offers.
	 *
	 * The preview is the whole reason the builder is usable, and a preview
	 * that shows a menu of three where the page draws two buttons is worse
	 * than none: it is confidently wrong.
	 *
	 * @return void
	 */
	public function test_the_preview_offers_what_the_form_offers(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$at = strpos( $js, "key: 'gender'" );
		$this->assertNotFalse( $at, 'the preview no longer knows about gender' );
		$line = substr( $js, $at, 120 );

		$this->assertStringContainsString( "type: 'radio'", $line, 'the preview still draws a menu' );
		$this->assertStringNotContainsString( 'אחר', $line, 'the preview still offers a third option' );
	}

	/**
	 * The submit button keeps its words out of its own fill.
	 *
	 * The pill is filled with `currentColor` so it takes the page's own
	 * colour. That only works while the label sits in a separate element:
	 * setting a colour on the button itself resolves the fill to the same
	 * value and the words vanish into it. Found in the browser the first
	 * time, which is the only place it shows.
	 *
	 * @return void
	 */
	public function test_the_button_keeps_its_words_out_of_its_fill(): void {
		foreach ( array( 'landing/custom-form.php', 'courses/interest-form.php' ) as $template ) {
			$src = (string) file_get_contents( KIVUN_DIR . 'templates/' . $template );
			$this->assertStringContainsString(
				'kivun-lead-submit__label',
				$src,
				$template . ' puts the label straight in the button, where the fill would swallow it'
			);
		}

		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString( '.kivun-interest--plain .kivun-lead-submit__label', $css );
	}

	/**
	 * The form is set in the site's own font, named rather than inherited.
	 *
	 * It was released to `inherit` while the aim was to take whatever font
	 * the page around it used. The site's font is ploni, and the plugin's
	 * own `--kivun-font` already starts there — so the wrapper keeps that
	 * chain and the fields inherit it, instead of a second rule saying the
	 * same thing from somewhere else.
	 *
	 * @return void
	 */
	public function test_the_form_is_set_in_the_site_s_font(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		// The chain itself begins at ploni.
		$this->assertStringContainsString( "'ploni'", $css, 'the font chain no longer names the site font' );

		// And the plain wrapper does not take itself out of it.
		$at = strpos( $css, '.kivun-interest--plain {' );
		$this->assertNotFalse( $at, 'the plain wrapper rule moved' );
		$this->assertStringNotContainsString(
			'font-family: inherit',
			substr( $css, $at, 600 ),
			'the form releases the font again and will not be set in ploni'
		);
	}
}
