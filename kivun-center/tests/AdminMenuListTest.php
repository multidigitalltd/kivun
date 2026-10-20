<?php
/**
 * The admin-menu checklist.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Two menus under one name have to be told apart.
 */
final class AdminMenuListTest extends TestCase {

	/**
	 * A name that only one menu uses needs nothing added to it.
	 *
	 * @return void
	 */
	public function test_a_unique_name_stands_on_its_own(): void {
		$rows = Kivun_Admin_Settings::menu_choices(
			array(
				'index.php'  => 'לוח בקרה',
				'upload.php' => 'מדיה',
			)
		);

		foreach ( $rows as $row ) {
			$this->assertSame( '', $row['hint'], $row['label'] . ' was marked as ambiguous' );
		}
	}

	/**
	 * A shared name gets each menu's own identifier beside it.
	 *
	 * Elementor registers two top-level menus under one word. Unticking one of
	 * two identical rows and watching the menu stay put reads as the setting
	 * being broken — and that is how it was read.
	 *
	 * @return void
	 */
	public function test_a_shared_name_carries_the_identifier(): void {
		$rows = Kivun_Admin_Settings::menu_choices(
			array(
				'elementor'     => 'אלמנטור',
				'elementor-app' => 'אלמנטור',
				'upload.php'    => 'מדיה',
			)
		);

		$hints = array();
		foreach ( $rows as $row ) {
			$hints[ $row['slug'] ] = $row['hint'];
		}

		$this->assertSame( 'elementor', $hints['elementor'] );
		$this->assertSame( 'elementor-app', $hints['elementor-app'] );
		$this->assertSame( '', $hints['upload.php'], 'a name nobody shares was marked anyway' );
	}

	/**
	 * The identifier is the value the checkbox submits.
	 *
	 * A hint that named anything else would point at the wrong row, which is
	 * worse than no hint at all.
	 *
	 * @return void
	 */
	public function test_the_identifier_is_what_the_box_submits(): void {
		$rows = Kivun_Admin_Settings::menu_choices(
			array(
				'elementor'     => 'אלמנטור',
				'elementor-app' => 'אלמנטור',
			)
		);

		foreach ( $rows as $row ) {
			$this->assertSame( $row['slug'], $row['hint'] );
		}
	}

	/**
	 * A menu with no name of its own is listed under its slug; that is not
	 * a collision to annotate.
	 *
	 * read_menu() falls back to the slug when the label is empty, so two such
	 * menus would otherwise be handed a hint repeating what the row already
	 * says.
	 *
	 * @return void
	 */
	public function test_a_row_already_showing_its_slug_is_left_alone(): void {
		$rows = Kivun_Admin_Settings::menu_choices(
			array(
				'odd-one' => 'odd-one',
				'other'   => 'שונה',
			)
		);

		$this->assertSame( '', $rows[0]['hint'] );
	}

	/**
	 * Every menu stays on the list.
	 *
	 * The checklist is the only way back for a menu that was hidden, so a row
	 * dropped here is a menu nobody can turn on again.
	 *
	 * @return void
	 */
	public function test_no_menu_is_dropped(): void {
		$menus = array(
			'index.php'     => 'לוח בקרה',
			'elementor'     => 'אלמנטור',
			'elementor-app' => 'אלמנטור',
			'upload.php'    => 'מדיה',
		);

		$rows = Kivun_Admin_Settings::menu_choices( $menus );
		$this->assertCount( count( $menus ), $rows );
		$this->assertSame( array_keys( $menus ), array_column( $rows, 'slug' ) );
	}
}
