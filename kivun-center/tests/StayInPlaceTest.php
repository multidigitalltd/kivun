<?php
/**
 * Coming back to where you were.
 *
 * Marking a job filled is a link to admin-post.php: the work happens there
 * and the browser is redirected back, which is a fresh navigation and starts
 * at the top of the page. A board manager marking jobs filled one after
 * another scrolled back down to find their place every time.
 *
 * The tab went the same way. activateTab() writes ?kivun_tab= on every
 * switch and nothing read it back, so any reload — the publisher toggle, the
 * job renewal — landed on the default tab, at roughly the old scroll offset
 * but in a different table.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The address carries enough to put you back.
 */
final class StayInPlaceTest extends TestCase {

	/**
	 * The job row is something a link can point at.
	 *
	 * @return void
	 */
	public function test_a_job_row_can_be_linked_to(): void {
		$tpl = (string) file_get_contents( KIVUN_DIR . 'templates/employer/dashboard.php' );

		$this->assertStringContainsString(
			'id="kivun-job-<?php echo esc_attr( $job->ID ); ?>"',
			$tpl,
			'a job row has no anchor to come back to'
		);
	}

	/**
	 * And marking one filled comes back to it.
	 *
	 * @return void
	 */
	public function test_marking_a_job_filled_returns_to_the_row(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-jobs.php' );

		$at = (int) strpos( $src, 'public static function toggle_filled' );
		$this->assertNotFalse( $at, 'the handler moved' );

		$body = substr( $src, $at, 1800 );
		$this->assertStringContainsString(
			"'#kivun-job-' . \$job_id",
			$body,
			'the redirect still lands at the top of the page'
		);
	}

	/**
	 * A fragment already on the address is replaced, not appended to.
	 *
	 * Browsers do not send one, so this is belt and braces — but two of them
	 * in a URL is a URL that points nowhere.
	 *
	 * @return void
	 */
	public function test_an_existing_fragment_is_not_doubled(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-jobs.php' );

		$this->assertStringContainsString(
			"strtok( \$back, '#' )",
			$src,
			'a referer that already carries a fragment would end up with two'
		);
	}

	/**
	 * The tab in the address is the tab you land on.
	 *
	 * @return void
	 */
	public function test_the_tab_in_the_address_is_restored(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertStringContainsString(
			"searchParams.get('kivun_tab')",
			$js,
			'the tab written into the address is still never read back'
		);
	}

	/**
	 * Restoring a tab does not rewrite the address.
	 *
	 * activateTab() strips every other kivun_* parameter when it switches, on
	 * the reasoning that they describe the tab being left. Restoring is not
	 * switching: the address already names this tab, and running that strip
	 * would drop kivun_as and the rest from a page already rendered with
	 * them.
	 *
	 * @return void
	 */
	public function test_restoring_a_tab_leaves_the_address_alone(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertStringContainsString( 'function activateTab(tab, focusIt, keepUrl)', $js );
		$this->assertStringContainsString( 'if (!keepUrl && dash.classList.contains', $js );
		$this->assertStringContainsString( 'activateTab(tab, false, true)', $js, 'the restore still rewrites the address' );
	}

	/**
	 * And the site loads all of it.
	 *
	 * The minified files are what the site serves. A fix that lives only in
	 * the source is a fix the client never receives — which has happened
	 * here before.
	 *
	 * @return void
	 */
	public function test_the_built_file_carries_it(): void {
		$min = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.min.js' );

		$this->assertStringContainsString( 'kivun_tab', $min );
		$this->assertStringContainsString( 'scrollIntoView', $min, 'the built file cannot put a row back on screen' );
	}
}
