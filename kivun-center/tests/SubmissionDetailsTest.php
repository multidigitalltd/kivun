<?php
/**
 * Visitor answers are read-only, escaped, and complete in both lead lists.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/functions.php';

/**
 * Tests for the shared saved-submission disclosure.
 */
final class SubmissionDetailsTest extends TestCase {

	/**
	 * Stored text can contain HTML from old records without becoming executable.
	 *
	 * @return void
	 */
	public function test_stored_html_is_displayed_as_text(): void {
		$message = 'תחום: <img src=x onerror="alert(1)"> & <script>alert(2)</script>';
		$html    = kivun_submission_details( $message );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&lt;img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertSame( 'פרטי הפנייה' . $message, html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Preserve complete legacy messages, answer labels, and multiple selections.
	 *
	 * @return void
	 */
	public function test_complete_multiline_answers_are_read_only_and_collapsed(): void {
		$message = "הערות הלקוח: נא לחזור אליי\r\nתחום: מחשבים, הוראה\n" . str_repeat( 'תשובה ארוכה ', 100 );
		$html    = kivun_submission_details( $message );

		$this->assertStringContainsString( '<details class="kivun-submission-details">', $html );
		$this->assertStringNotContainsString( ' open', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertSame( 2, substr_count( $html, '<br>' ) );
		$this->assertSame( 'פרטי הפנייה' . $message, html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Old records without a message have the same compact empty state.
	 *
	 * @return void
	 */
	public function test_empty_messages_have_no_disclosure(): void {
		$empty = kivun_submission_details( '' );

		$this->assertSame( $empty, kivun_submission_details( " \r\n\t" ) );
		$this->assertStringNotContainsString( '<details', $empty );
		$this->assertStringContainsString( 'לא נמסרו פרטים נוספים.', $empty );
		$this->assertStringContainsString( '>—</span>', $empty );
	}

	/**
	 * A numeric zero is a submitted answer, not an empty message.
	 *
	 * @return void
	 */
	public function test_zero_is_a_visible_answer(): void {
		$html = kivun_submission_details( '0' );

		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( '>0</div>', $html );
	}
}
