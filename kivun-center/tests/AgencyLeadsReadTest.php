<?php
/**
 * An agency reads the enquiries, and writes none of them.
 *
 * The role began as the campaign numbers and nothing else. The client has
 * since asked that the agency see the leads those campaigns produced — the
 * list itself, with the status, the note and who is handling it left alone.
 *
 * So reading and writing are two different questions here, and the thing
 * these tests hold still is that they stay two: the screen asks one, every
 * handler asks the other, and the capability that opens the table is not the
 * capability that writes to a row.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The reading half of the agency role.
 */
final class AgencyLeadsReadTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Stand in the agency's shoes.
	 *
	 * @return void
	 */
	private function as_agency(): void {
		kivun_test_set_caps( array( 'kivun_view_campaigns', 'kivun_read_leads' ) );
	}

	/**
	 * The table opens.
	 *
	 * @return void
	 */
	public function test_an_agency_may_read_the_leads(): void {
		$this->as_agency();

		$this->assertTrue( Kivun_Content_Creator::can_read_leads() );
		$this->assertTrue( Kivun_Content_Creator::is_agency(), 'the role stopped being an agency' );
	}

	/**
	 * And nothing on a row can be written.
	 *
	 * can_manage_leads() is the gate on the status, the note and the
	 * handler. Reading the table must not have opened it.
	 *
	 * @return void
	 */
	public function test_an_agency_may_not_write_a_row(): void {
		$this->as_agency();

		$this->assertFalse( Kivun_Content_Creator::can_manage_leads() );
		$this->assertFalse( Kivun_Admin::may_write_row( 'registrations' ) );
		$this->assertFalse( Kivun_Admin::may_write_row( 'applications' ) );
	}

	/**
	 * The status comes out as an answer, not a control.
	 *
	 * A dropdown nobody can save is worse than none: it takes the choice,
	 * the row keeps what it had, and the reader cannot tell.
	 *
	 * @return void
	 */
	public function test_the_status_is_shown_without_its_control(): void {
		$this->as_agency();

		$html = Kivun_Admin::status_select( 'registrations', 7, 'closed', array( 'closed' => 'נסגר ✓' ) );

		$this->assertStringNotContainsString( '<select', $html, 'an agency is offered a status it cannot set' );
		$this->assertStringContainsString( 'נסגר ✓', $html, 'the status it got to is not shown at all' );
	}

	/**
	 * A row nobody has reached says nothing, rather than inventing a state.
	 *
	 * @return void
	 */
	public function test_an_untouched_status_says_nothing(): void {
		$this->as_agency();

		$html = Kivun_Admin::status_select( 'registrations', 7, 'new_lead', array( 'new_lead' => 'ליד חדש' ) );

		$this->assertStringNotContainsString( 'ליד חדש', $html, 'a status nobody set is reported as one somebody did' );
	}

	/**
	 * The note is readable and not writable.
	 *
	 * @return void
	 */
	public function test_the_note_is_readable_but_not_writable(): void {
		$this->as_agency();

		$html = Kivun_Admin::notes_input( 'registrations', 7, 'דיברנו, חוזר ביום ראשון' );

		$this->assertStringNotContainsString( '<textarea', $html, 'an agency is offered a note it cannot save' );
		$this->assertStringContainsString( 'דיברנו, חוזר ביום ראשון', $html, 'the note is withheld rather than shown' );
	}

	/**
	 * Who is handling it is a name, not a picker.
	 *
	 * @return void
	 */
	public function test_the_handler_is_a_name_not_a_picker(): void {
		$this->as_agency();
		Kivun_Test_State::$options['kivun_settings'] = array( 'coordinators_female' => 'דסי, dasi@kivun.test = 1' );
		Kivun_Coordinators::forget_current();

		$html = Kivun_Admin::handler_select( 7, 'dasi@kivun.test' );

		$this->assertStringNotContainsString( '<select', $html, 'an agency is offered a handover it cannot make' );
		$this->assertStringContainsString( 'דסי', $html, 'whoever has the lead is not shown' );
	}

	/**
	 * Staff keep every control.
	 *
	 * The read-only rendering is for whoever cannot write, and that must not
	 * have quietly become everyone.
	 *
	 * @return void
	 */
	public function test_staff_still_get_the_controls(): void {
		kivun_test_set_caps( array( 'edit_posts' ) );

		$this->assertStringContainsString( '<select', Kivun_Admin::status_select( 'registrations', 7, 'closed', array( 'closed' => 'נסגר ✓' ) ) );
		$this->assertStringContainsString( '<textarea', Kivun_Admin::notes_input( 'registrations', 7, '' ) );
		$this->assertStringContainsString( '<select', Kivun_Admin::handler_select( 7, '' ) );
	}

	/**
	 * A leads reader keeps them too.
	 *
	 * That role works the leads; this change was not about it.
	 *
	 * @return void
	 */
	public function test_a_leads_reader_still_works_the_leads(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );

		$this->assertTrue( Kivun_Admin::may_write_row( 'registrations' ) );
		$this->assertFalse( Kivun_Admin::may_write_row( 'applications' ), 'CV applications left the jobs board' );
	}

	/**
	 * Every write handler refuses the agency, not just the screen.
	 *
	 * Hiding a control is a courtesy; this is the lock. A request posted by
	 * hand, or from a page left open after a role changed, reaches these and
	 * nothing else stands between it and the row.
	 *
	 * @return void
	 */
	public function test_every_write_handler_refuses_an_agency(): void {
		$this->as_agency();

		$posts = array(
			'ajax_update_status'  => array( 'table' => 'registrations', 'id' => 7, 'status' => 'closed' ),
			'ajax_save_note'      => array( 'table' => 'registrations', 'id' => 7, 'note' => 'לא אמור להישמר' ),
			'ajax_assign_handler' => array( 'id' => 7, 'handler' => 'dasi@kivun.test' ),
		);

		foreach ( $posts as $handler => $post ) {
			$_POST   = $post;
			$refused = false;
			try {
				Kivun_Admin::$handler();
			} catch ( Kivun_Test_Json $e ) {
				$refused = ! $e->ok;
			}
			$_POST = array();

			$this->assertTrue( $refused, $handler . ' let an agency write to a lead' );
		}

		$this->assertSame( array(), Kivun_Test_State::$updates, 'a row was written anyway' );
	}

	/**
	 * And the handlers decide it the same way the screen does.
	 *
	 * A control shown where the write would be refused is how somebody loses
	 * work they thought they had saved, so both sides call one test.
	 *
	 * @return void
	 */
	public function test_the_screen_and_the_handlers_ask_one_question(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-admin.php' );

		$this->assertStringNotContainsString(
			"\$may_edit = current_user_can( 'edit_posts' )",
			$source,
			'a handler still spells the test out on its own'
		);
	}

	/**
	 * An agency sees the enquiries its campaigns produced, and no others.
	 *
	 * The rest of the table is the client's: organic search, the newsletter,
	 * the contact form, the jobs board — the same names, phone numbers and
	 * internal notes, belonging to nobody the agency works for. Opening the
	 * tab with no filter chosen handed an outside supplier the lot. Found in
	 * review.
	 *
	 * @return void
	 */
	public function test_an_agency_sees_only_what_its_campaigns_brought(): void {
		$this->as_agency();

		$scope = Kivun_Content_Creator::lead_scope_sql();

		$this->assertNotSame( '', $scope, 'the leads table is read with no boundary at all' );
		$this->assertStringContainsString( 'utm_campaign', $scope );
		$this->assertStringContainsString( 'kivun_campaigns', $scope, 'the scope is not tied to the campaigns table' );
	}

	/**
	 * And everyone else still sees the whole table.
	 *
	 * @return void
	 */
	public function test_staff_read_the_whole_table(): void {
		kivun_test_set_caps( array( 'edit_posts' ) );
		$this->assertSame( '', Kivun_Content_Creator::lead_scope_sql() );

		kivun_test_set_caps( array( 'kivun_view_leads' ) );
		$this->assertSame( '', Kivun_Content_Creator::lead_scope_sql() );
	}

	/**
	 * The boundary is applied before any filter, so none can clear it.
	 *
	 * A scope that arrived as a default filter value would come off with a
	 * hand-edited URL, which is the one way somebody would try.
	 *
	 * @return void
	 */
	public function test_the_boundary_is_not_a_filter(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$at    = (int) strpos( $source, '$conds = array();' );
		$block = substr( $source, $at, 700 );

		$this->assertStringContainsString( 'self::lead_scope_sql()', $block, 'the boundary is not the first thing on the query' );

		// The settlements offered as a filter are read from the same table
		// and leak the same way, so that query carries it too.
		$at    = (int) strpos( $source, 'SELECT DISTINCT r.city' );
		$this->assertNotFalse( $at, 'the settlement list moved' );
		$before = substr( $source, max( 0, $at - 500 ), min( 500, $at ) );
		$this->assertStringContainsString( 'lead_scope_sql()', $before, 'the settlement filter lists towns from leads the reader cannot see' );
	}

	/**
	 * The export is not offered to whoever it refuses.
	 *
	 * Kivun_Export turns an agency down, so every press ended at
	 * "Unauthorized". Found in review.
	 *
	 * @return void
	 */
	public function test_the_export_button_is_not_offered_to_an_agency(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$at    = (int) strpos( $source, "Kivun_Export::url( 'registrations'" );
		$this->assertNotFalse( $at, 'the leads export link moved' );

		$before = substr( $source, max( 0, $at - 700 ), min( 700, $at ) );
		$this->assertStringContainsString( 'if ( self::can_manage_leads() ) :', $before, 'the export is offered to a reader who cannot use it' );
	}

	/**
	 * The capability that opens the table is not the one that writes.
	 *
	 * Granting kivun_view_leads would have opened the status, the note and
	 * the handler the moment it was given.
	 *
	 * @return void
	 */
	public function test_the_role_is_not_given_the_writing_capability(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );

		$at   = (int) strpos( $source, "'kivun_agency'," );
		$role = substr( $source, $at, 420 );

		$this->assertStringContainsString( "'kivun_read_leads'     => true,", $role );
		$this->assertStringNotContainsString( "'kivun_view_leads'", $role, 'the agency was given the writing capability' );
		$this->assertStringNotContainsString( "'edit_posts'", $role );
	}
}
