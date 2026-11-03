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
	 * A menu pushed to the edge opens back inward.
	 *
	 * The columns menu anchors its inline-start to its button, which is
	 * right while the button sits at the start of the bar. Moving the
	 * button to the far end made that anchor point outward: the menu is
	 * wider than the button, so it hung 98px past the console's left edge
	 * — and the console clips, so half of it and the reset button were
	 * simply not there. Measured with the menu open, before and after.
	 *
	 * @return void
	 */
	public function test_a_menu_pushed_to_the_edge_opens_inward(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		// The push, and the re-anchoring that has to travel with it.
		$at = strpos( $css, '.kivun-cc-console .kivun-cc-viewbar > :last-child { margin-inline-start: auto; }' );
		$this->assertNotFalse( $at, 'nothing pushes the columns button to the end of the bar any more' );

		$after = substr( $css, $at, 900 );
		$this->assertStringContainsString(
			'inset-inline-end: 0;',
			$after,
			'the columns menu still anchors outward from a button that now sits at the edge, so the console clips it'
		);
		$this->assertStringContainsString(
			'inset-inline-start: auto;',
			$after,
			'the old inline-start anchor is still in force, so the menu is pinned both ways'
		);

		// And the console does clip — which is what makes this matter.
		$this->assertMatchesRegularExpression(
			'/\.kivun-cc-console \{[^}]*overflow: hidden/s',
			$css,
			'this test assumes the console clips its overflow; if it no longer does, the reasoning needs rechecking'
		);
	}

	/**
	 * A width stops being a width when the bar turns on its side.
	 *
	 * The filter bar is a row on a desktop and a column under 782px, and a
	 * `flex-basis` follows the main axis: the 18rem that lets the read-out
	 * take the slack across a row becomes an 18rem *height* down a column.
	 * Measured at 390px before the fix — the read-out 288px tall, every
	 * picker 144px, the bar 1591px in total. Four screens of nothing before
	 * you reach a lead.
	 *
	 * @return void
	 */
	public function test_the_filter_widths_do_not_become_heights(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		// The column layout, and what it has to undo.
		$at = strpos( $css, '.kivun-cc-leadfilters { flex-direction: column' );
		$this->assertNotFalse( $at, 'the filter bar no longer becomes a column on a narrow screen' );
		$block = substr( $css, $at, 1400 );

		foreach ( array( 'kivun-cc-filterbar__read', 'kivun-pick-src', 'input[type="search"]' ) as $sized ) {
			$this->assertStringContainsString(
				$sized,
				$block,
				$sized . ' keeps a row-shaped flex-basis in the column layout, where it reads as a height'
			);
		}
		$this->assertStringContainsString(
			'flex: 0 0 auto;',
			$block,
			'nothing resets the flex bases, so each control is as tall as it was wide'
		);

		/*
		 * Height was only half of it. In a column of stretched items the
		 * widest child sets the content width, and the date range — two
		 * labels and two date inputs side by side — comes to 339px. Every
		 * control in a 252px card was drawn 339px wide and hung 87px off
		 * its left edge. Measured: twelve children overflowing, then one,
		 * then none.
		 */
		$this->assertStringContainsString(
			'.kivun-cc-daterange { flex-wrap: wrap;',
			$block,
			'the date range cannot wrap, so it sets a width every other control is stretched to'
		);
		$this->assertMatchesRegularExpression(
			'/kivun-cc-filterbar__read \{[^}]*flex-wrap: wrap/',
			$block,
			'the two read-outs stay side by side and run off the card'
		);
	}

	/**
	 * The status pill is coloured by what the select actually holds.
	 *
	 * A status that looks "enrolled" while the row says "new" is worse than
	 * no colour at all, so the colour cannot come from an attribute someone
	 * has to keep in step. `:has(option:checked)` follows the live
	 * selection — measured, the pill repainted on every change with no
	 * script involved, and each colour matched the badge that states the
	 * same status where it is read-only.
	 *
	 * @return void
	 */
	public function test_the_status_pill_is_coloured_by_the_live_selection(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$this->assertMatchesRegularExpression(
			'/\.kivun-status-select:has\(option\[value="[a-z_]+"\]:checked\)/',
			$css,
			'the status colour no longer reads the live selection, so it can disagree with the value underneath it'
		);

		// Every status the badge gives a colour to, the select gets too —
		// the two say the same thing about the same row.
		preg_match_all( '/\.kivun-status-badge\[data-status="([a-z_]+)"\]/', $css, $badged );
		preg_match_all( '/\.kivun-status-select:has\(option\[value="([a-z_]+)"\]:checked\)/', $css, $pilled );

		$this->assertNotEmpty( $badged[1], 'the status badge colours are gone' );
		$missing = array_diff( array_unique( $badged[1] ), array_unique( $pilled[1] ) );
		$this->assertSame(
			array(),
			array_values( $missing ),
			'these statuses are coloured on the read-only badge but not on the editable pill, so the same row reads differently depending on who is looking: ' . implode( ', ', $missing )
		);

		// Scoped, because the same helper draws this select on the WP-admin
		// leads screen, which keeps its own look.
		$this->assertStringContainsString(
			'.kivun-cc-console .kivun-status-select',
			$css,
			'the status pill is unscoped, so it redraws the WP-admin leads screen too'
		);

		/*
		 * And the console's pills are drawn from the design's tokens, not
		 * from hexes of their own. The colours before the handoff arrived
		 * were a green, a blue and an amber written straight into these
		 * rules — a palette the design does not contain. Tokens here mean
		 * the pills move with the design when it moves.
		 */
		$blocks = explode( '}', $css );
		$raw    = array();
		foreach ( $blocks as $block ) {
			$at = strpos( $block, '{' );
			if ( false === $at ) {
				continue;
			}
			$selectors = substr( $block, 0, $at );
			if ( false === strpos( $selectors, '.kivun-cc-console .kivun-status-' ) ) {
				continue;
			}
			// A hex anywhere in the declarations, other than plain white.
			if ( preg_match_all( '/#(?!fff\b)(?!ffffff\b)[0-9a-f]{3,8}\b/i', substr( $block, $at ), $hits ) ) {
				$raw[] = trim( preg_replace( '/\s+/', ' ', $selectors ) ?? '' ) . ' → ' . implode( ', ', $hits[0] );
			}
		}

		$this->assertSame(
			array(),
			$raw,
			"the console's status colours are written as hexes instead of design tokens, so they no longer follow the palette:\n  " . implode( "\n  ", $raw )
		);
	}

	/**
	 * Every filter the leads screen submits still has a field.
	 *
	 * Written down before that screen is redesigned, for the same reason
	 * the saved fields were. These ten travel in the query string: the
	 * reader bookmarks a filtered view, or presses back to it, and the
	 * screen rebuilds itself from them. A field dropped in a redesign does
	 * not raise anything — the filter simply stops narrowing, and a list
	 * that should have shown eleven leads shows twelve hundred.
	 *
	 * @return void
	 */
	public function test_every_lead_filter_still_has_a_field(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$filters = array(
			'kivun_content[]' => 'which content the lead came from',
			'kivun_ltype[]'   => 'the kind of content',
			'kivun_lstatus[]' => 'the lead status',
			'kivun_lgender[]' => 'gender',
			'kivun_lcity[]'   => 'city',
			'kivun_lfrom'     => 'the start of the date range',
			'kivun_lto'       => 'the end of the date range',
			'kivun_ls'        => 'the free-text search',
			'kivun_lpp'       => 'how many rows per page',
		);

		foreach ( $filters as $name => $what ) {
			$this->assertStringContainsString(
				'name="' . $name . '"',
				$src,
				'nothing on the leads screen submits ' . $name . ' (' . $what . '), so that filter silently stops narrowing'
			);
		}

		/*
		 * And the forms that carry them have to stay GETs, or a bookmarked
		 * or shared filtered view stops being a thing you can link to.
		 *
		 * There are two: the leads screen and the tracking-numbers screen
		 * share this class and this behaviour. Counted rather than found
		 * once, because the second one is easy to forget when restyling the
		 * first — a rule written without a scope reaches it too.
		 */
		$bars = substr_count( $src, 'class="kivun-cc-leadfilters"' );
		$this->assertGreaterThan( 0, $bars, 'the filter form is gone' );
		$this->assertSame(
			$bars,
			substr_count( $src, '<form class="kivun-cc-leadfilters" method="get"' ),
			'one of the filter forms no longer travels in the URL'
		);
	}

	/**
	 * And every control the leads scripts reach for is still emitted.
	 *
	 * The same guard the console-wide classes already have, extended to
	 * this screen before it is restyled. Each of these is bound by name
	 * from `admin-crm.js` or `frontend.js`: change the row that draws one
	 * and the control stays on screen, looking live, doing nothing.
	 *
	 * @return void
	 */
	public function test_every_leads_control_the_scripts_bind_is_still_emitted(): void {
		/*
		 * Both files, because the console's leads rows are not all its own:
		 * the status select, the coordinator select and the note come from
		 * Kivun_Admin helpers that the WP-admin leads screen uses too. Worth
		 * knowing before restyling them — a rule written without a scope
		 * would redraw that screen as well.
		 */
		$php = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' )
			. (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-admin.php' );

		$bound = array(
			'kivun-status-select'  => 'changing a lead status',
			'kivun-handler-select' => 'assigning a coordinator',
			'kivun-notes-input'    => 'the note on a lead',
			'kivun-pick-src'       => 'the multi-select filters',
			'kivun-viewer-form'    => 'inviting someone to view the leads',
			'kivun-viewer-email'   => 'the invite address',
			'kivun-remove-viewer'  => 'withdrawing access',
			'kivun-cc-leadfilters' => 'the filter form itself',
		);

		foreach ( $bound as $class => $what ) {
			$this->assertMatchesRegularExpression(
				'/(?<![a-z0-9_-])' . preg_quote( $class, '/' ) . '(?![a-z0-9_-])/',
				$php,
				$class . ' is gone, so ' . $what . ' is wired to nothing'
			);
		}

		// The column picker and the table/cards switch are driven by
		// attributes rather than classes, so a hand-rebuilt row would drop
		// them without renaming anything.
		$this->assertStringContainsString( 'data-col=', $php, 'the columns can no longer be put away individually' );
		$this->assertStringContainsString( 'data-col-toggle', $php, 'the column picker has nothing to toggle' );
	}

	/**
	 * A table the search brings back gets measured.
	 *
	 * The filter hides a pane's wrapper when nothing matches, and a table
	 * in a hidden wrapper has no width, so `fitTables()` skips it. Search
	 * for a title the archive does not have, open the archive, then clear
	 * the search: the wrapper returns and nothing has told anyone to
	 * measure it. Reproduced at 390px — the archive came back `stacked:
	 * false`, which on a phone is the unlabelled fallback.
	 *
	 * A click could not carry this one: the reveal happens on an input
	 * event, in the other script. So the filter says so itself.
	 *
	 * @return void
	 */
	public function test_a_table_the_search_brings_back_gets_measured(): void {
		$js  = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );
		$crm = (string) file_get_contents( KIVUN_DIR . 'assets/js/admin-crm.js' );

		$this->assertStringContainsString(
			"dispatchEvent(new CustomEvent('kivun:tables-shown'))",
			$js,
			'the filter reveals a wrapper without telling anyone to measure it'
		);
		$this->assertMatchesRegularExpression(
			'/if \(wrap\.hidden && shown !== 0\) \{ revealed = true; \}/',
			$js,
			'nothing notices that a hidden wrapper came back'
		);
		$this->assertStringContainsString(
			"document.addEventListener('kivun:tables-shown', fitTablesSoon);",
			$crm,
			'nobody is listening, so the table stays unmeasured'
		);
	}

	/**
	 * A cell that spans the row is not one column's value.
	 *
	 * `labelCells()` names each cell after the column above it. A cell with
	 * a colspan sits under all of them and belongs to none, and labelling
	 * it made the stacked layout treat it as a value: the custom forms
	 * table puts each page's whole form builder in such a cell, and the
	 * two-track grid squeezed the builder into 84px of a 244px card.
	 * Unlabelled, it takes the exemption that already exists for cells
	 * without a label and keeps the row's width.
	 *
	 * @return void
	 */
	public function test_a_spanning_cell_is_not_given_a_column_label(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/admin-crm.js' );

		$at = strpos( $js, 'function labelCells' );
		$this->assertNotFalse( $at, 'the cell labeller moved' );
		$body = substr( $js, $at, 1400 );

		$this->assertMatchesRegularExpression(
			'/colSpan\s*>\s*1[\s\S]{0,40}return/',
			$body,
			'a cell spanning the row is still given a column heading, which squeezes it into the value track'
		);

		// And the exemption it falls back on has to still be there.
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );
		$this->assertStringContainsString(
			'.kivun-cc-table.is-stacked td:not([data-label]) { display: block; }',
			$css,
			'an unlabelled cell no longer escapes the two-track grid'
		);
	}

	/**
	 * Each filter bar owns only the panes in its own panel.
	 *
	 * Every tab's panel is in the response, hidden rather than left out, so
	 * more than one filter bar is on the page at once — measured: two.
	 * Taking the first bar and then every pane in the document meant the
	 * custom forms search was never wired at all, a library search filtered
	 * the forms rows too, and clicking a library segment hid the forms pane,
	 * which matches neither "live" nor "archive" — leaving that tab blank
	 * until a reload.
	 *
	 * @return void
	 */
	public function test_each_filter_bar_owns_only_its_own_panes(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertStringContainsString(
			"document.querySelectorAll('.kivun-cc-filterbar')",
			$js,
			'only one filter bar is set up, so a second screen on the page gets no search'
		);
		$this->assertStringNotContainsString(
			"document.querySelector('.kivun-cc-filterbar')",
			$js,
			'the filter still takes the first bar on the page'
		);

		$at = strpos( $js, "document.querySelectorAll('.kivun-cc-filterbar')" );
		$body = substr( $js, $at, 1200 );
		$this->assertMatchesRegularExpression(
			'/closest\(\'\.kivun-tab-panel\'\)/',
			$body,
			'a bar collects panes beyond its own panel, so it hides another screen'
		);
		$this->assertStringNotContainsString(
			"document.querySelectorAll('.kivun-cc-pane')",
			$js,
			'the panes are still gathered from the whole document'
		);
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

		$at = strpos( $js, "document.querySelectorAll('.kivun-cc-filterbar')" );
		$this->assertNotFalse( $at, 'the library filter moved' );
		$body = substr( $js, $at, 2800 );

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

	// ── 3. The tracking-numbers screen, locked before it is restyled ─────────

	/**
	 * Every filter the tracking-numbers screen offers still has a field.
	 *
	 * The same contract as the leads screen, and it breaks the same way:
	 * these travel in the URL, so a bookmarked "calls from the newspaper
	 * in March" rebuilds the screen from them. A field dropped in a
	 * redesign does not error — the filter stops narrowing, and a log that
	 * should show eleven calls shows every call ever logged.
	 *
	 * Paging is in here for a reason of its own: `kivun_call_paged` and
	 * `kivun_call_pp` are what keep page two of a filtered view filtered.
	 *
	 * @return void
	 */
	public function test_every_call_filter_still_has_a_field(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$filters = array(
			'kivun_call_campaign' => 'which campaign the call came from',
			'kivun_call_media'    => 'which medium it was published in',
			'kivun_call_number'   => 'which tracking number was dialled',
			'kivun_call_from'     => 'the start of the date range',
			'kivun_call_to'       => 'the end of the date range',
			'kivun_call_s'        => 'the free-text search',
			'kivun_call_pp'       => 'how many rows per page',
		);

		foreach ( $filters as $name => $what ) {
			$this->assertStringContainsString(
				'name="' . $name . '"',
				$src,
				'nothing on the tracking-numbers screen submits ' . $name . ' (' . $what . '), so that filter silently stops narrowing'
			);
		}

		/*
		 * And the screen has to say which tab it is, or submitting the
		 * filters lands the reader back on the first tab with their filters
		 * applied to a screen that does not use them.
		 */
		$this->assertStringContainsString(
			'<input type="hidden" name="kivun_tab" value="calls">',
			$src,
			'the filter form no longer says it belongs to the calls tab, so filtering navigates away from the screen being filtered'
		);
	}

	/**
	 * And every control the tracking-numbers scripts reach for is still
	 * emitted.
	 *
	 * All of these are bound by name from `frontend.js`. The failure is the
	 * one this file was written for: the control stays on the screen,
	 * looking live, wired to nothing.
	 *
	 * @return void
	 */
	public function test_every_calls_control_the_scripts_bind_is_still_emitted(): void {
		$php = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$bound = array(
			// The numbers and their periods.
			'kivun-delete-phone'        => 'removing a tracking number',
			'kivun-edit-assignment'     => 'opening a period for editing',
			'kivun-delete-assignment'   => 'deleting a period',
			'kivun-assignment-form'     => 'saving a period',

			// The period's own fields. Dropping one does not empty it —
			// the save reads it as blank and writes that over the dates.
			'kivun-as-media'            => 'the medium of a period',
			'kivun-as-campaign'         => 'the campaign of a period',
			'kivun-as-label'            => 'what the advert was',
			'kivun-as-start'            => 'the date a period starts',
			'kivun-as-end'              => 'the date a period ends',

			// A campaign created without leaving the screen.
			'kivun-newcamp'             => 'the inline new-campaign panel',
			'kivun-newcamp-name'        => 'the new campaign name',
			'kivun-newcamp-btn'         => 'creating the campaign',

			// Adding numbers, by hand and by file.
			'kivun-phone-form'          => 'adding a number',
			'kivun-phone-number'        => 'the number being added',
			'kivun-phone-label'         => 'its nickname',
			'kivun-phone-file'          => 'the CSV being imported',
			'kivun-phone-import-btn'    => 'starting the import',
			'kivun-phone-import-status' => 'what the import reports back',

			// The switchboard setup, which is read-only and copied out.
			'kivun-camp-saved'          => 'the webhook address to copy',
			'kivun-camp-copy'           => 'copying it',
		);

		foreach ( $bound as $class => $what ) {
			$this->assertMatchesRegularExpression(
				'/(?<![a-z0-9_-])' . preg_quote( $class, '/' ) . '(?![a-z0-9_-])/',
				$php,
				$class . ' is gone, so ' . $what . ' is wired to nothing'
			);
		}
	}

	/**
	 * A period's editor follows the period it belongs to.
	 *
	 * The editor is a second row, hidden under the one it edits, and the
	 * two find each other by id alone: the edit button looks up
	 * `[data-assignment-edit="<id>"]`, and deleting a period removes both
	 * rows by the same pair of attributes. Rebuild the table and keep only
	 * the visible row, and the editor is orphaned — deleting a period
	 * leaves a form on screen that still submits for a period that no
	 * longer exists.
	 *
	 * The same shape as the forms builder row, and it already went wrong
	 * once there.
	 *
	 * @return void
	 */
	public function test_an_assignment_editor_follows_its_own_period(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			'data-assignment-row=',
			$src,
			'the period rows are no longer identified, so deleting one cannot find it'
		);
		$this->assertStringContainsString(
			'data-assignment-edit=',
			$src,
			'the period editor is no longer identified, so the edit button opens nothing'
		);

		// One editor per row, or a delete takes away the wrong pair.
		$this->assertSame(
			substr_count( $src, 'data-assignment-row="' ),
			substr_count( $src, 'data-assignment-edit="' ),
			'the period rows and their editors no longer come in pairs'
		);

		/*
		 * And the field the editor focuses on opening has to be inside it.
		 * `.kivun-as-start` is looked up within the panel, so a design that
		 * lifts the dates out of the editor leaves it opening onto nothing
		 * — which reads as the editor being broken rather than moved.
		 */
		$at = strpos( $src, 'data-assignment-edit="' );
		$this->assertNotFalse( $at, 'the period editor is gone' );
		$editor = substr( $src, $at, 2600 );
		$this->assertStringContainsString(
			'kivun-as-start',
			$editor,
			'the date the editor focuses on opening is no longer inside the editor'
		);
	}

	/**
	 * The period editor spans every column of its table.
	 *
	 * It sits in a single cell that has to reach across the whole table,
	 * and the column count is not fixed: the "answered" column only
	 * appears where the switchboard reports talk time. So the span is a
	 * number that has to track a conditional — exactly the kind that goes
	 * stale when a column is added for design reasons.
	 *
	 * Too small and the editor is squeezed into part of the row with blank
	 * cells beside it, which is what the forms builder row did: 84px of a
	 * 244px card. Nothing errors either way.
	 *
	 * @return void
	 */
	public function test_the_assignment_editor_spans_every_column(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		// The periods table is the one inside a number's panel.
		$at = strpos( $src, 'data-phone-row=' );
		$this->assertNotFalse( $at, 'the tracking numbers no longer render as panels' );

		$head_start = strpos( $src, '<thead>', $at );
		$head_end   = strpos( $src, '</thead>', (int) $head_start );
		$this->assertNotFalse( $head_start, 'the periods table has no header' );
		$this->assertNotFalse( $head_end, 'the periods table header never closes' );

		$thead  = substr( $src, (int) $head_start, (int) $head_end - (int) $head_start );
		$all    = substr_count( $thead, '<th scope="col">' );
		$capped = substr_count( $thead, "\$report['knows']" );

		$this->assertGreaterThan( 0, $all, 'the periods table lost its columns' );
		$this->assertSame(
			1,
			$capped,
			'the periods table no longer has exactly one column that depends on the switchboard reporting talk time, so the editor cannot span a known number of columns'
		);

		/*
		 * Written as the two numbers the table actually has rather than as
		 * a pattern, so adding a column makes this fail instead of quietly
		 * passing on a span that is now one short.
		 */
		$this->assertStringContainsString(
			"\$report['knows'] ? '" . $all . "' : '" . ( $all - 1 ) . "'",
			$src,
			'the period editor spans the wrong number of columns: the table has ' . $all . ' with the answered column and ' . ( $all - 1 ) . ' without'
		);
	}

	/**
	 * The medium field still points at the list of suggestions.
	 *
	 * A free-text field with a `<datalist>` behind it, joined by id. The
	 * join is HTML to HTML, so no script and no save notices when it
	 * breaks — the field simply stops suggesting, and the same medium
	 * starts being typed two ways, which splits it into two rows in every
	 * report built from it.
	 *
	 * @return void
	 */
	public function test_the_media_suggestions_still_point_at_their_list(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			'<datalist id="kivun-media-list">',
			$src,
			'the list of media suggestions is gone'
		);

		// Both medium fields — the one on an existing period and the one on
		// a new period — have to point at it.
		$this->assertSame(
			2,
			substr_count( $src, 'list="kivun-media-list"' ),
			'one of the medium fields no longer offers the existing media, so the same medium gets typed two ways and splits in the reports'
		);
	}

	/**
	 * The icon button is one shape, not two.
	 *
	 * The console grew two: `kivun-cc-iconbtn`, a bordered white square with
	 * its own hardcoded greys, and `kivun-cc-icon-btn` from the redesign.
	 * They were on screen at the same time, on different screens.
	 *
	 * They are unified by sharing a rule rather than by renaming, because
	 * the old name is not only in the PHP — `frontend.js` builds the custom
	 * form's question rows with it, and `ArchiveTest` asserts an exact class
	 * attribute. So the thing to guard is not the name but the shape: every
	 * block that styles the old class must style the new one in the same
	 * breath.
	 *
	 * Re-adding a standalone block for either one is the specific regression
	 * here, and it would not look like a mistake: it would sit LATER in the
	 * file and quietly win on source order at equal specificity. That is
	 * how the edit button came out brand-red.
	 *
	 * @return void
	 */
	public function test_the_icon_button_is_one_shape_not_two(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		/*
		 * Note the two names do not contain one another: `kivun-cc-iconbtn`
		 * is not a substring of `kivun-cc-icon-btn`, because of the hyphen.
		 * So a plain search for the old name cannot be satisfied by the new
		 * one — which is what makes this check mean anything.
		 */
		$this->assertStringNotContainsString(
			'kivun-cc-iconbtn',
			'kivun-cc-icon-btn',
			'the two class names now overlap, so this test can no longer tell them apart'
		);

		$blocks = explode( '}', $css );
		$found  = 0;

		foreach ( $blocks as $block ) {
			$at = strpos( $block, '{' );
			if ( false === $at ) {
				continue;
			}

			$selectors = substr( $block, 0, $at );
			if ( false === strpos( $selectors, 'kivun-cc-iconbtn' ) ) {
				continue;
			}

			++$found;
			$this->assertStringContainsString(
				'kivun-cc-icon-btn',
				$selectors,
				'a rule styles kivun-cc-iconbtn without styling kivun-cc-icon-btn, so the console is back to two icon-button shapes: ' . trim( preg_replace( '/\s+/', ' ', $selectors ) ?? '' )
			);
		}

		$this->assertGreaterThan(
			0,
			$found,
			'nothing styles kivun-cc-iconbtn any more, so the buttons the script builds for the question rows are unstyled'
		);
	}

	/**
	 * The console's type scale is stated in px, as the handoff states it.
	 *
	 * This is the whole of what "the design is not accurate" turned out to
	 * mean. The console sized its type in `rem`, which resolves against the
	 * root font size: an 18px label came out at 13.12, a 17px tab at 14.08,
	 * a 32px section title at 23.2. Every size landed at roughly
	 * three-quarters, nothing looked broken, and the console simply read a
	 * size small throughout.
	 *
	 * Measured against the reference, not asserted: the numbers below are
	 * what the design file's own computed styles report.
	 *
	 * A `rem` here is not a style preference — it is a silent rescale of
	 * the whole design by whatever the theme sets on `html`.
	 *
	 * @return void
	 */
	public function test_the_console_type_scale_is_in_absolute_pixels(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		/*
		 * Anchored to the rule that draws each thing, not searched for
		 * across the file. A first attempt looked the sizes up anywhere in
		 * the stylesheet and passed happily when the stat label was turned
		 * back into a rem, because some other rule still said 18px.
		 */
		$scale = array(
			'.kivun-cc-stat__num'     => array( 'font-size: 48px', 'the stat figure (Rubik 48/800)' ),
			'.kivun-cc-topbar__title' => array( 'font-size: 44px', 'the page title on the band (Rubik 44/900)' ),
			'.kivun-cc-console .kivun-cc-title' => array( 'font-size: 32px', 'the section title (Rubik 32/800)' ),
			'.kivun-cc-topbar__sub'   => array( 'font-size: 20px', 'the band subtitle' ),
			'.kivun-cc-stat__label'   => array( 'font-size: 18px', 'the stat label' ),
			'.kivun-cc-tabs .kivun-tab' => array( 'font-size: 17px', 'the tab label' ),
			'.kivun-cc-stat__flag'    => array( 'font-size: 14px', 'the "requires attention" badge' ),
		);

		$blocks = explode( '}', $css );

		foreach ( $scale as $selector => $expected ) {
			list( $decl, $what ) = $expected;

			$block = null;
			foreach ( $blocks as $candidate ) {
				$at = strpos( $candidate, '{' );
				if ( false === $at ) {
					continue;
				}
				$selectors = substr( $candidate, 0, $at );
				// The rule that *declares* this size, not one that only
				// mentions the selector in passing.
				if ( false !== strpos( $selectors, $selector ) && false !== strpos( $candidate, 'font-size:' ) ) {
					$block = $candidate;
					break;
				}
			}

			$this->assertNotNull( $block, 'no rule sets a font size for ' . $selector . ' (' . $what . ')' );
			$this->assertStringContainsString(
				$decl,
				(string) $block,
				$what . ' is no longer "' . $decl . '" in the rule for ' . $selector . ' — a rem here rescales the design with the theme root'
			);
		}

		/*
		 * And the card radii, which are the other half of the same problem:
		 * these were 18 and 13 against the design's 36 and 28.
		 */
		$this->assertStringContainsString( '--cc-radius: 36px', $css, 'a main card is no longer 36' );
		$this->assertStringContainsString( '--cc-radius-sm: 28px', $css, 'an inner card is no longer 28' );
	}

	/**
	 * Cards are flat; only the stats and popovers are raised.
	 *
	 * The handoff says it in one line — "Other cards are flat white on
	 * #f1f1f3" — and it is the single change that most altered how the
	 * console reads, because a shadow had been put on every card: panels,
	 * the tab bar, the table container, the pager, the wizard cards.
	 *
	 * @return void
	 */
	public function test_only_the_stats_are_raised_off_the_canvas(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		// The shadow token is the design's stat-card shadow, exactly.
		$this->assertStringContainsString(
			'--cc-shadow: 0 14px 34px rgba(17, 17, 20, .10)',
			$css,
			'the stat-card shadow is no longer the design\'s'
		);

		/*
		 * Which rules may use it. Anything else asking for a shadow means a
		 * card has been lifted off the canvas again.
		 */
		$allowed = array( '.kivun-cc-stat', '.kivun-cc-colpicker__menu', '.kivun-cc-pop' );

		$blocks = explode( '}', $css );
		$raised = array();
		foreach ( $blocks as $block ) {
			$at = strpos( $block, '{' );
			if ( false === $at || false === strpos( $block, 'box-shadow: var(--cc-shadow' ) ) {
				continue;
			}
			$selectors = trim( preg_replace( '/\s+/', ' ', substr( $block, 0, $at ) ) ?? '' );

			$ok = false;
			foreach ( $allowed as $good ) {
				if ( false !== strpos( $selectors, $good ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				$raised[] = $selectors;
			}
		}

		$this->assertSame(
			array(),
			$raised,
			"these are raised off the canvas, but the design has only the stat cards and popovers raised:\n  " . implode( "\n  ", $raised )
		);
	}

	/**
	 * The stats are left to the design's grid, and the badge can wrap.
	 *
	 * Two columns were forced for everything under 900px, from a time when
	 * the stat labels were 13px. At the handoff's 18px that card is too
	 * small to hold its own contents: on a 375px phone each card came out
	 * 124px wide, and the "requires attention" badge sat a full 67px
	 * outside it — its whole width — clipped away by the console's
	 * `overflow: hidden`, with the label overflowing by 95px.
	 *
	 * `repeat(auto-fit, minmax(200px, 1fr))` is the handoff's own answer
	 * and needs no help: two columns while two fit, one below that.
	 *
	 * @return void
	 */
	public function test_the_stats_grid_is_not_forced_narrower_than_its_contents(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$this->assertStringContainsString(
			'grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))',
			$css,
			'the stats no longer use the design\'s own responsive grid'
		);

		// And nothing pins them to a fixed number of columns again.
		$blocks = explode( '}', $css );
		$forced = array();
		foreach ( $blocks as $block ) {
			$at = strpos( $block, '{' );
			if ( false === $at || false === strpos( substr( $block, 0, $at ), 'kivun-cc-stats' ) ) {
				continue;
			}
			if ( preg_match( '/grid-template-columns:\s*repeat\(\s*\d/', substr( $block, $at ) ) ) {
				$forced[] = trim( preg_replace( '/\s+/', ' ', substr( $block, 0, $at ) ) ?? '' );
			}
		}
		$this->assertSame(
			array(),
			$forced,
			"the stats are pinned to a fixed column count again, which is how a card became too narrow to hold its own badge:\n  " . implode( "\n  ", $forced )
		);

		/*
		 * The badge is `flex: 0 0 auto` so it keeps its shape, which means
		 * the row it sits in has to be able to wrap — otherwise a card
		 * narrower than label-plus-badge pushes it out of the card rather
		 * than onto a second line.
		 */
		$at = strpos( $css, '.kivun-cc-stat__label {' );
		$this->assertNotFalse( $at, 'the stat label rule is gone' );
		$this->assertStringContainsString(
			'flex-wrap: wrap',
			substr( $css, $at, 400 ),
			'the stat label cannot wrap, so a badge that does not shrink is pushed outside the card instead of onto its own line'
		);
	}

	/**
	 * An active filter is marked from the selection, not from a guess.
	 *
	 * The handoff gives an active filter a red tint, a red border and bold
	 * text. The first attempt styled `select:not(:has(option[value=""]
	 * :checked))` and lit up every control while nothing was filtered —
	 * exactly backwards — for two reasons worth keeping out of the code.
	 *
	 * On the leads screen those selects are not what anyone sees. They are
	 * `kivun-pick-src`: hidden sources the picker builds its own control
	 * from, carrying the "all of them" wording in `data-pick-all` rather
	 * than in an option, so there is no option to match. The picker does
	 * keep the real selection on that native select, toggling
	 * `option.selected`, so the state is readable — from the wrapper.
	 *
	 * And the per-page select has no "all" option because it is not a
	 * filter, so any "active unless all is chosen" rule lights it forever.
	 *
	 * @return void
	 */
	public function test_an_active_filter_is_marked_from_the_real_selection(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		// The leads pickers are read through the native select the picker
		// keeps in step, not through the source element's own styling.
		$this->assertStringContainsString(
			'.kivun-pick:has(.kivun-pick__native option:checked) .kivun-pick__input',
			$css,
			'the active-filter mark no longer reads the picker\'s own selection, so it marks either nothing or everything'
		);

		/*
		 * And the per-page control is excluded wherever the "active unless
		 * the all-option is chosen" test is used. Without the exclusion it
		 * is permanently lit, because 25 rows is a choice, not a filter.
		 *
		 * Comments are stripped first. Everything before a `{` counts as
		 * the selector, and the comment above these rules quotes the very
		 * pattern being searched for — so without this the test reports
		 * its own explanation as a rule.
		 */
		$bare   = (string) preg_replace( '!/\*.*?\*/!s', '', $css );
		$blocks = explode( '}', $bare );
		$unguarded = array();
		foreach ( $blocks as $block ) {
			$at = strpos( $block, '{' );
			if ( false === $at ) {
				continue;
			}
			$selectors = substr( $block, 0, $at );
			if ( false === strpos( $selectors, ':not(:has(option[value="0"]:checked))' )
				&& false === strpos( $selectors, ':not(:has(option[value=""]:checked))' ) ) {
				continue;
			}
			if ( false === strpos( $selectors, '_pp' ) ) {
				$unguarded[] = trim( preg_replace( '/\s+/', ' ', $selectors ) ?? '' );
			}
		}

		$this->assertSame(
			array(),
			$unguarded,
			"an active-filter rule does not exclude the per-page select, which has no \"all\" option and would sit permanently lit:\n  " . implode( "\n  ", $unguarded )
		);
	}

	/**
	 * A fixed row height belongs to the table layout only.
	 *
	 * The handoff puts body rows at 72 tall. On a real table cell `height`
	 * behaves as a minimum — the cell grows for its content — so it says
	 * exactly that. The moment `is-stacked` turns each cell into a grid for
	 * the phone, the same declaration becomes a hard height that content
	 * cannot push past.
	 *
	 * Not a theoretical edge: with it unscoped, six of the first ten cells
	 * already overflowed by 20–66px at 390px with ordinary content, and an
	 * expanded panel or a note dragged taller overflowed by 256, spilling
	 * over the field beneath it instead of pushing it down.
	 *
	 * The narrow-screen fallback in this file is scoped the same way for
	 * the same reason, which is what makes this worth a standing test
	 * rather than a comment: the trap is easy to walk into twice.
	 *
	 * @return void
	 */
	public function test_the_fixed_row_height_does_not_reach_the_stacked_layout(): void {
		$css = (string) preg_replace( '!/\*.*?\*/!s', '', (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' ) );

		$offenders = array();
		foreach ( explode( '}', $css ) as $block ) {
			$at = strpos( $block, '{' );
			if ( false === $at ) {
				continue;
			}
			$selectors = substr( $block, 0, $at );
			$body      = substr( $block, $at );

			/*
			 * Body cells only. The stacked layout hides the header with
			 * the usual visually-hidden block, which sets `height: 1px`
			 * on purpose — a first version of this test reported that as
			 * the bug.
			 */
			if ( false === strpos( $selectors, 'kivun-cc-table' ) ) {
				continue;
			}
			if ( false === strpos( $selectors, 'tbody td' ) && false === strpos( $selectors, 'tbody tr' ) ) {
				continue;
			}
			if ( ! preg_match( '/(?<!min-)height:\s*\d/', $body ) ) {
				continue;
			}
			if ( false === strpos( $selectors, ':not(.is-stacked)' ) ) {
				$offenders[] = trim( preg_replace( '/\s+/', ' ', $selectors ) ?? '' ) . ' →' . trim( preg_replace( '/\s+/', ' ', $body ) ?? '' );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"a fixed cell height reaches the stacked layout, where it becomes a ceiling instead of a floor and content spills over the field below:\n  " . implode( "\n  ", $offenders )
		);
	}

	/**
	 * Every console token a rule asks for is a token that exists.
	 *
	 * The console's colours, radii and shadows are all custom properties. A
	 * reference to one that was renamed or removed does not warn and does
	 * not fall back to anything sensible: the declaration is simply thrown
	 * away, so the element keeps whatever it had — usually transparent.
	 *
	 * This is not hypothetical. Rewriting the tokens to the design handoff's
	 * values dropped `--cc-shell` while eleven rules still asked for it, and
	 * the header band rendered **transparent**. Nothing failed; the band was
	 * just gone. A browser measurement caught it, and this test is so the
	 * next one does not need to.
	 *
	 * A reference that carries its own fallback — `var(--x, 12px)` — is
	 * fine by design and is not counted.
	 *
	 * @return void
	 */
	public function test_every_console_token_used_is_defined(): void {
		$css = (string) file_get_contents( KIVUN_DIR . 'assets/css/frontend.css' );

		$defined = array();
		preg_match_all( '/(--cc-[a-z0-9-]+)\s*:/i', $css, $d );
		foreach ( $d[1] as $name ) {
			$defined[ $name ] = true;
		}
		$this->assertNotEmpty( $defined, 'the console defines no tokens at all' );

		// Only bare references: a var() with a comma has its own fallback.
		preg_match_all( '/var\(\s*(--cc-[a-z0-9-]+)\s*\)/i', $css, $u );

		$missing = array();
		foreach ( array_unique( $u[1] ) as $name ) {
			if ( ! isset( $defined[ $name ] ) ) {
				$missing[] = $name . ' (' . substr_count( $css, 'var(' . $name . ')' ) . ' uses)';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"these console tokens are used but never defined, so every rule that asks for one silently loses that declaration:\n  " . implode( "\n  ", $missing )
		);
	}

	/**
	 * The tracking-numbers screen keeps both of its scopes.
	 *
	 * `kivun-cc-calls` is what the screen's own styling hangs off, and it
	 * exists because two of the classes this screen draws with —
	 * `kivun-cc-card` and `kivun-camp-summary` — belong to the jobs board
	 * as well, which renders inside this same console. Drop the scope and
	 * the rules written for the numbers redraw the jobs board too.
	 *
	 * `kivun-cc-front` has to stay beside it: the library filter walks up
	 * with `closest('.kivun-cc-front')` to work out which panes it owns.
	 * Replacing one class with the other would look like a tidy-up and
	 * would quietly widen that filter's reach to the whole document.
	 *
	 * @return void
	 */
	public function test_the_numbers_screen_keeps_both_of_its_scopes(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			'class="kivun-cc-front kivun-cc-calls"',
			$src,
			'the tracking-numbers screen lost one of its two scopes: it needs kivun-cc-front for the filter script and kivun-cc-calls so its own styling does not reach the jobs board'
		);
	}
}
