<?php
/**
 * Keeping the stylesheets out of "unused CSS" optimisers.
 *
 * LiteSpeed and Autoptimize build one stylesheet per page by scanning that
 * page's HTML and discarding every rule they cannot find a match for. Much of
 * this plugin's markup does not exist at that moment — the application form
 * is in a popup, the success dialog is created after a submission, the
 * searchable filters are built by script once the page has loaded — so those
 * rules are thrown away and the control arrives unstyled. It has happened on
 * this site, which is why the exemption exists at all.
 *
 * The trap is that the exemption is a list of handles. Adding a stylesheet is
 * easy; remembering the list is not, and forgetting costs nothing until the
 * optimiser next rebuilds. Splitting the picker out of the main bundle did
 * exactly that: its styles left the protected file and nothing added them
 * back, and its markup is the worst case there is — every one of its classes
 * is created by script, so a scan finds none of them and drops the file
 * whole.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests that every registered stylesheet is exempted from CSS optimisation.
 */
final class UnoptimisedStylesTest extends TestCase {

	/**
	 * The source of the class that registers and exempts them.
	 *
	 * @return string
	 */
	private function core(): string {
		return (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-core.php' );
	}

	/**
	 * Every handle passed to wp_register_style() there.
	 *
	 * @return array<int,string>
	 */
	private function registered(): array {
		preg_match_all( "/wp_register_style\(\s*'([^']+)'/", $this->core(), $found );

		return $found[1];
	}

	/**
	 * The handles the optimiser is told to leave alone.
	 *
	 * @return array<int,string>
	 */
	private function exempted(): array {
		$found = preg_match( '/\$kivun_unoptimised\s*=\s*array\(([^)]*)\)/', $this->core(), $m );
		$this->assertSame( 1, $found, 'the exemption list has been renamed or removed' );

		preg_match_all( "/'([^']+)'/", $m[1], $handles );

		return $handles[1];
	}

	// ── The list ──────────────────────────────────────────────────────────────

	/**
	 * Something is registered at all — otherwise the check below passes by
	 * finding nothing to check.
	 *
	 * @return void
	 */
	public function test_there_are_stylesheets_to_protect(): void {
		$this->assertNotEmpty( $this->registered() );
		$this->assertContains( 'kivun-frontend', $this->registered() );
		$this->assertContains( 'kivun-picker', $this->registered() );
	}

	/**
	 * And every one of them is on the list. This is the whole point: a
	 * stylesheet added without its handle here is one an optimiser may strip,
	 * on a page where the markup it styles was not in the HTML to be scanned.
	 *
	 * @return void
	 */
	public function test_every_registered_stylesheet_is_exempted(): void {
		$missing = array_values( array_diff( $this->registered(), $this->exempted() ) );

		$this->assertSame(
			array(),
			$missing,
			'these stylesheets can be stripped by an unused-CSS optimiser; add them to $kivun_unoptimised'
		);
	}

	/**
	 * The list names only real handles, so a typo in it is not mistaken for
	 * protection that is not there.
	 *
	 * @return void
	 */
	public function test_the_list_names_only_stylesheets_that_exist(): void {
		$unknown = array_values( array_diff( $this->exempted(), $this->registered() ) );

		$this->assertSame( array(), $unknown, 'these handles are exempted but never registered' );
	}

	/**
	 * And the attributes are the ones LiteSpeed and Autoptimize actually
	 * read. A different spelling would look like protection and be none.
	 *
	 * @return void
	 */
	public function test_the_attributes_are_the_ones_the_optimisers_read(): void {
		$this->assertStringContainsString( 'data-no-optimize="1"', $this->core() );
		$this->assertStringContainsString( 'data-no-defer="1"', $this->core() );
	}
}
