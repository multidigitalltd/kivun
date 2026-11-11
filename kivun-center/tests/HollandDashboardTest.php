<?php
/**
 * The Holland questionnaire's own dashboard, and its absence from the
 * general leads screens.
 *
 * @package Kivun
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Holland_Dashboard.
 */
class HollandDashboardTest extends TestCase {

	/**
	 * Start clean.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
		$_GET = array();
	}

	/**
	 * Leave no request behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = array();
	}

	/**
	 * A stored row.
	 *
	 * @param int       $id   The row.
	 * @param array|int $yes  Item numbers answered yes, or how many answered (unfinished).
	 * @param bool      $done Whether it was finished.
	 * @return object
	 */
	private function row( int $id, $yes, bool $done ): object {
		$r = str_repeat( 'n', 216 );
		if ( is_int( $yes ) ) {
			$r = str_repeat( 'n', $yes ) . str_repeat( '-', 216 - $yes );
			$yes = array();
		}
		foreach ( $yes as $n ) {
			$r[ $n - 1 ] = 'y';
		}
		return (object) array(
			'id'         => $id,
			'type'       => 'holland',
			'name'       => 'משתתף ' . $id,
			'phone'      => '0501234567',
			'created_at' => '2026-11-08 10:00:00',
			'answers'    => (string) wp_json_encode( Kivun_Holland::stored_answers( '123456782', $r, $done, 0, $done ? '2026-11-09 12:00:00' : '' ) ),
		);
	}

	/**
	 * Draw the dashboard and return its HTML.
	 *
	 * @param array<int,object> $rows The rows the query returns.
	 * @return string
	 */
	private function draw( array $rows ): string {
		$GLOBALS['wpdb']->results_handler = static fn() => $rows;
		$GLOBALS['wpdb']->var_handler     = static fn( string $sql ) => false !== strpos( $sql, 'NOT LIKE' ) ? 0 : ( false !== strpos( $sql, 'holland\\_done' ) ? 1 : count( $rows ) );
		Kivun_Test_State::$posts[0] = array( 'permalink' => 'https://example.test/holland-admin/' );

		Kivun_Holland_Dashboard::render();
		$t = end( Kivun_Test_State::$templates );
		$this->assertSame( 'holland/dashboard.php', $t['template'] );
		ob_start();
		extract( $t['vars'] ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- The template's own variables.
		include KIVUN_DIR . 'templates/' . $t['template'];
		return (string) ob_get_clean();
	}

	/**
	 * Logged out: a way in, nothing else. Logged in without the leads
	 * capability: a refusal.
	 *
	 * @return void
	 */
	public function test_only_lead_managers_see_it(): void {
		Kivun_Test_State::$options['logged_in'] = false;
		$this->assertSame( 'gate', $this->vars()['view'] );
		$this->assertStringContainsString( 'wp-login.php', $this->vars()['login'] );

		Kivun_Test_State::$options['logged_in'] = true;
		$this->assertSame( 'gate', $this->vars()['view'] );
		$this->assertSame( '', $this->vars()['login'] );
		$this->assertStringContainsString( 'אין לך הרשאה', $this->vars()['message'] );
	}

	/**
	 * The variables the dashboard handed its template, for the current user.
	 *
	 * @return array<string,mixed>
	 */
	private function vars(): array {
		Kivun_Holland_Dashboard::render();
		return end( Kivun_Test_State::$templates )['vars'];
	}

	/**
	 * The table: a finished row with its three-letter code and its report,
	 * an unfinished one with how far it got; and the counts.
	 *
	 * @return void
	 */
	public function test_the_list(): void {
		kivun_test_set_caps( array( 'edit_others_posts' ) );
		$html = $this->draw(
			array(
				$this->row( 7, array_merge( range( 37, 48 ), range( 49, 60 ), range( 25, 30 ) ), true ),
				$this->row( 8, 40, false ),
			)
		);

		$this->assertStringContainsString( 'משתתף 7', $html );
		$this->assertStringContainsString( '123456782', $html );
		$this->assertStringContainsString( 'הושלם 9.11.2026', $html );
		$this->assertStringContainsString( 'בתהליך · 40/216', $html );
		preg_match_all( '#class="kivun-hl-dash__l" style="[^"]*">([RIASEC])</span>#', $html, $letters );
		$this->assertSame( array( 'S', 'E', 'A' ), $letters[1] );
		$this->assertSame( 1, substr_count( $html, 'class="kivun-hl-dash__report"' ) );
		$this->assertStringContainsString( 'action=kivun_holland_report&amp;lead=7', $html );
		$this->assertStringContainsString( 'ctype=holland', $html, 'the export asks for Holland rows by name' );
	}

	/**
	 * The query: Holland rows only; a search of digits finds the ID number
	 * at any place inside its nine digits, and only at the start of the
	 * summary, not in a score; "done" and "open" read the stored flag.
	 *
	 * @return void
	 */
	public function test_the_query(): void {
		$all = Kivun_Holland_Dashboard::where( '', '' );
		$this->assertSame( "WHERE type = 'holland'", $all );

		$id = Kivun_Holland_Dashboard::where( '45678', '' );
		$this->assertStringContainsString( "phone LIKE '%45678%'", $id );
		$this->assertStringContainsString( "message LIKE 'תעודת זהות: 45678%'", $id );
		$this->assertStringContainsString( "message LIKE 'תעודת זהות: ____45678%'", $id );
		$this->assertStringNotContainsString( "_____45678", $id, 'nine digits at most' );

		$this->assertStringContainsString( 'answers LIKE', Kivun_Holland_Dashboard::where( '', 'done' ) );
		$this->assertStringContainsString( 'answers NOT LIKE', Kivun_Holland_Dashboard::where( '', 'open' ) );
		$this->assertStringNotContainsString( 'message', Kivun_Holland_Dashboard::where( 'ישראל', '' ) );
	}

	/**
	 * The general leads screens leave the questionnaire out.
	 *
	 * @return void
	 */
	public function test_the_general_leads_leave_it_out(): void {
		$this->assertArrayNotHasKey( 'holland', Kivun_Admin::reg_type_labels() );
		$console = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString( "\$conds = array( \"r.type <> 'holland'\" );", $console );
		$this->assertStringNotContainsString( 'Kivun_Holland::report_url', $console );
	}

	/**
	 * The email to the representative links to the dashboard once it has a page.
	 *
	 * @return void
	 */
	public function test_the_email_links_to_the_dashboard(): void {
		Kivun_Test_State::$options['admin_email']                       = 'rep@example.test';
		Kivun_Test_State::$options[ Kivun_Holland_Dashboard::PAGE_OPTION ] = 5;
		Kivun_Test_State::$posts[5] = array(
			'permalink' => 'https://example.test/holland-admin/',
			'status'    => 'publish',
		);

		Kivun_Holland::notify_completed( $this->row( 7, array( 37 ), true ), Kivun_Holland::score( str_repeat( 'n', 216 ) ) );
		$this->assertStringContainsString( 'href="https://example.test/holland-admin/"', Kivun_Test_State::$mail[0]['message'] );
		$this->assertStringContainsString( 'לכל שאלוני הולנד', Kivun_Test_State::$mail[0]['message'] );
	}
}
