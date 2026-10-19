<?php
/**
 * What a workshop carries when it goes to the centre.
 *
 * The site reported that סדנאות arrive without their date, their time or
 * who they are for. All three were true, and for a reason neither end could
 * see from the outside: the type that was being sent as a "workshop" was
 * not a סדנה at all.
 *
 * Locally there are two separate post types. `kivun_session` is a סדנה;
 * `kivun_workshop`, despite the name, is a דף נחיתה. The sync's type map
 * listed `kivun_workshop` and not `kivun_session`, so no סדנה had ever been
 * sent — while the payload it built read `_kivun_session_*` meta, which a
 * landing page does not have. Empty values are dropped before the request,
 * so the three fields left silently on every push.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once KIVUN_DIR . 'includes/class-kivun-mercaz-sync.php';

/**
 * Tests for the workshop payload and the types that reach it.
 */
final class MercazWorkshopTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * A סדנה is sent at all.
	 *
	 * @return void
	 */
	public function test_a_session_is_a_workshop_to_them(): void {
		$map = Kivun_Mercaz_Sync::type_map();

		$this->assertArrayHasKey( 'kivun_session', $map, 'no סדנה has ever been sent' );
		$this->assertSame( 'workshops', $map['kivun_session'] );
	}

	/**
	 * And the landing page keeps going where it was going.
	 *
	 * It has been sent as a workshop for as long as this has run, and the
	 * records it made are on their side with ids stored against them here.
	 * Dropping it from the map would strand every one of those.
	 *
	 * @return void
	 */
	public function test_the_landing_page_still_goes(): void {
		$map = Kivun_Mercaz_Sync::type_map();

		$this->assertArrayHasKey( 'kivun_workshop', $map );
		$this->assertSame( 'workshops', $map['kivun_workshop'] );
	}

	/**
	 * Each of the two reads the keys its own form writes.
	 *
	 * This is the bug the site was seeing. One branch served both types and
	 * read one type's meta, so whichever type it was not reading for sent
	 * nothing in those three fields.
	 *
	 * @return void
	 */
	public function test_each_type_reads_its_own_fields(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz-sync.php' );
		$start  = (int) strpos( $source, "case 'workshops':" );
		$branch = substr( $source, $start, 1400 );

		$this->assertStringContainsString( "'kivun_workshop' === \$post->post_type ? '_kivun_ws_audience' : '_kivun_session_audience'", $branch );
		$this->assertStringContainsString( "'kivun_workshop' === \$post->post_type ? '_kivun_ws_date' : '_kivun_session_date'", $branch );
	}

	/**
	 * And those keys are the ones the forms actually write.
	 *
	 * Asserted against the admin form rather than against a list kept here,
	 * because a payload that agrees with a list and disagrees with the form
	 * is exactly what shipped.
	 *
	 * @return void
	 */
	public function test_those_keys_are_the_ones_the_forms_save(): void {
		$admin = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-admin.php' );

		foreach ( array(
			'_kivun_ws_date'           => 'the landing page date field',
			'_kivun_ws_audience'       => 'the landing page audience field',
			'_kivun_session_date'      => 'the סדנה date field',
			'_kivun_session_audience'  => 'the סדנה audience field',
		) as $key => $what ) {
			$this->assertStringContainsString(
				'name="' . $key . '"',
				$admin,
				"the payload reads {$key}, but {$what} no longer saves it"
			);
		}
	}

	/**
	 * An empty value never reaches the request.
	 *
	 * Worth stating because it is why this went unseen for so long: a field
	 * that mapped to nothing was dropped rather than sent empty, so their
	 * side had nothing to warn about and ours reported a clean push.
	 *
	 * @return void
	 */
	public function test_empty_values_are_dropped_before_sending(): void {
		$source = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-mercaz-sync.php' );

		$this->assertStringContainsString( "\$body['meta'] = array_filter(", $source );
	}
}
