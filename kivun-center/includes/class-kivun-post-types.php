<?php
/**
 * Registers the plugin custom post types and taxonomies.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Kivun custom post types and their labels.
 */
class Kivun_Post_Types {

	/**
	 * Hooks post-type registration into WordPress init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers all Kivun custom post types and taxonomies.
	 *
	 * @return void
	 */
	public static function register(): void {
		// ── Courses ──────────────────────────────────────────────────────────
		register_post_type(
			'kivun_course',
			array(
				'labels'        => self::labels( 'קורס', 'קורסים' ),
				'public'        => true,
				'has_archive'   => true,
				'menu_icon'     => 'dashicons-welcome-learn-more',
				'menu_position' => 5,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'       => array(
					'slug'       => 'courses',
					'with_front' => false,
				),
				'show_in_rest'  => true,
			)
		);

		// ── Landing pages (backed by the kivun_workshop post type) ────────────
		register_post_type(
			'kivun_workshop',
			array(
				'labels'        => self::labels( 'דף נחיתה', 'דפי נחיתה' ),
				'public'        => true,
				'has_archive'   => true,
				'menu_icon'     => 'dashicons-megaphone',
				'menu_position' => 6,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'       => array(
					'slug'       => 'landing',
					'with_front' => false,
				),
				'show_in_rest'  => true,
			)
		);

		// ── Workshops / sessions (separate from landing pages) ────────────────
		register_post_type(
			'kivun_session',
			array(
				'labels'        => self::labels( 'סדנה', 'סדנאות' ),
				'public'        => true,
				'has_archive'   => true,
				'menu_icon'     => 'dashicons-groups',
				'menu_position' => 7,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'       => array(
					'slug'       => 'sessions',
					'with_front' => false,
				),
				'show_in_rest'  => true,
			)
		);

		// ── Events (registration closes permanently after the event date) ─────
		register_post_type(
			'kivun_event',
			array(
				'labels'        => self::labels( 'אירוע', 'אירועים' ),
				'public'        => true,
				'has_archive'   => true,
				'menu_icon'     => 'dashicons-calendar-alt',
				'menu_position' => 8,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'       => array(
					'slug'       => 'events',
					'with_front' => false,
				),
				'show_in_rest'  => true,
			)
		);

		// ── Jobs ─────────────────────────────────────────────────────────────
		register_post_type(
			'kivun_job',
			array(
				'labels'          => self::labels( 'משרה', 'משרות' ),
				'public'          => true,
				'has_archive'     => true,
				'menu_icon'       => 'dashicons-businessperson',
				'menu_position'   => 6,
				'supports'        => array( 'title', 'editor', 'author', 'thumbnail' ),
				'rewrite'         => array(
					'slug'       => 'jobs',
					'with_front' => false,
				),
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		// ── Taxonomies ────────────────────────────────────────────────────────
		// Landing pages (kivun_workshop) intentionally have no categories.
		register_taxonomy(
			'kivun_course_cat',
			'kivun_course',
			array(
				'labels'       => array(
					'name'          => 'קטגוריות',
					'singular_name' => 'קטגוריה',
				),
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'course-cat' ),
			)
		);

		register_taxonomy(
			'kivun_job_scope',
			'kivun_job',
			array(
				'labels'       => array(
					'name'          => 'היקף משרה',
					'singular_name' => 'היקף',
				),
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'job-scope' ),
			)
		);

		register_taxonomy(
			'kivun_job_feature',
			'kivun_job',
			array(
				'labels'       => array(
					'name'          => 'מאפייני משרה',
					'singular_name' => 'מאפיין',
				),
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'job-feature' ),
			)
		);

		register_taxonomy(
			'kivun_job_region',
			'kivun_job',
			array(
				'labels'       => array(
					'name'          => 'אזורים',
					'singular_name' => 'אזור',
				),
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'job-region' ),
			)
		);

		register_taxonomy(
			'kivun_job_field',
			'kivun_job',
			array(
				'labels'       => array(
					'name'          => 'תחום מקצועי',
					'singular_name' => 'תחום',
				),
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'job-field' ),
			)
		);
	}

	/**
	 * Builds the labels array for a custom post type.
	 *
	 * @param string $singular The singular post-type label.
	 * @param string $plural   The plural post-type label.
	 * @return array The assembled labels array.
	 */
	private static function labels( string $singular, string $plural ): array {
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'add_new_item'  => "הוסף {$singular} חדש",
			'edit_item'     => "ערוך {$singular}",
			'new_item'      => "{$singular} חדש",
			'view_item'     => "צפה ב{$singular}",
			'search_items'  => "חפש {$plural}",
			'not_found'     => "לא נמצאו {$plural}",
		);
	}
}
