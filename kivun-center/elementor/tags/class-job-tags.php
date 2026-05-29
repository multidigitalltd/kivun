<?php
defined( 'ABSPATH' ) || exit;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

// ── Base ──────────────────────────────────────────────────────────────────────

abstract class Kivun_Job_Tag_Base extends Tag {

	public function get_group(): string {
		return 'kivun';
	}

	public function get_categories(): array {
		return [ Module::TEXT_CATEGORY ];
	}
}

// ── Company ───────────────────────────────────────────────────────────────────

class Kivun_Tag_Job_Company extends Kivun_Job_Tag_Base {

	public function get_name(): string  { return 'kivun-job-company'; }
	public function get_title(): string { return __( 'משרה — שם חברה', 'kivun' ); }

	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_company', true ) );
	}
}

// ── Salary ────────────────────────────────────────────────────────────────────

class Kivun_Tag_Job_Salary extends Kivun_Job_Tag_Base {

	public function get_name(): string  { return 'kivun-job-salary'; }
	public function get_title(): string { return __( 'משרה — שכר', 'kivun' ); }

	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_salary', true ) );
	}
}

// ── Requirements ──────────────────────────────────────────────────────────────

class Kivun_Tag_Job_Requirements extends Kivun_Job_Tag_Base {

	public function get_name(): string  { return 'kivun-job-requirements'; }
	public function get_title(): string { return __( 'משרה — דרישות', 'kivun' ); }

	public function get_categories(): array {
		return [ Module::TEXT_CATEGORY, Module::TEXTAREA_CATEGORY ];
	}

	public function render(): void {
		echo esc_html( get_post_meta( get_the_ID(), '_kivun_requirements', true ) );
	}
}

// ── Deadline ──────────────────────────────────────────────────────────────────

class Kivun_Tag_Job_Deadline extends Kivun_Job_Tag_Base {

	public function get_name(): string  { return 'kivun-job-deadline'; }
	public function get_title(): string { return __( 'משרה — תאריך אחרון', 'kivun' ); }

	public function render(): void {
		$date = get_post_meta( get_the_ID(), '_kivun_deadline', true );
		if ( ! $date ) return;

		// Format: YYYY-MM-DD → d/m/Y
		$ts = strtotime( $date );
		echo $ts ? esc_html( date_i18n( 'd/m/Y', $ts ) ) : esc_html( $date );
	}
}
