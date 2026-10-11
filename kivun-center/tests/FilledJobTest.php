<?php
/**
 * A job that has been filled.
 *
 * The centre places people, not one job. A role being taken used to close the
 * form, which threw away exactly the candidates worth calling about the next
 * one like it — so a filled job keeps taking applications, and says so. Three
 * things have to agree about that: the form, the letter back to the candidate,
 * and the notification on the coordinator's desk. Each is written in a
 * different file, and a disagreement between them is invisible from the code:
 * it shows up as a letter promising to be in touch about a job that is gone.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-jobs.php';

/**
 * Tests for the filled-job behaviour.
 */
final class FilledJobTest extends TestCase {

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
	 * Mark a job as filled.
	 *
	 * @param int $job_id The job.
	 * @return void
	 */
	private function fill( int $job_id ): void {
		update_post_meta( $job_id, Kivun_Jobs::FILLED_META, '2026-10-01 09:00:00' );
	}

	/**
	 * Render the application form for a job.
	 *
	 * @param int $job_id The job.
	 * @return string The markup.
	 */
	private function render_form( int $job_id ): string {
		ob_start();
		require KIVUN_DIR . 'templates/jobs/apply-form.php';
		return (string) ob_get_clean();
	}

	// ── Whether it is filled at all ───────────────────────────────────────────

	/**
	 * The absence of the meta is what makes a job open; nothing else is read.
	 *
	 * @return void
	 */
	public function test_a_job_is_open_until_it_is_marked(): void {
		$this->assertFalse( Kivun_Jobs::is_filled( 101 ) );

		$this->fill( 101 );

		$this->assertTrue( Kivun_Jobs::is_filled( 101 ) );
	}

	/**
	 * Filled jobs stay on the board — the client asked for them to be marked
	 * rather than removed, so the board keeps saying what the work is.
	 *
	 * @return void
	 */
	public function test_the_board_hides_nothing_by_default(): void {
		$this->assertSame( array(), Kivun_Jobs::board_meta_query() );
	}

	/**
	 * A site that would rather they expired can still say so.
	 *
	 * @return void
	 */
	public function test_an_expiry_can_be_asked_for(): void {
		add_filter( 'kivun_filled_job_grace_days', static fn() => 14 );

		$query = Kivun_Jobs::board_meta_query();

		$this->assertNotSame( array(), $query );
		$this->assertSame( 'OR', $query['relation'] );
	}

	// ── The form ──────────────────────────────────────────────────────────────

	/**
	 * The point of the change: a filled job still accepts a CV. The form used
	 * to be replaced by a sentence turning the candidate away.
	 *
	 * @return void
	 */
	public function test_a_filled_job_still_renders_the_form(): void {
		$this->fill( 202 );

		$html = $this->render_form( 202 );

		$this->assertStringContainsString( '<form class="kivun-apply-form"', $html );
		$this->assertStringContainsString( 'name="applicant_name"', $html );
		$this->assertStringContainsString( 'name="applicant_phone"', $html );
		$this->assertStringContainsString( 'שלח מועמדות', $html );
	}

	/**
	 * And says plainly that it is filled, with the reason to apply anyway —
	 * otherwise a form on a taken job is a trap rather than an invitation.
	 *
	 * @return void
	 */
	public function test_a_filled_job_says_so_above_the_form(): void {
		$this->fill( 202 );

		$html = $this->render_form( 202 );

		$this->assertStringContainsString( 'kivun-apply-filled', $html );
		$this->assertStringContainsString( 'המשרה אוישה', $html );
		$this->assertStringContainsString( 'משרה דומה', $html );
	}

	/**
	 * An open job carries no such notice.
	 *
	 * @return void
	 */
	public function test_an_open_job_carries_no_notice(): void {
		$html = $this->render_form( 303 );

		$this->assertStringContainsString( '<form class="kivun-apply-form"', $html );
		$this->assertStringNotContainsString( 'kivun-apply-filled', $html );
		$this->assertStringNotContainsString( 'אוישה', $html );
	}

	// ── The letter back to the candidate ──────────────────────────────────────

	/**
	 * "We will be in touch if the application is found suitable" reads as a
	 * maybe about a job that has already gone. The filled letter says what is
	 * actually true: the CV is kept, for the next role like it.
	 *
	 * @return void
	 */
	public function test_the_letter_tells_a_candidate_the_job_was_taken(): void {
		$lines = Kivun_Mailer::confirmation_lines( 'שרה', 'מזכירה', array(), true );

		$this->assertStringContainsString( 'המשרה הזו כבר אוישה', $lines[2] );
		$this->assertStringContainsString( 'משרה דומה', $lines[2] );
		$this->assertStringNotContainsString( 'תימצא מתאימה', $lines[2] );
	}

	/**
	 * An open job's letter is the one it always was.
	 *
	 * @return void
	 */
	public function test_an_open_job_letter_is_unchanged(): void {
		$lines = Kivun_Mailer::confirmation_lines( 'שרה', 'מזכירה' );

		$this->assertStringContainsString( 'נפנה אליך ישירות אם המועמדות תימצא מתאימה', $lines[2] );
		$this->assertStringNotContainsString( 'אוישה', $lines[2] );
	}

	/**
	 * Either way the sign-off is still last, so the coordinator's name has not
	 * been pushed into the middle of the letter by the extra line.
	 *
	 * @return void
	 */
	public function test_the_coordinator_still_signs_a_filled_job_letter(): void {
		$lines = Kivun_Mailer::confirmation_lines( 'שרה', 'מזכירה', array( 'name' => 'דסי' ), true );

		$this->assertSame( "בברכה,\nדסי", end( $lines ) );
		$this->assertCount( 4, $lines );
	}

	/**
	 * The letter carries the filled wording, not just the message on screen.
	 *
	 * @return void
	 */
	public function test_the_filled_wording_reaches_the_sent_letter(): void {
		Kivun_Mailer::send_application_confirmation(
			'sarah@example.test',
			'שרה',
			'מזכירה',
			'',
			true,
			array( 'name' => 'דסי' ),
			true
		);

		$sent = end( Kivun_Test_State::$mail );

		$this->assertSame( 'sarah@example.test', $sent['to'] );
		$this->assertStringContainsString( esc_html( 'המשרה הזו כבר אוישה' ), $sent['message'] );
	}

	// ── The coordinator's notification ────────────────────────────────────────

	/**
	 * Whoever opens the application needs to know which kind it is before they
	 * read it as a candidate for a vacancy that no longer exists.
	 *
	 * @return void
	 */
	public function test_the_notification_says_the_job_was_already_filled(): void {
		Kivun_Mailer::send_application(
			'dasi@kivun.test',
			'מזכירה',
			array(
				'name'    => 'שרה',
				'email'   => 'sarah@example.test',
				'phone'   => '0501234567',
				'message' => '',
				'filled'  => true,
			)
		);

		$body = (string) end( Kivun_Test_State::$mail )['message'];

		$this->assertStringContainsString( 'שכבר אוישה', $body );
		$this->assertStringContainsString( 'נשמרו לקראת משרה דומה', $body );
		$this->assertStringNotContainsString( 'קיבלת מועמדות חדשה', $body );
	}

	/**
	 * An open job's notification opens as it always did.
	 *
	 * @return void
	 */
	public function test_an_open_job_notification_is_unchanged(): void {
		Kivun_Mailer::send_application(
			'dasi@kivun.test',
			'מזכירה',
			array(
				'name'    => 'שרה',
				'email'   => 'sarah@example.test',
				'phone'   => '0501234567',
				'message' => '',
			)
		);

		$body = (string) end( Kivun_Test_State::$mail )['message'];

		$this->assertStringContainsString( 'קיבלת מועמדות חדשה למשרה', $body );
		$this->assertStringNotContainsString( 'אוישה', $body );
	}
}
