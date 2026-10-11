<?php
/**
 * Recognising a management screen so it is kept out of the page cache.
 *
 * These screens show live data behind a nonce, so a cached copy is wrong twice
 * over: the tables show what was true when the copy was taken, and the nonce
 * baked into it was minted for whoever it was taken for.
 *
 * The check used to read only the post's content. Every one of these pages on
 * this site is built in Elementor, which keeps a shortcode widget in
 * `_elementor_data` — so it matched nothing, the screens were cacheable, and a
 * job posted a minute earlier was missing from a copy taken before it existed.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests the detection that Kivun_Core::prevent_caching() relies on.
 */
final class ManagementPageCacheTest extends TestCase {

	/**
	 * Start each test with an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		Kivun_Test_State::reset();
	}

	/**
	 * The question prevent_caching() asks of a page.
	 *
	 * @param int $post_id The page.
	 * @return bool Whether it holds a management shortcode.
	 */
	private function is_management_page( int $post_id ): bool {
		$haystack = (string) get_post_field( 'post_content', $post_id )
			. (string) get_post_meta( $post_id, '_elementor_data', true );

		foreach ( array( 'kivun_content_creator', 'kivun_employer_dashboard', 'kivun_my_applications' ) as $tag ) {
			if ( has_shortcode( $haystack, $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register a page with content and optional builder data.
	 *
	 * @param int    $id        The page id.
	 * @param string $content   Its post content.
	 * @param string $elementor Its builder data.
	 * @return void
	 */
	private function page( int $id, string $content = '', string $elementor = '' ): void {
		Kivun_Test_State::$posts[ $id ] = array(
			'post_type'    => 'page',
			'post_content' => $content,
		);
		if ( '' !== $elementor ) {
			Kivun_Test_State::$meta[ $id ]['_elementor_data'] = $elementor;
		}
	}

	/**
	 * A plain page holding the shortcode in its content — found before and now.
	 *
	 * @return void
	 */
	public function test_a_shortcode_in_the_content_is_found(): void {
		$this->page( 1, '[kivun_employer_dashboard]' );

		$this->assertTrue( $this->is_management_page( 1 ) );
	}

	/**
	 * The one that was missed: a page built in Elementor keeps the shortcode
	 * in its builder data, and the screen was left cacheable.
	 *
	 * @dataProvider builder_page_provider
	 * @param string $tag The shortcode the page holds.
	 * @return void
	 */
	public function test_a_shortcode_in_the_builder_data_is_found( string $tag ): void {
		$this->page(
			2,
			'',
			'[{"widgetType":"shortcode","settings":{"shortcode":"[' . $tag . ']"}}]'
		);

		$this->assertTrue( $this->is_management_page( 2 ), $tag . ' should be found in builder data' );
	}

	/**
	 * Every screen that shows live data behind a nonce.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function builder_page_provider(): array {
		return array(
			'content console'  => array( 'kivun_content_creator' ),
			'jobs dashboard'   => array( 'kivun_employer_dashboard' ),
			'my applications'  => array( 'kivun_my_applications' ),
		);
	}

	/**
	 * A shortcode with attributes is still that shortcode.
	 *
	 * @return void
	 */
	public function test_attributes_do_not_hide_it(): void {
		$this->page( 3, '', '[{"settings":{"shortcode":"[kivun_employer_dashboard view=\"jobs\"]"}}]' );

		$this->assertTrue( $this->is_management_page( 3 ) );
	}

	/**
	 * An ordinary page stays cacheable — the point of the cache is that most
	 * pages keep using it.
	 *
	 * @return void
	 */
	public function test_an_ordinary_page_is_left_cacheable(): void {
		$this->page( 4, 'ברוכים הבאים', '[{"widgetType":"heading","settings":{"title":"אודות"}}]' );

		$this->assertFalse( $this->is_management_page( 4 ) );
	}

	/**
	 * Including one that merely mentions the name in prose.
	 *
	 * @return void
	 */
	public function test_a_page_that_only_names_it_is_left_cacheable(): void {
		$this->page( 5, 'השתמשו בקוד kivun_employer_dashboard כדי להציג את הלוח.' );

		$this->assertFalse( $this->is_management_page( 5 ) );
	}
}
