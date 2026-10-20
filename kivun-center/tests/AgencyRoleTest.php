<?php
/**
 * The outside advertising agency: counts, and nothing else.
 *
 * An agency runs campaigns for the centre and needs to know what they
 * produced. It does not need to know who enquired, and it has no business
 * changing anything — the campaigns are the centre's, and so are the
 * people who answered them.
 *
 * The role is defined by what it cannot do, which is why none of it rests
 * on hiding buttons. The capability it holds is deliberately not
 * `edit_posts`: every handler in this plugin that guards on `edit_posts`
 * would have opened the moment that was granted, and the screen would then
 * be the only thing standing between an agency and the whole console.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-content-creator.php';

/**
 * Tests for what the agency role can and cannot reach.
 */
final class AgencyRoleTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Become an agency account.
	 *
	 * @return void
	 */
	private function as_agency(): void {
		Kivun_Test_State::$caps = array(
			'read'                 => true,
			'kivun_view_campaigns' => true,
		);
	}

	// ── What it is ────────────────────────────────────────────────────────────

	/**
	 * The role is recognised from its own capability.
	 *
	 * @return void
	 */
	public function test_an_agency_is_recognised(): void {
		$this->as_agency();
		$this->assertTrue( Kivun_Content_Creator::is_agency() );
	}

	/**
	 * And an administrator is not one, however the capability is granted.
	 *
	 * Otherwise granting it to an existing manager would quietly take their
	 * console away.
	 *
	 * @return void
	 */
	public function test_an_administrator_is_not_an_agency(): void {
		Kivun_Test_State::$caps = array(
			'manage_options'       => true,
			'edit_posts'           => true,
			'kivun_view_campaigns' => true,
		);

		$this->assertFalse( Kivun_Content_Creator::is_agency() );
	}

	/**
	 * Somebody without the capability is not one either.
	 *
	 * @return void
	 */
	public function test_everyone_else_is_not_an_agency(): void {
		Kivun_Test_State::$caps = array( 'read' => true );
		$this->assertFalse( Kivun_Content_Creator::is_agency() );
	}

	// ── What it must never reach ──────────────────────────────────────────────

	/**
	 * It cannot read the leads.
	 *
	 * This is the whole point of the role. A count is the agency's
	 * business; a name and a phone number belong to the person who left
	 * them.
	 *
	 * @return void
	 */
	public function test_it_cannot_read_the_leads(): void {
		$this->as_agency();

		$this->assertFalse( Kivun_Content_Creator::can_manage_leads(), 'an agency can open the leads table' );
		$this->assertFalse( Kivun_Content_Creator::can_see_lead_source(), 'an agency can see where a lead came from' );
		$this->assertFalse( Kivun_Content_Creator::is_leads_only() );
	}

	/**
	 * It cannot write to the campaigns it reads.
	 *
	 * @return void
	 */
	public function test_it_cannot_change_a_campaign(): void {
		$this->as_agency();

		$this->assertFalse( Kivun_Campaigns::can_manage(), 'an agency can create, edit and delete campaigns' );
	}

	/**
	 * The capability it holds is not an editing one.
	 *
	 * Asserted against the role as the installer writes it, because this is
	 * the line the whole design rests on: `edit_posts` here would open every
	 * handler in the plugin that asks for it, and no screen could take that
	 * back.
	 *
	 * @return void
	 */
	public function test_the_role_holds_no_editing_capability(): void {
		$installer = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );
		$start     = (int) strpos( $installer, "add_role(\n\t\t\t'kivun_agency'" );
		$this->assertGreaterThan( 0, $start, 'the agency role is not created' );

		$block = substr( $installer, $start, 400 );

		$this->assertStringContainsString( "'kivun_view_campaigns' => true,", $block );
		foreach ( array( 'edit_posts', 'edit_others_posts', 'manage_options', 'kivun_view_leads', 'delete_posts' ) as $never ) {
			$this->assertStringNotContainsString( $never, $block, "the agency role was granted {$never}" );
		}
	}

	// ── What the screen offers it ─────────────────────────────────────────────

	/**
	 * Every control that writes is withheld, not merely refused.
	 *
	 * A button that cannot work should not be offered: an agency pressing
	 * one and reading "אין לך הרשאה" learns only that this screen was not
	 * built for them.
	 *
	 * @return void
	 */
	public function test_the_writing_controls_are_withheld(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$start  = (int) strpos( $source, 'private static function front_campaigns' );
		$end    = (int) strpos( $source, 'private static function campaign_form', $start );
		$tab    = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( '$kivun_read_only = self::is_agency();', $tab );

		// Each writing control sits behind that flag.
		foreach ( array(
			'kivun-camp-new'         => 'creating a campaign',
			'kivun-edit-campaign'    => 'editing a campaign',
			'kivun-delete-campaign'  => 'deleting a campaign',
			'kivun-archive-campaign' => 'archiving a campaign',
			'kivun-edit-link'        => 'editing a link',
			'kivun-delete-link'      => 'deleting a link',
			'kivun-camp-addlink'     => 'adding a link',
			'kivun-wa-save'          => 'saving a message',
		) as $control => $what ) {
			$at = (int) strpos( $tab, $control );
			$this->assertGreaterThan( 0, $at, "{$control} is no longer on the campaigns tab" );

			$before = substr( $tab, 0, $at );
			$guard  = strrpos( $before, 'if ( ! $kivun_read_only )' );
			$this->assertNotFalse( $guard, "{$what} is offered to an agency" );
		}
	}

	/**
	 * Copying a message that already exists is left alone.
	 *
	 * It is the one thing the agency came to do, and it reads rather than
	 * writes.
	 *
	 * @return void
	 */
	public function test_copying_a_message_is_still_offered(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$start  = (int) strpos( $source, 'private static function front_campaigns' );
		$end    = (int) strpos( $source, 'private static function campaign_form', $start );
		$tab    = substr( $source, $start, $end - $start );

		$this->assertStringContainsString( 'kivun-wa-copy', $tab, 'the agency can no longer copy a message' );
		$this->assertStringContainsString( 'kivun-camp-copy', $tab, 'the agency can no longer copy a link' );
	}

	/**
	 * The campaigns report is allowed; the leads export is not.
	 *
	 * The report holds counts and no enquiry, which is the question the
	 * agency is asking. The leads export is the answer it must not have —
	 * and an export is exactly where a screen restriction leaks.
	 *
	 * @return void
	 */
	public function test_the_report_is_allowed_and_the_leads_export_is_not(): void {
		$export = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );

		$camp = (int) strpos( $export, "if ( 'campaigns' === \$type ) {" );
		$this->assertStringContainsString(
			'Kivun_Content_Creator::is_agency()',
			substr( $export, $camp, 500 ),
			'the campaigns report is closed to the agency'
		);

		$regs = (int) strpos( $export, "if ( 'registrations' === \$type ) {" );
		$this->assertStringNotContainsString(
			'is_agency',
			substr( $export, $regs, 400 ),
			'the leads export was opened to the agency'
		);
	}

	// ── Three ways the boundary leaked ───────────────────────────────────────

	/**
	 * The content panels are not rendered at all.
	 *
	 * Hiding a panel is a CSS state; the markup is still in the response.
	 * front_library() asks for every draft, pending, private and scheduled
	 * item on the site, and front_form() fills itself in from a group id in
	 * the URL — so an agency reading the page source had all of it. The one
	 * failure this role was designed against, made one layer above the
	 * design. Found in review.
	 *
	 * @return void
	 */
	public function test_the_content_panels_are_not_rendered(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$at     = (int) strpos( $source, 'data-panel="form"' );
		$this->assertGreaterThan( 0, $at );

		$before = substr( $source, 0, $at );
		$guard  = (int) strrpos( $before, 'if ( ! $leads_only && ! $agency ) :' );
		$this->assertGreaterThan( 0, $guard, 'the publishing and library panels still render for an agency' );

		// And the same for the figures above them, which count site content.
		$this->assertStringContainsString( "self::console_stats( \$show_leads, \$show_jobs, ! \$leads_only && ! \$agency )", $source );
	}

	/**
	 * The call counts the role promises are actually produced.
	 *
	 * The agency is told it gets enquiry and call totals. The calls tab is
	 * closed to it — those rows hold the caller's own number — so the
	 * aggregate has to arrive another way or half the reporting does not
	 * exist. Found in review.
	 *
	 * @return void
	 */
	public function test_the_call_totals_exist(): void {
		$this->assertTrue(
			method_exists( 'Kivun_Campaigns', 'campaign_call_counts' ),
			'an agency is promised call totals that nothing produces'
		);

		// On the screen...
		$creator = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString( 'Kivun_Campaigns::campaign_call_counts()', $creator );

		// ...and in the file.
		$export = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );
		$this->assertStringContainsString( 'שיחות לקמפיין', $export );
	}

	/**
	 * The count is not offered as a way into the enquiries.
	 *
	 * The link opens the leads tab, which the routing sends straight back —
	 * so it reads as a fault rather than a boundary, and invites the one
	 * click the role exists to prevent. Found in review.
	 *
	 * @return void
	 */
	public function test_the_lead_count_is_not_a_link_for_an_agency(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			'<?php if ( $link_leads && ! $kivun_read_only ) : ?>',
			$source,
			'the enquiry count is still an anchor into the leads tab'
		);
	}

	// ── One campaign, all of its channels ────────────────────────────────────

	/**
	 * An agency sees totals of its own, not the site's.
	 *
	 * The strip above the tabs was built for a content manager, so with
	 * every one of its figures withheld an agency got an empty band and no
	 * headline at all. The numbers it is owed are what its campaigns
	 * produced — not the site's total enquiries, which arrive from organic
	 * search and the newsletter too and would credit the agency for them.
	 *
	 * @return void
	 */
	public function test_an_agency_gets_its_own_headline(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$at     = (int) strpos( $source, 'class="kivun-cc-stats"' );
		$strip  = substr( $source, $at, 2600 );

		$this->assertStringContainsString( 'array_sum( Kivun_Campaigns::campaign_lead_counts() )', $strip );
		$this->assertStringContainsString( 'array_sum( Kivun_Campaigns::campaign_call_counts() )', $strip );
		$this->assertStringContainsString( 'פניות מהקמפיינים', $strip, 'the label does not say the figure is the campaigns\' own' );
	}

	/**
	 * A campaign shows its phone numbers beside its links.
	 *
	 * A campaign on a billboard and in a newsletter is one campaign. The
	 * printed number and the link are two of its channels, and reading them
	 * off two screens leaves somebody adding up by hand to answer how it
	 * went.
	 *
	 * @return void
	 */
	public function test_a_campaign_shows_its_numbers_with_its_links(): void {
		$this->assertTrue( method_exists( 'Kivun_Phones', 'by_campaign' ) );

		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString( 'Kivun_Phones::by_campaign()', $source );
		$this->assertStringContainsString( 'kivun-camp-numbers', $source );
	}

	/**
	 * And the answered column keeps the rule the call log already follows.
	 *
	 * On a switchboard that reports only the start of a call every row
	 * carries answered = 0, and a column of zeros is a false report rather
	 * than a cautious one.
	 *
	 * @return void
	 */
	public function test_the_answered_column_is_withheld_when_unknown(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( '$kivun_knows_answers   = Kivun_Phones::has_answer_data();', $source );

		$at    = (int) strpos( $source, 'kivun-camp-numbers' );
		$block = substr( $source, $at, 2200 );
		$this->assertStringContainsString( 'if ( $kivun_knows_answers ) : ?>', $block );
	}
}
