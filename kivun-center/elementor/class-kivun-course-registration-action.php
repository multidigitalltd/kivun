<?php
/**
 * Elementor Pro Forms — custom "Course Registration" action.
 *
 * Lets an Elementor form submit into the Kivun course-registration pipeline:
 * the same validation, capacity check, de-duplication, database row, mailer,
 * and `kivun_after_registration` hook the built-in form uses. Paid courses
 * are routed to WooCommerce (add to cart + redirect to checkout).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

use ElementorPro\Modules\Forms\Classes\Action_Base;
use Elementor\Controls_Manager;

/**
 * Registers the "הרשמה לקורס – Kivun" action in Elementor Pro Forms.
 */
class Kivun_Course_Registration_Action extends Action_Base {

	/**
	 * Action machine name (stored in the form settings).
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kivun_course_registration';
	}

	/**
	 * Human label shown in the "Actions After Submit" dropdown.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'הרשמה לקורס – Kivun', 'kivun' );
	}

	/**
	 * Add the action's own controls section to the form widget.
	 *
	 * @param \Elementor\Widget_Base $widget The form widget.
	 * @return void
	 */
	public function register_settings_section( $widget ): void {
		$widget->start_controls_section(
			'section_kivun_course_registration',
			array(
				'label'     => __( 'הרשמה לקורס – Kivun', 'kivun' ),
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);

		$widget->add_control(
			'kivun_course_source',
			array(
				'label'   => __( 'מקור הקורס', 'kivun' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current' => __( 'הקורס הנוכחי (לפי כתובת העמוד)', 'kivun' ),
					'field'   => __( 'משדה נסתר בטופס (מומלץ לטמפלט יחיד)', 'kivun' ),
					'manual'  => __( 'מזהה קורס ידני', 'kivun' ),
				),
			)
		);

		$widget->add_control(
			'kivun_field_course',
			array(
				'label'       => __( 'מזהה שדה: קורס (ID)', 'kivun' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'course_id',
				'condition'   => array( 'kivun_course_source' => 'field' ),
				'description' => __( 'הוסיפו שדה נסתר (Hidden) שערכו ‎[kivun_course_id]‎ דרך Dynamic → Shortcode, ורשמו כאן את ה‑ID שלו.', 'kivun' ),
			)
		);

		$widget->add_control(
			'kivun_course_id',
			array(
				'label'       => __( 'מזהה קורס (ID)', 'kivun' ),
				'type'        => Controls_Manager::NUMBER,
				'condition'   => array( 'kivun_course_source' => 'manual' ),
				'description' => __( 'מזהה הפוסט של הקורס (kivun_course).', 'kivun' ),
			)
		);

		$widget->add_control(
			'kivun_field_name',
			array(
				'label'       => __( 'מזהה שדה: שם', 'kivun' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'name',
				'description' => __( 'ה‑ID של שדה הטופס (לשונית Advanced → ID).', 'kivun' ),
			)
		);

		$widget->add_control(
			'kivun_field_email',
			array(
				'label'   => __( 'מזהה שדה: אימייל', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'email',
			)
		);

		$widget->add_control(
			'kivun_field_phone',
			array(
				'label'   => __( 'מזהה שדה: טלפון', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'phone',
			)
		);

		$widget->add_control(
			'kivun_field_city',
			array(
				'label'   => __( 'מזהה שדה: עיר', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'city',
			)
		);

		$widget->add_control(
			'kivun_field_message',
			array(
				'label'   => __( 'מזהה שדה: הודעה', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'message',
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Run the action on form submission.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       The submitted form record.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler The AJAX response handler.
	 * @return void
	 */
	public function run( $record, $ajax_handler ): void {
		$settings = $record->get( 'form_settings' );
		$fields   = $record->get( 'fields' );

		$get_value = static function ( $field_id ) use ( $fields ) {
			$field_id = trim( (string) $field_id );
			if ( '' === $field_id || empty( $fields[ $field_id ] ) ) {
				return '';
			}
			return $fields[ $field_id ]['value'] ?? '';
		};

		$data = array(
			'name'    => $get_value( $settings['kivun_field_name'] ?? 'name' ),
			'email'   => $get_value( $settings['kivun_field_email'] ?? 'email' ),
			'phone'   => $get_value( $settings['kivun_field_phone'] ?? 'phone' ),
			'city'    => $get_value( $settings['kivun_field_city'] ?? 'city' ),
			'message' => $get_value( $settings['kivun_field_message'] ?? 'message' ),
		);

		// Resolve the target course.
		$source    = $settings['kivun_course_source'] ?? 'current';
		$course_id = 0;
		if ( 'manual' === $source ) {
			$course_id = absint( $settings['kivun_course_id'] ?? 0 );
		} elseif ( 'field' === $source ) {
			$course_id = absint( $get_value( $settings['kivun_field_course'] ?? 'course_id' ) );
		} else {
			$referer   = wp_get_referer();
			$course_id = $referer ? url_to_postid( $referer ) : 0;
		}

		if ( ! $course_id || get_post_type( $course_id ) !== 'kivun_course' ) {
			$ajax_handler->add_error_message( __( 'לא נמצא קורס לשיוך ההרשמה. בדקו את הגדרת "מקור הקורס".', 'kivun' ) );
			return;
		}

		// Paid course → WooCommerce cart + redirect to checkout.
		if ( Kivun_WooCommerce::is_paid_course( $course_id ) ) {
			$checkout_url = Kivun_WooCommerce::add_course_to_cart( $course_id, $data );
			if ( is_wp_error( $checkout_url ) ) {
				$ajax_handler->add_error_message( $checkout_url->get_error_message() );
				return;
			}
			$ajax_handler->add_response_data( 'redirect_url', $checkout_url );
			return;
		}

		// Free course → save registration, notify, fire hook.
		$result = Kivun_Courses::register_free( $course_id, $data );

		// A duplicate is not a real error — show the form's success message.
		if ( ! is_wp_error( $result ) || 'kivun_duplicate' === $result->get_error_code() ) {
			return;
		}

		$ajax_handler->add_error_message( $result->get_error_message() );
	}

	/**
	 * Strip this action's settings when a form is exported as a template.
	 *
	 * @param array $element The exported element data.
	 * @return array
	 */
	public function on_export( $element ) {
		unset(
			$element['settings']['kivun_course_source'],
			$element['settings']['kivun_field_course'],
			$element['settings']['kivun_course_id'],
			$element['settings']['kivun_field_name'],
			$element['settings']['kivun_field_email'],
			$element['settings']['kivun_field_phone'],
			$element['settings']['kivun_field_city'],
			$element['settings']['kivun_field_message']
		);

		return $element;
	}
}
