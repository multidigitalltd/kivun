<?php
/**
 * The letters the centre sends.
 *
 * Mail clients have no stylesheet and no reason to think a site is Hebrew, so
 * everything that makes a letter read right to left has to be written into the
 * markup. That is easy to break and impossible to notice from the code, since
 * the letter only looks wrong in somebody else's inbox.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Mailer.
 */
final class MailerTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Clear filters between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $kivun_test_filters;
		$kivun_test_filters = array();
	}

	/**
	 * A whole document, declared Hebrew and right to left. Gmail and Outlook
	 * rewrite what they are handed, so a wrapping div's styles are not enough.
	 *
	 * @return void
	 */
	public function test_the_letter_is_a_right_to_left_document(): void {
		$html = Kivun_Mailer::wrap( '<p>שלום</p>', 'כותרת' );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '<html dir="rtl"', $html );
		$this->assertStringContainsString( '<body dir="rtl"', $html );
	}

	/**
	 * Every table and cell repeats the direction, and the old align attribute
	 * beside it, for the clients that drop one or the other.
	 *
	 * @return void
	 */
	public function test_direction_is_repeated_on_the_tables(): void {
		$html = Kivun_Mailer::wrap( '<p>שלום</p>' );

		$this->assertGreaterThanOrEqual( 3, substr_count( $html, 'dir="rtl"' ) );
		$this->assertStringContainsString( 'align="right"', $html );
		$this->assertStringContainsString( 'text-align:right', $html );
	}

	/**
	 * The body survives wrapping.
	 *
	 * @return void
	 */
	public function test_the_message_is_in_the_letter(): void {
		$this->assertStringContainsString( 'קורות החיים שלך התקבלו', Kivun_Mailer::wrap( '<p>קורות החיים שלך התקבלו</p>' ) );
	}

	/**
	 * The site's own logo, so the letters follow the site rather than a copy of
	 * it that goes stale the day the logo changes.
	 *
	 * @return void
	 */
	public function test_the_site_logo_is_used_when_there_is_one(): void {
		Kivun_Test_State::$options['theme_mod_custom_logo'] = 12;
		Kivun_Test_State::$options['attachment_12']         = 'https://example.test/logo.png';

		$html = Kivun_Mailer::wrap( '<p>שלום</p>' );

		$this->assertStringContainsString( 'https://example.test/logo.png', $html );
		$this->assertStringContainsString( 'alt="מרכז כיוון"', $html, 'blocked images should still name the centre' );
	}

	/**
	 * Falling back to the site icon before giving up on a picture.
	 *
	 * @return void
	 */
	public function test_the_site_icon_is_the_next_choice(): void {
		Kivun_Test_State::$options['site_icon'] = 'https://example.test/icon.png';

		$this->assertStringContainsString( 'https://example.test/icon.png', Kivun_Mailer::wrap( '<p>שלום</p>' ) );
	}

	/**
	 * With no logo at all the centre's name is still at the head of the letter.
	 *
	 * @return void
	 */
	public function test_without_a_logo_the_name_is_shown_instead(): void {
		$html = Kivun_Mailer::wrap( '<p>שלום</p>' );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'מרכז כיוון', $html );
	}

	/**
	 * The brand colour is filterable, for a site whose brand is not this pink.
	 *
	 * @return void
	 */
	public function test_the_accent_colour_can_be_changed(): void {
		add_filter( 'kivun_email_accent', static fn() => '#123456' );

		$this->assertStringContainsString( '#123456', Kivun_Mailer::wrap( '<p>שלום</p>' ) );
	}

	// ── The candidate's confirmation ──────────────────────────────────────────

	/**
	 * The wording the client asked for, in order.
	 *
	 * @return void
	 */
	public function test_the_confirmation_says_what_was_asked_for(): void {
		$lines = Kivun_Mailer::confirmation_lines( 'שרה', 'מזכיר/ה רפואי/ת' );

		$this->assertSame( 'שלום שרה,', $lines[0] );
		$this->assertStringContainsString( 'קורות החיים שלך למשרה מזכיר/ה רפואי/ת התקבלו בהצלחה', $lines[1] );
		$this->assertStringContainsString( 'נפנה אליך ישירות אם המועמדות תימצא מתאימה', $lines[2] );
	}

	/**
	 * The sign-off names the person who actually has the application, so a
	 * candidate with a question knows who to ask.
	 *
	 * @return void
	 */
	public function test_the_coordinator_signs_the_letter(): void {
		$lines = Kivun_Mailer::confirmation_lines(
			'שרה',
			'משרה',
			array(
				'name'  => 'דסי',
				'phone' => '02-6456222',
				'email' => 'dasi@kivun.test',
			)
		);

		$sign_off = end( $lines );

		$this->assertStringContainsString( 'בברכה,', $sign_off );
		$this->assertStringContainsString( 'דסי', $sign_off );
		$this->assertStringContainsString( '02-6456222', $sign_off );
		$this->assertStringContainsString( 'dasi@kivun.test', $sign_off );
	}

	/**
	 * With nobody on the rota there is no such person, and signing in a name we
	 * do not have would be worse than signing as the centre.
	 *
	 * @return void
	 */
	public function test_without_a_coordinator_the_centre_signs(): void {
		$lines = Kivun_Mailer::confirmation_lines( 'שרה', 'משרה' );

		$this->assertStringContainsString( 'מרכז כיוון', end( $lines ) );
	}

	/**
	 * A coordinator with no phone on file does not leave a blank line.
	 *
	 * @return void
	 */
	public function test_a_missing_detail_leaves_no_empty_line(): void {
		$lines = Kivun_Mailer::confirmation_lines( 'שרה', 'משרה', array( 'name' => 'דסי' ) );

		$this->assertSame( "בברכה,\nדסי", end( $lines ) );
	}

	/**
	 * The letter and the message on screen are written once, so they cannot
	 * come to say different things.
	 *
	 * @return void
	 */
	public function test_the_letter_carries_every_line_of_the_message(): void {
		$coordinator = array(
			'name'  => 'דסי',
			'phone' => '02-6456222',
		);

		$html = Kivun_Mailer::confirmation_html( 'שרה', 'מזכירה', $coordinator );

		foreach ( Kivun_Mailer::confirmation_lines( 'שרה', 'מזכירה', $coordinator ) as $line ) {
			foreach ( explode( "\n", $line ) as $part ) {
				$this->assertStringContainsString( esc_html( $part ), $html );
			}
		}
	}

	/**
	 * A candidate's name is somebody else's input and is escaped.
	 *
	 * @return void
	 */
	public function test_a_name_cannot_carry_markup_into_the_letter(): void {
		$html = Kivun_Mailer::confirmation_html( '<script>alert(1)</script>', 'משרה' );

		$this->assertStringNotContainsString( '<script>', $html );
	}

	// ── The button ────────────────────────────────────────────────────────────

	/**
	 * Built as a table: Outlook draws no padding or background on a link, so a
	 * styled anchor arrives as bare blue text.
	 *
	 * @return void
	 */
	public function test_a_button_is_a_table_not_a_styled_link(): void {
		$button = Kivun_Mailer::button( 'https://example.test/leads/', 'מעבר לרשימת הלידים' );

		$this->assertStringContainsString( '<table', $button );
		$this->assertStringContainsString( 'https://example.test/leads/', $button );
		$this->assertStringContainsString( 'מעבר לרשימת הלידים', $button );
	}

	// ── Sending ───────────────────────────────────────────────────────────────

	/**
	 * Every letter goes out as HTML, or the markup arrives as text.
	 *
	 * @return void
	 */
	public function test_letters_are_sent_as_html(): void {
		Kivun_Mailer::send( 'someone@example.test', 'נושא', '<p>גוף</p>' );

		$sent = end( Kivun_Test_State::$mail );

		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $sent['headers'] );
		$this->assertStringContainsString( '<html dir="rtl"', $sent['message'] );
	}
}
