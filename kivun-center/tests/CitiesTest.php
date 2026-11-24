<?php
/**
 * The city a visitor picks from a list, and "אחר" that opens a box.
 *
 * The forms asked for a city in a free text box, and the same town came back
 * spelled five ways — five towns in the city filter and in every report. Now
 * every public form that asks offers one list; a town that is not on it is
 * typed in the box "אחר" opens, and that town is what the lead is stored
 * with.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-jobs.php';

/**
 * The list, what a submission resolves to, and the forms that offer it.
 */
final class CitiesTest extends TestCase {

	/**
	 * Start each test with an empty site and no filters.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
		global $kivun_test_filters;
		$kivun_test_filters = array();
	}

	/**
	 * Leave no filter behind for the next test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $kivun_test_filters;
		$kivun_test_filters = array();
	}

	/**
	 * The cities as the field shows them, "אחר" included.
	 *
	 * @param string $html The field's markup.
	 * @return array<int,string> The option values, the empty first one left out.
	 */
	private function options( string $html ): array {
		preg_match_all( '#<option value="([^"]*)">#u', $html, $m );
		return array_values( array_filter( $m[1], static fn( string $v ): bool => '' !== $v ) );
	}

	// ── The list ─────────────────────────────────────────────────────────────

	/**
	 * The cities the client listed, in their order, and "אחר" after them.
	 *
	 * @return void
	 */
	public function test_the_list_is_the_clients_in_their_order(): void {
		$this->assertSame(
			array(
				'ירושלים',
				'קרית יערים',
				'גבעת זאב',
				'תל ציון',
				'כוכב יעקב',
				'מעלה אדומים',
				'מבשרת ציון',
				'אדם',
				'מעלה עמוס',
				'בני ברק',
				'אלעד',
				'מודיעין עילית',
				'חיפה',
				'עפולה',
				'פתח תקווה',
				'ביתר עילית',
				'אשדוד',
				'טבריה',
				'אחר',
			),
			$this->options( Kivun_Cities::field() )
		);
	}

	/**
	 * A filter that lists "אחר" itself does not get it shown twice.
	 *
	 * @return void
	 */
	public function test_other_is_shown_once_whatever_a_filter_returns(): void {
		add_filter(
			'kivun_cities',
			static fn( array $cities ): array => array_merge( array( 'אחר', ' ' ), $cities, array( 'ירושלים', 'אחר' ) )
		);

		$options = $this->options( Kivun_Cities::field() );

		$this->assertSame( 1, count( array_keys( $options, 'אחר', true ) ) );
		$this->assertSame( 'אחר', end( $options ) );
		$this->assertSame( 1, count( array_keys( $options, 'ירושלים', true ) ) );
		$this->assertNotContains( '', $options );
	}

	/**
	 * Nothing chosen to begin with: a form that preselected the first city
	 * would record Jerusalem for everyone who skipped the question.
	 *
	 * @return void
	 */
	public function test_nothing_is_chosen_to_begin_with(): void {
		$html = Kivun_Cities::field();

		$this->assertStringContainsString( '<option value="">בחירת עיר</option>', $html );
		$this->assertStringNotContainsString( 'selected', $html );
	}

	// ── The box "אחר" opens ──────────────────────────────────────────────────

	/**
	 * The box is there, hidden and disabled until "אחר" is chosen, and is
	 * posted under the list's name with `_other`.
	 *
	 * @return void
	 */
	public function test_the_box_waits_hidden_and_disabled(): void {
		$html = Kivun_Cities::field( 'address' );

		$this->assertMatchesRegularExpression( '#<select name="address"[^>]*data-kivun-city="אחר"#u', $html );
		$this->assertMatchesRegularExpression( '#<input type="text" name="address_other" class="kivun-city-other"[^>]* hidden disabled>#u', $html );
		$this->assertMatchesRegularExpression( '#name="address_other"[^>]*aria-label="[^"]+"#u', $html );
	}

	/**
	 * One line: a page builder's wpautop() turns a newline between two tags
	 * into a `<br />`.
	 *
	 * @return void
	 */
	public function test_the_field_is_written_on_one_line(): void {
		$this->assertStringNotContainsString( "\n", Kivun_Cities::field( 'city', 'x' ) );
	}

	// ── What is stored ───────────────────────────────────────────────────────

	/**
	 * A city from the list is stored as chosen, and anything typed in a box
	 * that was not open is ignored.
	 *
	 * @return void
	 */
	public function test_a_city_from_the_list_is_stored_as_chosen(): void {
		$this->assertSame( 'בני ברק', Kivun_Cities::resolve( 'בני ברק', '' ) );
		$this->assertSame( 'בני ברק', Kivun_Cities::resolve( 'בני ברק', 'רמת גן' ) );
	}

	/**
	 * "אחר" stores the city typed in the box, not the word.
	 *
	 * @return void
	 */
	public function test_other_stores_the_city_typed(): void {
		$this->assertSame( 'רמת גן', Kivun_Cities::resolve( 'אחר', '  רמת גן ' ) );
	}

	/**
	 * "אחר" with the box left empty still says the visitor answered.
	 *
	 * @return void
	 */
	public function test_other_with_nothing_typed_keeps_the_word(): void {
		$this->assertSame( 'אחר', Kivun_Cities::resolve( 'אחר', '   ' ) );
	}

	/**
	 * No city chosen stays empty, as it always did.
	 *
	 * @return void
	 */
	public function test_no_city_stays_empty(): void {
		$this->assertSame( '', Kivun_Cities::resolve( '', 'רמת גן' ) );
	}

	/**
	 * Both handlers read the box, under the name the field posts it as.
	 *
	 * @return void
	 */
	public function test_both_handlers_read_the_box(): void {
		$leads = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-workshops.php' );
		$jobs  = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-jobs.php' );

		$this->assertMatchesRegularExpression( "#'city'\s*=> Kivun_Cities::resolve\(\s*sanitize_text_field\( wp_unslash\( \\\$_POST\['city'\][^\n]*\n\s*sanitize_text_field\( wp_unslash\( \\\$_POST\['city_other'\]#", $leads );
		$this->assertMatchesRegularExpression( "#\\\$address = Kivun_Cities::resolve\(\s*sanitize_text_field\( wp_unslash\( \\\$_POST\['address'\][^\n]*\n\s*sanitize_text_field\( wp_unslash\( \\\$_POST\['address_other'\]#", $jobs );
	}

	// ── The forms ────────────────────────────────────────────────────────────

	/**
	 * Render a template with the variables it expects.
	 *
	 * @param string              $template Relative to templates/.
	 * @param array<string,mixed> $vars     Its variables.
	 * @return string The markup.
	 */
	private function render( string $template, array $vars ): string {
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- The way kivun_get_template() hands a template its variables.
		ob_start();
		include KIVUN_DIR . 'templates/' . $template;
		return (string) ob_get_clean();
	}

	/**
	 * Every public form that asks for a city.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public static function forms(): array {
		return array(
			'event registration'  => array( 'events/register-form.php', array( 'post_type' => 'kivun_event' ), 'city' ),
			'course interest'     => array( 'courses/interest-form.php', array( 'post_type' => 'kivun_course' ), 'city' ),
			'landing page'        => array( 'landing/custom-form.php', array( 'post_type' => 'kivun_workshop' ), 'city' ),
			'job application'     => array( 'jobs/apply-form.php', array(), 'address' ),
		);
	}

	/**
	 * Each offers the list in place of the text box it had.
	 *
	 * @dataProvider forms
	 * @param string              $template The form.
	 * @param array<string,mixed> $vars     Its variables.
	 * @param string              $name     The name the city is posted under.
	 * @return void
	 */
	public function test_every_form_offers_the_list( string $template, array $vars, string $name ): void {
		Kivun_Test_State::$posts[9] = array( 'post_type' => $vars['post_type'] ?? 'kivun_job' );
		if ( 'landing/custom-form.php' === $template ) {
			Kivun_Custom_Forms::save( 9, array( array( 'label' => 'מסלול', 'type' => 'text' ) ), array( 'city' ) );
		}

		$html = $this->render( $template, $vars + array( 'post_id' => 9, 'job_id' => 9 ) );

		$this->assertMatchesRegularExpression( '#<select name="' . $name . '"[^>]*data-kivun-city="אחר"#u', $html );
		$this->assertStringContainsString( 'name="' . $name . '_other"', $html );
		$this->assertDoesNotMatchRegularExpression( '#<input[^>]*name="' . $name . '"#', $html, 'the old text box is gone' );
		$this->assertContains( 'אחר', $this->options( $html ) );
	}

	/**
	 * The landing page's label still points at the list.
	 *
	 * @return void
	 */
	public function test_the_landing_page_label_points_at_the_list(): void {
		Kivun_Test_State::$posts[9] = array( 'post_type' => 'kivun_workshop' );
		Kivun_Custom_Forms::save( 9, array( array( 'label' => 'מסלול', 'type' => 'text' ) ), array( 'city' ) );

		$html = $this->render( 'landing/custom-form.php', array( 'post_id' => 9, 'post_type' => 'kivun_workshop' ) );

		$this->assertStringContainsString( 'for="kivun-cf-city-9"', $html );
		$this->assertMatchesRegularExpression( '#<select name="city" id="kivun-cf-city-9"#', $html );
	}

	/**
	 * The form builder's preview draws the city as the list it now is.
	 *
	 * @return void
	 */
	public function test_the_builder_preview_draws_a_list(): void {
		$js = (string) file_get_contents( KIVUN_DIR . 'assets/js/frontend.js' );

		$this->assertStringContainsString( "{ key: 'city', label: 'עיר', type: 'select' }", $js );
	}
}
