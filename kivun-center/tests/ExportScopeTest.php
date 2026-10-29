<?php
/**
 * What a filtered export actually covers.
 *
 * A course and the landing page built for it are one piece of content to
 * whoever made them, and one to whoever left their details — the visitor saw
 * a page, not a post type. The leads table has always filtered that way. The
 * download did not, so filtering to a content and exporting it came back
 * holding the enquiries of one post out of the group and read as though the
 * rest had never arrived.
 *
 * Reported from the live site: thirty-five enquiries on screen, one in the
 * spreadsheet.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The ids a filtered export really asks the database for.
 */
final class ExportScopeTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * Reach the export's own scoping.
	 *
	 * @param array $post_ids The contents asked for.
	 * @return array
	 */
	private function scope( array $post_ids ): array {
		return Kivun_Content_Creator::content_scope( $post_ids );
	}

	/**
	 * Put a post in a content group.
	 *
	 * @param int    $id    The post.
	 * @param string $type  Its post type.
	 * @param string $group The group it belongs to, if any.
	 * @return int
	 */
	private function post( int $id, string $type, string $group = '' ): int {
		Kivun_Test_State::$posts[ $id ] = array( 'post_type' => $type );
		if ( '' !== $group ) {
			Kivun_Test_State::$meta[ $id ][ Kivun_Content_Creator::GROUP_META ] = $group;
		}
		return $id;
	}

	/**
	 * The export covers the whole piece of content, as the table does.
	 *
	 * This is the client's case: one content chosen in the filter, its
	 * enquiries spread across the posts that make it up, and a spreadsheet
	 * that held almost none of them.
	 *
	 * @return void
	 */
	public function test_a_chosen_content_covers_every_post_in_it(): void {
		$course  = $this->post( 10, 'kivun_course', 'control-programme' );
		$landing = $this->post( 11, 'kivun_workshop', 'control-programme' );
		$session = $this->post( 12, 'kivun_session', 'control-programme' );

		$scope = $this->scope( array( $course ) );

		sort( $scope );
		$this->assertSame(
			array( $course, $landing, $session ),
			$scope,
			'the export asked for one post of the content and would have left the rest out'
		);
	}

	/**
	 * Choosing the landing page covers the same content.
	 *
	 * Whichever post of the group the filter happens to name, the answer is
	 * the content — otherwise the result depends on which half was picked.
	 *
	 * @return void
	 */
	public function test_any_post_of_the_group_gives_the_same_answer(): void {
		$course  = $this->post( 10, 'kivun_course', 'control-programme' );
		$landing = $this->post( 11, 'kivun_workshop', 'control-programme' );

		$from_course  = $this->scope( array( $course ) );
		$from_landing = $this->scope( array( $landing ) );

		sort( $from_course );
		sort( $from_landing );
		$this->assertSame( $from_course, $from_landing );
	}

	/**
	 * A content that stands alone is still itself.
	 *
	 * @return void
	 */
	public function test_a_post_in_no_group_covers_only_itself(): void {
		$id = $this->post( 20, 'kivun_course' );

		$this->assertSame( array( $id ), $this->scope( array( $id ) ) );
	}

	/**
	 * Two contents chosen at once cover both, and nothing twice.
	 *
	 * @return void
	 */
	public function test_two_contents_cover_both_without_repeating(): void {
		$a_course  = $this->post( 10, 'kivun_course', 'first' );
		$a_landing = $this->post( 11, 'kivun_workshop', 'first' );
		$b_course  = $this->post( 20, 'kivun_course', 'second' );

		$scope = $this->scope( array( $a_course, $a_landing, $b_course ) );

		sort( $scope );
		$this->assertSame( array( $a_course, $a_landing, $b_course ), $scope );
	}

	/**
	 * Another content's posts are not swept in.
	 *
	 * The widening must not become "everything": an export that quietly held
	 * a second course's enquiries would be worse than one holding too few.
	 *
	 * @return void
	 */
	public function test_another_content_is_left_out(): void {
		$mine  = $this->post( 10, 'kivun_course', 'first' );
		$also  = $this->post( 11, 'kivun_workshop', 'first' );
		$other = $this->post( 20, 'kivun_course', 'second' );
		$this->post( 21, 'kivun_workshop', 'second' );

		$scope = $this->scope( array( $mine ) );

		sort( $scope );
		$this->assertSame( array( $mine, $also ), $scope );
		$this->assertNotContains( $other, $scope );
	}

	/**
	 * And the query is built from the widened list, not the asked-for one.
	 *
	 * The scoping is only worth having if the export uses it: a helper that
	 * returns the right ids while the query still interpolates the originals
	 * would pass every test above and ship the same bug.
	 *
	 * @return void
	 */
	public function test_the_table_and_the_button_use_the_same_list(): void {
		$src = (string) file_get_contents( KIVUN_DIR . 'includes/class-kivun-content-creator.php' );

		$this->assertStringContainsString(
			'$content_scope = self::content_scope( $content_filter );',
			$src,
			'the table works the scope out some other way'
		);
		$this->assertStringContainsString(
			'Kivun_Export::url( \'registrations\', $content_scope )',
			$src,
			'the export button carries the chosen contents rather than the posts they cover'
		);
		$this->assertStringNotContainsString(
			'Kivun_Export::url( \'registrations\', $content_filter )',
			$src,
			'the button still carries the unwidened filter'
		);
	}
}
