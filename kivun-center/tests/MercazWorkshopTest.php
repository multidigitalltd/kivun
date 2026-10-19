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

	// ── Reading a date a person wrote ─────────────────────────────────────────

	/**
	 * The date is read out of the sentence it is written in.
	 *
	 * Both forms suggest a format with more than a date in it — the סדנה
	 * field shows "15.9.2025 | 18:00" and the landing page
	 * "ד׳ 15.9.2025 | 18:00–21:00" — and neither parsed at all: the match
	 * was against the whole string, so it found nothing, and a date that
	 * parses to nothing is dropped before the request. Sending סדנאות
	 * without fixing this would have restored the field in name only.
	 * Found in review.
	 *
	 * @return void
	 */
	public function test_a_date_is_found_inside_what_people_type(): void {
		foreach ( array(
			'15.9.2025 | 18:00'          => '2025-09-15',
			'ד׳ 15.9.2025 | 18:00–21:00' => '2025-09-15',
			'15/09/2025'                 => '2025-09-15',
			'31/12/2026'                 => '2026-12-31',
			'1.1.26'                     => '2026-01-01',
			'2025-09-15'                 => '2025-09-15',
		) as $written => $sent ) {
			$this->assertSame( $sent, self::as_date( $written ), "\"{$written}\" is not read as a date" );
		}
	}

	/**
	 * And a value with no date in it still sends nothing.
	 *
	 * A course's "מועדים" field reads "ימי ג׳ 18:00-20:00", which is a
	 * schedule and not a date. Guessing one from it would put a wrong date
	 * on their site, which is worse than the empty field they have now.
	 *
	 * @return void
	 */
	public function test_text_with_no_date_sends_none(): void {
		foreach ( array( 'ימי ג׳ 18:00-20:00', 'בקרוב', '', '32.13.2025' ) as $written ) {
			$this->assertSame( '', self::as_date( $written ), "\"{$written}\" produced a date out of nothing" );
		}
	}

	/**
	 * as_date() as the class defines it, which is private.
	 *
	 * @param string $raw The written value.
	 * @return string
	 */
	private static function as_date( string $raw ): string {
		$method = new \ReflectionMethod( 'Kivun_Mercaz_Sync', 'as_date' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $raw );
	}

	// ── A copy is a new record ────────────────────────────────────────────────

	/**
	 * Duplicating synced content does not carry the centre's record id.
	 *
	 * Cloning copies the metadata wholesale. The id names a record on their
	 * side belonging to the original, so a copy holding it pushes over that
	 * record and replaces it instead of creating its own — and what it
	 * overwrote is already out there being read. Found in review.
	 *
	 * @return void
	 */
	public function test_a_copy_does_not_inherit_the_remote_record(): void {
		foreach ( array(
			'includes/class-kivun-duplicate.php'       => 'the admin duplicate',
			'includes/class-kivun-content-creator.php' => 'the console duplicate',
		) as $file => $which ) {
			$source = (string) file_get_contents( KIVUN_DIR . $file );
			$start  = (int) strpos( $source, '$skip = array(' );
			$list   = substr( $source, $start, 400 );

			$this->assertStringContainsString( 'Kivun_Mercaz_Sync::REMOTE_ID', $list, "{$which} copies the centre's record id" );
			$this->assertStringContainsString( 'Kivun_Mercaz_Sync::SYNCED_AT', $list, "{$which} copies the sync state" );
			$this->assertStringContainsString( 'Kivun_Mercaz_Sync::LAST_ERROR', $list, "{$which} copies the last error" );
		}
	}
}
