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

	// ── That the route survives activation ────────────────────────────────────

	/**
	 * Activation registers the route before it flushes.
	 *
	 * Activation runs after `init` has fired, so nothing that merely hooks
	 * `init` has registered its rules yet, and the flush would write a rule
	 * set without them. The version is stamped at the end of the same
	 * method, which stops the upgrade path from ever scheduling a second
	 * flush — so a route missed there is missed until somebody saves
	 * permalinks by hand, and every short link 404s in the meantime.
	 *
	 * @return void
	 */
	public function test_activation_registers_the_route_before_flushing(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );

		$start = strpos( $source, 'public static function activate()' );
		$this->assertNotFalse( $start );

		$body  = substr( $source, $start, (int) strpos( $source, 'public static function deactivate()' ) - $start );
		$route = strpos( $body, 'Kivun_Short_Links::add_route()' );
		$flush = strpos( $body, 'flush_rewrite_rules()' );

		$this->assertNotFalse( $route, 'activation flushes the rules without registering the short-link route' );
		$this->assertNotFalse( $flush );
		$this->assertLessThan( $flush, $route, 'the route is registered after the flush that was meant to record it' );
	}

	/**
	 * A code that leads nowhere is answered as a 404 rather than left to
	 * render the front page with a 200.
	 *
	 * @return void
	 */
	public function test_an_unknown_code_is_a_real_404(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-short-links.php' );

		$this->assertStringContainsString( 'set_404()', $source );
		$this->assertStringContainsString( 'status_header( 404 )', $source );
	}

	// ── The preview and the stored code are the same code ─────────────────────

	/**
	 * The browser tidies a typed code the same way this does.
	 *
	 * The address shown while typing is sent as the code to save, so the two
	 * cleaners have to agree: if the browser kept a character this strips,
	 * the preview would show one address and the row would hold another —
	 * and the reader would have no reason to doubt the one on screen.
	 *
	 * Checked step by step rather than by running the script, because the
	 * steps are the contract. The two were also compared directly on 22
	 * inputs, Hebrew and punctuation among them.
	 *
	 * @return void
	 */
	public function test_the_browser_tidies_a_code_the_same_way(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$start = strpos( $js, 'function kivunShortClean' );
		$this->assertNotFalse( $start, 'the browser-side cleaner is gone; the preview can no longer be trusted to match' );

		$body = substr( $js, $start, 400 );

		foreach ( array(
			'.toLowerCase()'             => 'case is levelled',
			'.replace(/[ _]/g, \'-\')'   => 'spaces and underscores become hyphens',
			'.replace(/[^a-z0-9-]/g, \'\')' => 'anything else is dropped',
			'.replace(/-{2,}/g, \'-\')'  => 'runs of hyphens collapse',
			'.slice(0, 64)'              => 'it is cut to the column width',
		) as $step => $why ) {
			$this->assertStringContainsString( $step, $body, "the browser no longer does this, but PHP does: {$why}" );
		}
	}

	/**
	 * And it draws a generated code from this alphabet rather than one of
	 * its own, because the field carries this one to it.
	 *
	 * @return void
	 */
	public function test_the_browser_is_given_this_alphabet(): void {
		$php = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$js  = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertStringContainsString( 'Kivun_Short_Links::ALPHABET', $php, 'the field no longer carries the alphabet' );
		$this->assertStringContainsString( 'dataset.shortAlphabet', $js, 'the browser no longer reads it, so it has one of its own' );
		$this->assertStringNotContainsString( self::class, $js );
	}

	// ── The code is drawn, never typed ────────────────────────────────────────

	/**
	 * The form has no box to type a code into.
	 *
	 * It used to have one, beside a line that spelled out the same address —
	 * two places saying one thing, which is what sent this back. The code is
	 * generated for every link now, so the field only carries it to the save.
	 *
	 * @return void
	 */
	public function test_there_is_nothing_to_type_a_code_into(): void {
		$php = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString( 'type="hidden"', $php );
		$this->assertStringContainsString( 'class="kivun-short-slug"', $php );
		$this->assertStringNotContainsString( 'יווצר אוטומטית', $php, 'the typed-code box is back' );
		$this->assertStringNotContainsString( 'kivun-short-slug__base', $php );
	}

	/**
	 * The line that spells out the address is shown only where there is no
	 * address yet.
	 *
	 * Once the link is saved its real short URL sits above with a copy
	 * button, and a preview of what it is "about to be" beside it is the
	 * same duplication in its other form.
	 *
	 * @return void
	 */
	public function test_the_preview_belongs_to_a_link_that_has_none(): void {
		$php   = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$start = strpos( $php, "<?php if ( '' === \$kivun_edit_short ) : ?>" );

		$this->assertIsInt( $start, 'the preview is no longer kept to links without a code' );
		$this->assertStringContainsString(
			'kivun-short-preview',
			substr( $php, $start, 1800 ),
			'the preview moved out of that branch'
		);
	}

	/**
	 * And the machinery that served typing is gone with it.
	 *
	 * Asking the server whether a code is taken, and the warning that said
	 * so, only ever answered a code somebody had typed. The server still
	 * settles collisions on save, which is where it always mattered.
	 *
	 * @return void
	 */
	public function test_the_typing_machinery_went_with_it(): void {
		$js  = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );
		$php = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-short-links.php' );
		$cc  = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		foreach ( array( 'kivunShortCheck', 'kivunShortTaken', 'shortResolved', 'shortAsked' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $js, "{$gone} is still there with nothing to feed it" );
		}
		$this->assertStringNotContainsString( 'ajax_preview', $php );
		$this->assertStringNotContainsString( 'kivun_short_preview', $php );
		$this->assertStringNotContainsString( 'short-preview__taken', $cc );

		// What still settles a collision, and must not have gone with them.
		$this->assertStringContainsString( 'function resolve_slug', $php );
		$this->assertStringContainsString( 'function slug_for_save', $php );
	}

	// ── Every link has a code ─────────────────────────────────────────────────

	/**
	 * Links made before the short codes existed are given one on upgrade.
	 *
	 * Their rows carried an empty slug, so the list had no code to show and
	 * offered the long tagged URL alone — the one thing nobody wants to
	 * paste into a message. The column means the same on every row only if
	 * every row has a code.
	 *
	 * @return void
	 */
	public function test_links_without_a_code_are_given_one(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );

		$this->assertStringContainsString( 'function backfill_short_codes', $source );
		$this->assertStringContainsString( "WHERE slug IS NULL OR slug = ''", $source );
		$this->assertStringContainsString( 'self::backfill_short_codes();', $source, 'the backfill is never called' );
	}

	/**
	 * And it leaves the codes that already exist alone.
	 *
	 * A code already out in the world — in a WhatsApp message, on a printed
	 * flyer — is the one promise this feature makes. A migration that
	 * reshuffled them would break the links shared the longest.
	 *
	 * @return void
	 */
	public function test_the_backfill_never_touches_a_code_that_exists(): void {
		$source  = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-installer.php' );
		$start   = (int) strpos( $source, 'private static function backfill_short_codes' );
		$handler = substr( $source, $start, 1600 );

		// It only ever selects the empty ones, and only ever updates by the
		// id it just read.
		$this->assertStringNotContainsString( 'UPDATE', $handler, 'a bulk update cannot tell an empty code from a shared one' );
		$this->assertStringContainsString( "array( 'id' => \$id )", $handler );
	}

	/**
	 * The copy button beside a short link copies that link.
	 *
	 * It used to copy the WhatsApp message instead: the field it was next to
	 * was not in the list of fields the handler knew, so it fell through to
	 * a fallback that reaches across the whole card — and the card holds the
	 * message. Silent, and wrong in the worst way, since what lands in the
	 * message is what the publisher sends out.
	 *
	 * @return void
	 */
	public function test_the_copy_button_copies_the_link_beside_it(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertStringContainsString(
			".kivun-camp-result, .kivun-camp-saved, .kivun-camp-short, .kivun-short-preview__url",
			$js,
			'a short link field the handler does not know falls through to the message'
		);
	}
}
