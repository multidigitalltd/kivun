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
				'label'       => __( 'מקור הדף', 'kivun' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'current',
				'options'     => array(
					'current' => __( 'הדף הנוכחי (לפי כתובת העמוד)', 'kivun' ),
					'board'   => __( 'לוח המשרות — לידים כלליים', 'kivun' ),
					'field'   => __( 'משדה נסתר בטופס (מומלץ לטמפלט יחיד)', 'kivun' ),
					'manual'  => __( 'מזהה דף ידני', 'kivun' ),
				),
				'description' => __( 'בחרו "לוח המשרות" לטופס איסוף לידים שיושב בארכיון המשרות. לוח המשרות הוא ארכיון ולא עמוד, ולכן אין לו מזהה — הפניות יישמרו תחת המקור "לוח משרות".', 'kivun' ),
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
			'kivun_lead_field_city',
			array(
				'label'   => __( 'מזהה שדה: עיר', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'city',
			)
		);

		$widget->add_control(
			'kivun_lead_field_gender',
			array(
				'label'   => __( 'מזהה שדה: מגדר', 'kivun' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'gender',
			)
		);

		$widget->add_control(
			'kivun_lead_field_consent',
			array(
				'label'       => __( 'מזהה שדה: אישור דיוור', 'kivun' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'consent',
				'description' => __( 'שדה Acceptance/צ׳קבוקס. סימון = הסכמה לקבלת דיוור.', 'kivun' ),
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

		$mapped  = array(
			'name'              => trim( (string) ( $settings['kivun_lead_field_name'] ?? 'name' ) ),
			'phone'             => trim( (string) ( $settings['kivun_lead_field_phone'] ?? 'phone' ) ),
			'email'             => trim( (string) ( $settings['kivun_lead_field_email'] ?? 'email' ) ),
			'city'              => trim( (string) ( $settings['kivun_lead_field_city'] ?? 'city' ) ),
			'gender'            => trim( (string) ( $settings['kivun_lead_field_gender'] ?? 'gender' ) ),
			'marketing_consent' => trim( (string) ( $settings['kivun_lead_field_consent'] ?? 'consent' ) ),
			'message'           => trim( (string) ( $settings['kivun_lead_field_message'] ?? 'message' ) ),
		);
		$raw     = is_array( $fields ) ? $fields : array();
		$answers = Kivun_Form_Details::fields( $raw );

		// Where the form's own type says what a field is for, and nothing was
		// mapped to it, that is good enough. An Elementor field keeps its
		// generated id (`field_1112454`) unless somebody renames it, so the
		// defaults here match almost nothing on a form nobody mapped — and
		// every submission then failed on "נא למלא שם וטלפון", which blamed
		// the visitor for a setting.
		$by_type = array(
			'phone' => 'tel',
			'email' => 'email',
		);

		// Keys the form has no field for at all. Told apart from a field that
		// is present and empty, because only one of those is the visitor's
		// doing.
		$unmapped = array();

		$data = array();
		foreach ( $mapped as $key => $id ) {
			if ( ! isset( $raw[ $id ] ) ) {
				$found = isset( $by_type[ $key ] ) ? Kivun_Form_Details::first_of_type( $raw, $by_type[ $key ] ) : '';

				if ( '' === $found ) {
					$unmapped[]   = $key;
					$data[ $key ] = '';
					continue;
				}

				// Remembered, so the answer is not also repeated into the
				// message as an unmapped extra.
				$mapped[ $key ] = $found;
				$id             = $found;
			}

			$data[ $key ] = Kivun_Form_Details::mapped_value( $raw, $id );
		}

		$data['marketing_consent'] = '' !== $data['marketing_consent'] ? 1 : 0;
		$excluded                  = array_values( $mapped );
		$excluded[]                = trim( (string) ( $settings['kivun_lead_field_post'] ?? 'post_id' ) );
		$data['message']           = Kivun_Form_Details::message( $answers, $excluded, $data['message'] );

		// Resolve the target landing page / course.
		$source  = $settings['kivun_lead_source'] ?? 'current';
		$post_id = 0;

		// The jobs board is the post type's archive, so there is no post to
		// resolve and nothing to look for — the lead is filed against the
		// board itself.
		if ( 'board' === $source ) {
			$this->finish( Kivun_Workshops::save_lead( 0, $data, 'board' ), $fields, $ajax_handler, $unmapped );
			return;
		}

		if ( 'manual' === $source ) {
			$post_id = absint( $settings['kivun_lead_post_id'] ?? 0 );
		} elseif ( 'field' === $source ) {
			$post_id = absint( $get_value( $settings['kivun_lead_field_post'] ?? 'post_id' ) );
		} else {
			$referer = wp_get_referer();
			$post_id = $referer ? url_to_postid( $referer ) : 0;
		}

		if ( ! in_array( get_post_type( $post_id ), array( 'kivun_course', 'kivun_workshop', 'kivun_session', 'kivun_event' ), true ) ) {
			$referer  = wp_get_referer();
			$from_url = $referer ? url_to_postid( $referer ) : 0;
			if ( in_array( get_post_type( $from_url ), array( 'kivun_course', 'kivun_workshop', 'kivun_session', 'kivun_event' ), true ) ) {
				$post_id = $from_url;
			}
		}

		// Still nothing, and the form is on the jobs board. There is no post
		// there to find — the board is an archive — so the lead belongs to the
		// board, whatever the source setting says. Without this a form left on
		// the default setting fails on every submission, and the visitor, told
		// something went wrong, sends it again.
		if ( ! $post_id && Kivun_Forms_Router::jobs_page( (string) wp_get_referer() ) ) {
			$this->finish( Kivun_Workshops::save_lead( 0, $data, 'board' ), $fields, $ajax_handler, $unmapped );
			return;
		}

		$this->finish( Kivun_Workshops::save_lead( $post_id, $data ), $fields, $ajax_handler, $unmapped );
	}

	/**
	 * Report what the pipeline made of the lead.
	 *
	 * @param true|\WP_Error                                   $result       What save_lead returned.
	 * @param mixed                                            $fields       The submitted fields, for diagnostics.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler The AJAX response handler.
	 * @param array<int,string>                                $unmapped     Standard fields the form has none of.
	 * @return void
	 */
	private function finish( $result, $fields, $ajax_handler, array $unmapped = array() ): void {
		// A duplicate is not a real error — the visitor already registered, so
		// let the form show its normal success message instead of an error.
		if ( ! is_wp_error( $result ) || 'kivun_duplicate' === $result->get_error_code() ) {
			return;
		}

		$code    = $result->get_error_code();
		$message = $result->get_error_message();

		// Help an admin diagnose a field-ID mapping mismatch: the IDs the form
		// actually submitted. Never shown to regular visitors.
		$diagnosis = ( current_user_can( 'manage_options' ) && is_array( $fields ) )
			/* translators: %s: comma-separated list of submitted field IDs. */
			? ' — ' . sprintf( __( '[אבחון למנהל] מזהי השדות שהתקבלו: %s', 'kivun' ), implode( ', ', array_keys( $fields ) ) )
			: '';

		// A fault in how the form was set up is not the visitor's to fix, and
		// telling them the submission failed is actively harmful: they send it
		// again, and the form's other actions — the email to the coordinators —
		// run a second time, so the same person arrives twice. The form is let
		// through, and the fault is reported where it can be acted on.
		// "Fill in a name and a phone number" is the right thing to say to
		// somebody who left the box empty, and the wrong thing to say when the
		// form has no such box for us to read. The second is a setting nobody
		// visiting the site can do anything about, and telling them the
		// submission failed only makes them send it again.
		if ( 'kivun_invalid' === $code && array_intersect( array( 'name', 'phone' ), $unmapped ) ) {
			$code = 'kivun_no_post';

			$message = sprintf(
				/* translators: %s: comma-separated list of unmapped standard fields. */
				__( 'הפנייה לא נשמרה: לטופס אין שדות שתואמים ל-%s. יש לעדכן את מזהי השדות בפעולת "השארת פרטים (ליד)".', 'kivun' ),
				implode( ', ', array_intersect( array( 'name', 'phone' ), $unmapped ) )
			);
		}

		if ( 'kivun_no_post' === $code ) {
			self::log( $message . $diagnosis );

			if ( '' !== $diagnosis ) {
				$ajax_handler->add_error_message( $message . $diagnosis );
			}

			return;
		}

		$ajax_handler->add_error_message( $message . $diagnosis );
	}

	/**
	 * Record a setup fault where somebody running the site can find it.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	private static function log( string $message ): void {
		update_option(
			'kivun_lead_action_last_error',
			array(
				'time'    => current_time( 'mysql' ),
				'message' => $message,
			),
			false
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in diagnostic logging.
			error_log( '[Kivun Lead Action] ' . $message );
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
			$element['settings']['kivun_lead_field_city'],
			$element['settings']['kivun_lead_field_gender'],
			$element['settings']['kivun_lead_field_consent'],
			$element['settings']['kivun_lead_field_message']
		);

		return $element;
	}
}
