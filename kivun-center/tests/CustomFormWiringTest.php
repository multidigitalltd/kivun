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
	 * The key is the question's, which is what the columns line up by: a
	 * label is only what a reader sees.
	 *
	 * @param array<string,string> $pairs Label => value; the key is derived.
	 * @return string
	 */
	private function stored( array $pairs ): string {
		$out = array();
		$i   = 0;
		foreach ( $pairs as $label => $value ) {
			++$i;
			$out[] = array( 'key' => 'q' . $i, 'label' => $label, 'value' => $value );
		}
		return (string) wp_json_encode( $out );
	}

	/**
	 * One row of answers with the keys spelled out.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $triples key, label, value.
	 * @return string
	 */
	private function stored_keyed( array $triples ): string {
		$out = array();
		foreach ( $triples as $t ) {
			$out[] = array( 'key' => $t[0], 'label' => $t[1], 'value' => $t[2] );
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

		$this->assertSame( array( 'q1' => 'מסלול', 'q2' => 'ניסיון' ), $this->export( 'question_columns', array( $rows ) ) );
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

		$this->assertSame( array( 'q1' => 'מסלול', 'q2' => 'תקציב' ), $this->export( 'question_columns', array( $rows ) ) );
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
		$stored = $this->stored_keyed(
			array(
				array( 'a', 'פרטים נוספים', 'ראשונה' ),
				array( 'b', 'פרטים נוספים', 'שנייה' ),
			)
		);

		$columns = $this->export( 'question_columns', array( array( array( 'answers' => $stored ) ) ) );
		$this->assertSame( array( 'a' => 'פרטים נוספים', 'b' => 'פרטים נוספים (2)' ), $columns );
		$this->assertSame( array( 'ראשונה', 'שנייה' ), $this->export( 'answer_cells', array( $stored, $columns ) ) );
	}

	/**
	 * And a lead who answered only the second stays under the second.
	 *
	 * An unanswered question is simply absent from a row, so a counter over
	 * the answers present numbered this lead's only answer as the first —
	 * putting it under the other question's column and mixing two questions
	 * together down the sheet. Found in review, as the consequence of
	 * numbering answers instead of identifying questions.
	 *
	 * @return void
	 */
	public function test_answering_only_the_second_stays_under_the_second(): void {
		$both = $this->stored_keyed(
			array(
				array( 'a', 'פרטים נוספים', 'ראשונה' ),
				array( 'b', 'פרטים נוספים', 'שנייה' ),
			)
		);
		$only = $this->stored_keyed( array( array( 'b', 'פרטים נוספים', 'רק השנייה' ) ) );

		$columns = $this->export( 'question_columns', array( array( array( 'answers' => $both ), array( 'answers' => $only ) ) ) );

		$this->assertSame( array( '', 'רק השנייה' ), $this->export( 'answer_cells', array( $only, $columns ) ) );
		$this->assertSame( array( 'ראשונה', 'שנייה' ), $this->export( 'answer_cells', array( $both, $columns ) ) );
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

		$this->assertSame( array( 'q1' => 'מסלול', 'q2' => 'ניסיון' ), $columns );
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
		$columns = array( 'k1' => 'מסלול', 'k2' => 'תקציב', 'k3' => 'ניסיון' );
		$cells   = $this->export( 'answer_cells', array( $this->stored_keyed( array( array( 'k3', 'ניסיון', 'ללא' ) ) ), $columns ) );

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

		$this->assertSame( array( 'q1' => 'מסלול' ), $this->export( 'question_columns', array( $rows ) ) );
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
		$this->assertSame( array( 'q1' => '=1+1' ), $columns, 'the lookup label was escaped too' );
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

		// Anchored on the heading row itself rather than on two headings
		// happening to sit next to each other: the first version of this
		// test named the pair 'אישור דיוור', 'סוג' and broke the moment a
		// column was added between them, which is a change it had no business
		// objecting to.
		$at = (int) strpos( $src, 'array_merge( $headings, array( \'שם\'' );
		$this->assertNotFalse( $at, 'the heading row moved' );

		$row = substr( $src, $at, 400 );
		$this->assertStringContainsString( "'הערות'", $row, 'the prose column is gone from the heading row' );
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
	 * Nobody names the fixed form but the one place allowed to.
	 *
	 * A page can be drawn from more than one template, and one of them
	 * showing the fixed form while the server validates answers it never
	 * offered would refuse every submission with nothing the visitor could
	 * do. That happened once, in review.
	 *
	 * Written as a search rather than a list. The first version named three
	 * files; one of them has since been deleted and another was added, and a
	 * list is wrong the moment either happens. What holds is the rule: the
	 * template's name may appear only inside the function whose job is to
	 * choose it.
	 *
	 * @return void
	 */
	public function test_nobody_names_the_fixed_form_but_the_chooser(): void {
		$allowed = 'includes/class-kivun-custom-forms.php';
		$looked  = 0;

		foreach ( array( 'templates', 'includes', 'shortcodes', 'elementor' ) as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( KIVUN_DIR . $dir ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$path = str_replace( KIVUN_DIR, '', $file->getPathname() );
				if ( $allowed === $path ) {
					continue;
				}

				++$looked;
				$this->assertStringNotContainsString(
					"'courses/interest-form.php'",
					(string) file_get_contents( $file->getPathname() ),
					$path . ' names the fixed form itself instead of asking which form the page has'
				);
			}
		}

		$this->assertGreaterThan( 20, $looked, 'the search found almost nothing; the directories moved' );
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
	 * The one duplication — the library's — knows what a copy must not inherit.
	 *
	 * The custom pages' tab once had a copy loop of its own, and review found
	 * three things missing from it one at a time. That tab is gone; copying a
	 * page, its form included, is the library's duplicate, which copies every
	 * meta key but these.
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
	 * The form is built in the wizard, and the old tab is gone.
	 *
	 * It is part of creating the content now: a type section for the landing
	 * page, saved by the same post as the title and the text. A bookmark to
	 * the old tab lands on the library, where the pages are listed.
	 *
	 * @return void
	 */
	public function test_the_form_is_built_in_the_wizard(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringNotContainsString( 'data-panel="forms"', $src, 'the separate tab is back' );
		$this->assertStringNotContainsString( "'forms'   => array(", $src, 'the separate tab is back in the bar' );
		$this->assertStringContainsString(
			"if ( 'forms' === \$tab && ! \$leads_only && ! \$agency ) {\n\t\t\t\$tab = 'library';",
			$src,
			'a bookmark to the old tab no longer lands on the library'
		);

		$at = strpos( $src, 'kivun-cc-formcard' );
		$this->assertNotFalse( $at, 'the wizard has no form card' );
		$this->assertStringContainsString( 'data-type="landing"', substr( $src, (int) $at, 200 ), 'the form card is not tied to the landing page type' );
		$this->assertStringContainsString( 'self::custom_form_builder( $kivun_landing_id );', $src );

		// Inside the wizard's form, so a div: a nested form is never submitted.
		$at   = (int) strpos( $src, 'private static function custom_form_builder' );
		$body = substr( $src, $at, 1600 );
		$this->assertStringContainsString( '<div class="kivun-form-builder"', $body );
		$this->assertStringContainsString( 'name="custom_form_fields"', $body );
		$this->assertStringContainsString( 'name="custom_form_builtins[]"', $body );
		$this->assertStringNotContainsString( '<form', $body );

		// And the save stores it with the landing page it belongs to.
		$this->assertStringContainsString( 'self::save_landing_form( (int) $landing_id );', $src );
	}

	/**
	 * The save: on stores the form, off goes back to the fixed one, absent
	 * leaves it alone.
	 *
	 * @return void
	 */
	public function test_the_wizard_saves_the_page_s_form(): void {
		$save = new ReflectionMethod( Kivun_Content_Creator::class, 'save_landing_form' );
		$save->setAccessible( true );

		$_POST = array(
			'custom_form_present'  => '1',
			'custom_form_on'       => '1',
			'custom_form_fields'   => wp_slash(
				wp_json_encode(
					array(
						array(
							'label'    => 'באיזו שכבת גיל?',
							'type'     => 'text',
							'required' => true,
							'options'  => '',
						),
					)
				)
			),
			'custom_form_builtins' => array( 'email', 'city', 'not-a-field' ),
		);
		$save->invoke( null, 21 );

		$this->assertTrue( Kivun_Custom_Forms::enabled( 21 ) );
		$fields = Kivun_Custom_Forms::fields( 21 );
		$this->assertCount( 1, $fields );
		$this->assertSame( 'באיזו שכבת גיל?', $fields[0]['label'], 'the Hebrew label did not survive the trip' );
		$this->assertTrue( $fields[0]['required'] );
		$this->assertSame( array( 'email', 'city' ), Kivun_Custom_Forms::builtins( 21 ) );

		// A save from a form without the builder — wp-admin's — changes nothing.
		$_POST = array( 'custom_form_fields' => '[]' );
		$save->invoke( null, 21 );
		$this->assertTrue( Kivun_Custom_Forms::enabled( 21 ), 'a save without the builder switched the form off' );
		$this->assertCount( 1, Kivun_Custom_Forms::fields( 21 ) );

		// Switched off: the fixed form again, the questions kept for later.
		$_POST = array( 'custom_form_present' => '1' );
		$save->invoke( null, 21 );
		$this->assertFalse( Kivun_Custom_Forms::enabled( 21 ) );
		$this->assertCount( 1, Kivun_Custom_Forms::fields( 21 ), 'switching off threw the questions away' );

		$_POST = array();
	}

	/**
	 * And the built file carries the builder's link to the wizard.
	 *
	 * @return void
	 */
	public function test_the_built_file_carries_the_builder(): void {
		$min = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.min.js' );

		$this->assertStringContainsString( 'kivun-cf-out', $min, 'the built script no longer writes the questions into the form' );
		$this->assertStringContainsString( 'kivun-cf-switch', $min );
	}
}
