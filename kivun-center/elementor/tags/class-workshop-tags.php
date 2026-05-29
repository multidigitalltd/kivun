<?php
defined( 'ABSPATH' ) || exit;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

abstract class Kivun_Workshop_Tag_Base extends Tag {
	public function get_group(): string    { return 'kivun'; }
	public function get_categories(): array { return [ Module::TEXT_CATEGORY ]; }
}

class Kivun_Tag_Workshop_Date extends Kivun_Workshop_Tag_Base {
	public function get_name(): string  { return 'kivun-workshop-date'; }
	public function get_title(): string { return __( 'סדנה — תאריך', 'kivun' ); }
	public function render(): void { echo esc_html( get_post_meta( get_the_ID(), '_kivun_ws_date', true ) ); }
}

class Kivun_Tag_Workshop_Duration extends Kivun_Workshop_Tag_Base {
	public function get_name(): string  { return 'kivun-workshop-duration'; }
	public function get_title(): string { return __( 'סדנה — משך', 'kivun' ); }
	public function render(): void { echo esc_html( get_post_meta( get_the_ID(), '_kivun_ws_duration', true ) ); }
}

class Kivun_Tag_Workshop_Location extends Kivun_Workshop_Tag_Base {
	public function get_name(): string  { return 'kivun-workshop-location'; }
	public function get_title(): string { return __( 'סדנה — מיקום', 'kivun' ); }
	public function render(): void { echo esc_html( get_post_meta( get_the_ID(), '_kivun_ws_location', true ) ); }
}

class Kivun_Tag_Workshop_Audience extends Kivun_Workshop_Tag_Base {
	public function get_name(): string  { return 'kivun-workshop-audience'; }
	public function get_title(): string { return __( 'סדנה — למי מיועדת', 'kivun' ); }
	public function get_categories(): array { return [ Module::TEXT_CATEGORY, Module::TEXTAREA_CATEGORY ]; }
	public function render(): void { echo esc_html( get_post_meta( get_the_ID(), '_kivun_ws_audience', true ) ); }
}

class Kivun_Tag_Workshop_Capacity extends Kivun_Workshop_Tag_Base {
	public function get_name(): string  { return 'kivun-workshop-capacity'; }
	public function get_title(): string { return __( 'סדנה — מקסימום משתתפים', 'kivun' ); }
	public function render(): void {
		$cap = get_post_meta( get_the_ID(), '_kivun_ws_capacity', true );
		if ( $cap ) echo esc_html( $cap );
	}
}
