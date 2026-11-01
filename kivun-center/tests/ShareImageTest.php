<?php
/**
 * The picture a shared link shows.
 *
 * Two things hold this feature up. The image must never reach the page —
 * that is the whole point of it being separate from the featured image —
 * and exactly one `og:image` must name it, which on a site running an SEO
 * plugin means letting that plugin do the naming rather than writing a
 * second tag beside its own.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Which image is shared, and who says so.
 */
final class ShareImageTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * A landing page with a featured image and optionally a share image.
	 *
	 * @param int $featured The featured attachment, or 0.
	 * @param int $share    The share attachment, or 0.
	 * @return int The post id.
	 */
	private function page( int $featured = 0, int $share = 0 ): int {
		Kivun_Test_State::$posts[9] = array( 'post_type' => 'kivun_workshop' );
		if ( $featured ) {
			Kivun_Test_State::$meta[9]['_thumbnail_id'] = $featured;
		}
		if ( $share ) {
			Kivun_Test_State::$meta[9][ Kivun_Share_Image::META ] = $share;
		}
		return 9;
	}

	// ── Which image ──────────────────────────────────────────────────────────

	/**
	 * The chosen one wins.
	 *
	 * @return void
	 */
	public function test_the_chosen_image_is_the_one_shared(): void {
		$id = $this->page( 100, 200 );

		$this->assertSame( 200, Kivun_Share_Image::attachment( $id ) );
	}

	/**
	 * With none chosen, the page's own picture stands in.
	 *
	 * A page that has a picture on it and none in its link card is a worse
	 * answer than reusing the one it already has.
	 *
	 * @return void
	 */
	public function test_with_none_chosen_the_featured_image_stands_in(): void {
		$id = $this->page( 100 );

		$this->assertSame( 100, Kivun_Share_Image::attachment( $id ) );
	}

	/**
	 * With neither, nothing is claimed.
	 *
	 * @return void
	 */
	public function test_with_neither_there_is_nothing_to_share(): void {
		$id = $this->page();

		$this->assertSame( 0, Kivun_Share_Image::attachment( $id ) );
		$this->assertSame( '', Kivun_Share_Image::url( $id ) );
	}

	// ── It must not reach the page ───────────────────────────────────────────

	/**
	 * No template renders it.
	 *
	 * This is the request in one line: an image that is seen only when the
	 * link is shared. A template that drew it would defeat the entire
	 * feature, and nothing about the meta key itself prevents that — only
	 * this does.
	 *
	 * @return void
	 */
	public function test_no_template_puts_it_on_the_page(): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( KIVUN_DIR . 'templates' ) );

		$checked = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			++$checked;
			$src = (string) file_get_contents( $file->getPathname() );
			$this->assertStringNotContainsString(
				'Kivun_Share_Image',
				$src,
				$file->getFilename() . ' draws the share image, which is only ever meant to be seen in a link card'
			);
		}

		$this->assertGreaterThan( 5, $checked, 'the templates moved; this test looked at almost none of them' );
	}

	// ── Exactly one tag names it ─────────────────────────────────────────────

	/**
	 * Where an SEO plugin owns the head, the image is handed to it.
	 *
	 * Writing our own tag would not override theirs — it would leave two in
	 * the head and let each network pick one. The filter leaves one tag,
	 * theirs, saying what was chosen here.
	 *
	 * @return void
	 */
	public function test_an_seo_plugin_is_handed_the_image_not_overruled(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-share-image.php' );

		foreach ( array( 'wpseo_opengraph_image', 'rank_math/opengraph/facebook/image', 'seopress_social_og_thumb' ) as $filter ) {
			$this->assertStringContainsString( $filter, $src, 'nothing hands the image to ' . $filter );
		}
	}

	/**
	 * And where one is running, we write nothing ourselves.
	 *
	 * @return void
	 */
	public function test_we_stay_quiet_while_an_seo_plugin_is_running(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-share-image.php' );

		$at = strpos( $src, 'function tags' );
		$this->assertNotFalse( $at, 'the tag writer moved' );
		$body = substr( $src, $at, 900 );

		$this->assertStringContainsString( 'seo_plugin_active()', $body, 'the tags are written whether or not somebody else is writing them' );
		$this->assertStringContainsString( 'kivun_share_image_output', $body, 'there is no way to silence ours for an SEO plugin we do not know' );
	}

	/**
	 * A plugin we do not detect can still be given the last word.
	 *
	 * @return void
	 */
	public function test_an_unknown_seo_plugin_can_silence_ours(): void {
		$this->assertTrue( Kivun_Share_Image::seo_plugin_active() === false, 'the harness has no SEO plugin, so this reads the default' );

		// The filter is what a site with an unrecognised plugin would use.
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-share-image.php' );
		$this->assertStringContainsString( "apply_filters( 'kivun_share_image_output'", $src );
	}

	// ── Both screens save it ─────────────────────────────────────────────────

	/**
	 * Both content forms carry the field.
	 *
	 * They share one save body, so a field on one screen and not the other
	 * means saving from the second reads as "none" and quietly clears what
	 * the first set. Checked rather than assumed, because the two forms look
	 * nothing alike and it would be easy to add it only where I was looking.
	 *
	 * @return void
	 */
	public function test_both_content_forms_carry_the_field(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertSame(
			2,
			substr_count( $src, 'name="share_image_id"' ),
			'one of the two content forms is missing the field, and saving from it would clear the image'
		);
		$this->assertSame( 2, substr_count( $src, 'name="share_image_file"' ) );
	}

	/**
	 * Each media box drives its own controls.
	 *
	 * The handlers used `document.querySelector`, which binds the first
	 * match on the page. That was invisible while the console had one image
	 * and wrong the moment a second appeared beside it: the share image's
	 * file input and remove button got no handlers at all, so its preview
	 * never updated and its remove button did nothing while looking as
	 * though it should. Found in review, and reproduced — with the old
	 * lookups, clicking the share image's remove button changes neither
	 * image.
	 *
	 * @return void
	 */
	public function test_each_media_box_drives_its_own_controls(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$at = strpos( $js, "document.querySelectorAll('.kivun-cc-media')" );
		$this->assertNotFalse( $at, 'the media controls are not bound per box' );

		$body = substr( $js, $at, 1400 );
		foreach ( array( 'kivun-cc-file', 'kivun-cc-media__preview', 'kivun-cc-media__id', 'kivun-cc-remove-flag', 'kivun-cc-media__remove' ) as $part ) {
			$this->assertStringContainsString(
				"box.querySelector('." . $part . "')",
				$body,
				$part . ' is looked up on the page rather than inside the box it belongs to'
			);
		}
	}

	/**
	 * Both screens can take the image off again.
	 *
	 * An image that can be set and not unset is a trap: the fallback to the
	 * featured image is advertised and unreachable. Each box needs the
	 * button and the flag — the button alone empties the id, which the save
	 * reads as "unchanged" rather than "remove", and nothing happens. That
	 * was true of the admin screen's featured image before this too, found
	 * while giving the share image the same control.
	 *
	 * @return void
	 */
	public function test_every_media_box_can_be_emptied(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		// The console: a flag per box.
		$this->assertSame(
			2,
			substr_count( $src, 'class="kivun-cc-remove-flag"' ),
			'one of the console boxes cannot be emptied'
		);

		// The admin screen: the same, under its own class.
		$this->assertSame(
			2,
			substr_count( $src, 'class="kivun-lp-media__flag"' ),
			'one of the admin boxes has a remove button that sets no flag, so removing does nothing'
		);
	}

	/**
	 * The AI button resolves the box it fills.
	 *
	 * The controls used to be plain variables in the script's outer scope,
	 * and two things read them: the buttons in the box, and the AI button
	 * further down. They shared those names by accident, and the moment a
	 * second box turned that scope into a loop, the AI handler was reaching
	 * for names that no longer existed — a ReferenceError its own catch
	 * reported as a network failure, so a generated image looked like a
	 * dead connection. My regression, found in review.
	 *
	 * @return void
	 */
	public function test_the_ai_button_resolves_the_box_it_fills(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$at = strpos( $src, "var aiBtn = document.querySelector( '.kivun-cc-ai-btn' );" );
		$this->assertNotFalse( $at, 'the AI button moved' );
		$body = substr( $src, $at, 2000 );

		$this->assertStringContainsString(
			"kivunLpBoxFor( 'thumbnail_id' )",
			$body,
			'the AI handler does not say which box it fills'
		);

		// And it must not lean on the loop's own names, which do not reach here.
		foreach ( array( 'idInput', 'removeBtn' ) as $gone ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . $gone . '\b/',
				$body,
				'the AI handler still reads ' . $gone . ', which now lives inside the per-box loop'
			);
		}
	}

	/**
	 * And the save writes it on every post of the group.
	 *
	 * @return void
	 */
	public function test_the_save_writes_it(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( "self::resolve_image( 'share_image_file', 'share_image_id' )", $src );
		$this->assertStringContainsString( 'update_post_meta( $post_id, Kivun_Share_Image::META, $share_id )', $src );
	}
}
