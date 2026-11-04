<?php
/**
 * What "לידים והרשמות" owes its handoff and the scripts behind it.
 *
 * The leads were rebuilt to design_handoff_leads: a grid of rows that
 * scrolls sideways at the handoff's column widths, details opened under
 * the row, deleting asked in the row. Each is a seam where the markup, the
 * stylesheet and two scripts have to agree, and each fails quietly — a
 * column put away that leaves its track behind, a delete asked twice, a
 * table turned into cards because it is wider than a laptop on purpose.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The leads screen's contracts.
 */
final class LeadsContractTest extends TestCase {

	/**
	 * The leads screen's own source: front_leads() and the helpers after it.
	 *
	 * @return string
	 */
	private function leads(): string {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$at  = (int) strpos( $src, 'private static function front_leads(' );
		return substr( $src, $at );
	}

	/**
	 * A script's source.
	 *
	 * @param string $name The file under assets/js.
	 * @return string
	 */
	private function js( string $name ): string {
		return (string) file_get_contents( KIVUN_DIR . 'assets/js/' . $name );
	}

	/**
	 * Every column at the handoff's width, in the system's order.
	 *
	 * @return void
	 */
	public function test_the_columns_are_the_handoff_s(): void {
		$src = $this->leads();

		$widths = array(
			"'name'    => array( __( 'שם', 'kivun' ), 120, true, false )",
			"'content' => array( __( 'תוכן', 'kivun' ), 160, true, true )",
			"\$lead_cols['utm'] = array( __( 'מקור (UTM)', 'kivun' ), 150, false, true )",
			"'contact' => array( __( 'יצירת קשר', 'kivun' ), 180, false, true )",
			"'city'    => array( __( 'עיר', 'kivun' ), 84, false, true )",
			"'gender'  => array( __( 'מגדר', 'kivun' ), 64, false, true )",
			"'handler' => array( __( 'רכז/ת מטפל/ת', 'kivun' ), 150, false, true )",
			"'consent' => array( __( 'דיוור', 'kivun' ), 56, false, true )",
			"'privacy' => array( __( 'פרטיות', 'kivun' ), 60, false, true )",
			"'message' => array( __( 'פרטי הפנייה', 'kivun' ), 170, false, true )",
			"'notes'   => array( __( 'הערות פנימיות', 'kivun' ), 190, false, true )",
			"'date'    => array( __( 'תאריך', 'kivun' ), 92, false, true )",
			"'status'  => array( __( 'סטטוס', 'kivun' ), 124, false, true )",
			"\$lead_cols['delete'] = array( __( 'מחיקה', 'kivun' ), 52, false, false )",
		);

		$last = 0;
		foreach ( $widths as $width ) {
			$at = strpos( $src, $width );
			$this->assertNotFalse( $at, "a column is not at the handoff's width: {$width}" );
			$this->assertGreaterThan( $last, $at, "the columns are out of the system's order at {$width}" );
			$last = (int) $at;
		}

		// The minimum the table scrolls at: the widths, 12px gaps, 20px a side.
		$this->assertStringContainsString( '$lead_min    = 40 + 12 * ( count( $lead_cols ) - 1 );', $src );
	}

	/**
	 * Every heading names its column, so putting a column away takes the
	 * heading with it — the date and status headings used to carry no
	 * name, and hiding either left the row one cell short of its header.
	 *
	 * @return void
	 */
	public function test_every_heading_names_its_column(): void {
		$src = $this->leads();
		$this->assertStringContainsString( '<th scope="col" data-col="<?php echo esc_attr( $kivun_col ); ?>" data-w=', $src );
		$this->assertStringNotContainsString( '<th scope="col"><?php', $src, 'a heading without data-col stays when its cells are put away' );

		foreach ( array( 'name', 'content', 'utm', 'contact', 'city', 'gender', 'handler', 'consent', 'privacy', 'message', 'notes', 'date', 'status', 'delete' ) as $col ) {
			$this->assertStringContainsString( 'data-col="' . $col . '"', $src, "no cell is drawn for {$col}" );
		}
	}

	/**
	 * A column put away leaves the grid, not just the row.
	 *
	 * @return void
	 */
	public function test_the_grid_follows_the_columns_showing(): void {
		$crm = $this->js( 'admin-crm.js' );

		$this->assertStringContainsString( 'function layLeadColumns(table)', $crm );
		$this->assertStringContainsString( "if (table.classList.contains('kivun-cc-leadtable')) { layLeadColumns(table); }", $crm, 'putting a column away no longer rebuilds the grid, so every cell after it slides a track over' );
		$this->assertStringContainsString( 'var LEAD_GAP = 12;', $crm );
		$this->assertStringContainsString( 'var LEAD_PAD = 40;', $crm );

		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString( 'grid-template-columns: var(--lead-cols);', $css );
		$this->assertStringContainsString( 'min-width: var(--lead-min, 1940px);', $css );
	}

	/**
	 * The leads table is not measured into cards.
	 *
	 * Fourteen columns are wider than any laptop by design, so the overflow
	 * test every other console table takes would always answer "cards".
	 *
	 * @return void
	 */
	public function test_the_table_scrolls_rather_than_stacking(): void {
		$crm = $this->js( 'admin-crm.js' );
		$this->assertStringContainsString(
			"stacked = choice ? (choice === 'cards') : (table.parentNode.clientWidth < LEADS_CARDS_BELOW);",
			$crm,
			'the leads are measured again, and a table wider than its card on purpose is turned into cards'
		);
		$this->assertStringContainsString( 'var LEADS_CARDS_BELOW = 640;', $crm );
	}

	/**
	 * Deleting is asked in the row, and asked once.
	 *
	 * @return void
	 */
	public function test_deleting_is_asked_in_the_row_and_once(): void {
		$src = $this->leads();

		$at = strpos( $src, '<div class="kivun-lead__confirm" hidden>' );
		$this->assertNotFalse( $at, 'the row has no confirm step' );
		$box = substr( (string) $src, (int) $at, 500 );
		$this->assertStringContainsString( 'class="kivun-delete-row kivun-lead__yes" data-table="registrations"', $box, '"מחיקה" is not the button the delete handler binds to' );
		$this->assertStringContainsString( 'kivun-lead__no', $box );

		// The mark only opens the question.
		$this->assertMatchesRegularExpression( '/<button type="button" class="kivun-lead__ask" aria-expanded="false" aria-label=/', $src );

		$crm = $this->js( 'admin-crm.js' );
		$this->assertStringContainsString( "if (!btn.closest('.kivun-lead__confirm') && !window.confirm(", $crm, 'the row\'s "מחיקה" asks a second time through confirm()' );
		$this->assertStringContainsString( "if (more && more.classList.contains('kivun-lead-more')) { more.parentNode.removeChild(more); }", $crm, 'a deleted lead leaves its details row behind' );
	}

	/**
	 * One lead's details open at a time, each under its own row.
	 *
	 * @return void
	 */
	public function test_details_open_one_at_a_time(): void {
		$src = $this->leads();
		$this->assertStringContainsString( '<tr class="kivun-lead-more" id="<?php echo esc_attr( $row_more ); ?>" hidden>', $src );
		$this->assertStringContainsString( 'aria-controls="<?php echo esc_attr( $row_more ); ?>"', $src );
		// A cell spanning the row, so the labeller leaves it alone.
		$this->assertStringContainsString( '<td colspan="<?php echo esc_attr( (string) count( $lead_cols ) ); ?>">', $src );

		$js = $this->js( 'frontend.js' );
		$this->assertStringContainsString( 'kivunLeadClose(table, btn);', $js, 'opening a lead\'s details no longer closes the one already open' );
	}

	/**
	 * The markup survives wpautop(): no paragraph of ours goes without a
	 * class, and no block sits inside an inline element.
	 *
	 * @return void
	 */
	public function test_the_screen_survives_wpautop(): void {
		$src = $this->leads();
		$end = (int) strpos( $src, 'public static function lead_answers(' );
		$fn  = substr( $src, 0, $end );

		$this->assertStringNotContainsString( '<p>', $fn, 'a classless paragraph of ours would be flattened away with wpautop()\'s' );
		$this->assertStringNotContainsString( '<br>', $fn );
		$this->assertStringNotContainsString( '<span class="kivun-cc-daterange">', $fn, 'the date range is inline again, and wpautop() splits it' );
		$this->assertStringNotContainsString( '<p class="kivun-error kivun-viewer-error"', $fn, 'an empty paragraph is left unclosed by wpautop()' );

		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertMatchesRegularExpression( '/\.kivun-leads p:not\(\[class\]\),\s*\.kivun-cc-wizard p:not\(\[class\]\)/', $css );
	}

	/**
	 * A lead's answers come apart into label and value.
	 *
	 * @return void
	 */
	public function test_a_lead_s_answers_come_apart(): void {
		$this->assertSame(
			array(
				array( '', "שלום\nאשמח לפרטים" ),
				array( 'רמת אנגלית', 'בסיסית' ),
				array( 'field_6ff8c9b', 'on' ),
			),
			Kivun_Content_Creator::lead_answers( "שלום\nאשמח לפרטים\nרמת אנגלית: בסיסית\r\nfield_6ff8c9b: on\n" )
		);
		$this->assertSame( array(), Kivun_Content_Creator::lead_answers( " \n " ) );

		// A textarea's own lines stay with the answer they belong to.
		$this->assertSame(
			array(
				array( 'ניסיון', "עבודה ראשונה\nעבודה שנייה" ),
				array( 'עיר', 'ירושלים' ),
			),
			Kivun_Content_Creator::lead_answers( "ניסיון: עבודה ראשונה\nעבודה שנייה\nעיר: ירושלים" )
		);
	}

	/**
	 * A multi-line answer keeps its lines when the details are opened.
	 *
	 * The screen hides every <br> (wpautop()'s), and the output is flattened
	 * onto one line, so each line is its own block instead.
	 *
	 * @return void
	 */
	public function test_an_answer_keeps_its_lines_in_the_details(): void {
		$src = $this->leads();
		$this->assertStringNotContainsString( 'nl2br(', $src, 'the details break lines with <br>, which this screen hides' );
		$this->assertStringContainsString( '<span class="kivun-lead-more__line">', $src );
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString( '.kivun-cc-console .kivun-leads .kivun-lead-more__line { display: block; }', $css );
	}

	/**
	 * Each source takes its dot.
	 *
	 * @return void
	 */
	public function test_each_source_takes_its_colour(): void {
		$this->assertSame( 'whatsapp', Kivun_Content_Creator::lead_source_tone( 'WhatsApp' ) );
		$this->assertSame( 'facebook', Kivun_Content_Creator::lead_source_tone( 'fb' ) );
		$this->assertSame( 'facebook', Kivun_Content_Creator::lead_source_tone( 'instagram_story' ) );
		$this->assertSame( 'google', Kivun_Content_Creator::lead_source_tone( 'google_ads' ) );
		$this->assertSame( 'other', Kivun_Content_Creator::lead_source_tone( 'newsletter' ) );
	}

	/**
	 * An untouched lead reads "חדש" here, and only here.
	 *
	 * @return void
	 */
	public function test_an_untouched_lead_reads_new_on_this_screen(): void {
		kivun_test_set_caps( array( 'edit_posts' ) );
		$html = Kivun_Admin::status_select( 'registrations', 7, 'new_lead', Kivun_Admin::reg_statuses(), 'חדש' );
		$this->assertStringContainsString( "<option value=\"\" selected='selected'>חדש</option>", $html );

		$this->assertStringContainsString(
			"Kivun_Admin::status_select( 'registrations', (int) \$r->id, (string) \$r->status, \$statuses, __( 'חדש', 'kivun' ) )",
			$this->leads()
		);
	}

	/**
	 * The mic stays on the search, as the handoff has it.
	 *
	 * @return void
	 */
	public function test_the_mic_is_on_the_search_only(): void {
		$this->assertStringContainsString( "field.closest('.kivun-pick, .kivun-leads-access')", $this->js( 'voice.js' ) );
	}

	/**
	 * The other list screens speak the same language.
	 *
	 * Campaigns, tracking numbers and the jobs board had no handoff of their
	 * own, so they take the leads': one white card, fields as pills with the
	 * mic inside, the field-grey table header, 38 discs for the marks.
	 *
	 * @return void
	 */
	public function test_the_other_list_screens_share_the_leads_language(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		foreach ( array( 'campaigns', 'calls', 'jobs' ) as $tab ) {
			$this->assertStringContainsString(
				'<section class="kivun-tab-panel kivun-tab-panel--list <?php echo \'' . $tab . '\' === $tab',
				$src,
				"the {$tab} tab no longer carries the list screens' scope, so it falls back to the old look"
			);
		}

		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertMatchesRegularExpression( '/\.kivun-cc-console > \.kivun-tab-panel--list\.is-active \{\s*padding: 32px;\s*background: var\(--cc-surface\);\s*border: 0;\s*border-radius: var\(--cc-radius\);/', $css );
		// The mic keeps its spot under its field only in block flow.
		$this->assertStringContainsString( '.kivun-cc-console .kivun-tab-panel--list :is(.kivun-form-row, .kivun-field) { display: block;', $css, 'the form rows are a flex column again, and every mic floats to the top of its row' );
		$this->assertStringContainsString( 'margin-inline-start: -51px;', $css, 'the filter search mic no longer sits inside its field' );
	}

	/**
	 * The call log loads more in place instead of paging away.
	 *
	 * Each page reloaded the console and the log came back closed. "טען עוד"
	 * adds the next page's rows under the ones shown; with no script it is
	 * still the link to that page, which now arrives open.
	 *
	 * @return void
	 */
	public function test_the_call_log_loads_more_in_place(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString( '<details class="kivun-cc-card kivun-camp-block kivun-calls-log" <?php echo ( $filtering || $call_paged > 1 ) ? \'open\' : \'\'; ?>>', $src, 'a later page of the log arrives closed again' );
		$this->assertStringContainsString( 'class="kivun-cc-btn kivun-cc-btn--sm kivun-calls-more__btn" href="<?php echo esc_url( $call_page_link( $call_paged + 1 ) ); ?>"', $src, '"טען עוד" is no longer a real link to the next page, so it does nothing without the script' );
		$this->assertStringContainsString( '<table class="kivun-cc-table kivun-calls-table">', $src );

		$js = $this->js( 'frontend.js' );
		$this->assertStringContainsString( "e.target.closest('.kivun-calls-more__btn')", $js );
		$this->assertStringContainsString( "body.appendChild(document.importNode(row, true));", $js, 'the next page\'s rows are not added to the log' );
		$this->assertStringContainsString( 'window.location.assign(href);', $js, 'a failed fetch leaves the button doing nothing instead of following its link' );
		$this->assertStringContainsString( 'delete table.dataset.labelled;', $js, 'the added rows go without their column names on a phone' );
	}

	/**
	 * The site theme's table striping does not reach the console.
	 *
	 * Hello Elementor greys each cell of an odd body row. The leads alternate
	 * a row with its hidden details row, so every lead is odd, and each cell
	 * came out as a grey box — reported from the live site.
	 *
	 * @return void
	 */
	public function test_the_theme_does_not_grey_the_cells(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString(
			'.kivun-cc-console :is(.kivun-cc-table, .kivun-jobs-table, .kivun-apps-table) tbody > tr > :is(td, th) { background-color: transparent; }',
			$css,
			'the theme\'s odd-row grey paints every lead cell again'
		);
	}
}
