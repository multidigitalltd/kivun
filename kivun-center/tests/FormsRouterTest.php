<?php
/**
 * Which submissions belong to the jobs coordinators.
 *
 * This is the rule that was wrong in the field: the rota was consulted for
 * every submission carrying a gender field, which swept in the landing pages
 * and skewed the split for the candidates it was built for. The cost of
 * getting it wrong is invisible — a lead reaching the wrong desk looks exactly
 * like one reaching the right desk — so it is pinned down here.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Kivun_Forms_Router's scoping.
 */
final class FormsRouterTest extends TestCase {

	/**
	 * A site with a jobs board, a job, a page holding the board, and a landing
	 * page — the shapes that actually exist on kivun.org.il.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();

		Kivun_Test_State::$options['archive_kivun_job'] = 'https://example.test/jobs/';

		Kivun_Test_State::$posts = array(
			164 => array(
				'post_type'    => 'page',
				'post_title'   => 'דף הבית',
				'post_content' => 'ברוכים הבאים',
				'permalink'    => 'https://example.test/',
			),
			900 => array(
				'post_type'    => 'page',
				'post_title'   => 'לוח משרות',
				'post_content' => '',
				'permalink'    => 'https://example.test/board/',
			),
			555 => array(
				'post_type'  => 'kivun_job',
				'post_title' => 'מזכיר/ה רפואי/ת',
				'permalink'  => 'https://example.test/jobs/medical-secretary/',
			),
			700 => array(
				'post_type'    => 'kivun_workshop',
				'post_title'   => 'דף נחיתה לקורס',
				'post_content' => 'טופס',
				'permalink'    => 'https://example.test/landing/course/',
			),
		);

		// The board reaches page 900 through an Elementor shortcode widget,
		// which lives in the builder data rather than the content.
		Kivun_Test_State::$meta[900]['_elementor_data'] =
			'[{"widgetType":"shortcode","settings":{"shortcode":"[kivun_jobs per_page=\"10\"]"}}]';
	}

	/**
	 * Ask the router whether a submission is the coordinators'.
	 *
	 * @param string $page_url  Where it was submitted from.
	 * @param string $form_name The Elementor form name.
	 * @param string $form_id   The Elementor form widget id.
	 * @param int    $posted_id The post or template the form sits in.
	 * @return bool
	 */
	private function applies( string $page_url, string $form_name = '', string $form_id = '', int $posted_id = 0 ): bool {
		$reflected = new ReflectionMethod( Kivun_Forms_Router::class, 'rota_applies' );
		$reflected->setAccessible( true );
		return (bool) $reflected->invoke( null, $page_url, $form_name, $form_id, $posted_id );
	}

	/**
	 * Put a form allowlist into settings.
	 *
	 * @param string $lines One form name or id per line.
	 * @return void
	 */
	private function allow( string $lines ): void {
		Kivun_Test_State::$options['kivun_settings'] = array( 'coordinators_forms' => $lines );
	}

	/**
	 * The board's own form is the candidates' one.
	 *
	 * @return void
	 */
	public function test_the_board_is_the_coordinators(): void {
		$this->assertTrue( $this->applies( 'https://example.test/jobs/', 'שלחו לי פרטים', '35e8f9e' ) );
	}

	/**
	 * A tracked link must not change the answer.
	 *
	 * @return void
	 */
	public function test_utm_parameters_do_not_change_the_answer(): void {
		$this->assertTrue( $this->applies( 'https://example.test/jobs/?utm_source=facebook&utm_medium=cpc' ) );
	}

	/**
	 * An enquiry from a job is a candidate's.
	 *
	 * @return void
	 */
	public function test_a_job_page_is_the_coordinators(): void {
		$this->assertTrue( $this->applies( 'https://example.test/jobs/medical-secretary/' ) );
	}

	/**
	 * The board may be an ordinary page holding the shortcode, and on this site
	 * that shortcode is in Elementor's data rather than the content.
	 *
	 * @return void
	 */
	public function test_a_page_holding_the_board_counts(): void {
		$this->assertTrue( $this->applies( 'https://example.test/board/' ) );
	}

	/**
	 * The bug. A landing page collects a gender and is not the board.
	 *
	 * @return void
	 */
	public function test_a_landing_page_is_not_the_coordinators(): void {
		$this->assertFalse( $this->applies( 'https://example.test/landing/course/', 'דף נחיתה', 'df2d319', 700 ) );
	}

	/**
	 * The site-wide contact form is not the board's, wherever it appears.
	 *
	 * @return void
	 */
	public function test_the_contact_form_on_the_home_page_is_not_the_coordinators(): void {
		$this->assertFalse( $this->applies( 'https://example.test/', 'צרו קשר', 'a25770b', 164 ) );
	}

	/**
	 * No referer at all must not quietly switch the split off: Elementor also
	 * says which template the form was built into.
	 *
	 * @return void
	 */
	public function test_the_template_identifies_the_board_without_a_referer(): void {
		Kivun_Test_State::$posts[868] = array(
			'post_type'    => 'elementor_library',
			'post_title'   => 'ארכיון משרות',
			'post_content' => '',
		);
		Kivun_Test_State::$meta[868]['_elementor_data'] =
			'[{"widgetType":"shortcode","settings":{"shortcode":"[kivun_jobs]"}}]';

		$this->assertTrue( $this->applies( '', '', '35e8f9e', 868 ) );
	}

	/**
	 * Knowing nothing means not theirs, rather than a guess.
	 *
	 * @return void
	 */
	public function test_knowing_nothing_means_not_theirs(): void {
		$this->assertFalse( $this->applies( '' ) );
	}

	/**
	 * A form moved off the board can be named in settings, by its id.
	 *
	 * @return void
	 */
	public function test_an_allowlisted_form_id_counts_anywhere(): void {
		$this->allow( "35e8f9e\nטופס מועמדים" );

		$this->assertTrue( $this->applies( 'https://example.test/somewhere/', '', '35e8f9e' ) );
	}

	/**
	 * Or by its name, which is what the editor sees.
	 *
	 * @return void
	 */
	public function test_an_allowlisted_form_name_counts_anywhere(): void {
		$this->allow( "35e8f9e\nטופס מועמדים" );

		$this->assertTrue( $this->applies( 'https://example.test/somewhere/', 'טופס מועמדים', 'zzz' ) );
	}

	/**
	 * A form not on the list is still not theirs.
	 *
	 * @return void
	 */
	public function test_a_form_not_on_the_list_is_not_theirs(): void {
		$this->allow( '35e8f9e' );

		$this->assertFalse( $this->applies( 'https://example.test/somewhere/', 'צרו קשר', 'a25770b' ) );
	}

	/**
	 * The lead a board form files through the plugin's own pipeline is still the
	 * board's, whichever post it gets filed against. Both entry points claim a
	 * submission and the first there decides, so they have to agree.
	 *
	 * @return void
	 */
	public function test_a_board_lead_filed_against_another_post_is_still_theirs(): void {
		$this->assertTrue( $this->applies( 'https://example.test/jobs/', 'Kivun', '', 700 ) );
	}

	/**
	 * And the same lead filed from a landing page is not.
	 *
	 * @return void
	 */
	public function test_the_same_pipeline_from_a_landing_page_is_not_theirs(): void {
		$this->assertFalse( $this->applies( 'https://example.test/landing/course/', 'Kivun', '', 700 ) );
	}

	/**
	 * A site that wants the question answered differently can say so.
	 *
	 * @return void
	 */
	public function test_the_decision_can_be_overridden_by_a_filter(): void {
		add_filter( 'kivun_coordinators_apply', static fn() => true );

		$this->assertTrue( $this->applies( 'https://example.test/landing/course/' ) );
	}

	/**
	 * Clear the filter this class registers, so it cannot reach another test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $kivun_test_filters;
		$kivun_test_filters = array();
	}

	// ── Gender detection ──────────────────────────────────────────────────────

	/**
	 * The gender field is matched on its label, which is written by whoever
	 * built the form.
	 *
	 * @dataProvider gender_label_provider
	 * @param string $label What the field is called.
	 * @return void
	 */
	public function test_a_gender_field_is_found_by_its_label( string $label ): void {
		$reflected = new ReflectionMethod( Kivun_Forms_Router::class, 'gender_in' );
		$reflected->setAccessible( true );

		$this->assertSame( 'אשה', $reflected->invoke( null, array( $label => 'אשה' ) ) );
	}

	/**
	 * Labels a form builder might use.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function gender_label_provider(): array {
		return array(
			'hebrew'      => array( 'מגדר' ),
			'english'     => array( 'Gender' ),
			'lowercase'   => array( 'gender' ),
			'sex'         => array( 'Sex' ),
			'either side' => array( 'גבר / אישה' ),
		);
	}

	/**
	 * A form with no gender field says nothing about gender.
	 *
	 * @return void
	 */
	public function test_a_form_without_a_gender_field_says_nothing(): void {
		$reflected = new ReflectionMethod( Kivun_Forms_Router::class, 'gender_in' );
		$reflected->setAccessible( true );

		$this->assertSame(
			'',
			$reflected->invoke(
				null,
				array(
					'שם'    => 'דנה',
					'טלפון' => '0501234567',
					'עיר'   => 'ירושלים',
				)
			)
		);
	}
}
