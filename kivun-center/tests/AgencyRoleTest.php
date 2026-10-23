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

		// And the same for the figures above them. Both tallies an agency has
		// no business in — the site's content and the whole leads table — are
		// not asked for rather than asked for and then withheld, so neither
		// can reappear by somebody printing a value that was lying around.
		$at   = (int) strpos( $source, 'self::console_stats(' );
		$call = substr( $source, $at, (int) strpos( $source, ';', $at ) - $at );

		$this->assertSame(
			2,
			substr_count( $call, '! $agency' ),
			'the console still counts the site content or the whole leads table for an agency'
		);
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

		// ...and in the file, now as a row per number rather than a campaign
		// total repeated across the link rows. The repeated total could not
		// be summed: adding the column up multiplied one campaign's calls by
		// its number of links.
		$export = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );
		$this->assertStringContainsString( "'מספר טלפון',", $export, 'the report has no row per tracked number' );
		$this->assertStringContainsString( '(int) $assign->calls,', $export );
		$this->assertStringNotContainsString( 'שיחות לקמפיין', $export, 'the unsummable campaign total is back' );
	}

	/**
	 * The count leads where the reader may actually go.
	 *
	 * It used to be plain text for an agency, because an agency could not
	 * open the leads tab and an anchor landing back on the page it was
	 * clicked from reads as a fault rather than a boundary. The client has
	 * since asked that an agency read those enquiries, so the figure is a
	 * link again — for whoever can open the tab, and only for them.
	 *
	 * @return void
	 */
	public function test_the_lead_count_links_only_where_the_tab_opens(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			'<?php if ( $link_leads && $kivun_leads_reachable ) : ?>',
			$source,
			'the enquiry count is an anchor regardless of who is reading'
		);
		$this->assertStringContainsString(
			'$kivun_leads_reachable = self::can_read_leads();',
			$source,
			'the link is decided by something other than who may open the tab'
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

	/**
	 * The message can be taken, not rewritten.
	 *
	 * Left editable the box invites a rewrite that cannot be saved and is
	 * gone on the next reload — and worse, one that gets copied and sent to
	 * publishers while the message stored here still says something else.
	 *
	 * readonly rather than disabled, so the text stays selectable and the
	 * copy button still reads it.
	 *
	 * @return void
	 */
	public function test_the_message_is_readable_but_not_writable(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			"dir=\"rtl\" <?php echo \$kivun_read_only ? 'readonly' : ''; ?>>",
			$source,
			'an agency can type into a message it cannot save'
		);
		$this->assertStringNotContainsString(
			"\$kivun_read_only ? 'disabled'",
			$source,
			'disabled would stop the copy button reading the text'
		);
	}

	/**
	 * And nothing on the way in offers an edit that will not be there.
	 *
	 * The fold used to be headed "show and edit", with a closing note
	 * pointing at an edit button the agency does not get. Both are read
	 * before the box itself, so both have to know who is reading.
	 *
	 * @return void
	 */
	public function test_nothing_promises_an_edit_the_agency_cannot_make(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$open = strpos( $source, '<details class="kivun-link-wa-saved">' );
		$this->assertNotFalse( $open, 'the fold moved' );

		$close = strpos( $source, '</details>', (int) $open );
		$fold  = substr( $source, (int) $open, (int) $close - (int) $open );

		foreach ( array( 'הצגה ועריכה של ההודעה', 'השתמשו בכפתור העריכה שבסוף השורה' ) as $promise ) {
			$at = strpos( $fold, $promise );
			$this->assertNotFalse( $at, 'the wording moved: ' . $promise );

			$guard = strpos( $fold, '$kivun_read_only' );
			$this->assertNotFalse( $guard, 'the fold does not ask who is reading' );
			$this->assertLessThan(
				$at,
				$guard,
				'a reader who cannot save is still offered an edit: ' . $promise
			);
		}
	}
}
