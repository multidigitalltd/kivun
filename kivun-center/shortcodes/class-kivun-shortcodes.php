<?php
/**
 * Front-end shortcode handlers for the Kivun Center plugin.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Kivun Center shortcodes.
 */
class Kivun_Shortcodes {

	/**
	 * Register all plugin shortcodes.
	 *
	 * @return void
	 */
	public static function init(): void {
		$map = array(
			'kivun_courses'            => 'render_courses',
			'kivun_course_single'      => 'render_course_single',
			'kivun_course_register'    => 'render_course_register',
			'kivun_course_interest'    => 'render_course_interest',
			'kivun_workshops'          => 'render_workshops',
			'kivun_workshop_single'    => 'render_workshop_single',
			'kivun_jobs'               => 'render_jobs',
			'kivun_apply'              => 'render_apply',
			'kivun_spots_left'         => 'render_spots_left',
			'kivun_my_applications'    => 'render_my_applications',
			'kivun_employer_register'  => 'render_employer_register',
			'kivun_employer_dashboard' => 'render_employer_dashboard',
		);

		foreach ( $map as $tag => $method ) {
			add_shortcode( $tag, array( __CLASS__, $method ) );
		}
	}

	// ── Courses archive ───────────────────────────────────────────────────────

	/**
	 * Render the courses archive grid.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_courses( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'per_page' => 12,
				'columns'  => 3,
				'orderby'  => 'date',
			),
			$atts,
			'kivun_courses'
		);

		$args = array(
			'post_type'      => 'kivun_course',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'DESC',
		);

		if ( $atts['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'kivun_course_cat',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $atts['category'] ),
				),
			);
		}

		$query = new WP_Query( $args );

		ob_start();
		kivun_get_template(
			'courses/archive.php',
			array(
				'query'   => $query,
				'columns' => (int) $atts['columns'],
			)
		);
		wp_reset_postdata();

		return ob_get_clean();
	}

	// ── Single course ─────────────────────────────────────────────────────────

	/**
	 * Render a single course view.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_course_single( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kivun_course_single' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();

		if ( 'kivun_course' !== get_post_type( $id ) ) {
			return '';
		}

		ob_start();
		kivun_get_template( 'courses/single.php', array( 'course_id' => $id ) );
		return ob_get_clean();
	}

	// ── Course registration form ──────────────────────────────────────────────

	/**
	 * Render the course registration form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_course_register( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kivun_course_register' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_course' ) {
			return '';
		}

		ob_start();
		kivun_get_template( 'courses/register-form.php', array( 'course_id' => $id ) );
		return ob_get_clean();
	}

	// ── Course interest form ──────────────────────────────────────────────────

	/**
	 * Render the course interest form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_course_interest( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kivun_course_interest' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_course' ) {
			return '';
		}

		ob_start();
		kivun_get_template(
			'courses/interest-form.php',
			array(
				'post_id'   => $id,
				'post_type' => 'kivun_course',
			)
		);
		return ob_get_clean();
	}

	// ── Workshops archive ─────────────────────────────────────────────────────

	/**
	 * Render the workshops archive grid.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_workshops( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'per_page' => 12,
				'columns'  => 3,
			),
			$atts,
			'kivun_workshops'
		);

		$args = array(
			'post_type'      => 'kivun_workshop',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $atts['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'kivun_workshop_cat',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $atts['category'] ),
				),
			);
		}

		$query = new WP_Query( $args );

		ob_start();
		kivun_get_template(
			'workshops/archive.php',
			array(
				'query'   => $query,
				'columns' => (int) $atts['columns'],
			)
		);
		wp_reset_postdata();

		return ob_get_clean();
	}

	// ── Workshop single ───────────────────────────────────────────────────────

	/**
	 * Render a single workshop view.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_workshop_single( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kivun_workshop_single' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_workshop' ) {
			return '';
		}

		ob_start();
		kivun_get_template( 'workshops/single.php', array( 'workshop_id' => $id ) );
		return ob_get_clean();
	}

	// ── Jobs board ────────────────────────────────────────────────────────────

	/**
	 * Render the jobs board archive.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_jobs( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'show_filters' => 'yes',
				'per_page'     => 10,
				'scope'        => '',
				'region'       => '',
				'field'        => '',
			),
			$atts,
			'kivun_jobs'
		);

		$args = array(
			'post_type'      => 'kivun_job',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$tax_query = array();
		foreach ( array(
			'scope'  => 'kivun_job_scope',
			'region' => 'kivun_job_region',
			'field'  => 'kivun_job_field',
		) as $key => $tax ) {
			if ( $atts[ $key ] ) {
				$tax_query[] = array(
					'taxonomy' => $tax,
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $atts[ $key ] ),
				);
			}
		}
		if ( $tax_query ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new WP_Query( $args );

		ob_start();
		kivun_get_template(
			'jobs/archive.php',
			array(
				'query'        => $query,
				'show_filters' => 'yes' === $atts['show_filters'],
			)
		);
		wp_reset_postdata();

		return ob_get_clean();
	}

	// ── Spots left ────────────────────────────────────────────────────────────

	/**
	 * Render the number of spots left for a course or workshop.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_spots_left( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'        => 0,
				'full_text' => 'מלא',
			),
			$atts,
			'kivun_spots_left'
		);
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();

		$left = kivun_get_spots_left( $id );
		if ( null === $left ) {
			return '';
		}

		if ( 0 === $left ) {
			return '<span class="kivun-spots kivun-spots--full">' . esc_html( $atts['full_text'] ) . '</span>';
		}

		return '<span class="kivun-spots">' . sprintf(
			/* translators: %d: number of available spots. */
			esc_html__( 'נותרו %d מקומות', 'kivun' ),
			$left
		) . '</span>';
	}

	// ── CV application form ───────────────────────────────────────────────────

	/**
	 * Render the CV application form for a job.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_apply( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'kivun_apply' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_job' ) {
			return '';
		}

		ob_start();
		kivun_get_template( 'jobs/apply-form.php', array( 'job_id' => $id ) );
		return ob_get_clean();
	}

	// ── My applications (personal area) ──────────────────────────────────────

	/**
	 * Render the applicant's personal applications area.
	 *
	 * @return string Rendered HTML markup.
	 */
	public static function render_my_applications(): string {
		ob_start();
		kivun_get_template( 'applicant/my-applications.php' );
		return ob_get_clean();
	}

	// ── Employer register ─────────────────────────────────────────────────────

	/**
	 * Render the employer registration form.
	 *
	 * @return string Rendered HTML markup.
	 */
	public static function render_employer_register(): string {
		ob_start();
		kivun_get_template( 'employer/register-form.php' );
		return ob_get_clean();
	}

	// ── Employer dashboard ────────────────────────────────────────────────────

	/**
	 * Render the employer dashboard.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML markup.
	 */
	public static function render_employer_dashboard( array $atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Shortcode callback signature.
		ob_start();
		kivun_get_template( 'employer/dashboard.php' );
		return ob_get_clean();
	}
}
