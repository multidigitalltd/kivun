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

	/**
	 * An action drawn as an icon still says what it is.
	 *
	 * The library's row actions lost their words to the design — view,
	 * duplicate, archive and delete are marks now, and only editing keeps
	 * its label. A mark with no accessible name is a button that a screen
	 * reader announces as "button" and a hover explains not at all, and
	 * four of them in a row would be unusable. The word has to survive the
	 * pixels.
	 *
	 * @return void
	 */
	public function test_an_icon_action_still_says_what_it_is(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		// Every icon-only control in the file, wherever it is. Stated over all
		// of them rather than over one cell: an earlier version read "the
		// first `.kivun-cc-rowactions` in the file", and started inspecting a
		// different screen's actions the moment a second one adopted the
		// same markup.
		/*
		 * PHP blocks come out first. An attribute whose value is an echoed
		 * PHP block carries that block's own closing angle bracket, so a tag
		 * pattern that stops at the first one reads half a tag and reports a
		 * missing attribute that is plainly there.
		 *
		 * Written as a block comment on purpose: a line comment holding a
		 * PHP close tag ends the PHP block, which is what the first version
		 * of this comment did to the rest of the file.
		 */
		$tags = (string) preg_replace( '/<\?php.*?\?>/s', 'PHP', $src );
		preg_match_all( '/<(?:a|button)\b[^>]*\bclass="[^"]*kivun-cc-icon-btn[^"]*"[^>]*>/', $tags, $m );
		$this->assertNotEmpty( $m[0], 'there are no icon-only controls; this test now checks nothing' );

		foreach ( $m[0] as $tag ) {
			$this->assertStringContainsString(
				'aria-label=',
				$tag,
				"an icon-only control carries no accessible name:\n  " . trim( preg_replace( '/\s+/', ' ', $tag ) )
			);
		}

		// Archiving says one of two things depending on where the row is, so
		// its name comes from a variable rather than a literal — which is
		// still a name, as long as the variable holds the words it replaced.
		$this->assertMatchesRegularExpression(
			'/\$kivun_archive_label\s*=\s*\$archived\s*\?\s*__\( \'החזרה מהארכיון\'.*__\( \'העברה לארכיון\'/u',
			$src,
			'the archive action names itself from something other than the word it used to show'
		);

		// Duplicate and delete are built by helpers, which name the button
		// from the same word they would have printed on it.
		$this->assertStringContainsString(
			"self::icon_button_parts( \$icon,",
			$src,
			'the form helpers no longer route their label into an accessible name'
		);
		$this->assertMatchesRegularExpression(
			'/aria-label="\' \. esc_attr\( \$label \)[^;]*title="\' \. esc_attr\( \$label \)/s',
			$src,
			'an icon-only form button carries neither a label nor a tooltip'
		);

		// And the one action per row that keeps its word still has it: the
		// whole point of making the others marks is that one is not.
		preg_match_all( '/<(?:a|button)\b[^>]*\bclass="[^"]*kivun-cc-act--edit[^"]*"[^>]*>(.*?)<\/(?:a|button)>/s', $src, $labelled );
		$this->assertNotEmpty( $labelled[0], 'no action keeps its label any more' );
		foreach ( $labelled[1] as $i => $inside ) {
			$this->assertMatchesRegularExpression(
				'/esc_html_e?_?_?\(\s*\'[^\']+\'/',
				$inside,
				'the labelled action at position ' . $i . ' shows no word, which leaves the row all marks'
			);
		}
	}

	/**
	 * The library's filter works on what is already on the page.
	 *
	 * Both lists are rendered server-side — the archive used to sit below
	 * the table in a shut `<details>` — which is the whole reason the
	 * search and the pane switch can be client-side and instant. If the
	 * archive ever stopped being rendered, the toggle would quietly show an
	 * empty pane rather than fetching anything.
	 *
	 * @return void
	 */
	public function test_both_lists_are_on_the_page_for_the_filter(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		// The two the segmented control switches between, named rather than
		// counted — other screens use panes too, and counting them made this
		// test fail the moment one more appeared.
		$this->assertStringContainsString( 'data-pane="live"', $src, 'the live pane is gone' );
		$this->assertStringContainsString( 'data-pane="archive"', $src, 'the archive pane is gone, so the control would switch to nothing' );
		$this->assertStringContainsString( 'self::library_table( $put_away,', $src, 'the archive list is no longer rendered' );

		// The search reads the title off the cell rather than the row, so
		// that searching "קורס" does not match every row that happens to be
		// one.
		$this->assertStringContainsString( 'data-search="', $src, 'nothing marks which text the search should match' );

		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );
		$this->assertStringContainsString( "querySelector('[data-search]')", $js, 'the search no longer reads the title cell' );
	}

	/**
	 * A builder row follows the row it belongs to.
	 *
	 * The custom-forms table puts each page's form builder in a row of its
	 * own beneath it. It carries no title, so searching it as if it were a
	 * list row would match on whatever words happen to be inside the
	 * builder — and filtering a page out while leaving its open builder
	 * behind would strand a panel under somebody else's row.
	 *
	 * @return void
	 */
	public function test_a_builder_row_follows_its_own_row(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$at = strpos( $js, 'var ccFilterBar' );
		$this->assertNotFalse( $at, 'the library filter moved' );
		$body = substr( $js, $at, 2600 );

		$this->assertStringContainsString(
			'formEdit',
			$body,
			'the filter treats the builder rows as rows of the list'
		);
		$this->assertMatchesRegularExpression(
			'/nextElementSibling[\s\S]{0,200}formEdit[\s\S]{0,200}data-filtered/',
			$body,
			'a filtered row does not take its builder row with it'
		);
	}

	/**
	 * A row the search ruled out is actually hidden.
	 *
	 * Two rules set a display on these rows. The narrow-screen fallback's
	 * `… > tbody > tr { display: block }` carries three classes to the
	 * filter's two, so below 782px it won: the row was marked, counted as
	 * filtered, and still drawn. Measured at 390px — searching the archive
	 * for something absent left the row marked and visible.
	 *
	 * @return void
	 */
	public function test_a_filtered_row_is_actually_hidden(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$this->assertMatchesRegularExpression(
			'/tr\[data-filtered\]\s*\{\s*display:\s*none\s*!important/',
			$css,
			'a filtered row can be out-specified by a layout rule and stay on screen'
		);
	}

	/**
	 * Revealing the archive measures its table.
	 *
	 * `fitTables()` cannot measure a table whose wrapper has no width, so a
	 * pane that starts hidden is skipped and never gets the labelled card
	 * layout — it opens as the unlabelled fallback, or overflows. The
	 * archive used to live in a `<details>`, whose toggle event triggered
	 * the remeasure; the segmented control that replaced it fires no such
	 * event, and quietly took the remeasure with it.
	 *
	 * @return void
	 */
	public function test_revealing_the_archive_measures_its_table(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/admin-crm.js' );

		$at = strpos( $js, 'function fitTablesSoon' );
		$this->assertNotFalse( $at, 'the table-fitting helper moved' );

		$this->assertMatchesRegularExpression(
			'/closest\(\'[^\']*\.kivun-cc-seg[^\']*\'\)\s*\)\s*\{\s*fitTablesSoon\(\)/',
			substr( $js, $at ),
			'switching to the archive no longer remeasures it, so it opens unstacked'
		);
	}

	/**
	 * The actions cell stays a table cell.
	 *
	 * `display: flex` on a `<td>` takes it out of table layout, so it stops
	 * stretching to its row. Measured at 59px in a row whose other cells
	 * were 64px, which drew its bottom border five pixels above theirs. It
	 * had been that way for a long time and the zebra striping hid it; with
	 * the stripes gone the separator carries the structure, and a broken one
	 * is the first thing you see.
	 *
	 * @return void
	 */
	public function test_the_actions_cell_stays_a_table_cell(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$this->assertStringContainsString(
			'td.kivun-cc-rowactions { display: table-cell; }',
			$css,
			'the actions cell is flexed again, so it no longer stretches to its row and its separator sits high'
		);
	}

	/**
	 * The narrow-screen fallback yields to the labelled one.
	 *
	 * Two mobile strategies target the same cells. `admin-crm.js` measures
	 * each table, copies every column heading onto its cells as `data-label`
	 * and lays the row out as a card; the no-script fallback just turns
	 * everything into blocks and drops the header. The fallback's selectors
	 * carry three elements to the good rule's one, so below 782px it won —
	 * and the labels the script had just written were rendered inline
	 * against their values, giving "פניות34" and "תאריך15.9.2025".
	 *
	 * @return void
	 */
	public function test_the_narrow_fallback_yields_to_the_labelled_one(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		// Every rule in the file that re-lays-out a console table — whatever
		// media query it sits in and whatever order the blocks are written
		// in — has to leave the stacked one alone. Stated over the rules
		// themselves rather than over a slice of the file, because there is
		// more than one `@media (max-width: 782px)` and an earlier version
		// of this test anchored on the wrong one.
		preg_match_all( '/([^{}]*\.kivun-cc-tablewrap[^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );
		$this->assertNotEmpty( $rules, 'nothing styles the console table wrapper any more' );

		$checked = 0;
		foreach ( $rules as $rule ) {
			list( , $selector, $decls ) = $rule;

			// Only the rules that change how the table is laid out. Hiding
			// the header or a hidden row is right either way.
			if ( false === strpos( $decls, 'display: block' ) ) {
				continue;
			}
			if ( false === strpos( $selector, '.kivun-cc-table' ) ) {
				continue;
			}

			++$checked;
			$this->assertStringContainsString(
				':not(.is-stacked)',
				$selector,
				'this rule re-lays-out the table without sparing .is-stacked, so the unlabelled blocks beat the labelled cards:' . "\n  " . trim( $selector )
			);
		}

		$this->assertGreaterThan( 0, $checked, 'the narrow-screen fallback is gone; this test now checks nothing' );
	}

	/**
	 * The step rail stays inside the form.
	 *
	 * This one is new, and it is the reason to write it down. The handler
	 * finds the steps with `form.querySelectorAll('.kivun-wiz-step')` and
	 * walks back up from a click with `closest('.kivun-cc-wizard')`. The
	 * rail now sits in a sidebar, which looks like a piece of page
	 * furniture and invites being moved out next to the heading — and a
	 * rail outside the form still draws perfectly, still highlights
	 * nothing, and no longer navigates. Nothing about the markup hints at
	 * that, so the test says it.
	 *
	 * @return void
	 */
	public function test_the_step_rail_stays_inside_the_form(): void {
		$form = $this->console_form();

		$this->assertStringContainsString(
			'kivun-wiz-steps',
			$form,
			'the step rail is outside the form, so clicking a step cannot reach the wizard it belongs to'
		);
		$this->assertStringContainsString(
			'data-gostep=',
			$form,
			'the steps no longer say which step they go to'
		);

		// And the rail must not have become a second copy: two sets of steps
		// would both be highlighted, and only one would be the one you see.
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );
		$this->assertSame(
			1,
			substr_count( $src, 'class="kivun-wiz-steps"' ),
			'there is more than one step rail; both would be driven, and the reader would be told two different things'
		);
	}
}
