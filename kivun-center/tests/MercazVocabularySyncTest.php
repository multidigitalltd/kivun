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
		// How it moves them is asserted in
		// test_a_merge_keeps_the_other_terms_on_the_job; here it only has
		// to exist and be reached.
		$this->assertStringContainsString( 'self::merge_term( $taxonomy,', $source );
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

	// ── What it refuses to claim ──────────────────────────────────────────────

	/**
	 * A sync that could not read their list is not reported as a sync.
	 *
	 * It would otherwise stamp a fresh "סונכרן לאחרונה: היום" on the term
	 * screen and answer the button with a cheerful row of zeros, while the
	 * integration stays broken and the list stays weeks stale. Found in
	 * review.
	 *
	 * @return void
	 */
	public function test_a_failed_fetch_is_not_reported_as_a_sync(): void {
		// No credentials, so the request fails before it leaves.
		$done = Kivun_Mercaz::sync_vocabularies();

		$this->assertTrue( is_wp_error( $done ), 'a sync that read nothing reported success' );
		$this->assertSame( '', (string) get_option( 'kivun_mercaz_vocab_synced', '' ), 'the last-synced stamp was written for a sync that did not happen' );
	}

	/**
	 * The whole vocabulary is read, not its first hundred.
	 *
	 * Their API caps a page at 100. Reading one page leaves later values
	 * missing here and unavailable to adopt, with nothing on screen to say
	 * they exist. Found in review.
	 *
	 * @return void
	 */
	public function test_it_reads_past_the_first_page(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'public static function remote_terms' );
		$body   = substr( $source, $start, 1800 );

		$this->assertStringContainsString( "'page'     => \$page", $body );
		$this->assertStringContainsString( "x-wp-totalpages", $body, 'nothing reads how many pages there are' );
		$this->assertStringContainsString( 'while ( $pages > 0 && $page <= $pages', $body );

		// And no reader keeps a capped request of its own. Asserted on the
		// thing that would be wrong rather than on how many callers there
		// are, which grows for good reasons.
		$this->assertGreaterThan( 1, substr_count( $source, 'self::remote_terms(' ) );
		$this->assertSame(
			1,
			substr_count( $source, "'per_page' => 100" ),
			'something fetches their vocabulary without paging through it'
		);
	}

	/**
	 * A rename onto a name we already hold is a merge, not a lost rename.
	 *
	 * wp_update_term() refuses it as term_exists. Ignoring that left the
	 * jobs on the old name, still rejected, while the sync counted the
	 * rename as done. Found in review.
	 *
	 * @return void
	 */
	public function test_a_rename_onto_an_existing_name_merges(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'private static function sync_vocabulary' );
		$body   = substr( $source, $start, 4000 );

		$this->assertStringContainsString( '$clash = get_term_by( \'name\', $name, $taxonomy );', $body );
		$this->assertStringContainsString( 'self::merge_term( $taxonomy,', $body );

		// And a rename that does fail is not counted as one that worked.
		$this->assertStringContainsString( 'if ( ! is_wp_error( $renamed ) ) {', $body );
	}

	// ── The one-time move onto their list ─────────────────────────────────────

	/**
	 * Every value this site had maps to one of theirs, with no asking.
	 *
	 * The table is read against their real lists, which came back as:
	 * job-type — היברידית, חלקית, מלאה, משמרות, משרה לאקדמאים, משרת אם,
	 * סטודנט, עבודה מהבית, פרילנס; and job_cat — 39 values including
	 * אדמיניסטרציה, בריאות, חינוך, הייטק, כללי, כספים, מכירות, ניהול,
	 * עיצוב ואמנות and טיפול.
	 *
	 * @return void
	 */
	public function test_every_old_value_has_one_of_theirs(): void {
		$theirs = array(
			// Their job-type list, as it came back.
			'היברידית',
			'חלקית',
			'מלאה',
			'משמרות',
			'משרה לאקדמאים',
			'משרת אם',
			'סטודנט',
			'עבודה מהבית',
			'פרילנס',
			// The parts of their job_cat list this table reaches for.
			'אדמיניסטרציה',
			'בריאות',
			'חינוך',
			'הייטק',
			'כללי',
			'כספים',
			'מכירות',
			'ניהול',
			'עיצוב ואמנות',
			'טיפול',
		);

		foreach ( Kivun_Mercaz::legacy_names() as $was => $becomes ) {
			$this->assertContains(
				$becomes,
				$theirs,
				"\"{$was}\" is mapped to \"{$becomes}\", which is not on the centre's list"
			);
		}
	}

	/**
	 * The two values that already matched are left out of the table.
	 *
	 * "משרת אם" is theirs word for word, and renaming it to itself is a
	 * line of work that can only go wrong.
	 *
	 * @return void
	 */
	public function test_values_that_already_matched_are_not_in_the_table(): void {
		$this->assertArrayNotHasKey( 'משרת אם', Kivun_Mercaz::legacy_names() );
	}

	/**
	 * Volunteering is deliberately not mapped.
	 *
	 * They have nothing like it, and sending it to the nearest-looking
	 * value would file volunteering under paid work.
	 *
	 * @return void
	 */
	public function test_volunteering_is_left_alone(): void {
		$this->assertArrayNotHasKey( 'התנדבות', Kivun_Mercaz::legacy_names() );
	}

	/**
	 * The move happens once and records itself.
	 *
	 * Run again later it would find nothing — or catch a value somebody has
	 * since created on purpose under one of the old names and rename it out
	 * from under them.
	 *
	 * @return void
	 */
	public function test_the_move_happens_once(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'private static function adopt_legacy_names' );
		$body   = substr( $source, $start, 3500 );

		$this->assertStringContainsString( "if ( get_option( 'kivun_mercaz_legacy_adopted' ) ) {", $body );
		$this->assertStringContainsString( "update_option( 'kivun_mercaz_legacy_adopted', 1 );", $body );
	}

	/**
	 * And it is not marked done when their list could not be read.
	 *
	 * Marking it would leave this site half on their vocabulary for good,
	 * with no second attempt.
	 *
	 * @return void
	 */
	public function test_it_is_not_marked_done_on_a_failed_read(): void {
		Kivun_Mercaz::sync_vocabularies();

		$this->assertSame(
			'',
			(string) get_option( 'kivun_mercaz_legacy_adopted', '' ),
			'the one-time move was recorded as done although nothing was read'
		);
	}

	/**
	 * A value they turn out not to have is left as it was.
	 *
	 * Their list is the authority even over this table: renaming to
	 * something they reject swaps one dead value for another and loses the
	 * old name on the way.
	 *
	 * @return void
	 */
	public function test_a_target_they_lack_is_skipped(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'private static function adopt_legacy_names' );
		$body   = substr( $source, $start, 3500 );

		$this->assertStringContainsString( '$their_id = (int) array_search( $becomes, $theirs, true );', $body );
		$this->assertStringContainsString( 'if ( ! $their_id ) {', $body );
	}

	// ── A merge that only moves what it means to ─────────────────────────────

	/**
	 * Merging takes the old value away without taking the others with it.
	 *
	 * wp_set_object_terms() with replace would hand the job a term set of
	 * exactly one, so a job tagged with two fields comes out of the merge
	 * holding one and nothing says the other was ever there. The job form
	 * offers a single choice, but the WordPress term box and an import do
	 * not. Found in review.
	 *
	 * @return void
	 */
	public function test_a_merge_keeps_the_other_terms_on_the_job(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'private static function merge_term' );
		$body   = substr( $source, $start, 1600 );

		// Appended, then the old one removed — never a wholesale replace.
		$this->assertStringContainsString( 'wp_set_object_terms( (int) $id, array( $to ), $taxonomy, true );', $body );
		$this->assertStringContainsString( 'wp_remove_object_terms( (int) $id, array( $from ), $taxonomy );', $body );
		$this->assertStringNotContainsString( '$taxonomy, false );', $body, 'the merge replaces the job\'s terms instead of adding to them' );
	}

	/**
	 * A value that could not be renamed is tried again tomorrow.
	 *
	 * Writing the done flag anyway would leave its jobs on a name the
	 * centre rejects, with every later sync stepping straight over it.
	 * Found in review.
	 *
	 * @return void
	 */
	public function test_a_stuck_rename_leaves_the_pass_unfinished(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz.php' );
		$start  = (int) strpos( $source, 'private static function adopt_legacy_names' );
		$body   = substr( $source, $start, 4000 );

		$this->assertStringContainsString( '$stuck = true;', $body );
		$this->assertStringContainsString( 'if ( ! $stuck ) {', $body );

		// The flag is written inside that guard, not beside it.
		$guard = (int) strpos( $body, 'if ( ! $stuck ) {' );
		$flag  = (int) strpos( $body, "update_option( 'kivun_mercaz_legacy_adopted', 1 );" );
		$this->assertGreaterThan( $guard, $flag, 'the pass is marked done outside the check that it finished' );
	}
}
