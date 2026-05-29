<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Post_Types {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		// ── Courses ──────────────────────────────────────────────────────────
		register_post_type( 'kivun_course', [
			'labels'        => self::labels( 'קורס', 'קורסים' ),
			'public'        => true,
			'has_archive'   => true,
			'menu_icon'     => 'dashicons-welcome-learn-more',
			'menu_position' => 5,
			'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'rewrite'       => [ 'slug' => 'courses', 'with_front' => false ],
			'show_in_rest'  => true,
		] );

		// ── Jobs ─────────────────────────────────────────────────────────────
		register_post_type( 'kivun_job', [
			'labels'            => self::labels( 'משרה', 'משרות' ),
			'public'            => true,
			'has_archive'       => true,
			'menu_icon'         => 'dashicons-businessperson',
			'menu_position'     => 6,
			'supports'          => [ 'title', 'editor', 'author' ],
			'rewrite'           => [ 'slug' => 'jobs', 'with_front' => false ],
			'show_in_rest'      => true,
			'capability_type'   => 'post',
			'map_meta_cap'      => true,
		] );

		// ── Taxonomies ────────────────────────────────────────────────────────
		register_taxonomy( 'kivun_course_cat', 'kivun_course', [
			'labels'        => [ 'name' => 'קטגוריות', 'singular_name' => 'קטגוריה' ],
			'hierarchical'  => true,
			'show_in_rest'  => true,
			'rewrite'       => [ 'slug' => 'course-cat' ],
		] );

		register_taxonomy( 'kivun_job_scope', 'kivun_job', [
			'labels'        => [ 'name' => 'היקף משרה', 'singular_name' => 'היקף' ],
			'hierarchical'  => false,
			'show_in_rest'  => true,
			'rewrite'       => [ 'slug' => 'job-scope' ],
		] );

		register_taxonomy( 'kivun_job_region', 'kivun_job', [
			'labels'        => [ 'name' => 'אזורים', 'singular_name' => 'אזור' ],
			'hierarchical'  => true,
			'show_in_rest'  => true,
			'rewrite'       => [ 'slug' => 'job-region' ],
		] );

		register_taxonomy( 'kivun_job_field', 'kivun_job', [
			'labels'        => [ 'name' => 'תחום מקצועי', 'singular_name' => 'תחום' ],
			'hierarchical'  => true,
			'show_in_rest'  => true,
			'rewrite'       => [ 'slug' => 'job-field' ],
		] );
	}

	private static function labels( string $singular, string $plural ): array {
		return [
			'name'          => $plural,
			'singular_name' => $singular,
			'add_new_item'  => "הוסף {$singular} חדש",
			'edit_item'     => "ערוך {$singular}",
			'new_item'      => "{$singular} חדש",
			'view_item'     => "צפה ב{$singular}",
			'search_items'  => "חפש {$plural}",
			'not_found'     => "לא נמצאו {$plural}",
		];
	}
}
