<?php
/**
 * Where a composed form meets the rest of the plugin.
 *
 * The questions themselves are held still by CustomFormTest. These are the
 * seams: the tab, the template the page picks, the copy, and the report
 * column that was the whole point of storing the answers twice.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The seams.
 */
final class CustomFormWiringTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Reach one of the export's private helpers.
	 *
	 * @param string $name The method.
	 * @param array  $args Its arguments.
	 * @return mixed
	 */
	private function export( string $name, array $args ) {
		$method = new ReflectionMethod( Kivun_Export::class, $name );
		$method->setAccessible( true );
		return $method->invoke( null, ...$args );
	}

	/**
	 * One row of answers, as the lead stores them.
	 *
	 * @param array<string,string> $pairs Label => value.
	 * @return string
	 */
	private function stored( array $pairs ): string {
		$out = array();
		foreach ( $pairs as $label => $value ) {
			$out[] = array( 'label' => $label, 'value' => $value );
		}
		return (string) wp_json_encode( $out );
	}

	// ── The report ───────────────────────────────────────────────────────────

	/**
	 * Each question gets a column of its own.
	 *
	 * Left in the one prose cell the answers can be read and nothing else —
	 * not sorted, not counted, not pivoted, which is what a spreadsheet is
	 * opened for.
	 *
	 * @return void
	 */
	public function test_each_question_gets_a_column(): void {
		$rows = array(
			array( 'answers' => $this->stored( array( 'מסלול' => 'חשמל', 'ניסיון' => 'ללא' ) ) ),
		);

		$this->assertSame( array( 'מסלול', 'ניסיון' ), $this->export( 'question_columns', array( $rows ) ) );
	}

	/**
	 * The same question on two pages shares one column.
	 *
	 * @return void
	 */
	public function test_the_same_question_shares_a_column(): void {
		$rows = array(
			array( 'answers' => $this->stored( array( 'מסלול' => 'חשמל' ) ) ),
			array( 'answers' => $this->stored( array( 'מסלול' => 'מכונות', 'תקציב' => '500' ) ) ),
		);

		$this->assertSame( array( 'מסלול', 'תקציב' ), $this->export( 'question_columns', array( $rows ) ) );
	}

	/**
	 * A row that never answered a question leaves that cell empty, in the
	 * right place.
	 *
	 * A cell that slips a column puts one lead's answer against another
	 * lead's question, which is worse than leaving it out.
	 *
	 * @return void
	 */
	public function test_a_missing_answer_leaves_its_own_cell_empty(): void {
		$columns = array( 'מסלול', 'תקציב', 'ניסיון' );
		$cells   = $this->export( 'answer_cells', array( $this->stored( array( 'ניסיון' => 'ללא' ) ), $columns ) );

		$this->assertSame( array( '', '', 'ללא' ), $cells );
	}

	/**
	 * A lead from a page with no questions adds no columns and no cells.
	 *
	 * @return void
	 */
	public function test_a_plain_lead_changes_nothing(): void {
		$this->assertSame( array(), $this->export( 'question_columns', array( array( array( 'answers' => '' ) ) ) ) );
		$this->assertSame( array(), $this->export( 'answer_cells', array( '', array() ) ) );
	}

	/**
	 * A row mangled in the database does not stop the report.
	 *
	 * A report is not the place to discover that one row of three thousand
	 * was edited by hand.
	 *
	 * @return void
	 */
	public function test_a_broken_row_does_not_stop_the_report(): void {
		$rows = array(
			array( 'answers' => 'not json' ),
			array( 'answers' => $this->stored( array( 'מסלול' => 'חשמל' ) ) ),
		);

		$this->assertSame( array( 'מסלול' ), $this->export( 'question_columns', array( $rows ) ) );
	}

	/**
	 * And the answers keep their prose column too.
	 *
	 * It is the copy that cannot lose anything — including an answer to a
	 * question since deleted, which has no column to go in.
	 *
	 * @return void
	 */
	public function test_the_prose_column_is_still_there(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );

		$at   = (int) strpos( $src, "'אישור דיוור', 'סוג'" );
		$this->assertNotFalse( $at, 'the heading row moved' );
		$this->assertStringContainsString( "'הערות'", substr( $src, $at, 200 ) );
	}

	// ── The seams ────────────────────────────────────────────────────────────

	/**
	 * A page with a form of its own renders it; every other page does not.
	 *
	 * @return void
	 */
	public function test_the_page_picks_the_form_it_was_given(): void {
		$tpl = (string) file_get_contents( KIVUN_DIR . 'templates/landing/single.php' );

		$this->assertStringContainsString( 'Kivun_Custom_Forms::enabled( (int) $landing_id )', $tpl );
		$this->assertStringContainsString( "'landing/custom-form.php'", $tpl );
		$this->assertStringContainsString( "'courses/interest-form.php'", $tpl, 'the fixed form is no longer reachable' );
	}

	/**
	 * A question can never land in a built-in field.
	 *
	 * The answers are posted under their own prefix, so a question keyed
	 * "name" cannot overwrite the visitor's name — and the handler can tell
	 * the two apart without guessing.
	 *
	 * @return void
	 */
	public function test_an_answer_cannot_overwrite_a_built_in_field(): void {
		$tpl = (string) file_get_contents( KIVUN_DIR . 'templates/landing/custom-form.php' );
		$this->assertStringContainsString( "'kivun_q[' . \$kivun_field['key'] . ']'", $tpl );

		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' );
		$this->assertStringContainsString( "\$_POST['kivun_q']", $src );
	}

	/**
	 * The submission is refused before the lead is written.
	 *
	 * A required question noticed after the row exists is a row somebody has
	 * to go and clean up.
	 *
	 * @return void
	 */
	public function test_a_bad_submission_never_becomes_a_lead(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' );

		$check = (int) strpos( $src, 'Kivun_Custom_Forms::collect(' );
		$write = (int) strpos( $src, '$result = self::save_lead(' );

		$this->assertNotFalse( $check, 'the answers are never read' );
		$this->assertLessThan( $write, $check, 'the lead is written before the answers are checked' );
	}

	/**
	 * A copy does not inherit the original's place on the centre's board.
	 *
	 * The remote id names the original over there. Letting the copy carry it
	 * would make the next sync overwrite the original with the copy.
	 *
	 * @return void
	 */
	public function test_a_copy_does_not_steal_the_original_s_remote_id(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$at   = (int) strpos( $src, 'public static function ajax_duplicate_landing' );
		$this->assertNotFalse( $at, 'the handler moved' );
		$body = substr( $src, $at, 2600 );

		$this->assertStringContainsString( 'Kivun_Mercaz_Sync::REMOTE_ID === $key', $body );
		$this->assertStringContainsString( "'post_status'  => 'draft',", $body, 'a copy is published without anybody reading it' );
	}

	/**
	 * The tab is there, and is not offered to the roles that cannot publish.
	 *
	 * @return void
	 */
	public function test_the_tab_is_for_whoever_publishes_content(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( "'forms'   => array(", $src );
		$this->assertStringContainsString( "( 'forms' === \$tab && ( \$leads_only || \$agency ) ) ||", $src, 'an agency can reach the builder by URL' );

		$at = (int) strpos( $src, 'data-panel="forms"' );
		$this->assertNotFalse( $at, 'the panel is missing' );
		$before = substr( $src, max( 0, $at - 300 ), min( 300, $at ) );
		$this->assertStringContainsString( 'if ( ! $leads_only && ! $agency ) :', $before );
	}

	/**
	 * And the built file carries the builder.
	 *
	 * @return void
	 */
	public function test_the_built_file_carries_the_builder(): void {
		$min = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.min.js' );

		$this->assertStringContainsString( 'kivun_save_custom_form', $min );
		$this->assertStringContainsString( 'kivun_duplicate_landing', $min );
	}
}
