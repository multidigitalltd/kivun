<?php
/**
 * One submission, one letter per inbox.
 *
 * A course registration was arriving twice at the address set on the course.
 * Two senders were writing to it: the letter written for a registration, and
 * the forms router, hooked to the `kivun_after_registration` fired a line
 * later and resolving its destination from the very same `_kivun_contact_email`.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The two senders, and who gives way.
 */
final class DuplicateNotificationTest extends TestCase {

	/**
	 * Reset the stubs between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Put a course on the books with its own address.
	 *
	 * @param string $email The address the course sends its registrations to.
	 * @return int The course id.
	 */
	private function course( string $email ): int {
		Kivun_Test_State::$posts[ 71 ] = array(
			'post_type'  => 'kivun_course',
			'post_title' => 'קורס בדיקה',
		);
		Kivun_Test_State::$meta[ 71 ]['_kivun_contact_email'] = $email;
		return 71;
	}

	/**
	 * Run the router's routing step, which is private.
	 *
	 * @param string $email    The destination.
	 * @param bool   $use_rota Whether the coordinators share this one.
	 * @return void
	 */
	private function route( string $email, bool $use_rota = false ): void {
		$method = new ReflectionMethod( Kivun_Forms_Router::class, 'route' );
		$method->setAccessible( true );
		$method->invoke( null, $email, 'Kivun', array( 'שם' => 'דנה', 'מגדר' => 'אישה' ), 'https://example.test/course/', $use_rota );
	}

	/**
	 * Both senders resolve the same address — which is why they collided.
	 *
	 * If this ever stops being true the duplicate is gone for a different
	 * reason, and the guard below is guarding nothing.
	 *
	 * @return void
	 */
	public function test_both_senders_aim_at_the_same_inbox(): void {
		$course = $this->course( 'courses@example.test' );

		Kivun_Mailer::send_course_registration( $course, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'city' => 'ירושלים', 'message' => '' ) );
		$to = array_column( Kivun_Test_State::$mail, 'to' );

		$this->assertContains( 'courses@example.test', $to );
		$this->assertSame( 'courses@example.test', Kivun_Coordinators::page_handler( $course ) );
	}

	/**
	 * The second sender gives way.
	 *
	 * @return void
	 */
	public function test_the_router_does_not_write_to_an_inbox_already_told(): void {
		$course = $this->course( 'courses@example.test' );
		Kivun_Mailer::send_course_registration( $course, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'city' => 'ירושלים', 'message' => '' ) );

		$before = count( array_filter( array_column( Kivun_Test_State::$mail, 'to' ), fn( $to ) => 'courses@example.test' === $to ) );
		$this->route( 'courses@example.test' );
		$after = count( array_filter( array_column( Kivun_Test_State::$mail, 'to' ), fn( $to ) => 'courses@example.test' === $to ) );

		$this->assertSame( 1, $before, 'the registration letter did not go out' );
		$this->assertSame( 1, $after, 'the same inbox got a second copy of the same submission' );
	}

	/**
	 * Case does not make it a different inbox.
	 *
	 * @return void
	 */
	public function test_the_same_address_in_another_case_is_the_same_inbox(): void {
		$course = $this->course( 'Courses@Example.test' );
		Kivun_Mailer::send_course_registration( $course, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'city' => 'ירושלים', 'message' => '' ) );

		$this->route( 'courses@example.TEST' );

		$this->assertCount( 2, Kivun_Test_State::$mail, 'the registrant\'s confirmation plus one notification, and nothing else' );
	}

	/**
	 * Surrounding space does not make it a different inbox either.
	 *
	 * The router validates the address before it gets this far, so this is
	 * asked of the comparison itself.
	 *
	 * @return void
	 */
	public function test_surrounding_space_is_the_same_inbox(): void {
		$course = $this->course( ' courses@example.test ' );
		Kivun_Mailer::send_course_registration( $course, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'city' => 'ירושלים', 'message' => '' ) );

		$this->assertTrue( Kivun_Mailer::already_notified( 'courses@example.test' ) );
	}

	/**
	 * An address nobody has written to still gets the router's letter.
	 *
	 * @return void
	 */
	public function test_an_untouched_inbox_still_gets_routed_mail(): void {
		$this->route( 'central@example.test' );

		$this->assertSame( array( 'central@example.test' ), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * A letter that did not get out leaves the router to try.
	 *
	 * The router is the fallback for exactly this. Standing down after a
	 * failed send would leave the lead with nobody at all.
	 *
	 * @return void
	 */
	public function test_a_failed_letter_does_not_silence_the_fallback(): void {
		$course                        = $this->course( 'courses@example.test' );
		Kivun_Test_State::$mail_fails = true;
		Kivun_Mailer::send_course_registration( $course, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'city' => 'ירושלים', 'message' => '' ) );
		Kivun_Test_State::$mail_fails = false;

		$this->route( 'courses@example.test' );

		$this->assertSame( array( 'courses@example.test' ), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * A landing page's lead is the same story.
	 *
	 * @return void
	 */
	public function test_a_landing_page_lead_is_not_doubled_either(): void {
		Kivun_Test_State::$posts[ 71 ] = array(
			'post_type'  => 'kivun_workshop',
			'post_title' => 'דף נחיתה',
		);
		Kivun_Test_State::$meta[ 71 ]['_kivun_contact_email'] = 'landing@example.test';

		Kivun_Mailer::send_lead_notification( 71, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'message' => '' ), 'workshop' );
		$this->route( 'landing@example.test' );

		$this->assertSame( array( 'landing@example.test' ), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * On an Elementor form of ours the order is not ours to pick, so the
	 * decision waits for the end of the request.
	 *
	 * @return void
	 */
	public function test_our_own_elementor_form_holds_the_routed_letter_back(): void {
		$carries = new ReflectionMethod( Kivun_Forms_Router::class, 'carries_own_action' );
		$carries->setAccessible( true );

		$ours = new class() {
			/**
			 * The form's settings.
			 *
			 * @param string $key Which setting.
			 * @return mixed
			 */
			public function get_form_settings( string $key ) {
				return 'submit_actions' === $key ? array( 'email', 'kivun_course_registration' ) : '';
			}
		};
		$theirs = new class() {
			/**
			 * The form's settings.
			 *
			 * @param string $key Which setting.
			 * @return mixed
			 */
			public function get_form_settings( string $key ) {
				return 'submit_actions' === $key ? array( 'email' ) : '';
			}
		};

		$this->assertTrue( $carries->invoke( null, $ours ) );
		$this->assertFalse( $carries->invoke( null, $theirs ) );
		$this->assertFalse( $carries->invoke( null, null ), 'a record that cannot be asked is not ours' );
	}

	/**
	 * Held back and then dropped, once the dedicated letter has gone.
	 *
	 * @return void
	 */
	public function test_a_held_letter_is_dropped_when_the_dedicated_one_went(): void {
		$pending = new ReflectionProperty( Kivun_Forms_Router::class, 'pipeline_pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, true );

		$this->route( 'courses@example.test' );
		$this->assertSame( array(), Kivun_Test_State::$mail, 'the letter was sent instead of held' );

		$course = $this->course( 'courses@example.test' );
		Kivun_Mailer::send_course_registration( $course, array( 'name' => 'דנה', 'email' => 'dana@example.test', 'phone' => '0500000000', 'city' => 'ירושלים', 'message' => '' ) );

		Kivun_Forms_Router::flush_deferred();

		$to = array_filter( array_column( Kivun_Test_State::$mail, 'to' ), fn( $t ) => 'courses@example.test' === $t );
		$this->assertCount( 1, $to, 'the held letter went out on top of the dedicated one' );
	}

	/**
	 * Held back and then sent, when the pipeline decided not to write.
	 *
	 * A duplicate registration, a full course, a paid one that went to
	 * checkout: the dedicated letter never comes, and this is the only one.
	 *
	 * @return void
	 */
	public function test_a_held_letter_goes_out_when_no_dedicated_one_came(): void {
		$pending = new ReflectionProperty( Kivun_Forms_Router::class, 'pipeline_pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, true );

		$this->route( 'courses@example.test' );
		Kivun_Forms_Router::flush_deferred();

		$this->assertSame( array( 'courses@example.test' ), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * A coordinator who is also the inbox already told gets no second copy.
	 *
	 * The rota's pick and the address a page sends its leads to are
	 * sometimes the same person, and that person was reading two copies of
	 * one submission.
	 *
	 * @return void
	 */
	public function test_a_coordinator_who_was_already_told_is_skipped(): void {
		Kivun_Test_State::$options['kivun_settings'] = array( 'coordinators_female' => 'dasi@example.test' );

		Kivun_Mailer::note_notified( 'dasi@example.test', true );
		$this->route( '', true );

		$this->assertSame( array(), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * And a coordinator waits for the end of the request too, when one of
	 * our own forms still has its action to run.
	 *
	 * Deferring only the page's address left this ordering doubling: the
	 * router wrote to the coordinator at once, and the dedicated letter
	 * went to the same person a moment later.
	 *
	 * @return void
	 */
	public function test_a_coordinator_is_held_back_as_well(): void {
		Kivun_Test_State::$options['kivun_settings'] = array( 'coordinators_female' => 'dasi@example.test' );

		$pending = new ReflectionProperty( Kivun_Forms_Router::class, 'pipeline_pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, true );

		$this->route( '', true );
		$this->assertSame( array(), Kivun_Test_State::$mail, 'the coordinator was written to before the pipeline had run' );

		Kivun_Mailer::note_notified( 'dasi@example.test', true );
		Kivun_Forms_Router::flush_deferred();

		$this->assertSame( array(), array_column( Kivun_Test_State::$mail, 'to' ), 'the held letter went out on top of the dedicated one' );
	}

	/**
	 * One inbox that is both the page's address and the coordinator is
	 * queued once, not twice.
	 *
	 * @return void
	 */
	public function test_one_inbox_is_held_once(): void {
		Kivun_Test_State::$options['kivun_settings'] = array( 'coordinators_female' => 'both@example.test' );

		$pending = new ReflectionProperty( Kivun_Forms_Router::class, 'pipeline_pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, true );

		$this->route( 'both@example.test', true );
		Kivun_Forms_Router::flush_deferred();

		$this->assertSame( array( 'both@example.test' ), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * The same inbox reached twice in one pass is written to once.
	 *
	 * @return void
	 */
	public function test_one_inbox_is_written_to_once(): void {
		Kivun_Test_State::$options['kivun_settings'] = array( 'coordinators_female' => 'both@example.test' );

		$this->route( 'both@example.test', true );

		$this->assertSame( array( 'both@example.test' ), array_column( Kivun_Test_State::$mail, 'to' ) );
	}

	/**
	 * Flushing twice does not send twice.
	 *
	 * @return void
	 */
	public function test_the_held_letter_is_sent_once(): void {
		$pending = new ReflectionProperty( Kivun_Forms_Router::class, 'pipeline_pending' );
		$pending->setAccessible( true );
		$pending->setValue( null, true );

		$this->route( 'courses@example.test' );
		Kivun_Forms_Router::flush_deferred();
		Kivun_Forms_Router::flush_deferred();

		$this->assertCount( 1, Kivun_Test_State::$mail );
	}
}
