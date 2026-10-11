<?php
/**
 * The job vocabularies, and what the posting form must collect.
 *
 * A term is matched across the two sites by name, so a name that differs by
 * one letter resolves to nothing and the field is dropped from the request
 * without a word. That is not hypothetical: "אדרכילות/בנייה" sat in the seed
 * list with its letters transposed, and every job in that category was sent
 * with no category at all.
 *
 * The remote ids here are recorded from mercaz-kivun.co.il's own taxonomy
 * endpoints. They are not sent — names are — but they pin down which remote
 * vocabulary these names were checked against.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-employer.php';

/**
 * Tests for the job taxonomies and the posting form's required fields.
 */
final class JobVocabularyTest extends TestCase {

	/**
	 * The remote job-type vocabulary, as the API returns it.
	 *
	 * @var array<int,string>
	 */
	private const REMOTE_JOB_TYPE = array(
		71   => 'מלאה',
		72   => 'חלקית',
		73   => 'משמרות',
		74   => 'פרילנס',
		75   => 'היברידית',
		76   => 'עבודה מהבית',
		83   => 'סטודנט',
		84   => 'משרה לאקדמאים',
		1347 => 'משרת אם',
	);

	/**
	 * The remote job_features vocabulary.
	 *
	 * @var array<int,string>
	 */
	private const REMOTE_FEATURES = array(
		85 => 'מתאים לסטודנטים',
		86 => 'מתאים להורים',
		87 => 'מתאים למגזר החרדי',
		88 => 'רכב צמוד',
		89 => 'משרה זמנית',
		90 => 'משרה מיידית',
	);

	/**
	 * The terms a taxonomy is seeded with, read out of the installer.
	 *
	 * Read from the source rather than copied, so the test is pinned to what
	 * actually ships — a copy would keep passing after the list changed.
	 *
	 * @param string $taxonomy The local taxonomy.
	 * @return array<int,string>
	 */
	private function seeded( string $taxonomy ): array {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );
		$body   = substr( $source, (int) strpos( $source, 'function seed_default_terms' ) );

		$found = preg_match( "/'" . preg_quote( $taxonomy, '/' ) . "'\s*=>\s*array\((.*?)\n\t\t\t\),/s", $body, $m );
		$this->assertSame( 1, $found, "could not find the seed list for {$taxonomy}" );

		// Comments in these lists quote the term they are about, so reading
		// every quoted string would report a removed term as still seeded —
		// which is exactly the term this file exists to check.
		$list = preg_replace( '~^\s*//.*$~m', '', $m[1] );

		preg_match_all( "/'([^']+)'/", (string) $list, $terms );
		return $terms[1];
	}

	// ── The vocabularies ──────────────────────────────────────────────────────

	/**
	 * Every job type we offer exists on their side under that exact name.
	 *
	 * @return void
	 */
	public function test_every_job_type_exists_remotely(): void {
		$remote = array_values( self::REMOTE_JOB_TYPE );

		foreach ( $this->seeded( 'kivun_job_scope' ) as $name ) {
			$this->assertContains(
				$name,
				$remote,
				"\"{$name}\" has no match in their job-type vocabulary, so a job marked with it is broadcast with no type"
			);
		}
	}

	/**
	 * And every feature.
	 *
	 * @return void
	 */
	public function test_every_feature_exists_remotely(): void {
		$remote = array_values( self::REMOTE_FEATURES );

		foreach ( $this->seeded( 'kivun_job_feature' ) as $name ) {
			$this->assertContains( $name, $remote, "\"{$name}\" has no match in their job_features vocabulary" );
		}
	}

	/**
	 * The category whose letters were transposed. Named on its own because
	 * it is the one that actually went wrong, and a general check over a list
	 * that no longer contains it would pass for the wrong reason.
	 *
	 * @return void
	 */
	public function test_the_building_category_is_spelt_their_way(): void {
		$seeded = $this->seeded( 'kivun_job_field' );

		$this->assertContains( 'אדריכלות/בנייה', $seeded );
		$this->assertNotContains( 'אדרכילות/בנייה', $seeded, 'the transposed spelling resolves to nothing' );
	}

	/**
	 * Volunteering is not one of their job types, so offering it would hand
	 * somebody a choice that cannot be broadcast.
	 *
	 * @return void
	 */
	public function test_volunteering_is_not_offered_as_a_job_type(): void {
		$this->assertNotContains( 'התנדבות', $this->seeded( 'kivun_job_scope' ) );
	}

	// ── What the form must collect ────────────────────────────────────────────

	/**
	 * A complete posting goes through.
	 *
	 * @return void
	 */
	public function test_a_complete_posting_is_accepted(): void {
		$this->assertSame(
			'',
			Kivun_Employer::missing_for_broadcast( 'מזכיר/ה', 'תיאור', 'ירושלים', 'חלקית' )
		);
	}

	/**
	 * Each missing field is named in the refusal, so the person filling the
	 * form is told what to go and add rather than that something is wrong.
	 *
	 * @return void
	 */
	public function test_the_refusal_names_what_is_missing(): void {
		$message = Kivun_Employer::missing_for_broadcast( 'מזכיר/ה', 'תיאור', '', '' );

		$this->assertStringContainsString( 'עיר', $message );
		$this->assertStringContainsString( 'סוג משרה', $message );
		$this->assertStringNotContainsString( 'כותרת', $message );
	}

	/**
	 * Whitespace is not a settlement.
	 *
	 * @return void
	 */
	public function test_whitespace_does_not_pass_for_a_value(): void {
		$this->assertNotSame(
			'',
			Kivun_Employer::missing_for_broadcast( 'מזכיר/ה', 'תיאור', '   ', 'חלקית' )
		);
	}

	/**
	 * The title and description stayed required, as they already were.
	 *
	 * @return void
	 */
	public function test_the_old_requirements_still_hold(): void {
		$message = Kivun_Employer::missing_for_broadcast( '', '', 'ירושלים', 'חלקית' );

		$this->assertStringContainsString( 'כותרת', $message );
		$this->assertStringContainsString( 'תיאור', $message );
	}
}
