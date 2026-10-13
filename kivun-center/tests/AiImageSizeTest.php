<?php
/**
 * The shape of a generated cover image.
 *
 * A content page renders its hero at the image's own ratio — the stylesheet
 * sets width and nothing else — so the file's proportions decide how much of
 * the screen is a picture before a reader reaches a word. At 16:9 that was
 * most of it, which is what the client was seeing and calling "too big".
 *
 * The arithmetic that fixes it is the kind that fails quietly: a crop box a
 * few pixels out still produces a picture, just a squashed one or one with
 * the top of somebody's head missing, and nobody measures a cover.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-ai-image.php';

/**
 * Tests for the cover's size, crop and prompt.
 */
final class AiImageSizeTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Clear filters between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $kivun_test_filters;
		$kivun_test_filters = array();
	}

	/**
	 * Store a configured size.
	 *
	 * @param mixed $width  The width setting.
	 * @param mixed $height The height setting.
	 * @return void
	 */
	private function configure( $width, $height ): void {
		Kivun_Test_State::$options['kivun_settings'] = array(
			'ai_image_width'  => $width,
			'ai_image_height' => $height,
		);
	}

	// ── The size a cover is saved at ──────────────────────────────────────────

	/**
	 * A wide strip out of the box, which is the shape the client asked for.
	 *
	 * @return void
	 */
	public function test_the_default_is_a_wide_strip(): void {
		$this->assertSame( array( 1200, 400 ), Kivun_AI_Image::output_size() );
	}

	/**
	 * A site that wants it taller or shorter says so in the settings rather
	 * than waiting for a release.
	 *
	 * @return void
	 */
	public function test_a_configured_size_is_used(): void {
		$this->configure( 1000, 500 );

		$this->assertSame( array( 1000, 500 ), Kivun_AI_Image::output_size() );
	}

	/**
	 * A typo cannot ask for a stamp or for four megapixels.
	 *
	 * @return void
	 */
	public function test_an_absurd_size_is_brought_back_into_range(): void {
		$this->configure( 12, 99999 );

		$this->assertSame(
			array( Kivun_AI_Image::MIN_EDGE, Kivun_AI_Image::MAX_EDGE ),
			Kivun_AI_Image::output_size()
		);
	}

	/**
	 * An empty or non-numeric setting falls back rather than collapsing to
	 * zero — a zero height would be a division by zero when the ratio is
	 * worked out.
	 *
	 * @return void
	 */
	public function test_an_empty_setting_falls_back_to_the_default(): void {
		$this->configure( '', 'abc' );

		$this->assertSame( array( 1200, 400 ), Kivun_AI_Image::output_size() );
	}

	/**
	 * And a theme can override it in code.
	 *
	 * @return void
	 */
	public function test_the_size_can_be_filtered(): void {
		add_filter( 'kivun_ai_image_size', static fn() => array( 900, 300 ) );

		$this->assertSame( array( 900, 300 ), Kivun_AI_Image::output_size() );
	}

	// ── The crop ──────────────────────────────────────────────────────────────

	/**
	 * The model returns 1536×1024; a 3:1 cover is the middle band of it.
	 *
	 * @return void
	 */
	public function test_a_three_to_one_cover_takes_the_middle_band(): void {
		$this->assertSame( array( 0, 256, 1536, 512 ), Kivun_AI_Image::crop_box( 1536, 1024, 3.0 ) );
	}

	/**
	 * dall-e-3's wider frame is cropped the same way, from its own middle.
	 *
	 * @return void
	 */
	public function test_the_wider_model_is_cropped_from_its_middle_too(): void {
		list( $x, $y, $w, $h ) = Kivun_AI_Image::crop_box( 1792, 1024, 3.0 );

		$this->assertSame( 0, $x );
		$this->assertSame( 1792, $w );
		$this->assertSame( 597, $h );
		$this->assertSame( (int) round( ( 1024 - 597 ) / 2 ), $y );
	}

	/**
	 * A source wider than the cover loses its sides instead, and keeps its
	 * full height.
	 *
	 * @return void
	 */
	public function test_an_over_wide_source_loses_its_sides(): void {
		$this->assertSame( array( 400, 0, 1200, 400 ), Kivun_AI_Image::crop_box( 2000, 400, 3.0 ) );
	}

	/**
	 * Whatever the source, the kept box has the cover's ratio — otherwise the
	 * resize that follows squashes the picture instead of scaling it.
	 *
	 * @return void
	 */
	public function test_the_kept_box_always_has_the_covers_ratio(): void {
		foreach ( array( array( 1536, 1024 ), array( 1792, 1024 ), array( 1024, 1024 ), array( 1024, 1536 ) ) as $source ) {
			$box = Kivun_AI_Image::crop_box( $source[0], $source[1], 3.0 );

			$this->assertNotNull( $box, "{$source[0]}×{$source[1]} should be cropped" );
			$this->assertEqualsWithDelta(
				3.0,
				$box[2] / $box[3],
				0.01,
				"{$source[0]}×{$source[1]} was cropped to the wrong shape"
			);
		}
	}

	/**
	 * The box stays inside the picture — a crop running off the edge is how
	 * an image editor returns an error instead of an image.
	 *
	 * @return void
	 */
	public function test_the_box_stays_inside_the_picture(): void {
		$box = Kivun_AI_Image::crop_box( 1536, 1024, 3.0 );

		$this->assertGreaterThanOrEqual( 0, $box[0] );
		$this->assertGreaterThanOrEqual( 0, $box[1] );
		$this->assertLessThanOrEqual( 1536, $box[0] + $box[2] );
		$this->assertLessThanOrEqual( 1024, $box[1] + $box[3] );
	}

	/**
	 * An image already the right shape is not cropped at all.
	 *
	 * @return void
	 */
	public function test_a_source_already_the_right_shape_is_left_alone(): void {
		$this->assertNull( Kivun_AI_Image::crop_box( 1200, 400, 3.0 ) );
	}

	/**
	 * A size the editor could not read is skipped rather than handed to crop()
	 * as a zero-width box.
	 *
	 * @return void
	 */
	public function test_an_unreadable_size_is_skipped(): void {
		$this->assertNull( Kivun_AI_Image::crop_box( 0, 0, 3.0 ) );
		$this->assertNull( Kivun_AI_Image::crop_box( 1536, 1024, 0.0 ) );
	}

	// ── How the ratio is written into the prompt ──────────────────────────────

	/**
	 * Whole ratios read as ratios.
	 *
	 * @return void
	 */
	public function test_a_whole_ratio_is_written_as_one(): void {
		$this->assertSame( '3:1', Kivun_AI_Image::ratio_label( 1200, 400 ) );
		$this->assertSame( '16:9', Kivun_AI_Image::ratio_label( 1280, 720 ) );
	}

	/**
	 * An awkward one is given as a decimal: "1200:401" reads as a pixel size
	 * and invites the model to render one.
	 *
	 * @return void
	 */
	public function test_an_awkward_ratio_is_given_as_a_decimal(): void {
		$this->assertSame( '2.99:1', Kivun_AI_Image::ratio_label( 1200, 401 ) );
	}

	// ── The prompt ────────────────────────────────────────────────────────────

	/**
	 * A 3:1 centre crop throws away most of a 3:2 frame. Asking for the
	 * framing up front is what keeps the subject inside the band.
	 *
	 * @return void
	 */
	public function test_the_prompt_asks_for_a_croppable_banner(): void {
		$prompt = Kivun_AI_Image::build_prompt( 'קורס הכנה לראיונות', '', 'course', 'photo', '' );

		$this->assertStringContainsString( 'wide horizontal banner', $prompt );
		$this->assertStringContainsString( '3:1', $prompt );
		$this->assertStringContainsString( 'above and below', $prompt );
	}

	/**
	 * The ratio in the prompt follows the configured size, so the two cannot
	 * drift apart into a model composing for one shape and a crop taking
	 * another.
	 *
	 * @return void
	 */
	public function test_the_prompt_follows_the_configured_size(): void {
		$this->configure( 1280, 720 );

		$this->assertStringContainsString(
			'16:9',
			Kivun_AI_Image::build_prompt( 'קורס', '', 'course', 'photo', '' )
		);
	}

	/**
	 * The subject still comes from the page, and the no-text rule survives.
	 *
	 * @return void
	 */
	public function test_the_subject_and_the_no_text_rule_remain(): void {
		$prompt = Kivun_AI_Image::build_prompt( 'קורס הכנה לראיונות', 'מיומנויות ראיון', 'course', 'illustration', '' );

		$this->assertStringContainsString( 'קורס הכנה לראיונות', $prompt );
		$this->assertStringContainsString( 'מיומנויות ראיון', $prompt );
		$this->assertStringContainsString( 'Modern flat vector illustration', $prompt );
		$this->assertStringContainsString( 'Do not include any text', $prompt );
	}

	/**
	 * A free-text subject replaces the derived one rather than joining it.
	 *
	 * @return void
	 */
	public function test_free_text_replaces_the_derived_subject(): void {
		$prompt = Kivun_AI_Image::build_prompt( 'קורס הכנה לראיונות', '', 'course', 'photo', 'two people shaking hands' );

		$this->assertStringContainsString( 'two people shaking hands', $prompt );
		$this->assertStringNotContainsString( 'קורס הכנה לראיונות', $prompt );
	}
}
