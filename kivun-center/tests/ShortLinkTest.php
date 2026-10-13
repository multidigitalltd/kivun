<?php
/**
 * Short links for campaign URLs.
 *
 * A campaign link carries its UTM parameters in the query string — which is
 * what makes it work and what makes it unshareable. A Hebrew campaign name
 * percent-encodes into a line of noise, and the result does not fit in a
 * message. The short code is what gets shared; the long URL is still what
 * the visitor is sent to, so the analytics see what they always saw.
 *
 * The part worth pinning down is the code itself. It is read off a screen,
 * said down a phone and printed on paper, and once it has been sent to
 * people it cannot change — so what it may contain, and when it is allowed
 * to be regenerated, are not cosmetic questions.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-short-links.php';

/**
 * Tests for Kivun_Short_Links.
 */
final class ShortLinkTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	// ── What a code may contain ───────────────────────────────────────────────

	/**
	 * Plain text survives as it is.
	 *
	 * @return void
	 */
	public function test_a_plain_code_is_kept(): void {
		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::clean_slug( 'newsletter-oct' ) );
	}

	/**
	 * Spaces and underscores become hyphens, and case is levelled — the same
	 * code said two ways should not be two codes.
	 *
	 * @return void
	 */
	public function test_spacing_and_case_are_levelled(): void {
		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::clean_slug( '  Newsletter Oct  ' ) );
		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::clean_slug( 'newsletter_oct' ) );
		$this->assertSame( 'a-b', Kivun_Short_Links::clean_slug( 'a---b' ) );
		$this->assertSame( 'ab', Kivun_Short_Links::clean_slug( '-ab-' ) );
	}

	/**
	 * Hebrew is dropped rather than carried.
	 *
	 * This is the whole point of the feature: Hebrew in a URL percent-encodes
	 * into exactly the unreadable string the short link exists to replace, so
	 * letting it into the code would reproduce the problem in the fix.
	 *
	 * @return void
	 */
	public function test_hebrew_does_not_reach_the_url(): void {
		$this->assertSame( '', Kivun_Short_Links::clean_slug( 'קמפיין' ) );
		$this->assertSame( 'oct', Kivun_Short_Links::clean_slug( 'קמפיין oct' ) );
	}

	/**
	 * Nor does anything that would need escaping, or change what the path
	 * means.
	 *
	 * @return void
	 */
	public function test_nothing_that_would_need_escaping_gets_through(): void {
		foreach ( array( 'a/b', 'a?b=1', 'a#b', 'a b', '../x', 'a%20b', '<script>' ) as $raw ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z0-9-]*$/',
				Kivun_Short_Links::clean_slug( $raw ),
				$raw
			);
		}
	}

	/**
	 * And it cannot outgrow its column.
	 *
	 * @return void
	 */
	public function test_a_code_fits_the_column(): void {
		$this->assertLessThanOrEqual( 64, strlen( Kivun_Short_Links::clean_slug( str_repeat( 'ab', 80 ) ) ) );
	}

	// ── Generated codes ───────────────────────────────────────────────────────

	/**
	 * A generated code holds only characters that survive being read aloud:
	 * no 0/o, 1/l/i or 5/s, which is the difference between a code somebody
	 * can pass on and one they have to send a screenshot of.
	 *
	 * @return void
	 */
	public function test_a_generated_code_has_no_characters_that_are_read_wrong(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$slug = Kivun_Short_Links::random_slug();

			$this->assertSame( Kivun_Short_Links::LENGTH, strlen( $slug ) );
			$this->assertMatchesRegularExpression( '/^[23467889abcdefghjkmnpqrtuvwxyz]+$/', $slug );
			$this->assertSame( $slug, Kivun_Short_Links::clean_slug( $slug ), 'a generated code must survive its own cleaning' );
		}
	}

	/**
	 * The alphabet really excludes the confusable characters, rather than
	 * happening not to produce them in a sample.
	 *
	 * @return void
	 */
	public function test_the_alphabet_excludes_the_confusable_characters(): void {
		foreach ( array( '0', 'o', '1', 'l', 'i', '5', 's' ) as $character ) {
			$this->assertStringNotContainsString(
				$character,
				Kivun_Short_Links::ALPHABET,
				"\"{$character}\" is too easily read as another character"
			);
		}
	}

	/**
	 * Generated codes differ from one another. A generator that returned the
	 * same code twice would point two campaigns at one page.
	 *
	 * @return void
	 */
	public function test_generated_codes_are_not_all_the_same(): void {
		$seen = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$seen[ Kivun_Short_Links::random_slug() ] = true;
		}

		$this->assertGreaterThan( 190, count( $seen ) );
	}

	// ── The address ───────────────────────────────────────────────────────────

	/**
	 * The public address is the site's own, under the short path.
	 *
	 * @return void
	 */
	public function test_the_address_is_built_from_the_site_url(): void {
		$this->assertSame(
			'https://example.test/go/ab3k',
			Kivun_Short_Links::url_for( 'ab3k' )
		);
	}

	/**
	 * A link with no code has no address, rather than one pointing at the
	 * short path with nothing after it.
	 *
	 * @return void
	 */
	public function test_no_code_means_no_address(): void {
		$this->assertSame( '', Kivun_Short_Links::url_for( '' ) );
		$this->assertSame( '', Kivun_Short_Links::url_for( '   ' ) );
	}

	/**
	 * The path is one segment, so the rewrite rule that reads it and the
	 * address that is handed out cannot disagree.
	 *
	 * @return void
	 */
	public function test_the_address_matches_the_route_it_is_read_by(): void {
		$url  = Kivun_Short_Links::url_for( 'newsletter-oct' );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$this->assertMatchesRegularExpression(
			'#^/' . Kivun_Short_Links::BASE . '/([^/]+)/?$#',
			$path,
			'the generated address is not matched by the rewrite rule that resolves it'
		);
	}

	// ── Choosing the code to store ────────────────────────────────────────────

	/**
	 * Answer the two reads the resolver makes, without pretending to be a
	 * database: which codes are taken, and what a given link already has.
	 *
	 * @param array<int,string> $taken    Codes already in use.
	 * @param string            $existing The code the link being saved has.
	 * @return void
	 */
	private function database( array $taken = array(), string $existing = '' ): void {
		$GLOBALS['wpdb']->var_handler = static function ( string $sql ) use ( $taken, $existing ) {
			if ( false !== strpos( $sql, 'SELECT slug' ) ) {
				return $existing;
			}

			foreach ( $taken as $slug ) {
				if ( false !== strpos( $sql, "slug = '" . $slug . "'" ) ) {
					return 99;
				}
			}

			return null;
		};
	}

	/**
	 * Clear the stub again.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb']->var_handler = null;
	}

	/**
	 * A code somebody typed is the code they get.
	 *
	 * @return void
	 */
	public function test_a_free_code_is_given_as_asked(): void {
		$this->database();

		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::slug_for_save( 'Newsletter Oct', 0 ) );
	}

	/**
	 * A code already in use is not handed out twice — the second link would
	 * take the first one's visitors.
	 *
	 * @return void
	 */
	public function test_a_taken_code_is_not_given_out_again(): void {
		$this->database( array( 'newsletter' ) );

		$this->assertSame( 'newsletter-2', Kivun_Short_Links::slug_for_save( 'newsletter', 0 ) );
	}

	/**
	 * A new link with nothing typed gets a generated code rather than none.
	 *
	 * @return void
	 */
	public function test_a_new_link_is_given_a_code(): void {
		$this->database();

		$this->assertMatchesRegularExpression( '/^[a-z0-9]{5}$/', Kivun_Short_Links::slug_for_save( '', 0 ) );
	}

	/**
	 * The one that matters: editing a link without naming a code keeps the
	 * code it has. A fresh code on every save would break every link already
	 * sent out — silently, because the screen would show the new one and
	 * look perfectly correct.
	 *
	 * @return void
	 */
	public function test_editing_keeps_a_code_that_has_already_been_shared(): void {
		$this->database( array(), 'newsletter-oct' );

		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::slug_for_save( '', 42 ) );
		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::slug_for_save( '   ', 42 ) );
		$this->assertSame( 'newsletter-oct', Kivun_Short_Links::slug_for_save( 'קמפיין', 42 ), 'a code with nothing usable in it is not a new code' );
	}

	/**
	 * But naming a new one does change it, because that is the only way to
	 * change it on purpose.
	 *
	 * @return void
	 */
	public function test_editing_with_a_new_code_changes_it(): void {
		$this->database( array(), 'ab3k7' );

		$this->assertSame( 'winter', Kivun_Short_Links::slug_for_save( 'Winter', 42 ) );
	}

	/**
	 * An old link that never had a code gets one when it is next saved.
	 *
	 * @return void
	 */
	public function test_a_link_without_a_code_gains_one_on_save(): void {
		$this->database( array(), '' );

		$this->assertMatchesRegularExpression( '/^[a-z0-9]{5}$/', Kivun_Short_Links::slug_for_save( '', 42 ) );
	}
}
