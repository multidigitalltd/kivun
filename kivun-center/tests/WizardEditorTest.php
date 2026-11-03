<?php
/**
 * The wizard's two editors, and nothing else.
 *
 * The publish handoff orders the editor toolbar differently and adds a
 * left-to-right toggle. That is done in `teeny_mce_before_init`, which runs
 * for every teeny editor on the site — the jobs board's among them — so
 * the scoping is the part worth pinning down. A filter that forgot its
 * editor ids would reorder a toolbar nobody asked to change, and would do
 * it silently: every button still there, just somewhere else.
 *
 * The other half is that reordering never drops a button. One the design
 * does not name, added by another plugin, has to survive the trip.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The editor settings filter.
 */
final class WizardEditorTest extends TestCase {

	/**
	 * WordPress's own teeny toolbar, as core builds it.
	 */
	private const CORE_TOOLBAR = 'bold,italic,underline,blockquote,strikethrough,bullist,numlist,alignleft,aligncenter,alignright,undo,redo,link,fullscreen';

	/**
	 * Core's teeny settings, the parts the filter reads.
	 *
	 * @param string $toolbar The toolbar to start from.
	 * @return array<string,mixed>
	 */
	private function init( string $toolbar = self::CORE_TOOLBAR ): array {
		return array(
			'toolbar1' => $toolbar,
			'plugins'  => 'colorpicker,lists,fullscreen,image,wordpress,wpeditimage,wplink',
		);
	}

	/**
	 * Any other editor comes back exactly as it went in.
	 *
	 * @return void
	 */
	public function test_other_editors_are_left_alone(): void {
		foreach ( array( 'job_description', 'content', 'kivun_cc_short', '' ) as $id ) {
			$this->assertSame( $this->init(), Kivun_Content_Creator::wizard_editor_init( $this->init(), $id ), "editor '$id' was changed" );
		}
	}

	/**
	 * The wizard's toolbar is in the design's order, grouped.
	 *
	 * @return void
	 */
	public function test_the_toolbar_follows_the_design(): void {
		foreach ( array( 'kivun_ccf_short', 'kivun_ccf_long' ) as $id ) {
			$out = Kivun_Content_Creator::wizard_editor_init( $this->init(), $id );
			$this->assertSame(
				'bold,italic,underline,strikethrough,|,blockquote,bullist,numlist,|,alignright,aligncenter,alignleft,ltr,|,link,undo,redo,fullscreen',
				$out['toolbar1']
			);
			$this->assertContains( 'directionality', explode( ',', $out['plugins'] ), 'the direction toggle has no plugin to run it' );
			$this->assertStringContainsString( 'padding:18px 22px', $out['content_style'] );
		}
	}

	/**
	 * Every button that was on the toolbar is still on it.
	 *
	 * @return void
	 */
	public function test_no_button_is_dropped(): void {
		$out     = Kivun_Content_Creator::wizard_editor_init( $this->init( self::CORE_TOOLBAR . ',forecolor' ), 'kivun_ccf_long' );
		$buttons = array_diff( explode( ',', $out['toolbar1'] ), array( '|' ) );

		foreach ( explode( ',', self::CORE_TOOLBAR . ',forecolor' ) as $button ) {
			$this->assertContains( $button, $buttons, "'$button' fell off the toolbar" );
		}
		$this->assertSame( 'forecolor', end( $buttons ), 'a button the design does not name should keep a place at the end' );
	}

	/**
	 * A button that was not there is not invented — except the toggle.
	 *
	 * @return void
	 */
	public function test_a_missing_button_is_not_added(): void {
		$out = Kivun_Content_Creator::wizard_editor_init( $this->init( 'bold,italic,link' ), 'kivun_ccf_short' );
		$this->assertSame( 'bold,italic,|,ltr,|,link', $out['toolbar1'] );
	}

	/**
	 * Running it twice changes nothing more than running it once.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent(): void {
		$once  = Kivun_Content_Creator::wizard_editor_init( $this->init(), 'kivun_ccf_short' );
		$twice = Kivun_Content_Creator::wizard_editor_init( $once, 'kivun_ccf_short' );
		$this->assertSame( $once['toolbar1'], $twice['toolbar1'] );
		$this->assertSame( $once['plugins'], $twice['plugins'] );
	}
}
