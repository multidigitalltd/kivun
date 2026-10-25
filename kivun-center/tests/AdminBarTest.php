<?php
/**
 * WordPress's toolbar, and who still sees it.
 *
 * The black bar across the top is WordPress's own furniture: it says
 * "WordPress", it links into wp-admin, and most of what it offers is refused
 * to the roles this plugin adds. Everyone but an administrator now works
 * without it.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Tests for the toolbar's visibility.
 */
final class AdminBarTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * An administrator keeps it.
	 *
	 * And keeps their own choice with it: the value handed in is what
	 * WordPress worked out from their profile, so somebody who turned the
	 * toolbar off for themselves stays turned off.
	 *
	 * @return void
	 */
	public function test_an_administrator_keeps_the_toolbar(): void {
		kivun_test_set_caps( array( 'manage_options' ) );

		$this->assertTrue( Kivun_Core::admin_bar_for_admins_only( true ) );
		$this->assertFalse(
			Kivun_Core::admin_bar_for_admins_only( false ),
			'an administrator who switched it off in their profile got it back'
		);
	}

	/**
	 * Every role this plugin adds loses it.
	 *
	 * Each of them works from a screen of its own, and a strip of somebody
	 * else's navigation above it is at best noise and at worst an invitation
	 * to a page that will turn them away.
	 *
	 * @return void
	 */
	public function test_the_plugin_s_own_roles_lose_it(): void {
		$roles = array(
			'agency'       => array( 'kivun_view_campaigns', 'kivun_read_leads' ),
			'leads reader' => array( 'kivun_view_leads' ),
			'jobs manager' => array( 'kivun_employer', 'kivun_manage_jobs' ),
			'employer'     => array( 'kivun_employer' ),
		);

		foreach ( $roles as $who => $caps ) {
			kivun_test_set_caps( $caps );
			$this->assertFalse(
				Kivun_Core::admin_bar_for_admins_only( true ),
				$who . ' still sees WordPress\'s toolbar'
			);
		}
	}

	/**
	 * So does an editor, and somebody with no role at all.
	 *
	 * The line is drawn at administrator, which is what was asked for.
	 *
	 * @return void
	 */
	public function test_everyone_below_administrator_loses_it(): void {
		kivun_test_set_caps( array( 'edit_posts', 'edit_others_posts', 'publish_posts' ) );
		$this->assertFalse( Kivun_Core::admin_bar_for_admins_only( true ), 'an editor still sees it' );

		kivun_test_set_caps( array( 'read' ) );
		$this->assertFalse( Kivun_Core::admin_bar_for_admins_only( true ), 'a subscriber still sees it' );
	}

	/**
	 * A site can hand it back without editing the plugin.
	 *
	 * @return void
	 */
	public function test_a_filter_can_hand_it_back(): void {
		kivun_test_set_caps( array( 'kivun_view_leads' ) );
		$this->assertFalse( Kivun_Core::admin_bar_for_admins_only( true ) );

		add_filter( 'kivun_show_admin_bar', fn() => true );
		$this->assertTrue(
			Kivun_Core::admin_bar_for_admins_only( true ),
			'the escape hatch does not open'
		);
	}

	/**
	 * It is hung on the hook WordPress asks the question with.
	 *
	 * Hiding the bar with CSS leaves it rendered, loads its stylesheet and
	 * its script, and leaves the page still pushed down by the 32px WordPress
	 * reserves for it.
	 *
	 * @return void
	 */
	public function test_the_toolbar_is_not_merely_hidden(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-core.php' );

		$this->assertStringContainsString(
			"add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar_for_admins_only' ) );",
			$source
		);

		foreach ( array( 'assets/css/frontend.css', 'assets/css/admin.css' ) as $sheet ) {
			$css = (string) file_get_contents( KIVUN_DIR . $sheet );
			$this->assertStringNotContainsString(
				'#wpadminbar',
				$css,
				$sheet . ' hides the toolbar with CSS, leaving it loaded'
			);
		}
	}
}
