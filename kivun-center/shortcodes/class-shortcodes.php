<?php
defined( 'ABSPATH' ) || exit;

class Kivun_Shortcodes {

	public static function init(): void {
		$map = [
			'kivun_courses'            => 'render_courses',
			'kivun_course_single'      => 'render_course_single',
			'kivun_course_register'    => 'render_course_register',
			'kivun_jobs'               => 'render_jobs',
			'kivun_apply'              => 'render_apply',
			'kivun_employer_dashboard' => 'render_employer_dashboard',
		];

		foreach ( $map as $tag => $method ) {
			add_shortcode( $tag, [ __CLASS__, $method ] );
		}
	}

	// ── Courses archive ───────────────────────────────────────────────────────

	public static function render_courses( array $atts ): string {
		$atts = shortcode_atts( [
			'category' => '',
			'per_page' => 12,
			'columns'  => 3,
			'orderby'  => 'date',
		], $atts, 'kivun_courses' );

		$args = [
			'post_type'      => 'kivun_course',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'DESC',
		];

		if ( $atts['category'] ) {
			$args['tax_query'] = [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'taxonomy' => 'kivun_course_cat',
				'field'    => 'slug',
				'terms'    => sanitize_text_field( $atts['category'] ),
			] ];
		}

		$query = new WP_Query( $args );

		ob_start();
		kivun_get_template( 'courses/archive.php', [
			'query'   => $query,
			'columns' => (int) $atts['columns'],
		] );
		wp_reset_postdata();

		return ob_get_clean();
	}

	// ── Single course ─────────────────────────────────────────────────────────

	public static function render_course_single( array $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'kivun_course_single' );
		$id   = absint( $atts['id'] ) ?: get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_course' ) return '';

		ob_start();
		kivun_get_template( 'courses/single.php', [ 'course_id' => $id ] );
		return ob_get_clean();
	}

	// ── Course registration form ──────────────────────────────────────────────

	public static function render_course_register( array $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'kivun_course_register' );
		$id   = absint( $atts['id'] ) ?: get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_course' ) return '';

		ob_start();
		kivun_get_template( 'courses/register-form.php', [ 'course_id' => $id ] );
		return ob_get_clean();
	}

	// ── Jobs board ────────────────────────────────────────────────────────────

	public static function render_jobs( array $atts ): string {
		$atts = shortcode_atts( [
			'show_filters' => 'yes',
			'per_page'     => 10,
			'scope'        => '',
			'region'       => '',
			'field'        => '',
		], $atts, 'kivun_jobs' );

		$args = [
			'post_type'      => 'kivun_job',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$tax_query = [];
		foreach ( [ 'scope' => 'kivun_job_scope', 'region' => 'kivun_job_region', 'field' => 'kivun_job_field' ] as $key => $tax ) {
			if ( $atts[ $key ] ) {
				$tax_query[] = [ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => sanitize_text_field( $atts[ $key ] ) ];
			}
		}
		if ( $tax_query ) {
			if ( count( $tax_query ) > 1 ) $tax_query['relation'] = 'AND';
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $args );

		ob_start();
		kivun_get_template( 'jobs/archive.php', [
			'query'        => $query,
			'show_filters' => $atts['show_filters'] === 'yes',
		] );
		wp_reset_postdata();

		return ob_get_clean();
	}

	// ── CV application form ───────────────────────────────────────────────────

	public static function render_apply( array $atts ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'kivun_apply' );
		$id   = absint( $atts['id'] ) ?: get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_job' ) return '';

		ob_start();
		kivun_get_template( 'jobs/apply-form.php', [ 'job_id' => $id ] );
		return ob_get_clean();
	}

	// ── Employer dashboard ────────────────────────────────────────────────────

	public static function render_employer_dashboard( array $atts ): string {
		ob_start();
		kivun_get_template( 'employer/dashboard.php' );
		return ob_get_clean();
	}
}
