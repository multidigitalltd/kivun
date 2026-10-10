<?php
/**
 * Who is shown where a lead came from.
 *
 * Which campaign brought somebody in belongs on the campaigns screen. A
 * coordinator working the leads is doing a different job and the column is
 * kept off their table — but a manager, who is running the advertising, sees
 * it in both places. The two are told apart by capability rather than by
 * screen, so the rule is pinned here: a quiet drift either way would leak the
 * column to the wrong people or hide it from the right ones.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Content_Creator::can_see_lead_source().
 */
final class LeadSourceVisibilityTest extends TestCase {

	/**
	 * Start each test with nobody signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Each role, and whether the source column is theirs to see.
	 *
	 * @dataProvider role_provider
	 * @param array<int,string> $caps  What the user holds.
	 * @param bool              $sees  Whether they should see the source.
	 * @param string            $who   The role, for the failure message.
	 * @return void
	 */
	public function test_who_sees_the_source( array $caps, bool $sees, string $who ): void {
		kivun_test_set_caps( $caps );

		$this->assertSame( $sees, Kivun_Content_Creator::can_see_lead_source(), $who );
	}

	/**
	 * The roles this site actually has.
	 *
	 * @return array<string,array{0:array<int,string>,1:bool,2:string}>
	 */
	public static function role_provider(): array {
		return array(
			'administrator' => array( array( 'read', 'edit_posts', 'edit_others_posts', 'manage_options' ), true, 'an administrator sees it' ),
			'editor'        => array( array( 'read', 'edit_posts', 'edit_others_posts' ), true, 'a content manager sees it' ),
			'leads viewer'  => array( array( 'read', 'kivun_view_leads' ), false, 'a coordinator reading leads does not' ),
			'author'        => array( array( 'read', 'edit_posts' ), false, 'an author of their own content does not' ),
			'jobs manager'  => array( array( 'read', 'kivun_employer', 'kivun_manage_jobs' ), false, 'the jobs manager does not' ),
			'subscriber'    => array( array( 'read' ), false, 'nobody else does' ),
		);
	}

	/**
	 * The leads reader is exactly who this hides it from, and they can still
	 * read the leads — the two questions are separate.
	 *
	 * @return void
	 */
	public function test_the_leads_reader_still_reads_the_leads(): void {
		kivun_test_set_caps( array( 'read', 'kivun_view_leads' ) );

		$this->assertTrue( Kivun_Content_Creator::can_manage_leads(), 'they still get the table' );
		$this->assertFalse( Kivun_Content_Creator::can_see_lead_source(), 'just not that column' );
	}

	/**
	 * Not the same bar as reading the leads. If these ever became the same
	 * call the column would come back for the people it was hidden from.
	 *
	 * @return void
	 */
	public function test_it_is_a_narrower_rule_than_reading_leads(): void {
		kivun_test_set_caps( array( 'read', 'kivun_view_leads' ) );
		$this->assertNotSame(
			Kivun_Content_Creator::can_manage_leads(),
			Kivun_Content_Creator::can_see_lead_source()
		);

		// And for a manager the two agree, so nothing is withheld from them.
		kivun_test_set_caps( array( 'read', 'edit_posts', 'edit_others_posts', 'manage_options' ) );
		$this->assertSame(
			Kivun_Content_Creator::can_manage_leads(),
			Kivun_Content_Creator::can_see_lead_source()
		);
	}
}
