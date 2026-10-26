<?php
/**
 * Correcting a tracking number's period.
 *
 * A period's dates are set from a media plan and change once the real run is
 * known. Until now the only thing offered on a finished period was the bin,
 * and deleting it takes its calls with it — so correcting a date meant losing
 * the history that made the number worth tracking.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The edit path, and what it does to the calls.
 */
final class AssignmentEditTest extends TestCase {

	/**
	 * The handler's source, read once per test.
	 *
	 * @return string
	 */
	private function handler(): string {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-phones.php' );
		$at  = (int) strpos( $src, 'public static function ajax_save_assignment' );
		$this->assertNotFalse( $at, 'the handler moved' );

		$end = (int) strpos( $src, 'public static function ', $at + 40 );
		return substr( $src, $at, $end - $at );
	}

	/**
	 * One handler, two jobs: an id corrects, no id opens a new period.
	 *
	 * @return void
	 */
	public function test_an_id_corrects_instead_of_opening_a_new_period(): void {
		$body = $this->handler();

		$this->assertStringContainsString( "\$assignment_id = absint( wp_unslash( \$_POST['id'] ?? 0 ) );", $body );
		$this->assertStringContainsString( 'if ( $assignment_id ) {', $body );
		$this->assertStringContainsString( '$wpdb->update(', $body, 'an id still inserts a second period' );
		$this->assertStringContainsString( '$wpdb->insert(', $body, 'a new period can no longer be opened' );
	}

	/**
	 * A period does not overlap itself.
	 *
	 * Every period covers its own dates, so without excluding the row being
	 * edited the clash check would refuse every edit — including one that
	 * only changes the medium.
	 *
	 * @return void
	 */
	public function test_the_row_being_edited_is_not_its_own_clash(): void {
		$body = $this->handler();

		$at = (int) strpos( $body, 'SELECT id FROM' );
		$this->assertNotFalse( $at, 'the clash check moved' );

		$query = substr( $body, $at, 420 );
		$this->assertStringContainsString( 'id <> %d', $query, 'an edit clashes with the period it is editing' );
	}

	/**
	 * A call that fell out of the period stops being credited to it.
	 *
	 * Shortening a period leaves calls behind that happened outside it. A
	 * report that counts a call against a week it did not happen in is worse
	 * than one that counts it against nobody.
	 *
	 * @return void
	 */
	public function test_calls_that_left_the_period_are_released(): void {
		$body = $this->handler();

		$this->assertStringContainsString( 'SET assignment_id = 0', $body, 'calls stay on a period that no longer covers them' );
		$this->assertStringContainsString( 'DATE( started_at ) < %s OR DATE( started_at ) > %s', $body );
	}

	/**
	 * And a call that fell into it is picked up.
	 *
	 * The crediting pass already existed for a new period; widening an
	 * existing one has to run it too, so it sits after both branches rather
	 * than inside the insert.
	 *
	 * @return void
	 */
	public function test_calls_that_entered_the_period_are_credited(): void {
		$body = $this->handler();

		$credit = (int) strpos( $body, 'WHERE number_id = %d AND assignment_id = 0' );
		$this->assertNotFalse( $credit, 'the crediting pass moved' );

		$branch = (int) strpos( $body, 'if ( $assignment_id ) {' );
		$close  = (int) strpos( $body, '$assignment_id = (int) $wpdb->insert_id;' );
		$this->assertGreaterThan( $close, $credit, 'widening a period no longer picks up its calls' );
		$this->assertGreaterThan( $branch, $credit );
	}

	/**
	 * An id from elsewhere cannot move somebody else's period.
	 *
	 * @return void
	 */
	public function test_the_update_is_scoped_to_this_number(): void {
		$body = $this->handler();

		$at = (int) strpos( $body, '$wpdb->update(' );
		$this->assertNotFalse( $at );

		$call = substr( $body, $at, 420 );
		$this->assertStringContainsString( "'number_id' => \$number_id,", $call, 'the update is not scoped to the number it came from' );
	}

	/**
	 * The row offers the edit, beside the bin.
	 *
	 * @return void
	 */
	public function test_the_row_offers_an_edit(): void {
		$tpl = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( 'kivun-edit-assignment', $tpl );
		$this->assertStringContainsString( 'data-assignment-edit="<?php echo esc_attr( $row->id ); ?>"', $tpl );
		$this->assertStringContainsString( 'kivun-assignment-edit', $tpl, 'the panel carries no form' );
	}

	/**
	 * The panel spans the table, answered columns or not.
	 *
	 * The calls table grows a "נענו" column on a switchboard that reports
	 * answers, so a fixed colspan would tear the row on half the sites.
	 *
	 * @return void
	 */
	public function test_the_panel_spans_whatever_the_table_has(): void {
		$tpl = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			"colspan=\"<?php echo esc_attr( \$report['knows'] ? '6' : '5' ); ?>\"",
			$tpl,
			'the edit row does not follow the answered column'
		);
	}

	/**
	 * And the built file carries the wiring.
	 *
	 * @return void
	 */
	public function test_the_built_file_carries_it(): void {
		$min = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.min.js' );

		$this->assertStringContainsString( 'kivun-edit-assignment', $min );
		$this->assertStringContainsString( 'data-assignment-edit', $min );
	}
}
