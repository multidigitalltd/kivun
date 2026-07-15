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
			'kivun_course_id'          => 'render_course_id',
			'kivun_post_id'            => 'render_post_id',
			'kivun_session_status'     => 'render_session_status',
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

		// Fallback for contexts rendered outside the main loop (e.g. an
		// Elementor Off-Canvas / Popup), where get_the_ID() is unreliable.
		if ( get_post_type( $id ) !== 'kivun_job' ) {
			$queried = get_queried_object_id();
			if ( $queried && 'kivun_job' === get_post_type( $queried ) ) {
				$id = $queried;
			}
		}

		if ( get_post_type( $id ) !== 'kivun_job' ) {
			return '';
		}

		ob_start();
		kivun_get_template( 'jobs/apply-form.php', array( 'job_id' => $id ) );
		return ob_get_clean();
	}

	/**
	 * Output the current course ID — for binding an Elementor hidden field
	 * (via a "Shortcode" dynamic tag) so the Forms action knows which course
	 * the visitor is on, even inside a shared single template.
	 *
	 * @return string The course ID, or empty string when not on a course.
	 */
	public static function render_course_id(): string {
		$id = (int) get_the_ID();

		if ( get_post_type( $id ) !== 'kivun_course' ) {
			$queried = get_queried_object_id();
			if ( $queried && 'kivun_course' === get_post_type( $queried ) ) {
				$id = (int) $queried;
			}
		}

		return get_post_type( $id ) === 'kivun_course' ? (string) $id : '';
	}

	/**
	 * Output the current Kivun post ID (course or landing page) — for binding an
	 * Elementor hidden field on a shared single template so the Forms action
	 * knows which item the visitor is on.
	 *
	 * @return string The post ID, or empty string when not on a Kivun item.
	 */
	public static function render_post_id(): string {
		$id = (int) get_the_ID();

		if ( ! in_array( get_post_type( $id ), array( 'kivun_course', 'kivun_workshop', 'kivun_session' ), true ) ) {
			$queried = get_queried_object_id();
			if ( $queried && in_array( get_post_type( $queried ), array( 'kivun_course', 'kivun_workshop', 'kivun_session' ), true ) ) {
				$id = (int) $queried;
			}
		}

		return in_array( get_post_type( $id ), array( 'kivun_course', 'kivun_workshop', 'kivun_session' ), true ) ? (string) $id : '';
	}

	/**
	 * Render a "registration closed" notice for a session whose validity date has
	 * passed. Outputs nothing while registration is still open (unless an "open"
	 * message is supplied). Built for Elementor pages — drop it into a Shortcode
	 * or HTML widget above the form.
	 *
	 * Attributes:
	 *  - id     : Session ID (default: the current/queried session).
	 *  - closed : Message shown when registration is closed.
	 *  - open   : Optional message shown while registration is open (default: none).
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string Rendered HTML, or empty string.
	 */
	public static function render_session_status( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'closed' => __( 'ההרשמה לסדנה נסגרה — היא תיפתח שוב במחזור הבא.', 'kivun' ),
				'open'   => '',
			),
			$atts,
			'kivun_session_status'
		);

		$id = absint( $atts['id'] );
		if ( ! $id ) {
			$id = (int) get_the_ID();
			if ( 'kivun_session' !== get_post_type( $id ) ) {
				$queried = get_queried_object_id();
				if ( $queried && 'kivun_session' === get_post_type( $queried ) ) {
					$id = (int) $queried;
				}
			}
		}

		if ( 'kivun_session' !== get_post_type( $id ) ) {
			return '';
		}

		if ( kivun_session_registration_open( $id ) ) {
			return '' !== trim( (string) $atts['open'] )
				? '<div class="kivun-session-status kivun-session-status--open" role="status">' . esc_html( $atts['open'] ) . '</div>'
				: '';
		}

		$lock = '<svg class="kivun-session-status__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm3 8H9V6a3 3 0 0 1 6 0v3Z"/></svg>';

		return '<div class="kivun-session-status kivun-session-status--closed" role="status">'
			. $lock
			. '<span>' . esc_html( $atts['closed'] ) . '</span>'
			. '</div>';
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
