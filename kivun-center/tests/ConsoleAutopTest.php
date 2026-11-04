<?php
/**
 * The console's markup survives a page builder that runs wpautop() on it.
 *
 * On the live site the publish tab came out with its four type tiles one
 * grid cell apart, two rows deep, each tile tall with its icon floating in
 * the middle. Elementor's text widget runs the shortcode's output through
 * wpautop(), which turned every newline between two tags into a `<br />`:
 * 138 of them on that tab, each one a box, each one taking a cell.
 *
 * The shortcode now hands back its markup on one line. What has to hold is
 * that this costs nothing: no text moves (a newline between tags was only
 * ever rendered as one space), and a textarea keeps every line it had —
 * those newlines are somebody's WhatsApp message.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Flattening the console's output.
 */
final class ConsoleAutopTest extends TestCase {

	/**
	 * No newline is left for wpautop() to turn into a break.
	 *
	 * @return void
	 */
	public function test_no_newline_is_left_between_tags(): void {
		$html = "<div class=\"a\">\n\t\t<label>\n\t\t\t<input type=\"checkbox\">\n\t\t\t<span>דף נחיתה</span>\n\t\t</label>\n\n\t\t<label>קורס</label>\n</div>\n";
		$out  = Kivun_Content_Creator::flatten_output( $html );

		$this->assertStringNotContainsString( "\n", $out );
		$this->assertStringNotContainsString( "\r", $out );
		$this->assertSame(
			'<div class="a"> <label> <input type="checkbox"> <span>דף נחיתה</span> </label> <label>קורס</label> </div> ',
			$out,
			'whitespace with a newline in it is one space — all the browser ever rendered it as'
		);
	}

	/**
	 * Text on one line is left exactly as it was.
	 *
	 * @return void
	 */
	public function test_text_on_one_line_is_untouched(): void {
		$html = '<label>כותרת <span class="kivun-cc-req">*</span></label>';
		$this->assertSame( $html, Kivun_Content_Creator::flatten_output( $html ) );
	}

	/**
	 * A textarea keeps every line, written so wpautop() cannot see it.
	 *
	 * @return void
	 */
	public function test_a_textarea_keeps_its_lines(): void {
		$html = "<p>\n<textarea name=\"whatsapp\" rows=\"8\">שורה אחת\r\nשורה שתיים\n\nאחרי שורה ריקה</textarea>\n</p>";
		$out  = Kivun_Content_Creator::flatten_output( $html );

		$this->assertStringNotContainsString( "\n", $out );
		$this->assertStringContainsString(
			'<textarea name="whatsapp" rows="8">שורה אחת&#10;שורה שתיים&#10;&#10;אחרי שורה ריקה</textarea>',
			$out,
			'a line in the message was lost or merged'
		);
		// What the browser reads back: the same text, line for line.
		preg_match( '#<textarea[^>]*>(.*)</textarea>#s', $out, $m );
		$this->assertSame( "שורה אחת\nשורה שתיים\n\nאחרי שורה ריקה", html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Several textareas, each with its own content, each kept separately.
	 *
	 * @return void
	 */
	public function test_every_textarea_is_kept(): void {
		$html = "<textarea name=\"a\">1\n2</textarea>\n<div>\n</div>\n<TEXTAREA name=\"b\">3\n4</TEXTAREA>";
		$this->assertSame(
			'<textarea name="a">1&#10;2</textarea> <div> </div> <TEXTAREA name="b">3&#10;4</TEXTAREA>',
			Kivun_Content_Creator::flatten_output( $html )
		);
	}

	/**
	 * Scripts, styles and pre blocks pass through untouched.
	 *
	 * A newline can end a statement in a script, and pre is wpautop()'s own
	 * exception, so neither is rewritten.
	 *
	 * @return void
	 */
	public function test_scripts_and_pre_are_untouched(): void {
		$script = "<script>\nvar a = 1\nvar b = 2 // two\n</script>";
		$style  = "<style>\n.a { color: red; }\n</style>";
		$pre    = "<pre>\n  keep\n</pre>";
		$out    = Kivun_Content_Creator::flatten_output( "<div>\n" . $script . "\n" . $style . "\n" . $pre . "\n</div>" );

		$this->assertStringContainsString( $script, $out );
		$this->assertStringContainsString( $style, $out );
		$this->assertStringContainsString( $pre, $out );
	}

	/**
	 * What wpautop() still adds after the flattening is not drawn.
	 *
	 * With no newlines left it can no longer make breaks, but it still wraps
	 * any run of inline markup between two blocks in a paragraph of its own —
	 * a hidden input and the switch beside it, a label and its field — each
	 * with 16px of margin. Every paragraph of the wizard's and the library's
	 * own carries a class, so a classless one is wpautop()'s, and is taken
	 * out of the layout.
	 *
	 * @return void
	 */
	public function test_wpautop_s_own_paragraphs_take_no_room(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertMatchesRegularExpression(
			'/\.kivun-cc-wizard p:not\(\[class\]\),\s*\.kivun-lib-card p:not\(\[class\]\)\s*\{\s*display:\s*contents;/',
			$css,
			'wpautop()\'s wrapping paragraphs are drawn again, each with its margins'
		);

		// Which is only safe while no paragraph of ours goes without a class.
		$src  = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$from = (int) strpos( $src, 'private static function front_form(' );
		$to   = (int) strpos( $src, 'private static function library_table(', $from );
		$to   = (int) strpos( $src, "\n\t}\n", $to );
		$this->assertStringNotContainsString( '<p>', substr( $src, $from, $to - $from ), 'a classless paragraph of our own would be flattened away with wpautop()\'s' );
	}

	/**
	 * The shortcode is what hands the console back flattened.
	 *
	 * The function on its own fixes nothing; the page builder sees whatever
	 * the shortcode returns.
	 *
	 * @return void
	 */
	public function test_the_shortcode_returns_flattened_markup(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString(
			'return self::flatten_output( (string) ob_get_clean() );',
			$src,
			'the console reaches the page builder with its newlines, and wpautop() turns each one into a box'
		);
	}
}
