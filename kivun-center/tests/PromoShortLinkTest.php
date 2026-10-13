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
}
