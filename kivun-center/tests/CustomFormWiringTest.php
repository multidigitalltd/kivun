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
	 * Two questions worded alike keep two answers.
	 *
	 * A form may ask "פרטים נוספים" twice, or carry a duplicated question
	 * nobody renamed. They are separate questions with separate answers, and
	 * keying the columns by the label alone kept only the last — the
	 * spreadsheet showed one answer where the lead gave two, silently. Found
	 * in review.
	 *
	 * @return void
	 */
	public function test_two_questions_worded_alike_keep_both_answers(): void {
		$stored = (string) wp_json_encode(
			array(
				array( 'label' => 'פרטים נוספים', 'value' => 'ראשונה' ),
				array( 'label' => 'פרטים נוספים', 'value' => 'שנייה' ),
			)
		);

		$columns = $this->export( 'question_columns', array( array( array( 'answers' => $stored ) ) ) );
		$this->assertSame( array( 'פרטים נוספים', 'פרטים נוספים (2)' ), $columns );
		$this->assertSame( array( 'ראשונה', 'שנייה' ), $this->export( 'answer_cells', array( $stored, $columns ) ) );
	}

	/**
	 * And an ordinary form gains no numbering it did not ask for.
	 *
	 * @return void
	 */
	public function test_an_ordinary_form_is_not_numbered(): void {
		$columns = $this->export(
			'question_columns',
			array( array( array( 'answers' => $this->stored( array( 'מסלול' => 'חשמל', 'ניסיון' => 'ללא' ) ) ) ) )
		);

		$this->assertSame( array( 'מסלול', 'ניסיון' ), $columns );
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
	 * A question's heading cannot carry a formula into a spreadsheet.
	 *
	 * Headings were always words of ours until one of them became whatever
	 * the client typed into the builder. A label beginning = + - or @ is a
	 * formula as far as Excel is concerned, and sanitize_text_field() does
	 * not touch it. Found in review.
	 *
	 * @return void
	 */
	public function test_a_question_heading_cannot_become_a_formula(): void {
		$safe = new ReflectionMethod( Kivun_Export::class, 'csv_safe' );
		$safe->setAccessible( true );

		foreach ( array( '=1+1', '+SUM(A1)', '-2', '@cmd' ) as $nasty ) {
			$this->assertNotSame( $nasty, $safe->invoke( null, $nasty ), $nasty . ' goes into the sheet as written' );
		}

		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );
		$at  = (int) strpos( $src, '$question_columns = self::question_columns( $rows );' );
		$this->assertNotFalse( $at, 'the question columns moved' );

		$this->assertStringContainsString(
			"fputcsv( \$out, array_map( array( __CLASS__, 'csv_safe' ), \$headings ) );",
			substr( $src, $at, 900 ),
			'the heading row is written without escaping'
		);
	}

	/**
	 * And the lookup still uses the label as the client wrote it.
	 *
	 * Escaping the heading must not change what the answers are matched by,
	 * or every cell under an escaped heading would come out empty.
	 *
	 * @return void
	 */
	public function test_escaping_a_heading_does_not_break_its_column(): void {
		$rows = array( array( 'answers' => $this->stored( array( '=1+1' => 'כן' ) ) ) );

		$columns = $this->export( 'question_columns', array( $rows ) );
		$this->assertSame( array( '=1+1' ), $columns, 'the lookup label was escaped too' );
		$this->assertSame( array( 'כן' ), $this->export( 'answer_cells', array( $rows[0]['answers'], $columns ) ) );
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
		$this->assertSame( 'courses/interest-form.php', Kivun_Custom_Forms::template( 404 ) );

		Kivun_Custom_Forms::save( 9, array( array( 'label' => 'שאלה', 'type' => 'text' ) ), array() );
		$this->assertSame( 'landing/custom-form.php', Kivun_Custom_Forms::template( 9 ) );
	}

	/**
	 * And every place that draws a form asks the same question.
	 *
	 * A landing page is rendered from more than one template — its own, and
	 * the workshop single the shortcode uses. One of them showing the fixed
	 * form while the server validates answers it never offered would refuse
	 * every submission, with nothing the visitor could do. Found in review.
	 *
	 * @return void
	 */
	public function test_no_render_site_can_drift_from_the_validation(): void {
		$sites = array(
			'templates/landing/single.php',
			'templates/workshops/single.php',
			'shortcodes/class-kivun-shortcodes.php',
		);

		foreach ( $sites as $site ) {
			$src = (string) file_get_contents( KIVUN_DIR . $site );
			$this->assertStringContainsString(
				'Kivun_Custom_Forms::template(',
				$src,
				$site . ' picks a form without asking which one the page has'
			);
			$this->assertStringNotContainsString(
				"kivun_get_template(\n\t\t\t'courses/interest-form.php'",
				$src,
				$site . ' still hard-codes the fixed form'
			);
		}
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
	 * Duplication goes through the console's own, not a second copy of it.
	 *
	 * The handler first grew a copy loop of its own, and review found three
	 * things missing from it one at a time: the content group, which would
	 * have collapsed the two pages into one library row; the centre's record
	 * id, which would have made the next sync overwrite the original with
	 * the copy; and the archive mark, which would have born the new draft
	 * inside the shut section nobody is looking in. The fourth would have
	 * been found the same way.
	 *
	 * So there is one duplication, and it is the one that already knew.
	 *
	 * @return void
	 */
	public function test_duplication_reuses_the_console_s_own(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$at   = (int) strpos( $src, 'public static function ajax_duplicate_landing' );
		$this->assertNotFalse( $at, 'the handler moved' );
		$body = substr( $src, $at, 2200 );

		$this->assertStringContainsString( 'self::clone_post( $post_id, wp_generate_uuid4() )', $body );
		$this->assertStringNotContainsString( 'add_post_meta(', $body, 'the handler is copying meta by hand again' );
	}

	/**
	 * And that one knows everything a copy must not inherit.
	 *
	 * @return void
	 */
	public function test_the_clone_withholds_what_identifies_the_original(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$at   = (int) strpos( $src, 'private static function clone_post' );
		$this->assertNotFalse( $at, 'the clone moved' );
		$body = substr( $src, $at, 2000 );

		foreach ( array(
			'self::GROUP_META,',
			'self::ARCHIVED_META,',
			'Kivun_Mercaz_Sync::REMOTE_ID,',
			'Kivun_Mercaz_Sync::SYNCED_AT,',
			'Kivun_Mercaz_Sync::LAST_ERROR,',
		) as $withheld ) {
			$this->assertStringContainsString( $withheld, $body, 'a copy would inherit ' . $withheld );
		}
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
