<?php
/**
 * The short link reaching the message that actually gets shared.
 *
 * The WhatsApp promo is written before the link is saved, so it is built
 * around the long URL — and copying that promo is how these links are
 * distributed. A short link that existed only in the campaigns table, while
 * every message still carried three lines of percent-encoded Hebrew, would
 * be a feature that did nothing where it was wanted.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-campaigns.php';

/**
 * Tests for the substitution that puts the short link into the promo.
 */
final class PromoShortLinkTest extends TestCase {

	/**
	 * A long campaign URL of the shape this site produces, Hebrew campaign
	 * name and all.
	 */
	private const LONG = 'https://example.test/course/?utm_source=newsletter&utm_medium=email&utm_campaign=%D7%A7%D7%9E%D7%A4%D7%99%D7%99%D7%9F&utm_content=story-1';

	/**
	 * The short one that replaces it.
	 */
	private const SHORT = 'https://example.test/go/ab3k';

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * The address in the message becomes the short one.
	 *
	 * @return void
	 */
	public function test_the_long_address_is_replaced(): void {
		$promo = "הקורס נפתח!\nלפרטים והרשמה: " . self::LONG . "\nמוזמנים לשתף.";

		$this->assertSame(
			"הקורס נפתח!\nלפרטים והרשמה: " . self::SHORT . "\nמוזמנים לשתף.",
			Kivun_Campaigns::shorten_in_text( $promo, self::SHORT, array( self::LONG ) )
		);
	}

	/**
	 * Including the address the link had before its parameters changed —
	 * an edit rebuilds the URL, and the message still holds the old one.
	 *
	 * @return void
	 */
	public function test_the_address_from_before_an_edit_is_replaced_too(): void {
		$previous = 'https://example.test/course/?utm_source=facebook&utm_medium=social';
		$promo    = 'לפרטים: ' . $previous;

		$this->assertSame(
			'לפרטים: ' . self::SHORT,
			Kivun_Campaigns::shorten_in_text( $promo, self::SHORT, array( self::LONG, $previous ) )
		);
	}

	/**
	 * It appears more than once in some messages, and all of them go.
	 *
	 * @return void
	 */
	public function test_every_occurrence_is_replaced(): void {
		$promo  = self::LONG . ' — ' . self::LONG;
		$result = Kivun_Campaigns::shorten_in_text( $promo, self::SHORT, array( self::LONG ) );

		$this->assertSame( self::SHORT . ' — ' . self::SHORT, $result );
		$this->assertStringNotContainsString( 'utm_source', $result );
	}

	/**
	 * The rest of the message is somebody's own writing and is left exactly
	 * as it was. Only whole addresses are swapped — nothing is matched by
	 * pattern, because a pattern loose enough to catch a URL it was not
	 * given is loose enough to eat a sentence.
	 *
	 * @return void
	 */
	public function test_the_writing_around_it_is_untouched(): void {
		$promo = "שלום! 📣\nנפתחה ההרשמה לקורס — 50% הנחה למשלמים מראש.\nhttps://example.test/other-page/\nבהצלחה!";

		$this->assertSame(
			$promo,
			Kivun_Campaigns::shorten_in_text( $promo, self::SHORT, array( self::LONG ) )
		);
	}

	/**
	 * With no short link there is nothing to put in, and the message is
	 * returned whole rather than having its address cut out.
	 *
	 * @return void
	 */
	public function test_without_a_short_link_nothing_changes(): void {
		$promo = 'לפרטים: ' . self::LONG;

		$this->assertSame( $promo, Kivun_Campaigns::shorten_in_text( $promo, '', array( self::LONG ) ) );
		$this->assertSame( $promo, Kivun_Campaigns::shorten_in_text( $promo, '   ', array( self::LONG ) ) );
	}

	/**
	 * An empty message stays empty.
	 *
	 * @return void
	 */
	public function test_an_empty_message_stays_empty(): void {
		$this->assertSame( '', Kivun_Campaigns::shorten_in_text( '', self::SHORT, array( self::LONG ) ) );
	}

	/**
	 * Blanks among the addresses to replace are ignored rather than matching
	 * everywhere, which would shred the message.
	 *
	 * @return void
	 */
	public function test_a_blank_address_does_not_match_everything(): void {
		$promo = 'לפרטים: ' . self::LONG;

		$this->assertSame(
			'לפרטים: ' . self::SHORT,
			Kivun_Campaigns::shorten_in_text( $promo, self::SHORT, array( '', '   ', self::LONG ) )
		);
	}

	// ── A code that changed between the preview and the save ──────────────────

	/**
	 * The message is now written around the short link from the start, so
	 * what it holds is the address the form was showing. Where the save
	 * settles on a different code — somebody took that one in between — the
	 * stored copy is corrected, or the message would send its readers to
	 * that other person's link.
	 *
	 * @return void
	 */
	public function test_a_previewed_code_that_changed_is_corrected(): void {
		$previewed = 'https://example.test/go/ab3k';
		$final     = 'https://example.test/go/ab3k-2';

		$this->assertSame(
			"להרשמה:\n{$final}",
			Kivun_Campaigns::shorten_in_text(
				"להרשמה:\n{$previewed}",
				$final,
				array( self::LONG, '', $previewed )
			)
		);
	}

	/**
	 * The address the message was written around is carried to the save, not
	 * rebuilt there from the code.
	 *
	 * A code resolved to "sale-2" while the message still said "sale" is the
	 * ordinary case, and /go/sale is a prefix of /go/sale-2 — so a save that
	 * rebuilt the old address from the new code would replace that prefix
	 * inside the full address and produce /go/sale-2-2, which is nobody's
	 * link at all. Carrying the exact string makes the match exact.
	 *
	 * @return void
	 */
	public function test_the_exact_address_is_carried_rather_than_rebuilt(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );

		$this->assertStringContainsString(
			"\$wa_short = esc_url_raw( wp_unslash( \$_POST['wa_short'] ?? '' ) );",
			$source,
			'the save no longer receives the address the message was built around'
		);
		$this->assertStringNotContainsString(
			'Kivun_Short_Links::url_for( Kivun_Short_Links::clean_slug( $wanted_slug ) )',
			$source,
			'the save is rebuilding the old address from the code again'
		);

		$script = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );
		$this->assertStringContainsString( "form.dataset.waShort = res.data.url || '';", $script );
		$this->assertStringContainsString( "wa_short: linkForm.dataset.waShort || '',", $script );
	}

	/**
	 * And the code the message names becomes the link's code, rather than
	 * being drawn again at save time.
	 *
	 * A link from before the short codes existed has none to keep and none
	 * typed in its form, so the message would have been written around one
	 * random code and the row stored another. Every one of those messages
	 * points at a code belonging to nobody, and the only sign of it is the
	 * person who clicked the link.
	 *
	 * @return void
	 */
	public function test_the_code_the_message_names_is_the_one_saved(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );

		$this->assertSame(
			3,
			substr_count( $source, "'slug'   => \$slug," ),
			'an answer that does not say which code it used leaves the form to guess'
		);

		$script = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );
		$this->assertStringContainsString( 'slugEl.value = res.data.slug;', $script );
		$this->assertStringContainsString( 'form.dataset.shortResolved = res.data.slug;', $script );
	}

	/**
	 * And where it did not change, the message is left exactly as it was
	 * rather than having its own address swapped for itself.
	 *
	 * @return void
	 */
	public function test_a_code_that_held_is_left_alone(): void {
		$message = "להרשמה:\n" . self::SHORT;

		$this->assertSame(
			$message,
			Kivun_Campaigns::shorten_in_text( $message, self::SHORT, array( self::LONG, '', self::SHORT ) )
		);
	}

	// ── That both save paths do it ────────────────────────────────────────────

	/**
	 * Both places that store a promo run it through the substitution. The
	 * full save is the obvious one; rewording a promo on its own is the more
	 * frequent change, and missing it would put the long URL back.
	 *
	 * @return void
	 */
	public function test_both_paths_that_store_a_promo_shorten_it(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );

		$this->assertSame(
			2,
			substr_count( $source, 'self::shorten_in_text(' ),
			'a path that stores a promo without shortening it puts the long URL back'
		);
	}

	/**
	 * And that the message is built around the short link rather than only
	 * having it swapped in at save time.
	 *
	 * Swapping at save came too late: the text the writer reads, approves
	 * and often copies straight out of the box is the generated one, and it
	 * carried three lines of percent-encoded Hebrew.
	 *
	 * @return void
	 */
	public function test_the_generated_message_is_built_around_the_short_link(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-campaigns.php' );

		$this->assertStringContainsString(
			"\$slug          = Kivun_Short_Links::slug_for_save( \$wanted_slug, \$link_id );",
			$source,
			'the generated promo no longer settles the code the way the save does'
		);
		$this->assertStringContainsString(
			"\$short         = Kivun_Short_Links::url_for( \$slug );",
			$source,
			'the generated promo no longer names the short link'
		);
		$this->assertStringContainsString(
			"\$fields['url'] = '' !== \$short ? \$short : self::build_url( \$target, \$utm );",
			$source,
			'the long URL is no longer the fallback, or is no longer the exception'
		);
	}

	/**
	 * Both the save and the message ask one function which code this form
	 * settled on. Two copies of that reasoning drifting apart would put a
	 * different address in the message than in the row — which is the one
	 * thing the preview exists not to do.
	 *
	 * @return void
	 */
	public function test_the_message_and_the_save_agree_on_the_code(): void {
		$script = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertSame(
			1,
			substr_count( $script, 'function kivunShortChosen(' ),
			'the code a form saves under is worked out in more than one place'
		);
		$this->assertStringContainsString(
			'var shortSlug = kivunShortChosen(linkForm);',
			$script,
			'the save works the code out its own way again'
		);
		$this->assertStringContainsString(
			'slug: kivunShortChosen(form)',
			$script,
			'the message no longer says which link it is for'
		);
	}
}
