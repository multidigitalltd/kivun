<?php
/**
 * Getting a page's form onto the page.
 *
 * The plugin draws the built-in landing layout only where a site asked for
 * it, and most do not: they design the landing page in Elementor, where none
 * of the plugin's own render sites are reached. So a page could be given
 * questions of its own and have no way of showing them — which is what
 * happened on the live site, and is why these exist.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The shortcode and the widget that let a form into a design.
 */
final class LeadFormPlacementTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Give a post a type, and optionally a form of its own.
	 *
	 * @param int    $id     The post.
	 * @param string $type   Its post type.
	 * @param bool   $custom Whether it carries questions.
	 * @return int
	 */
	private function post( int $id, string $type, bool $custom = false ): int {
		Kivun_Test_State::$posts[ $id ] = array( 'post_type' => $type );
		if ( $custom ) {
			Kivun_Custom_Forms::save( $id, array( array( 'label' => 'מסלול', 'type' => 'text' ) ), array() );
		}
		return $id;
	}

	/**
	 * The template the shortcode asked for, and what it handed it.
	 *
	 * @return array{template:string,vars:array}|null
	 */
	private function drawn(): ?array {
		$all = Kivun_Test_State::$templates;
		return $all ? end( $all ) : null;
	}

	// ── The shortcode ────────────────────────────────────────────────────────

	/**
	 * A landing page with questions of its own shows them.
	 *
	 * @return void
	 */
	public function test_a_custom_form_is_drawn_for_a_landing_page(): void {
		$id = $this->post( 7, 'kivun_workshop', true );

		Kivun_Shortcodes::render_lead_form( array( 'id' => $id ) );

		$drawn = $this->drawn();
		$this->assertSame( 'landing/custom-form.php', $drawn['template'] );
		$this->assertSame( $id, $drawn['vars']['post_id'] );
		$this->assertSame( 'kivun_workshop', $drawn['vars']['post_type'] );
	}

	/**
	 * A landing page without them shows the form it always showed.
	 *
	 * @return void
	 */
	public function test_a_page_with_no_questions_shows_the_fixed_form(): void {
		$id = $this->post( 7, 'kivun_workshop' );

		Kivun_Shortcodes::render_lead_form( array( 'id' => $id ) );

		$this->assertSame( 'courses/interest-form.php', $this->drawn()['template'] );
	}

	/**
	 * With no id it is the page being viewed.
	 *
	 * This is the case that matters most in practice: one Elementor template
	 * serves every landing page, so naming an id there would pin all of them
	 * to a single page's form.
	 *
	 * @return void
	 */
	public function test_with_no_id_it_is_the_page_being_viewed(): void {
		$id                               = $this->post( 31, 'kivun_workshop', true );
		Kivun_Test_State::$current_post   = $id;

		Kivun_Shortcodes::render_lead_form( array() );

		$drawn = $this->drawn();
		$this->assertSame( 'landing/custom-form.php', $drawn['template'] );
		$this->assertSame( $id, $drawn['vars']['post_id'] );
	}

	/**
	 * A course keeps working through the same door.
	 *
	 * @return void
	 */
	public function test_a_course_is_served_too(): void {
		$id = $this->post( 12, 'kivun_course' );

		Kivun_Shortcodes::render_lead_form( array( 'id' => $id ) );

		$drawn = $this->drawn();
		$this->assertSame( 'courses/interest-form.php', $drawn['template'] );
		$this->assertSame( 'kivun_course', $drawn['vars']['post_type'] );
	}

	/**
	 * Anywhere else it draws nothing at all.
	 *
	 * A template carrying the widget may be applied to pages that are not
	 * landing pages; a form filed against a post the lead code knows nothing
	 * about is worse than no form.
	 *
	 * @return void
	 */
	public function test_it_draws_nothing_on_another_kind_of_post(): void {
		$id = $this->post( 5, 'page' );

		$this->assertSame( '', Kivun_Shortcodes::render_lead_form( array( 'id' => $id ) ) );
		$this->assertSame( array(), Kivun_Test_State::$templates );
	}

	/**
	 * And nothing on a post that does not exist.
	 *
	 * @return void
	 */
	public function test_it_draws_nothing_for_a_missing_post(): void {
		$this->assertSame( '', Kivun_Shortcodes::render_lead_form( array( 'id' => 4040 ) ) );
		$this->assertSame( array(), Kivun_Test_State::$templates );
	}

	/**
	 * A form written without attributes does not take the page down.
	 *
	 * WordPress hands a shortcode written as `[kivun_lead_form]` an empty
	 * string rather than an empty array before 6.5, and this plugin supports
	 * 6.0. An `array` type hint therefore made the one way the shortcode is
	 * meant to be written a fatal error — and a fatal in a shortcode takes
	 * the whole page with it. Found in review; the test that called this
	 * with `array()` never saw it.
	 *
	 * @return void
	 */
	public function test_a_shortcode_written_without_attributes_still_works(): void {
		$id                             = $this->post( 31, 'kivun_workshop', true );
		Kivun_Test_State::$current_post = $id;

		// How WordPress calls it before 6.5.
		Kivun_Shortcodes::render_lead_form( '' );

		$this->assertSame( 'landing/custom-form.php', $this->drawn()['template'] );
	}

	/**
	 * And neither does any other shortcode written the same way.
	 *
	 * The defect was not mine alone: every callback in the file declared
	 * `array $atts`, and six of them fall back to the page being viewed —
	 * which is to say they are meant to be written bare. Fixed across the
	 * file rather than only where review happened to point, since one of
	 * them fataling takes the page down just as thoroughly.
	 *
	 * Asserted by calling them the way WordPress does before 6.5 and watching
	 * for a TypeError, which is the defect itself: the argument being refused
	 * at the boundary. An archive shortcode that gets past the boundary and
	 * then wants a WP_Query the harness does not stub has already proved the
	 * point, so only the TypeError counts.
	 *
	 * @return void
	 */
	public function test_no_shortcode_fatals_when_written_without_attributes(): void {
		Kivun_Shortcodes::init();

		$tags = array();
		foreach ( Kivun_Test_State::$options as $key => $callback ) {
			if ( 0 === strpos( (string) $key, 'shortcode:' ) ) {
				$tags[ substr( (string) $key, 10 ) ] = $callback;
			}
		}
		$this->assertNotEmpty( $tags, 'no shortcodes were registered, so this test proves nothing' );

		$refused = array();
		foreach ( $tags as $tag => $callback ) {
			Kivun_Test_State::$templates = array();
			try {
				$callback( '' );
			} catch ( TypeError $e ) {
				$refused[] = $tag;
			} catch ( Throwable $e ) {
				continue;
			}
		}

		$this->assertSame( array(), $refused, 'these shortcodes fatal when written without attributes' );
	}

	/**
	 * The form brings the script that makes it a form.
	 *
	 * Without it the browser posts the page instead of calling the lead
	 * endpoint: the form draws perfectly, the visitor gets no error, and the
	 * enquiry is simply gone. The shortcode path is covered by the
	 * `do_shortcode_tag` filter, but the widget calls this method directly
	 * and that filter never runs. Found in review.
	 *
	 * @return void
	 */
	public function test_the_form_brings_its_script_with_it(): void {
		$id = $this->post( 7, 'kivun_workshop', true );

		Kivun_Shortcodes::render_lead_form( array( 'id' => $id ) );

		$this->assertContains( 'kivun-frontend', Kivun_Test_State::$enqueued, 'the form was drawn without its submit handler' );
	}

	/**
	 * And nothing is loaded where no form is drawn.
	 *
	 * @return void
	 */
	public function test_nothing_is_loaded_where_no_form_is_drawn(): void {
		$this->post( 5, 'page' );

		Kivun_Shortcodes::render_lead_form( array( 'id' => 5 ) );

		$this->assertSame( array(), Kivun_Test_State::$enqueued );
	}

	/**
	 * The shortcode is registered under the name the widget and the docs use.
	 *
	 * @return void
	 */
	public function test_the_shortcode_is_registered(): void {
		Kivun_Shortcodes::init();

		$this->assertSame(
			array( 'Kivun_Shortcodes', 'render_lead_form' ),
			Kivun_Test_State::$options['shortcode:kivun_lead_form'] ?? null
		);
	}

	// ── The widget ───────────────────────────────────────────────────────────

	/**
	 * The widget draws through the shortcode rather than its own copy.
	 *
	 * Three render sites drifting apart is the bug this whole feature already
	 * met once, in review. A fourth that decided for itself which template a
	 * page shows would be the same bug again.
	 *
	 * @return void
	 */
	public function test_the_widget_renders_through_the_shortcode(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'elementor/class-kivun-lead-form-widget.php' );

		$this->assertStringContainsString( 'Kivun_Shortcodes::render_lead_form(', $src );
		$this->assertStringNotContainsString(
			'Kivun_Custom_Forms::template(',
			$src,
			'the widget decides for itself which form to draw'
		);
	}

	/**
	 * And Elementor is told the widget exists.
	 *
	 * The widget class alone changes nothing: without the registration hook
	 * it never reaches the editor's panel, and the feature stays as
	 * unreachable as it was.
	 *
	 * @return void
	 */
	public function test_the_widget_is_registered_with_elementor(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'elementor/class-kivun-elementor.php' );

		$this->assertStringContainsString( "add_action( 'elementor/widgets/register'", $src );
		$this->assertStringContainsString( 'new Kivun_Lead_Form_Widget()', $src );
	}
}
