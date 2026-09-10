<?php
/**
 * Elementor Pro integration loader.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Kivun Center dynamic tag group and all tags.
 *
 * Only loads when Elementor is active.
 */
class Kivun_Elementor {

	/**
	 * Guards against registering the Forms action more than once (the modern
	 * registrar hook and the legacy fallback can both fire on newer Pro).
	 *
	 * @var bool
	 */
	private static $form_action_registered = false;

	/**
	 * Hook the tag registration into Elementor.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Wait for Elementor to be fully loaded.
		add_action( 'elementor/dynamic_tags/register', array( __CLASS__, 'register_tags' ) );
	}

	/**
	 * Register Kivun's custom Elementor Pro Forms actions (modern hook,
	 * Elementor Pro 3.5+).
	 *
	 * @param mixed $registrar The actions registrar (Form_Actions_Registrar).
	 * @return void
	 */
	public static function register_form_actions( $registrar ): void {
		if ( self::$form_action_registered ) {
			return;
		}
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) || ! is_object( $registrar ) ) {
			return;
		}

		require_once KIVUN_DIR . 'elementor/class-kivun-course-registration-action.php';
		require_once KIVUN_DIR . 'elementor/class-kivun-lead-action.php';
		$registrar->register( new Kivun_Course_Registration_Action() );
		$registrar->register( new Kivun_Lead_Action() );
		self::$form_action_registered = true;
	}

	/**
	 * Backward-compatible registration for Elementor Pro versions older than
	 * 3.5 that lack the registrar hook (uses the Forms module's add_form_action).
	 *
	 * @return void
	 */
	public static function register_form_actions_legacy(): void {
		if ( self::$form_action_registered ) {
			return;
		}
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) || ! class_exists( '\ElementorPro\Plugin' ) ) {
			return;
		}

		$forms = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'forms' );
		if ( ! $forms || ! method_exists( $forms, 'add_form_action' ) ) {
			return;
		}

		require_once KIVUN_DIR . 'elementor/class-kivun-course-registration-action.php';
		require_once KIVUN_DIR . 'elementor/class-kivun-lead-action.php';
		$course_action = new Kivun_Course_Registration_Action();
		$lead_action   = new Kivun_Lead_Action();
		$forms->add_form_action( $course_action->get_name(), $course_action );
		$forms->add_form_action( $lead_action->get_name(), $lead_action );
		self::$form_action_registered = true;
	}

	/**
	 * Register the Kivun group and all dynamic tags.
	 *
	 * @param \Elementor\Core\DynamicTags\Manager $manager The dynamic tags manager.
	 * @return void
	 */
	public static function register_tags( \Elementor\Core\DynamicTags\Manager $manager ): void {
		// Base classes first so concrete tags can extend them.
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-course-tag-base.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-job-tag-base.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-workshop-tag-base.php';

		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-price.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-schedule.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-duration.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-audience.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-capacity.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-spots.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-course-is-free.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-job-company.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-job-description.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-job-salary.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-job-requirements.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-job-deadline.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-workshop-date.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-workshop-duration.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-workshop-location.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-workshop-audience.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-workshop-capacity.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-landing-short.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-landing-cost.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-cta-title.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-cta-content.php';
		require_once KIVUN_DIR . 'elementor/tags/class-kivun-tag-cta-button.php';

		// Register the Kivun group so all tags appear under one section.
		$manager->register_group( 'kivun', array( 'title' => __( 'Kivun Center', 'kivun' ) ) );

		foreach ( array(
			Kivun_Tag_Course_Price::class,
			Kivun_Tag_Course_Schedule::class,
			Kivun_Tag_Course_Duration::class,
			Kivun_Tag_Course_Audience::class,
			Kivun_Tag_Course_Capacity::class,
			Kivun_Tag_Course_Spots::class,
			Kivun_Tag_Course_Is_Free::class,
			Kivun_Tag_Job_Company::class,
			Kivun_Tag_Job_Description::class,
			Kivun_Tag_Job_Salary::class,
			Kivun_Tag_Job_Requirements::class,
			Kivun_Tag_Job_Deadline::class,
			Kivun_Tag_Workshop_Date::class,
			Kivun_Tag_Workshop_Duration::class,
			Kivun_Tag_Workshop_Location::class,
			Kivun_Tag_Workshop_Audience::class,
			Kivun_Tag_Workshop_Capacity::class,
			Kivun_Tag_Landing_Short::class,
			Kivun_Tag_Landing_Cost::class,
			Kivun_Tag_CTA_Title::class,
			Kivun_Tag_CTA_Content::class,
			Kivun_Tag_CTA_Button::class,
		) as $class ) {
			$manager->register( new $class() );
		}
	}
}
