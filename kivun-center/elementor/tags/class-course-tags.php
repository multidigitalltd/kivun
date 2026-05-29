<?php
defined( 'ABSPATH' ) || exit;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

// ── Base class ────────────────────────────────────────────────────────────────

abstract class Kivun_Course_Tag_Base extends Tag {

	public function get_group(): string {
		return 'kivun';
	}

	public function get_categories(): array {
		return [ Module::TEXT_CATEGORY ];
	}
}

// ── Price ─────────────────────────────────────────────────────────────────────

class Kivun_Tag_Course_Price extends Kivun_Course_Tag_Base {

	public function get_name(): string  { return 'kivun-course-price'; }
	public function get_title(): string { return __( 'קורס — עלות', 'kivun' ); }

	public function render(): void {
		$price = (int) get_post_meta( get_the_ID(), '_kivun_price', true );
		echo $price > 0
			? '₪' . esc_html( number_format( $price ) )
			: esc_html__( 'חינמי', 'kivun' );
	}
}

// ── Schedule ──────────────────────────────────────────────────────────────────

class Kivun_Tag_Course_Schedule extends Kivun_Course_Tag_Base {

	public function get_name(): string  { return 'kivun-course-schedule'; }
	public function get_title(): string { return __( 'קורס — זמנים', 'kivun' ); }

	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_schedule', true ) );
	}
}

// ── Duration ──────────────────────────────────────────────────────────────────

class Kivun_Tag_Course_Duration extends Kivun_Course_Tag_Base {

	public function get_name(): string  { return 'kivun-course-duration'; }
	public function get_title(): string { return __( 'קורס — משך', 'kivun' ); }

	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_duration', true ) );
	}
}

// ── Target audience ───────────────────────────────────────────────────────────

class Kivun_Tag_Course_Audience extends Kivun_Course_Tag_Base {

	public function get_name(): string  { return 'kivun-course-audience'; }
	public function get_title(): string { return __( 'קורס — קהל יעד', 'kivun' ); }

	public function get_categories(): array {
		return [ Module::TEXT_CATEGORY, Module::TEXTAREA_CATEGORY ];
	}

	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_target_audience', true ) );
	}
}

// ── Capacity ──────────────────────────────────────────────────────────────────

class Kivun_Tag_Course_Capacity extends Kivun_Course_Tag_Base {

	public function get_name(): string  { return 'kivun-course-capacity'; }
	public function get_title(): string { return __( 'קורס — מקסימום משתתפים', 'kivun' ); }

	public function render(): void {
		$cap = get_post_meta( get_the_ID(), '_kivun_capacity', true );
		if ( $cap ) echo esc_html( $cap );
	}
}

// ── Is Free (boolean label) ───────────────────────────────────────────────────

class Kivun_Tag_Course_Is_Free extends Kivun_Course_Tag_Base {

	public function get_name(): string  { return 'kivun-course-is-free'; }
	public function get_title(): string { return __( 'קורס — חינמי/בתשלום', 'kivun' ); }

	public function render(): void {
		$price = (int) get_post_meta( get_the_ID(), '_kivun_price', true );
		echo $price > 0
			? esc_html__( 'בתשלום', 'kivun' )
			: esc_html__( 'חינמי', 'kivun' );
	}
}
