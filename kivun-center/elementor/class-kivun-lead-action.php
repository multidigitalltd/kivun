<?php
/**
 * Elementor Pro Forms — custom "Lead / Landing-page registration" action.
 *
 * Lets an Elementor form on a landing page (or a course interest form) submit
 * into the Kivun lead pipeline: the same validation, capacity check,
 * de-duplication, database row, per-item lead email, and `kivun_after_lead`
 * hook the built-in form uses. No payment is involved.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

use ElementorPro\Modules\Forms\Classes\Action_Base;
use Elementor\Controls_Manager;

/**
 * Registers the "השארת פרטים (ליד) – Kivun" action in Elementor Pro Forms.
 */
class Kivun_Lead_Action extends Action_Base {

	/**
	 * Action machine name (stored in the form settings).
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kivun_lead';
	}

	/**
	 * Human label shown in the "Actions After Submit" dropdown.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'השארת פרטים (ליד) – Kivun', 'kivun' );
	}

	/**
	 * Add the action's own controls section to the form widget.
	 *
	 * @param \Elementor\Widget_Base $widget The form widget.
	 * @return void
	 */
	public function register_settings_section( $widget ): void {
		$widget->start_controls_section(
			'section_kivun_lead',
			array(
				'label'     => __( 'השארת פרטים (ליד) – Kivun', 'kivun' ),
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);

		$widget->add_control(
			'kivun_lead_source',
			array(
				'label'   => __( 'מקור הדף', 'kivun' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current' => __( 'הדף הנוכחי (לפי כתובת העמוד)', 'kivun' ),
					'field'   => __( 'משדה נסתר בטופס (מומלץ לטמפלט יחיד)', 'kivun' ),
					'manual'  => __( 'מזהה דף ידני', 'kivun' ),
				),
			)
		);

		$widget->add_control(
			'kivun_lead_field_post',
			array(
				'label'       => __( 'מזהה שדה: דף (ID)', 'kivun' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'post_id',
				'condition'   => array( 'kivun_lead_source' => 'field' ),
				'description' => __( 'הוסיפו שדה נסתר (Hidden) שערכו ‎[kivun_post_id]‎ דרך Dynamic → Shortcode, ורשמו כאן את ה‑ID שלו.', 'kivun' ),
			)
		);

		$widget->add_control(
			'kivun_lead_post_id',
			array(
				'label'       => __( 'מזהה דף נחיתה (ID)', 'kivun' ),
				'type'        => Controls_Manager::NUMBER,
				'condition'   => array( 'kivun_lead_source' => 'manual' ),
				'description' => __( 'מזהה הפוסט של דף הנחיתה.', 'kivun' ),
			)
		);

		$widget->add_control(
			'kivun_lead_field_name',
			array(
				'label'       => __( 'מזהה שדה: שם', 'kivun' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'name',
				'description' => __( 'ה‑ID של שדה הטופס (לשונית Advanced → ID).', 'kivun' ),
			)
		);

		$widget->add_control(
			'kivun_lead_field_phone',
			array(
				'label'   => __( 'מזהה שדה: טלפון', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'phone',
			)
		);

		$widget->add_control(
			'kivun_lead_field_email',
			array(
				'label'   => __( 'מזהה שדה: אימייל', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'email',
			)
		);

		$widget->add_control(
			'kivun_lead_field_message',
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
			'name'    => $get_value( $settings['kivun_lead_field_name'] ?? 'name' ),
			'phone'   => $get_value( $settings['kivun_lead_field_phone'] ?? 'phone' ),
			'email'   => $get_value( $settings['kivun_lead_field_email'] ?? 'email' ),
			'message' => $get_value( $settings['kivun_lead_field_message'] ?? 'message' ),
		);

		// Resolve the target landing page / course.
		$source  = $settings['kivun_lead_source'] ?? 'current';
		$post_id = 0;
		if ( 'manual' === $source ) {
			$post_id = absint( $settings['kivun_lead_post_id'] ?? 0 );
		} elseif ( 'field' === $source ) {
			$post_id = absint( $get_value( $settings['kivun_lead_field_post'] ?? 'post_id' ) );
		} else {
			$referer = wp_get_referer();
			$post_id = $referer ? url_to_postid( $referer ) : 0;
		}

		$result = Kivun_Workshops::save_lead( $post_id, $data );
		if ( is_wp_error( $result ) ) {
			$ajax_handler->add_error_message( $result->get_error_message() );
		}
	}

	/**
	 * Strip this action's settings when a form is exported as a template.
	 *
	 * @param array $element The exported element data.
	 * @return array
	 */
	public function on_export( $element ) {
		unset(
			$element['settings']['kivun_lead_source'],
			$element['settings']['kivun_lead_field_post'],
			$element['settings']['kivun_lead_post_id'],
			$element['settings']['kivun_lead_field_name'],
			$element['settings']['kivun_lead_field_phone'],
			$element['settings']['kivun_lead_field_email'],
			$element['settings']['kivun_lead_field_message']
		);

		return $element;
	}
}
