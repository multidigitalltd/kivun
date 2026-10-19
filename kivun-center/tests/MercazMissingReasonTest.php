<?php
/**
 * Saying why a job did not go, not only that it did not.
 *
 * A bulk push came back with fifty lines reading "המשרה לא שודרה — חסר:
 * סוג משרה", and from fifty of those there is no way to tell what to do.
 * Either nobody chose a type on the job, or somebody chose one and the
 * centre has no such name in its list. The first is filling a field here;
 * the second is agreeing on a word with them. The message named neither.
 *
 * In the same run a job that did go came back "נשלחה, אך לא נקלטו: 0" —
 * their warning arrived as field => number, and the reader printed the
 * number and dropped the field name, which was the only part worth
 * reading.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-mercaz.php';
require_once KIVUN_DIR . 'includes/class-kivun-mercaz-sync.php';

/**
 * Tests for what a refused or warned push tells the person reading it.
 */
final class MercazMissingReasonTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	// ── Why a field is missing ────────────────────────────────────────────────

	/**
	 * A field nobody filled in says so.
	 *
	 * @return void
	 */
	public function test_a_field_nobody_chose_says_so(): void {
		$post = new WP_Post( 7, 'kivun_job' );

		$lines = Kivun_Mercaz_Sync::missing_detail( $post, array(), array( 'job-type' => 'סוג משרה' ) );

		$this->assertSame( array( 'סוג משרה (לא נבחר במשרה)' ), $lines );
	}

	/**
	 * A field that was filled in, and did not survive the mapping, shows
	 * what it held.
	 *
	 * That value is the thing to go and compare against their list, and
	 * without it the person reading has nothing to compare.
	 *
	 * @return void
	 */
	public function test_a_value_their_list_rejects_is_named(): void {
		$post = new WP_Post( 7, 'kivun_job' );

		Kivun_Test_State::$terms[7]['kivun_job_scope'] = array( 'משרה מלאה' );

		$lines = Kivun_Mercaz_Sync::missing_detail( $post, array(), array( 'job-type' => 'סוג משרה' ) );

		$this->assertSame(
			array( 'סוג משרה ("משרה מלאה" — אין ערך כזה ברשימה של מרכז כיוון)' ),
			$lines
		);
	}

	/**
	 * The settlement reads from its own meta rather than a taxonomy, and
	 * reports the same two ways.
	 *
	 * @return void
	 */
	public function test_the_settlement_reports_both_ways_too(): void {
		$post = new WP_Post( 8, 'kivun_job' );

		$this->assertSame(
			array( 'עיר (לא נבחר במשרה)' ),
			Kivun_Mercaz_Sync::missing_detail( $post, array(), array( 'city' => 'עיר' ) )
		);

		update_post_meta( 8, '_kivun_city', 'מעלה אדומים' );

		$this->assertSame(
			array( 'עיר ("מעלה אדומים" — אין ערך כזה ברשימה של מרכז כיוון)' ),
			Kivun_Mercaz_Sync::missing_detail( $post, array(), array( 'city' => 'עיר' ) )
		);
	}

	/**
	 * A field that is only post content gets no invented reason.
	 *
	 * @return void
	 */
	public function test_plain_content_is_reported_plainly(): void {
		$post = new WP_Post( 9, 'kivun_job' );

		$this->assertSame(
			array( 'תיאור המשרה' ),
			Kivun_Mercaz_Sync::missing_detail( $post, array(), array( 'content' => 'תיאור המשרה' ) )
		);
	}

	/**
	 * What is present is not reported at all.
	 *
	 * @return void
	 */
	public function test_nothing_is_said_about_a_field_that_arrived(): void {
		$post = new WP_Post( 10, 'kivun_job' );
		$body = array( 'job-type' => array( 41 ) );

		$this->assertSame( array(), Kivun_Mercaz_Sync::missing_detail( $post, $body, array( 'job-type' => 'סוג משרה' ) ) );
	}

	// ── Their warnings ────────────────────────────────────────────────────────

	/**
	 * A warning keyed by field name keeps the field name.
	 *
	 * This is the one that shipped: field => 0 printed "0".
	 *
	 * @return void
	 */
	public function test_a_warning_keeps_the_field_name(): void {
		$this->assertSame(
			array( 'job_cat' ),
			Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => array( 'job_cat' => 0 ) ) )
		);
	}

	/**
	 * And keeps the reason when there is one worth reading.
	 *
	 * @return void
	 */
	public function test_a_reason_is_kept_beside_the_name(): void {
		$this->assertSame(
			array( 'job_cat (not in list)', 'city (unknown)' ),
			Kivun_Mercaz_Sync::warnings(
				array(
					'ckvn_warnings' => array(
						'job_cat' => 'not in list',
						'city'    => 'unknown',
					),
				)
			)
		);
	}

	/**
	 * A plain list of field names reads as field names.
	 *
	 * The shape is not promised beyond "empty means everything was kept",
	 * so both are handled.
	 *
	 * @return void
	 */
	public function test_a_plain_list_reads_as_names(): void {
		$this->assertSame(
			array( 'job_cat', 'city' ),
			Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => array( 'job_cat', 'city' ) ) )
		);
	}

	/**
	 * And nothing to report reports nothing.
	 *
	 * @return void
	 */
	public function test_no_warnings_is_no_warnings(): void {
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => array() ) ) );
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( array() ) );

		// A bare number is not a field name; it is what produced the "0".
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => array( 0 ) ) ) );
	}

	// ── Not asked is not the same as answered "no" ────────────────────────────

	/**
	 * A lookup that never got through does not blame the value.
	 *
	 * The centre being unreachable leaves exactly the same empty field
	 * behind as a word they have never heard of, and the push is blocked
	 * either way — but one is a connection to fix and the other is a word
	 * to agree on. Telling somebody to rename good data because their
	 * network blinked is worse than saying nothing. Found in review.
	 *
	 * @return void
	 */
	public function test_an_unanswered_lookup_does_not_blame_the_value(): void {
		$post = new WP_Post( 11, 'kivun_job' );
		Kivun_Test_State::$terms[11]['kivun_job_scope'] = array( 'משרה מלאה' );

		// No credentials, so the request never leaves the building and comes
		// back as an error — the same "could not ask" the centre being down
		// produces, and the one the harness can arrange honestly.
		Kivun_Mercaz_Sync::resolve_term( 'job-type', 'משרה מלאה', 'jobs' );

		$this->assertTrue( Kivun_Mercaz_Sync::lookup_failed( 'job-type', 'משרה מלאה' ) );

		$lines = Kivun_Mercaz_Sync::missing_detail( $post, array(), array( 'job-type' => 'סוג משרה' ) );

		$this->assertSame(
			array( 'סוג משרה ("משרה מלאה" — לא הצלחנו לבדוק מול מרכז כיוון, ייתכן שהחיבור נכשל)' ),
			$lines
		);
	}

	/**
	 * And a name nobody asked about is not reported as unreachable either.
	 *
	 * @return void
	 */
	public function test_a_name_never_looked_up_is_not_called_unreachable(): void {
		$this->assertFalse( Kivun_Mercaz_Sync::lookup_failed( 'job-type', 'משרה חלקית' ) );
	}
}
