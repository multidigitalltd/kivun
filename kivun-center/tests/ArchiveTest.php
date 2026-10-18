<?php
/**
 * Putting a campaign or a piece of content away.
 *
 * The console lists everything ever made, so the screens fill up and the one
 * thing somebody is looking for is buried. The fix asked for was the mild
 * one: move the old things to the bottom.
 *
 * What makes it worth testing is what it must NOT do. A campaign's short
 * links are already out in the world — in WhatsApp messages, in mail, on
 * printed flyers — and its leads are still arriving. A content page is live
 * on the site with a form on it. "Tidy the screen" and "switch this off" are
 * one keystroke apart in the code and a world apart to the person whose link
 * stops working, so the line between them is held here.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-campaigns.php';

/**
 * Tests for the archive flag and the boundary around it.
 */
final class ArchiveTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	// ── What it does ──────────────────────────────────────────────────────────

	/**
	 * A campaign is read as put away, or not, from its own column.
	 *
	 * @return void
	 */
	public function test_a_campaign_knows_whether_it_is_put_away(): void {
		$this->assertTrue( Kivun_Campaigns::is_archived( (object) array( 'archived' => 1 ) ) );
		$this->assertTrue( Kivun_Campaigns::is_archived( (object) array( 'archived' => '1' ) ) );
		$this->assertFalse( Kivun_Campaigns::is_archived( (object) array( 'archived' => 0 ) ) );
		$this->assertFalse( Kivun_Campaigns::is_archived( (object) array( 'archived' => '0' ) ) );

		// A row from before the column existed is not put away.
		$this->assertFalse( Kivun_Campaigns::is_archived( (object) array( 'label' => 'קמפיין' ) ) );
	}

	/**
	 * The list asks for them in the order the screen draws them: the ones in
	 * use first, and within each the newest.
	 *
	 * @return void
	 */
	public function test_the_ones_in_use_come_first(): void {
		$this->assertStringContainsString(
			'ORDER BY archived ASC, created_at DESC, id DESC',
			(string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' ),
			'the archived campaigns are no longer sorted to the end'
		);
	}

	/**
	 * The column is added to sites that already have the table, not only to
	 * new ones. Without it every existing install reads a missing column.
	 *
	 * @return void
	 */
	public function test_existing_installs_get_the_column(): void {
		$installer = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );

		$this->assertStringContainsString( "archived    tinyint(1)          NOT NULL DEFAULT 0", $installer );
		$this->assertStringContainsString( "'archived' => 'tinyint(1) NOT NULL DEFAULT 0'", $installer );
	}

	// ── What it must not do ───────────────────────────────────────────────────

	/**
	 * Archiving writes to one column and nothing else.
	 *
	 * The handler is read rather than run, because what matters is the whole
	 * of what it touches: an UPDATE that also set a status, or a call that
	 * also went near the links table, would be the feature quietly becoming
	 * "switch this off".
	 *
	 * @return void
	 */
	public function test_archiving_a_campaign_writes_only_that_flag(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );
		$start  = strpos( $source, 'public static function ajax_archive()' );
		$this->assertIsInt( $start );

		$handler = substr( $source, $start, 1400 );

		$this->assertStringContainsString( "array( 'archived' => \$archived )", $handler );
		$this->assertStringNotContainsString( 'kivun_campaign_links', $handler );
		$this->assertStringNotContainsString( 'delete', $handler );
		$this->assertStringNotContainsString( 'slug', $handler );
	}

	/**
	 * Nothing that resolves a short link or counts a lead asks whether the
	 * campaign is archived.
	 *
	 * This is the promise the whole feature rests on, and it is one grep
	 * away from being broken by somebody adding a sensible-looking filter.
	 *
	 * @return void
	 */
	public function test_tracking_never_asks_about_the_flag(): void {
		foreach ( array(
			'includes/class-kivun-short-links.php' => 'resolving a short code',
			'includes/class-kivun-utm.php'         => 'crediting a lead to its campaign',
		) as $file => $what ) {
			$this->assertStringNotContainsString(
				'archived',
				(string) file_get_contents( KIVUN_DIR . $file ),
				"{$what} now depends on the archive flag — an archived campaign would stop working"
			);
		}

		// The lead counts are a join across links and registrations. An
		// archived campaign must still appear in it, or its numbers vanish
		// from the report the moment somebody tidies the screen.
		$campaigns = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );
		$start     = strpos( $campaigns, 'public static function link_lead_counts()' );
		$this->assertIsInt( $start );
		$this->assertStringNotContainsString( 'archived', substr( $campaigns, $start, 1200 ) );
	}

	/**
	 * Content is put away with a meta mark, and its posts keep their status.
	 *
	 * The page stays published: somebody tidying the management screen is
	 * not somebody taking a page off the site, and if those were the same
	 * button the first person to use it would find out from a visitor.
	 *
	 * @return void
	 */
	public function test_archiving_content_leaves_the_page_published(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$start  = strpos( $source, 'public static function ajax_archive_content()' );
		$this->assertIsInt( $start );

		$handler = substr( $source, $start, 2200 );

		$this->assertStringContainsString( 'update_post_meta( $id, self::ARCHIVED_META', $handler );
		$this->assertStringContainsString( 'delete_post_meta( $id, self::ARCHIVED_META', $handler );
		$this->assertStringNotContainsString( 'wp_update_post', $handler );
		$this->assertStringNotContainsString( 'wp_trash_post', $handler );
		$this->assertStringNotContainsString( "'post_status' =>", $handler );
	}

	/**
	 * The mark goes on every post of the group.
	 *
	 * The library lists the group, not the post: a course and the landing
	 * page built for it are one line there, and one of them left unmarked
	 * puts that line straight back in the list.
	 *
	 * @return void
	 */
	public function test_the_whole_group_is_marked(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$start  = (int) strpos( $source, 'public static function ajax_archive_content()' );
		$handler = substr( $source, $start, 2200 );

		$this->assertStringContainsString( "'meta_value'             => \$group", $handler );
		$this->assertStringContainsString( 'foreach ( array_map( \'absint\', (array) $ids ) as $id )', $handler );
	}

	/**
	 * Only somebody who may create content may put it away — the rule the
	 * client chose, and the same one the publishing form already applies.
	 *
	 * @return void
	 */
	public function test_who_may_put_content_away(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$start  = (int) strpos( $source, 'public static function ajax_archive_content()' );

		$this->assertStringContainsString(
			"if ( ! current_user_can( 'edit_posts' ) )",
			substr( $source, $start, 900 )
		);
	}

	// ── The library's two halves ──────────────────────────────────────────────

	/**
	 * The library asks for each half separately rather than filtering one
	 * list after the fact.
	 *
	 * It matters more than it looks: the query is capped at 200, and always
	 * was, so past that the oldest content simply stopped appearing with
	 * nothing on screen to say so. Filtering afterwards would have left that
	 * ceiling exactly where it is — putting things away would tidy the list
	 * without making any room in it.
	 *
	 * @return void
	 */
	public function test_each_half_is_its_own_query(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( 'self::library_posts( false )', $source );
		$this->assertStringContainsString( 'self::library_posts( true )', $source );
		$this->assertStringContainsString( "'compare' => \$archived ? 'EXISTS' : 'NOT EXISTS'", $source );
	}
}
