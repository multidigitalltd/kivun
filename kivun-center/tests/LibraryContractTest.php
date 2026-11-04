<?php
/**
 * What "התכנים שלי" owes its handoff and the scripts behind it.
 *
 * The list was rebuilt to the "My Content" handoff: a grid of rows rather
 * than a table, seven columns or four depending on the list's own width,
 * and deleting confirmed in the row rather than in a browser dialog. Each
 * of those is a seam where markup and script have to agree, and each fails
 * without a word — a switch that is not drawn, a delete that asks twice or
 * not at all, a layout that never widens.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The library's contracts.
 */
final class LibraryContractTest extends TestCase {

	/**
	 * The plugin's source.
	 *
	 * @return string
	 */
	private function php(): string {
		return (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
	}

	/**
	 * The script's source.
	 *
	 * @return string
	 */
	private function js(): string {
		return (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );
	}

	/**
	 * The live/archive switch is always drawn, an empty archive included.
	 *
	 * It used to appear only once something had been archived, which made
	 * the archive a place nobody knew was there until they had used it.
	 *
	 * @return void
	 */
	public function test_the_archive_switch_is_always_there(): void {
		$src = $this->php();
		$at  = (int) strpos( $src, 'private static function front_library(' );
		$end = (int) strpos( $src, 'private static function library_groups(', $at );
		$fn  = substr( $src, $at, $end - $at );

		$this->assertStringContainsString( 'data-shows="archive"', $fn );
		$this->assertStringNotContainsString( '<?php if ( $put_away ) : ?>', $fn, 'the switch or the archive pane is drawn only when something is archived again' );
		$this->assertStringContainsString( "self::library_table( \$put_away, \$lead_counts, \$page_url, \$can_delete, true );", $fn );
	}

	/**
	 * Each list says which kind of empty it is.
	 *
	 * @return void
	 */
	public function test_an_empty_list_says_so(): void {
		$src = $this->php();
		$this->assertStringContainsString( 'תכנים שתעבירו לארכיון יופיעו כאן', $src );
		$this->assertStringContainsString( 'אין תכנים פעילים', $src );
		$this->assertStringContainsString( 'לא נמצאו תכנים בחיפוש הזה', $src );
		$this->assertStringContainsString( "pane.querySelector('.kivun-lib__empty--base')", $this->js(), 'the search no longer knows the list has its own empty state' );
	}

	/**
	 * Deleting is asked in the row, and asked once.
	 *
	 * The trash mark opens "למחוק? מחיקה / ביטול"; "מחיקה" is the existing
	 * delete form's own button. That form must not also raise the browser's
	 * confirm(), which every other delete form in the console still does —
	 * the reader would be asked twice, the second time in a different voice.
	 *
	 * @return void
	 */
	public function test_deleting_is_asked_in_the_row_and_once(): void {
		$src = $this->php();

		$at  = (int) strpos( $src, '<div class="kivun-lib__confirm" hidden>' );
		$this->assertNotFalse( $at, 'the row has no confirm step' );
		$box = substr( $src, $at, 600 );
		$this->assertStringContainsString( 'self::delete_form( (string) $gid, $page_url, __( \'מחיקה\', \'kivun\' ), \'kivun-lib__yes\' )', $box, 'the confirm step does not submit the existing delete form' );
		$this->assertStringContainsString( 'kivun-lib__no', $box );

		// The trash mark opens the question; it does not submit anything.
		$this->assertMatchesRegularExpression( '/<button type="button" class="kivun-cc-icon-btn kivun-cc-icon-btn--danger kivun-lib__ask"/', $src );

		// Blocks, because they hold forms: a <form> inside a <span> is what
		// wpautop() splits, leaving the confirm buttons outside their row.
		$this->assertStringContainsString( '<div class="kivun-cc-actgroup">', $src );
		$this->assertStringContainsString( '<div class="kivun-lib__acts">', $src );

		$js = $this->js();
		$this->assertStringContainsString( "if (f.closest('.kivun-lib__confirm')) { return; }", $js, 'the row\'s delete form asks a second time through confirm()' );
		$this->assertStringContainsString( 'kivunLibCancel(scope);', $js, 'switching between the lists leaves a row waiting for an answer' );
	}

	/**
	 * The layout follows the list's own width, at the handoff's 1190.
	 *
	 * @return void
	 */
	public function test_the_layout_follows_the_list_width(): void {
		$js = $this->js();
		$this->assertStringContainsString( 'var KIVUN_LIB_WIDE = 1190;', $js );
		// The compact grid's own minimum: 170 + 52 + 150 + 280, three 14px
		// gaps, 40 of padding and 4 of border is 738. Below it the actions
		// were clipped by the list's edge, so the cards take over there.
		$this->assertStringContainsString( 'var KIVUN_LIB_COMPACT = 740;', $js, 'the cards start below the width the compact columns need, so the actions are clipped in between' );
		$this->assertStringContainsString( 'new ResizeObserver(', $js );

		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString( '--lib-cols: minmax(170px, 1fr) 52px 150px 280px;', $css, 'the compact columns are not the handoff\'s' );
		$this->assertStringContainsString( '.kivun-lib.is-wide { --lib-cols: minmax(200px, 1fr) 110px 84px 56px 96px 160px 280px; }', $css, 'the wide columns are not the handoff\'s' );
	}

	/**
	 * The hooks the existing scripts reach for are still on the row.
	 *
	 * @return void
	 */
	public function test_the_existing_hooks_survive(): void {
		$src = $this->php();
		foreach ( array(
			'kivun-archive-content',
			'kivun-mercaz-push',
			'kivun-mercaz-status',
			'kivun-cc-actgroup',
			'data-search="',
			'class="kivun-cc-pane" data-pane="live"',
			'class="kivun-cc-pane" data-pane="archive"',
			'kivun-cc-search__input',
		) as $hook ) {
			$this->assertStringContainsString( $hook, $src, "the list lost {$hook}, which a script binds to" );
		}
		$this->assertStringContainsString( "'.kivun-cc-table tbody tr, .kivun-lib__row'", $this->js(), 'the search no longer walks the library\'s rows' );
	}
}
