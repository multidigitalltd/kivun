<?php
/**
 * The reports that come out as a spreadsheet.
 *
 * Three questions get asked of this system and only one of them had an
 * answer: who enquired. "Which campaign brought them" and "what did each
 * tracking number bring" had no export at all, and the one that existed
 * handed over every lead ever recorded, leaving the cutting to whoever
 * opened the file.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-export.php';

/**
 * Tests for the exports' shape and the cuts they take.
 */
final class ReportExportTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	// ── A date is a date ──────────────────────────────────────────────────────

	/**
	 * Only a real Y-m-d is accepted as one.
	 *
	 * The value goes into a query, and anything that is not a date is not a
	 * narrower report — it is a report of nothing, or worse.
	 *
	 * @return void
	 */
	public function test_only_a_real_date_is_read_as_one(): void {
		foreach ( array( '2026-10-19' => '2026-10-19', '19/10/2026' => '', 'yesterday' => '', '' => '', "2026-10-19' OR 1=1" => '' ) as $given => $kept ) {
			$_GET = array( 'from' => $given );
			$this->assertSame( $kept, self::range()['from'], "\"{$given}\" was read as a date" );
		}
		$_GET = array();
	}

	/**
	 * The closing day is included whole.
	 *
	 * A month that ends on the 31st means the 31st. Comparing against the
	 * bare date would stop at its midnight and quietly drop a day's leads
	 * from every monthly report.
	 *
	 * @return void
	 */
	public function test_the_last_day_is_included_whole(): void {
		$sql = self::range_sql(
			array(
				'from' => '2026-10-01',
				'to'   => '2026-10-31',
			),
			'r.created_at'
		);

		$this->assertSame( array( '2026-10-01 00:00:00', '2026-10-31 23:59:59' ), $sql['args'] );
		$this->assertSame( ' AND r.created_at >= %s AND r.created_at <= %s', $sql['sql'] );
	}

	/**
	 * No dates asked for, no dates in the query.
	 *
	 * @return void
	 */
	public function test_no_range_adds_no_condition(): void {
		$sql = self::range_sql(
			array(
				'from' => '',
				'to'   => '',
			),
			'r.created_at'
		);

		$this->assertSame( '', $sql['sql'] );
		$this->assertSame( array(), $sql['args'] );
	}

	/**
	 * The file says which period it holds.
	 *
	 * Three monthly reports in a downloads folder are the same file three
	 * times over unless the name separates them.
	 *
	 * @return void
	 */
	public function test_the_file_name_carries_the_period(): void {
		$this->assertSame(
			'2026-10-01_2026-10-31',
			self::range_slug(
				array(
					'from' => '2026-10-01',
					'to'   => '2026-10-31',
				)
			)
		);
		$this->assertSame(
			'all',
			self::range_slug(
				array(
					'from' => '',
					'to'   => '',
				)
			)
		);
	}

	// ── What each report is cut by ────────────────────────────────────────────

	/**
	 * Every cut reaches the query as a placeholder.
	 *
	 * These come from the address bar, and a report is not worth an
	 * injection.
	 *
	 * @return void
	 */
	public function test_every_cut_is_a_placeholder(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );

		foreach ( array( "' AND r.type = %s'", "' AND r.utm_campaign = %s'", "\" AND {\$column} >= %s\"", "\" AND {\$column} <= %s\"" ) as $fragment ) {
			$this->assertStringContainsString( $fragment, $source, 'a report cut is built into the SQL rather than passed to it' );
		}

		$this->assertStringContainsString( '$args ? $wpdb->prepare( $sql, $args ) : $sql', $source );
	}

	/**
	 * A link that brought nothing in the period keeps its row.
	 *
	 * The dates belong in the join, not a WHERE: filtering on the lead's
	 * date in a WHERE drops the link along with it, and a campaign report
	 * that silently omits the links which produced nothing is answering a
	 * different question from the one asked.
	 *
	 * @return void
	 */
	public function test_a_link_with_no_leads_still_appears(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );
		$start  = (int) strpos( $source, 'function link_lead_counts(' );
		$body   = substr( $source, $start, 2000 );

		$this->assertStringContainsString( '){$period}', $body, 'the date filter moved out of the join' );
		$this->assertStringContainsString( 'AND r.created_at >= %s', $body );
	}

	/**
	 * A rate out of no clicks is left blank, not called zero.
	 *
	 * 0% reads as a verdict on the link. Nobody having clicked it is not a
	 * verdict; it is an absence of evidence.
	 *
	 * @return void
	 */
	public function test_a_rate_out_of_nothing_is_blank(): void {
		$this->assertStringContainsString(
			"\$clicks > 0 ? round( \$count / \$clicks * 100, 1 ) . '%' : ''",
			(string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' )
		);
	}

	// ── Who may run them ──────────────────────────────────────────────────────

	/**
	 * Each report is behind the rule of the screen it reports on.
	 *
	 * @return void
	 */
	public function test_each_report_keeps_its_own_rule(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );

		foreach ( array(
			'campaigns' => 'Kivun_Campaigns::can_manage()',
			'numbers'   => 'Kivun_Phones::can_manage()',
		) as $type => $rule ) {
			$start = (int) strpos( $source, "if ( '{$type}' === \$type ) {" );
			$this->assertGreaterThan( 0, $start, "the {$type} report is not routed" );
			$this->assertStringContainsString( $rule, substr( $source, $start, 400 ), "the {$type} report is not behind a permission check" );
		}
	}

	/**
	 * The UTM values are columns of their own.
	 *
	 * They were stored separately all along and exported as one line of
	 * prose, which reads well and cannot be summed, sorted or pivoted —
	 * the three things a spreadsheet is opened for.
	 *
	 * @return void
	 */
	public function test_the_utm_values_each_get_a_column(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );

		$this->assertStringContainsString( "'utm_source', 'utm_medium', 'utm_campaign', 'utm_content'", $source );
		$this->assertStringContainsString( "\$line[] = \$r['utm_campaign'] ?? '';", $source );
	}

	// ── Reaching the private helpers ──────────────────────────────────────────

	/**
	 * date_range() as the class defines it.
	 *
	 * @return array{from:string,to:string}
	 */
	private static function range(): array {
		$method = new \ReflectionMethod( 'Kivun_Export', 'date_range' );
		$method->setAccessible( true );

		return (array) $method->invoke( null );
	}

	/**
	 * range_sql() as the class defines it.
	 *
	 * @param array{from:string,to:string} $range  The range.
	 * @param string                       $column The column.
	 * @return array{sql:string,args:array<int,string>}
	 */
	private static function range_sql( array $range, string $column ): array {
		$method = new \ReflectionMethod( 'Kivun_Export', 'range_sql' );
		$method->setAccessible( true );

		return (array) $method->invoke( null, $range, $column );
	}

	/**
	 * range_slug() as the class defines it.
	 *
	 * @param array{from:string,to:string} $range The range.
	 * @return string
	 */
	private static function range_slug( array $range ): string {
		$method = new \ReflectionMethod( 'Kivun_Export', 'range_slug' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $range );
	}

	// ── Two ways a report can state a falsehood ───────────────────────────────

	/**
	 * Missed calls are reported only where the switchboard says so.
	 *
	 * "Answered" is worked out from the talk time, so on a switchboard that
	 * sends only the start of a call every row carries answered = 0 — and
	 * the report would say every call in the period went unanswered. That
	 * is not a cautious number, it is a wrong one, and the call log already
	 * draws this line. Found in review.
	 *
	 * @return void
	 */
	public function test_missed_calls_are_hidden_when_nobody_knows(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-export.php' );
		$start  = (int) strpos( $source, 'function export_numbers' );
		$body   = substr( $source, $start, 3000 );

		$this->assertStringContainsString( 'Kivun_Phones::has_answer_data()', $body, 'the numbers report states answered and missed without checking it can' );

		// The subtraction that produces "missed" sits behind that check.
		$guard  = (int) strpos( $body, 'if ( $knows ) {' );
		$missed = (int) strpos( $body, '$calls - $answered' );
		$this->assertGreaterThan( 0, $guard );
		$this->assertGreaterThan( $guard, $missed, 'the missed count is written before the check that it is knowable' );
	}

	/**
	 * The numbers report is given the dates the screen is showing.
	 *
	 * The call screen names them kivun_call_from and kivun_call_to; the
	 * export reads from and to. Reading the screen's array for the export's
	 * names finds nothing, and the button then downloads all of time while
	 * the screen shows one month — with nothing in the file to say which it
	 * is. Found in review.
	 *
	 * @return void
	 */
	public function test_the_numbers_button_carries_the_dates_on_screen(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( "'kivun_call_from' => 'from'", $source );
		$this->assertStringContainsString( "'kivun_call_to'   => 'to'", $source );
		$this->assertStringNotContainsString(
			"array_intersect_key( \$call_args, array_flip( array( 'from', 'to' ) ) )",
			$source,
			'the export is back to looking for names the screen does not use'
		);
	}

	/**
	 * And those are the names the call screen really uses.
	 *
	 * Asserted against the screen rather than a copy kept here, because the
	 * translation is only right while the two agree.
	 *
	 * @return void
	 */
	public function test_those_are_the_names_the_screen_uses(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( "'from'        => 'kivun_call_from',", $source );
		$this->assertStringContainsString( "'to'          => 'kivun_call_to',", $source );
	}
}
