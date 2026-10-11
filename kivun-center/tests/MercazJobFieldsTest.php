<?php
/**
 * What a job has to carry before it is broadcast to Mercaz Kivun.
 *
 * Their API takes a job without a settlement, a type or a number, answers 201
 * and files it — and the job then sits on their board findable by nobody. Of
 * the 23 Jerusalem jobs already sent, all 23 arrived without a type or a
 * number and 17 without a settlement, and neither end said a word. Nothing
 * about that failure is visible: the request succeeds, the status reads
 * "sent", and the cost only shows up when somebody searches and the job is
 * not in the results.
 *
 * So the check lives here, before the request, and the response's own warning
 * list is read rather than assumed empty.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-mercaz-sync.php';

/**
 * Tests for the job broadcast's required fields and warnings.
 */
final class MercazJobFieldsTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * A body carrying everything the API needs.
	 *
	 * @return array<string,mixed>
	 */
	private function complete_body(): array {
		return array(
			'title'    => 'מזכיר/ה רפואי/ת',
			'content'  => '<p>תיאור המשרה</p>',
			'city'     => array( 294 ),
			'job-type' => array( 72 ),
			'job_cat'  => array( 31 ),
			'meta'     => array(
				'job_number' => '4821',
				'expires_at' => '2026-12-31',
			),
		);
	}

	// ── What counts as required ───────────────────────────────────────────────

	/**
	 * The five the documentation names, and no more — anything else refused
	 * here would block a posting their API would have accepted.
	 *
	 * @return void
	 */
	public function test_the_required_set_is_the_documented_five(): void {
		$this->assertSame(
			array( 'title', 'content', 'city', 'job-type', 'job_number' ),
			array_keys( Kivun_Mercaz_Sync::job_required() )
		);
	}

	/**
	 * A complete job is not held back.
	 *
	 * @return void
	 */
	public function test_a_complete_job_is_missing_nothing(): void {
		$this->assertSame( array(), Kivun_Mercaz_Sync::missing_fields( $this->complete_body() ) );
	}

	/**
	 * Each one is noticed on its own, by a name somebody can go and act on.
	 *
	 * @return void
	 */
	public function test_each_missing_field_is_named(): void {
		foreach ( array(
			'title'    => 'שם המשרה',
			'content'  => 'תיאור המשרה',
			'city'     => 'עיר',
			'job-type' => 'סוג משרה',
		) as $key => $label ) {
			$body = $this->complete_body();
			unset( $body[ $key ] );

			$this->assertSame( array( $label ), Kivun_Mercaz_Sync::missing_fields( $body ), $key );
		}
	}

	/**
	 * Including the one that lives under meta.
	 *
	 * @return void
	 */
	public function test_a_missing_job_number_is_noticed(): void {
		$body = $this->complete_body();
		unset( $body['meta']['job_number'] );

		$this->assertSame( array( 'מספר משרה' ), Kivun_Mercaz_Sync::missing_fields( $body ) );
	}

	/**
	 * A taxonomy that resolved to nothing arrives as an empty array, which is
	 * exactly as absent as not being there — this is the case that produced
	 * 17 jobs with no settlement, because the name was filled in locally and
	 * only failed on the way out.
	 *
	 * @return void
	 */
	public function test_a_taxonomy_that_resolved_to_nothing_counts_as_missing(): void {
		$body         = $this->complete_body();
		$body['city'] = array();

		$this->assertSame( array( 'עיר' ), Kivun_Mercaz_Sync::missing_fields( $body ) );
	}

	/**
	 * A blank string is missing too, not merely falsy — "0" is a real value.
	 *
	 * @return void
	 */
	public function test_blank_is_missing_but_zero_is_a_value(): void {
		$body                        = $this->complete_body();
		$body['meta']['job_number']  = '   ';
		$this->assertSame( array( 'מספר משרה' ), Kivun_Mercaz_Sync::missing_fields( $body ) );

		$body['meta']['job_number'] = '0';
		$this->assertSame( array(), Kivun_Mercaz_Sync::missing_fields( $body ) );
	}

	/**
	 * All of them at once, in the order they are declared, so the message
	 * reads the same way every time.
	 *
	 * @return void
	 */
	public function test_an_empty_job_names_everything(): void {
		$this->assertSame(
			array( 'שם המשרה', 'תיאור המשרה', 'עיר', 'סוג משרה', 'מספר משרה' ),
			Kivun_Mercaz_Sync::missing_fields( array() )
		);
	}

	/**
	 * The recommended set is checked the same way but kept apart, so it can be
	 * reported without refusing the broadcast.
	 *
	 * @return void
	 */
	public function test_the_recommended_set_is_separate(): void {
		$body = $this->complete_body();
		unset( $body['job_cat'], $body['meta']['expires_at'] );

		$this->assertSame( array(), Kivun_Mercaz_Sync::missing_fields( $body ) );
		$this->assertSame(
			array( 'תחום', 'תאריך הסרה' ),
			Kivun_Mercaz_Sync::missing_fields( $body, Kivun_Mercaz_Sync::job_recommended() )
		);
	}

	// ── The response's own warnings ───────────────────────────────────────────

	/**
	 * An empty list means everything was kept — the documented meaning.
	 *
	 * @return void
	 */
	public function test_no_warnings_means_everything_was_kept(): void {
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( array( 'id' => 12, 'ckvn_warnings' => array() ) ) );
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( array( 'id' => 12 ) ) );
	}

	/**
	 * A plain list of field names comes back as it is.
	 *
	 * @return void
	 */
	public function test_a_list_of_field_names_is_read(): void {
		$this->assertSame(
			array( 'city', 'job-type' ),
			Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => array( 'city', 'job-type' ) ) )
		);
	}

	/**
	 * The shape is not promised beyond "empty means fine", so a field => reason
	 * map is read too rather than coming out as "Array".
	 *
	 * @return void
	 */
	public function test_a_field_to_reason_map_is_read(): void {
		$this->assertSame(
			array( 'city (unknown term)' ),
			Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => array( 'city' => 'unknown term' ) ) )
		);
	}

	/**
	 * And nothing that is not a response at all is mistaken for one.
	 *
	 * @return void
	 */
	public function test_a_response_without_warnings_is_not_invented(): void {
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( null ) );
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( 'ok' ) );
		$this->assertSame( array(), Kivun_Mercaz_Sync::warnings( array( 'ckvn_warnings' => '' ) ) );
	}
}
