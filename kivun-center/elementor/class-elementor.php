<?php
defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro integration — registers Kivun Center dynamic tag group + all tags.
 * Only loads when Elementor is active.
 */
class Kivun_Elementor {

	public static function init(): void {
		// Wait for Elementor to be fully loaded
		add_action( 'elementor/dynamic_tags/register', [ __CLASS__, 'register_tags' ] );
	}

	public static function register_tags( \Elementor\Core\DynamicTags\Manager $manager ): void {
		require_once KIVUN_DIR . 'elementor/tags/class-course-tags.php';
		require_once KIVUN_DIR . 'elementor/tags/class-job-tags.php';

		// Register the Kivun group so all tags appear under one section
		$manager->register_group( 'kivun', [ 'title' => __( 'Kivun Center', 'kivun' ) ] );

		foreach ( [
			Kivun_Tag_Course_Price::class,
			Kivun_Tag_Course_Schedule::class,
			Kivun_Tag_Course_Duration::class,
			Kivun_Tag_Course_Audience::class,
			Kivun_Tag_Course_Capacity::class,
			Kivun_Tag_Course_Is_Free::class,
			Kivun_Tag_Job_Company::class,
			Kivun_Tag_Job_Salary::class,
			Kivun_Tag_Job_Requirements::class,
			Kivun_Tag_Job_Deadline::class,
		] as $class ) {
			$manager->register( new $class() );
		}
	}
}
