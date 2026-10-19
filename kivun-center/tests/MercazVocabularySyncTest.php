<?php
/**
 * Keeping our list of values the centre's list.
 *
 * A job is matched to the centre's vocabulary by name, so a value they do
 * not have is a value that gets dropped on the way out. Of 54 jobs held
 * back, 42 carried a type they reject — every type we had: "משרה מלאה"
 * against their "מלאה", "פרילאנס" against their "פרילנס", one letter
 * apart. It was never a choice between two vocabularies; it was a choice
 * between theirs and nothing.
 *
 * So theirs is pulled here, nightly, and ours follows it.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-mercaz.php';

/**
 * Tests for the vocabulary sync and what it refuses to do.
 */
final class MercazVocabularySyncTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Both vocabularies a job is matched on are kept in step.
	 *
	 * @return void
	 */
	public function test_both_vocabularies_are_synced(): void {
		$this->assertSame(
			array(
				'job-type' => 'kivun_job_scope',
				'job_cat'  => 'kivun_job_field',
			),
			Kivun_Mercaz::vocabularies()
		);
	}

	/**
	 * It runs on its own, nightly, rather than when somebody remembers.
	 *
	 * A value they add on Sunday should be choosable here on Monday — not
	 * discovered weeks later by a job being refused.
	 *
	 * @return void
	 */
	public function test_it_runs_nightly_without_being_asked(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );

		$this->assertStringContainsString(
			"add_action( 'kivun_daily', array( __CLASS__, 'sync_vocabularies' ) );",
			$source
		);

		// And that hook is one the plugin actually schedules.
		$this->assertStringContainsString(
			"wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'kivun_daily' );",
			(string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-cron.php' ),
			'the nightly hook is never scheduled, so the sync would never run'
		);
	}

	/**
	 * A value of ours they have dropped is never deleted.
	 *
	 * Deleting it would untag every job carrying it, and an untagged job is
	 * a worse problem than a value that no longer sends — the first loses
	 * information nobody can recover, the second is visible and fixable.
	 *
	 * @return void
	 */
	public function test_it_never_deletes_one_of_ours(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'private static function sync_vocabulary' );
		$body   = substr( $source, $start, 3200 );

		$this->assertStringNotContainsString( 'wp_delete_term', $body, 'the sync deletes terms, which would untag the jobs under them' );
	}

	/**
	 * A term is tied to the centre's id, not only to its name.
	 *
	 * Without the id a rename on their side arrives as a stranger: a new
	 * value appears, ours stays behind holding all the jobs, and the two
	 * sit side by side meaning the same thing.
	 *
	 * @return void
	 */
	public function test_a_term_is_tied_to_their_id(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );

		$this->assertStringContainsString( "const REMOTE_TERM = '_kivun_mercaz_term_id';", $source );
		$this->assertStringContainsString( 'update_term_meta( (int) $made[\'term_id\'], self::REMOTE_TERM, $id );', $source );

		// A rename follows through rather than adding a second value.
		$start = (int) strpos( $source, 'private static function sync_vocabulary' );
		$body  = substr( $source, $start, 3200 );
		$this->assertStringContainsString( 'if ( $by_remote[ $id ]->name !== $name ) {', $body );
		$this->assertStringContainsString( 'wp_update_term(', $body );
	}

	/**
	 * Adopting a value by hand ties it as well.
	 *
	 * Otherwise the one-time mapping works today and comes apart the first
	 * time the centre rewords anything.
	 *
	 * @return void
	 */
	public function test_adopting_ties_the_term_too(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'public static function ajax_adopt' );
		$body   = substr( $source, $start, 4200 );

		$this->assertStringContainsString( 'update_term_meta( (int) $term->term_id, self::REMOTE_TERM', $body );
	}

	/**
	 * A name that is not on their list is refused.
	 *
	 * The choice of which of their words a value means is a person's, and
	 * arrives from the browser. Renaming to something they also reject
	 * would report success and change nothing that matters.
	 *
	 * @return void
	 */
	public function test_a_name_they_do_not_have_is_refused(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'public static function ajax_adopt' );
		$body   = substr( $source, $start, 4200 );

		$this->assertStringContainsString( 'if ( ! in_array( $name, $theirs, true ) ) {', $body );
	}

	/**
	 * Two of ours meaning one of theirs is a merge, not a collision.
	 *
	 * "כספים וחשבונאות" and a later value may both be "כספים" to them. The
	 * second adoption has to move its jobs across rather than fail, or the
	 * person doing the mapping is stuck halfway with no way forward.
	 *
	 * @return void
	 */
	public function test_two_of_ours_can_become_one_of_theirs(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );

		$this->assertStringContainsString( 'private static function merge_term', $source );
		$this->assertStringContainsString( 'wp_set_object_terms( (int) $id, array( $to ), $taxonomy, false );', $source );
	}

	/**
	 * The screen where these are edited says not to.
	 *
	 * Renaming one by hand looks like tidying. It is not: the jobs under it
	 * stop being accepted, silently, and the term screen is the one place
	 * somebody would do it without ever seeing this plugin's settings.
	 *
	 * @return void
	 */
	public function test_the_term_screen_says_where_the_list_comes_from(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );

		$this->assertStringContainsString( 'function vocabulary_notice', $source );
		$this->assertStringContainsString( "_pre_add_form', array( __CLASS__, 'vocabulary_notice' )", $source );
		$this->assertStringContainsString( 'הרשימה הזו נשאבת מאתר כיוון הראשי', $source );
	}
}
