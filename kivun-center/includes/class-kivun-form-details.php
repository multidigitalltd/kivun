<?php
/**
 * Safe, readable answers shared by Elementor lead capture and Kivun actions.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps additional answers in the existing visitor message, separate from CRM notes.
 */
class Kivun_Form_Details {

	/**
	 * Normalize user-facing Elementor fields, retaining every selected value.
	 *
	 * @param array $raw Elementor's submitted field records, keyed by field ID.
	 * @return array<string,array{label:string,type:string,value:string}> Safe answers.
	 */
	public static function fields( array $raw ): array {
		$fields = array();
		foreach ( $raw as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = sanitize_key( is_scalar( $field['type'] ?? null ) ? $field['type'] : '' );
			$key  = strtolower( (string) $id );
			if (
				in_array( $type, array( 'hidden', 'password', 'html', 'step', 'submit' ), true )
				|| preg_match( '/captcha|turnstile|honeypot/', $type . ' ' . $key )
				|| in_array( $key, array( 'action', 'nonce', '_nonce', '_wpnonce', 'security', 'post_id', 'course_id', 'form_id', 'form_name', 'is_paid', 'password', 'user_pass', 'pwd' ), true )
			) {
				continue;
			}

			$value = self::text( $field['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}

			$label = sanitize_text_field( is_scalar( $field['title'] ?? null ) ? $field['title'] : '' );
			if ( '' === $label ) {
				$label = sanitize_text_field( (string) $id );
			}
			$fields[ $id ] = array(
				'label' => $label,
				'type'  => $type,
				'value' => $value,
			);
		}
		return $fields;
	}

	/**
	 * Read an explicitly mapped field, including a trusted hidden dynamic value.
	 *
	 * Hidden contact/message mappings are supported by Elementor. Unmapped hidden
	 * fields remain excluded by fields(), and passwords/CAPTCHA are never answers.
	 *
	 * @param array  $raw      Elementor's submitted field records.
	 * @param string $field_id The configured standard-field mapping.
	 * @return string Sanitized mapped value, or an empty string.
	 */
	public static function mapped_value( array $raw, string $field_id ): string {
		$field = $raw[ $field_id ] ?? null;
		if ( ! is_array( $field ) ) {
			return '';
		}
		if ( 'hidden' === ( $field['type'] ?? '' ) ) {
			$field['type'] = 'text';
		}
		$fields = self::fields( array( $field_id => $field ) );
		return $fields[ $field_id ]['value'] ?? '';
	}

	/**
	 * Append unmapped answers to a visitor's message exactly once.
	 *
	 * @param array  $fields   Normalized answers returned by fields().
	 * @param array  $excluded IDs already stored in standard columns or the message.
	 * @param string $message The mapped visitor message.
	 * @return string Plain-text message including labeled extra answers.
	 */
	public static function message( array $fields, array $excluded, string $message = '' ): string {
		$lines = array();
		if ( '' !== trim( $message ) ) {
			$lines[] = sanitize_textarea_field( $message );
		}
		foreach ( $fields as $id => $field ) {
			if ( ! in_array( (string) $id, $excluded, true ) ) {
				$lines[] = $field['label'] . ': ' . $field['value'];
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Convert scalar or multiple answers to safe plain text without losing choices.
	 *
	 * @param mixed $value A submitted answer or an array of answers.
	 * @return string Sanitized answer text.
	 */
	private static function text( $value ): string {
		if ( is_array( $value ) ) {
			$values = array();
			foreach ( $value as $choice ) {
				$text = self::text( $choice );
				if ( '' !== $text ) {
					$values[] = $text;
				}
			}
			return implode( ', ', $values );
		}
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}
}
