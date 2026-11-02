<?php
/**
 * What the content console's markup owes the code behind it.
 *
 * A redesign changes markup, and markup is not only how the console looks —
 * it is also how the console works. Two contracts run through it, and both
 * break silently, which is what makes them worth a test:
 *
 * 1. **The fields the save reads.** A field dropped or renamed in a redesign
 *    does not raise an error. The save reads it as "empty" and writes that
 *    over whatever was there, so the first sign of trouble is a customer
 *    saying their text disappeared.
 *
 * 2. **The classes the script binds to.** A wrapper renamed for tidiness
 *    leaves `querySelector` returning null and the handler never firing.
 *    The control is still on the screen and still looks live. Four review
 *    rounds in a row came from this exact failure, in this exact file.
 *
 * Neither contract is about appearance, so neither constrains the design.
 * Both say: whatever this ends up looking like, these names survive.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The console's markup contracts.
 */
final class ConsoleContractTest extends TestCase {

	/**
	 * The fields the content console's form must carry.
	 *
	 * Written out rather than scanned, because the whole point is that it
	 * cannot change quietly: scanning the file the test is checking would
	 * make the test agree with whatever the file happens to say. Adding a
	 * field here is a deliberate act; so is removing one.
	 *
	 * @var string[]
	 */
	private const SAVED_FIELDS = array(
		// What the content is.
		'title',
		'slug',
		'audience',
		'city',
		'cost',
		'date',
		'duration',
		'email',
		'whatsapp',
		'group',
		'status',
		'schedule',
		'redirect',
		'action',

		// The call to action.
		'cta_title',
		'cta_content',
		'cta_button',

		// Course.
		'course_capacity',
		'course_wc',

		// Session.
		'session_location',
		'session_capacity',
		'session_valid_until',

		// Event, which has its own fields rather than the general ones.
		'event_date',
		'event_time',
		'event_location',
		'event_capacity',
		'event_mode',
		'event_url',
		'event_button',
		'event_popup',
		'event_image_id',
		'event_image_file',

		// The two pictures, each with the flag that says "removed" rather
		// than "unchanged". Without the flag the save cannot tell them
		// apart, and removing an image does nothing.
		'thumbnail_id',
		'thumbnail_file',
		'remove_thumbnail',
		'share_image_id',
		'share_image_file',
		'remove_share_image',
	);

	/**
	 * The content console's form, from `<form>` to its matching close.
	 *
	 * @return string
	 */
	private function console_form(): string {
		$src   = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$lines = explode( "\n", $src );

		$start = null;
		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, '<form' ) && false !== strpos( $line, 'kivun-cc-form' ) ) {
				$start = $i;
				break;
			}
		}
		$this->assertNotNull( $start, 'the content console form is gone' );

		$depth = 0;
		$end   = count( $lines ) - 1;
		for ( $i = $start; $i < count( $lines ); $i++ ) {
			$depth += substr_count( $lines[ $i ], '<form' );
			$depth -= substr_count( $lines[ $i ], '</form>' );
			if ( 0 === $depth && $i > $start ) {
				$end = $i;
				break;
			}
		}

		return implode( "\n", array_slice( $lines, $start, $end - $start + 1 ) );
	}

	// ── 1. The fields the save reads ─────────────────────────────────────────

	/**
	 * Every saved field is still on the form.
	 *
	 * @return void
	 */
	public function test_every_saved_field_is_still_on_the_form(): void {
		$form = $this->console_form();

		foreach ( self::SAVED_FIELDS as $field ) {
			$this->assertStringContainsString(
				'name="' . $field . '"',
				$form,
				$field . ' is no longer on the console form. The save still reads it, so it will read empty and overwrite what is stored.'
			);
		}
	}

	/**
	 * And the two rich-text fields and the nonce, which are not written as
	 * plain inputs.
	 *
	 * `wp_editor()` emits the textarea and its name; `wp_nonce_field()`
	 * emits the nonce. A redesign that replaces an editor with a plain
	 * textarea would keep the field working, so what is checked here is
	 * only that something still emits each name.
	 *
	 * @return void
	 */
	public function test_the_editors_and_the_nonce_are_still_emitted(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		foreach ( array( 'short', 'long' ) as $name ) {
			$this->assertMatchesRegularExpression(
				'/(wp_editor\([^;]*|name=")' . $name . '/s',
				$src,
				'nothing emits the ' . $name . ' field any more'
			);
		}

		$this->assertStringContainsString(
			"wp_nonce_field( 'kivun_create_content_front', 'kivun_cc_front_nonce'",
			$src,
			'the console form has no nonce, so every save from it will be rejected'
		);
	}

	// ── 2. The classes the script binds to ───────────────────────────────────

	/**
	 * Every console class the script reaches for is still emitted.
	 *
	 * Derived from the script rather than listed, so it covers whatever the
	 * script asks for today without this test being kept in step by hand.
	 * It is the direction that fails silently: the script keeps asking for
	 * a class, the markup stops providing it, and the handler simply never
	 * runs.
	 *
	 * @return void
	 */
	public function test_every_class_the_script_binds_is_still_emitted(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		preg_match_all( "/'\.(kivun-cc[a-z0-9_-]*)'/", $js, $m );
		$wanted = array_unique( $m[1] );

		$this->assertGreaterThan(
			15,
			count( $wanted ),
			'this test found almost no console selectors; the script moved or the pattern no longer matches'
		);

		$php = '';
		foreach ( glob( KIVUN_DIR . '{includes,admin,shortcodes,templates}/*.php', GLOB_BRACE ) as $file ) {
			$php .= (string) file_get_contents( $file );
		}
		foreach ( glob( KIVUN_DIR . 'templates/*/*.php' ) as $file ) {
			$php .= (string) file_get_contents( $file );
		}

		// As whole class names, not substrings. A plain `strpos` finds
		// `kivun-cc-media` inside `kivun-cc-media__preview`, so renaming
		// the wrapper while leaving its children alone passed this test
		// while the wrapper's own handlers were dead — which is the very
		// case it exists to catch.
		$missing = array();
		foreach ( $wanted as $class ) {
			$whole = '/(?<![a-z0-9_-])' . preg_quote( $class, '/' ) . '(?![a-z0-9_-])/';
			if ( ! preg_match( $whole, $php ) ) {
				$missing[] = $class;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"the script binds to these classes and nothing emits them any more, so their controls are dead while still looking live:\n  ." . implode( "\n  .", $missing )
		);
	}

	/**
	 * The wizard's own structure survives.
	 *
	 * The steps are driven by attributes, not classes, so a redesign that
	 * rebuilt the markup by hand could drop them without touching a single
	 * class name — and the console would open on step one with no way
	 * forward.
	 *
	 * @return void
	 */
	public function test_the_wizard_structure_survives(): void {
		$form = $this->console_form();

		// Each step by name. Checking only that `data-step=` appears
		// somewhere passed while a step had been renamed away, because the
		// other three still carried the attribute.
		foreach ( array( 1, 2, 3, 4 ) as $step ) {
			$this->assertStringContainsString(
				'data-step="' . $step . '"',
				$form,
				'step ' . $step . ' of the wizard is gone, so nothing shows that part of the form'
			);
		}
		$this->assertStringContainsString( 'kivun-cc-wizard', $form, 'the form is no longer a wizard, so no script drives its steps' );

		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertStringContainsString( 'data-current=', $src, 'nothing says which step the wizard is on' );

		// The type toggles decide which sections are shown and what is
		// created, so they are structure rather than decoration.
		$this->assertStringContainsString( 'kivun-cc-toggle', $src, 'the content-type toggles are gone' );
		foreach ( array( 'landing', 'course', 'session', 'event' ) as $type ) {
			$this->assertStringContainsString(
				'data-type="' . $type . '"',
				$src,
				'the ' . $type . ' type is no longer offered'
			);
		}
	}
}
