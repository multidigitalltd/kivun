<?php
/**
 * Capture EVERY Elementor Pro form submission into the leads table.
 *
 * Forms handled by a Kivun action (course/session/landing registration) already
 * save their own row, so those are skipped here to avoid duplicates. Any other
 * form (contact, generic, etc.) is stored as type 'form', marked with its source
 * (form name + page) so staff can see where each lead came from.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores generic Elementor form submissions as leads.
 */
class Kivun_Lead_Capture {

	/**
	 * Hook into Elementor's global "new record" event.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'capture' ), 30, 2 );
	}

	/**
	 * Save a generic form submission as a lead row.
	 *
	 * @param mixed $record  The Form_Record.
	 * @param mixed $handler The Ajax_Handler (unused).
	 * @return void
	 */
	public static function capture( $record, $handler ): void {
		unset( $handler );

		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$actions   = array();
		$form_name = '';
		if ( method_exists( $record, 'get_form_settings' ) ) {
			$actions   = (array) $record->get_form_settings( 'submit_actions' );
			$form_name = (string) $record->get_form_settings( 'form_name' );
		}

		// Kivun-action forms save their own lead — don't duplicate them here.
		if ( array_intersect( $actions, array( 'kivun_lead', 'kivun_course_registration' ) ) ) {
			return;
		}

		/**
		 * Allow opting a specific form out of lead capture.
		 *
		 * @param bool  $skip   Whether to skip. Default false.
		 * @param mixed $record The Form_Record.
		 */
		if ( apply_filters( 'kivun_lead_capture_skip', false, $record ) ) {
			return;
		}

		$raw = $record->get( 'fields' );
		if ( ! is_array( $raw ) ) {
			return;
		}

		$data = self::extract( $raw );

		// Need at least one contact detail to be a useful lead.
		if ( '' === $data['name'] && '' === $data['phone'] && '' === $data['email'] ) {
			return;
		}

		$page_url = (string) wp_get_referer();
		$post_id  = '' !== $page_url ? (int) url_to_postid( $page_url ) : 0;

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'kivun_registrations',
			array(
				'course_id'         => $post_id,
				'name'              => $data['name'],
				'email'             => $data['email'],
				'phone'             => $data['phone'],
				'city'              => $data['city'],
				'gender'            => $data['gender'],
				'marketing_consent' => $data['consent'],
				'message'           => $data['message'],
				'source'            => self::source_label( $form_name, $post_id, $page_url ),
				'status'            => 'new_lead',
				'type'              => 'form',
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Extract standard lead fields from Elementor's field array by type/label.
	 *
	 * @param array $raw The submitted fields.
	 * @return array{name:string,phone:string,email:string,city:string,gender:string,consent:int,message:string}
	 */
	private static function extract( array $raw ): array {
		$out   = array(
			'name'    => '',
			'phone'   => '',
			'email'   => '',
			'city'    => '',
			'gender'  => '',
			'consent' => 0,
			'message' => '',
		);
		$extra = array();

		foreach ( $raw as $id => $field ) {
			$type  = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
			$title = is_array( $field ) ? (string) ( $field['title'] ?? $id ) : (string) $id;
			$value = is_array( $field ) ? ( $field['value'] ?? '' ) : '';
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			$key = mb_strtolower( $id . ' ' . $title );

			if ( '' === $out['email'] && ( 'email' === $type || is_email( $value ) ) ) {
				$out['email'] = sanitize_email( $value );
			} elseif ( '' === $out['phone'] && ( 'tel' === $type || preg_match( '/phone|tel|טלפו|נייד|פלאפון|סלולר/u', $key ) ) ) {
				$out['phone'] = sanitize_text_field( $value );
			} elseif ( 'acceptance' === $type || preg_match( '/consent|דיוור|הסכמ|אישור קבל/u', $key ) ) {
				$out['consent'] = 1;
			} elseif ( '' === $out['name'] && preg_match( '/name|שם/u', $key ) ) {
				$out['name'] = sanitize_text_field( $value );
			} elseif ( '' === $out['city'] && preg_match( '/city|עיר|יישוב|ישוב/u', $key ) ) {
				$out['city'] = sanitize_text_field( $value );
			} elseif ( '' === $out['gender'] && preg_match( '/gender|מגדר|מין/u', $key ) ) {
				$out['gender'] = sanitize_text_field( $value );
			} elseif ( 'textarea' === $type || preg_match( '/message|הודעה|הערות|תוכן|פנייה/u', $key ) ) {
				$out['message'] .= ( '' !== $out['message'] ? "\n" : '' ) . $value;
			} else {
				$extra[] = $title . ': ' . $value;
			}
		}

		if ( $extra ) {
			$out['message'] = trim( $out['message'] . ( '' !== $out['message'] ? "\n" : '' ) . implode( "\n", $extra ) );
		}
		$out['message'] = sanitize_textarea_field( $out['message'] );

		return $out;
	}

	/**
	 * Build a human-readable "where it came from" label.
	 *
	 * @param string $form_name Elementor form name.
	 * @param int    $post_id   The submitting page's post ID (0 if none).
	 * @param string $page_url  The submitting page URL.
	 * @return string
	 */
	private static function source_label( string $form_name, int $post_id, string $page_url ): string {
		$parts = array();
		if ( '' !== trim( $form_name ) ) {
			$parts[] = $form_name;
		}
		if ( $post_id ) {
			$title = get_the_title( $post_id );
			if ( '' !== $title ) {
				$parts[] = $title;
			}
		} elseif ( '' !== $page_url ) {
			$path    = wp_parse_url( $page_url, PHP_URL_PATH );
			$parts[] = $path ? $path : $page_url;
		}
		if ( ! $parts ) {
			$parts[] = __( 'טופס Elementor', 'kivun' );
		}
		return sanitize_text_field( implode( ' · ', $parts ) );
	}
}
