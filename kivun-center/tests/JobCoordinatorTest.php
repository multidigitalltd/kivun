<?php
/**
 * Which coordinator an application reaches.
 *
 * The rota shares out candidates who arrive through the board and belong to
 * nobody. A job is different: whoever posted it named the person handling that
 * employer. Getting the precedence wrong is invisible — a CV still arrives,
 * just on the wrong desk — so it is pinned down here.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the job → coordinator assignment.
 */
final class JobCoordinatorTest extends TestCase {

	/**
	 * Start each test with an empty site and a two-person roster.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
		Kivun_Test_State::$options['kivun_settings'] = array(
			'coordinators_male'   => "יהודה, yehudaf@kivun.test = 1\nרועי, roim@kivun.test = 1",
			'coordinators_female' => "אביגיל, avigaila@kivun.test = 1/4\nדסי, dasi@kivun.test = 3/4",
		);
	}

	/**
	 * The picker offers everyone, once each, however many rosters they are on.
	 *
	 * @return void
	 */
	public function test_everyone_is_offered_once(): void {
		$all = Kivun_Coordinators::all();

		$this->assertSame(
			array( 'yehudaf@kivun.test', 'roim@kivun.test', 'avigaila@kivun.test', 'dasi@kivun.test' ),
			array_keys( $all )
		);
	}

	/**
	 * Somebody on both rosters is one person, not two entries.
	 *
	 * @return void
	 */
	public function test_someone_on_both_rosters_appears_once(): void {
		Kivun_Test_State::$options['kivun_settings'] = array(
			'coordinators_male'   => 'דסי, dasi@kivun.test = 1',
			'coordinators_female' => 'דסי, dasi@kivun.test = 1',
		);

		$this->assertSame( array( 'dasi@kivun.test' ), array_keys( Kivun_Coordinators::all() ) );
	}

	/**
	 * The name is carried, so the picker can show who the address belongs to.
	 *
	 * @return void
	 */
	public function test_a_coordinator_is_found_by_address(): void {
		$found = Kivun_Coordinators::by_email( 'dasi@kivun.test' );

		$this->assertSame( 'דסי', $found['name'] );
	}

	/**
	 * An address nobody is on is nobody. This is what stops a job quietly
	 * holding someone who has left and still being sent their applications.
	 *
	 * @return void
	 */
	public function test_an_address_off_the_roster_is_nobody(): void {
		$this->assertSame( array(), Kivun_Coordinators::by_email( 'someone@elsewhere.test' ) );
		$this->assertSame( array(), Kivun_Coordinators::by_email( '' ) );
		$this->assertSame( array(), Kivun_Coordinators::by_email( 'not an address' ) );
	}

	/**
	 * The rule the application handler follows: the job's own coordinator
	 * first, the rota only when the job names nobody.
	 *
	 * @param string $assigned What the job holds.
	 * @param string $gender   What the candidate said.
	 * @return array{email:string,weight:int,name:string,phone:string}|array{}
	 */
	private function routed( string $assigned, string $gender ): array {
		$coordinator = Kivun_Coordinators::by_email( $assigned );
		if ( ! $coordinator ) {
			$coordinator = Kivun_Coordinators::pick( $gender );
		}
		return $coordinator;
	}

	/**
	 * A named coordinator gets it, whatever the candidate said about gender —
	 * the choice was deliberate and the rota must not overrule it.
	 *
	 * @return void
	 */
	public function test_the_named_coordinator_wins_over_the_rota(): void {
		foreach ( array( 'גבר', 'אשה', '' ) as $gender ) {
			$this->assertSame( 'avigaila@kivun.test', $this->routed( 'avigaila@kivun.test', $gender )['email'] );
		}
	}

	/**
	 * And taking that route does not move the rota on, or a run of assigned
	 * jobs would skew the split for the candidates the rota is actually for.
	 *
	 * @return void
	 */
	public function test_an_assigned_job_does_not_advance_the_rota(): void {
		// Where the rota stands, read from a fresh one rather than assumed.
		$expected = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$expected[] = Kivun_Coordinators::pick( 'אשה' )['email'];
		}

		Kivun_Test_State::$options[ Kivun_Coordinators::STATE ] = array();

		// Ten assigned jobs go by. None of them is the rota's business.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->routed( 'dasi@kivun.test', 'אשה' );
		}

		$after = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$after[] = Kivun_Coordinators::pick( 'אשה' )['email'];
		}

		$this->assertSame( $expected, $after, 'an assigned job must not take somebody\'s turn' );
	}

	/**
	 * A job nobody was named on still reaches somebody.
	 *
	 * @return void
	 */
	public function test_an_unassigned_job_falls_back_to_the_rota(): void {
		$this->assertContains(
			$this->routed( '', 'גבר' )['email'],
			array( 'yehudaf@kivun.test', 'roim@kivun.test' )
		);
	}

	/**
	 * Including when the job holds an address that is no longer on a roster.
	 *
	 * @return void
	 */
	public function test_a_stale_assignment_falls_back_to_the_rota(): void {
		$this->assertContains(
			$this->routed( 'whoever@left.test', 'גבר' )['email'],
			array( 'yehudaf@kivun.test', 'roim@kivun.test' )
		);
	}

	/**
	 * With no roster at all, nothing is named and nothing is picked — the
	 * application is still saved, and the employer address still gets it.
	 *
	 * @return void
	 */
	public function test_with_no_roster_nobody_is_named(): void {
		Kivun_Test_State::$options['kivun_settings'] = array();

		$this->assertSame( array(), $this->routed( 'dasi@kivun.test', 'אשה' ) );
	}
}
