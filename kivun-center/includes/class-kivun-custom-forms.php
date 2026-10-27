<?php
/**
 * Landing pages whose form is composed rather than fixed.
 *
 * A landing page has always carried the same form: name, phone, email, city,
 * gender, a message and the marketing consent. That form is the right one
 * most of the time and the wrong one often enough — an open day wants to know
 * which track, a scholarship check wants half a dozen answers nobody else
 * needs.
 *
 * So a page may carry a form of its own instead. It is the same post type and
 * the same lead: everything downstream — the leads table, the campaigns, the
 * rota, the reports — goes on working without being told this exists.
 *
 * Two kinds of field, and the difference matters:
 *
 *  - The BUILT-INS are the columns a lead actually has. Name, phone, email,
 *    city and gender are read by the coordinators' rota, the city filter, the
 *    gender split and the sync to Mercaz Kivun. They can be switched off but
 *    never replaced, because a question labelled "your phone" that lands in
 *    the message column leaves the row with no phone and the rota with
 *    nothing to work on.
 *  - Everything else is a QUESTION this page asks. Its answer is kept with
 *    its label, so a column nobody has to add in advance can still be read
 *    back in a report.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * The form a landing page was given, and the answers it collects.
 */
class Kivun_Custom_Forms {

	/**
	 * Post meta holding the questions, as JSON.
	 */
	const FIELDS = '_kivun_form_fields';

	/**
	 * Post meta holding which built-in fields are asked for, as JSON.
	 */
	const BUILTINS = '_kivun_form_builtins';

	/**
	 * Post meta marking a page as carrying a form of its own.
	 */
	const FLAG = '_kivun_custom_form';

	/**
	 * How many questions one page may ask.
	 *
	 * Not a technical limit — a form this long is a form nobody finishes —
	 * but the definition is read on every view of the page, so it is also the
	 * ceiling on what one bad save can cost every visitor after it.
	 */
	const MAX_FIELDS = 40;

	/**
	 * How many choices one question may offer.
	 */
	const MAX_OPTIONS = 30;

	/**
	 * The question types on offer, and what each is called on screen.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			'text'     => __( 'טקסט קצר', 'kivun' ),
			'textarea' => __( 'טקסט ארוך', 'kivun' ),
			'select'   => __( 'בחירה מרשימה', 'kivun' ),
			'radio'    => __( 'בחירה יחידה', 'kivun' ),
			'checkbox' => __( 'בחירה מרובה', 'kivun' ),
			'date'     => __( 'תאריך', 'kivun' ),
			'number'   => __( 'מספר', 'kivun' ),
			'consent'  => __( 'אישור', 'kivun' ),
		);
	}

	/**
	 * The types that offer the visitor a list to pick from.
	 *
	 * @return array<int,string>
	 */
	public static function choice_types(): array {
		return array( 'select', 'radio', 'checkbox' );
	}

	/**
	 * The built-in fields, and what each is called.
	 *
	 * Name and phone are not in here as switchable: a lead with neither is a
	 * row nobody can act on, and both are refused by save_lead() anyway.
	 *
	 * @return array<string,string>
	 */
	public static function optional_builtins(): array {
		return array(
			'email'             => __( 'אימייל', 'kivun' ),
			'city'              => __( 'עיר', 'kivun' ),
			'gender'            => __( 'מגדר', 'kivun' ),
			'message'           => __( 'הערות חופשיות', 'kivun' ),
			'marketing_consent' => __( 'אישור דיוור', 'kivun' ),
		);
	}

	/**
	 * Whether this page carries a form of its own.
	 *
	 * @param int $post_id The landing page.
	 * @return bool
	 */
	public static function enabled( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::FLAG, true );
	}

	/**
	 * The questions this page asks, in order.
	 *
	 * Read through the same sanitiser the save went through: the stored value
	 * is a string somebody could have edited in the database, and a view of
	 * the page is not the place to find that out.
	 *
	 * @param int $post_id The landing page.
	 * @return array<int,array{key:string,label:string,type:string,required:bool,options:array<int,string>}>
	 */
	public static function fields( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::FIELDS, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? self::sanitize_fields( $decoded ) : array();
	}

	/**
	 * Which built-in fields this page asks for.
	 *
	 * A page with no stored choice is a page that was never edited, so it
	 * gets the set the fixed form has always shown.
	 *
	 * @param int $post_id The landing page.
	 * @return array<int,string>
	 */
	public static function builtins( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::BUILTINS, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array_keys( self::optional_builtins() );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array_keys( self::optional_builtins() );
		}

		return array_values( array_intersect( array_keys( self::optional_builtins() ), array_map( 'strval', $decoded ) ) );
	}

	/**
	 * Store a page's form.
	 *
	 * @param int   $post_id  The landing page.
	 * @param array $fields   The questions, as submitted.
	 * @param array $builtins The built-in field keys to ask for.
	 * @return array<int,array<string,mixed>> What was actually stored.
	 */
	public static function save( int $post_id, array $fields, array $builtins ): array {
		$clean = self::sanitize_fields( $fields );
		$keep  = array_values( array_intersect( array_keys( self::optional_builtins() ), array_map( 'strval', $builtins ) ) );

		// wp_slash() because update_post_meta() unslashes what it is given,
		// and JSON is made of backslashes. A Hebrew label encodes to
		// \u05de..., which arrives in the database as literal u05de; a label
		// with a quote in it encodes to \" and arrives as a bare quote, which
		// makes the whole definition unreadable. Either way fields() then
		// returns nothing — the form empties itself and its required
		// questions stop being required, without a word.
		update_post_meta( $post_id, self::FIELDS, wp_slash( (string) wp_json_encode( $clean ) ) );
		update_post_meta( $post_id, self::BUILTINS, wp_slash( (string) wp_json_encode( $keep ) ) );
		update_post_meta( $post_id, self::FLAG, '1' );

		return $clean;
	}

	/**
	 * Put a definition into the one shape the rest of this class trusts.
	 *
	 * Anything unrecognisable is dropped rather than repaired: a question
	 * with no label is a question nobody can answer, and guessing one would
	 * put a word of ours in a form the client wrote.
	 *
	 * @param array $raw The questions, from a form post or from storage.
	 * @return array<int,array{key:string,label:string,type:string,required:bool,options:array<int,string>}>
	 */
	public static function sanitize_fields( array $raw ): array {
		$types = self::types();
		$out   = array();
		$seen  = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			if ( '' === trim( $label ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( ! isset( $types[ $type ] ) ) {
				$type = 'text';
			}

			// The key is what the answer arrives under. Taken from the stored
			// one where there is one, so renaming a question does not orphan
			// the answers already given to it.
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$key = 'q' . ( count( $out ) + 1 ) . '_' . substr( md5( $label . count( $out ) ), 0, 6 );
			}
			$seen[ $key ] = true;

			$options = array();
			if ( in_array( $type, self::choice_types(), true ) ) {
				$list = $field['options'] ?? array();
				if ( is_string( $list ) ) {
					$list = preg_split( '/\r\n|\r|\n/', $list );
				}
				foreach ( (array) $list as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== trim( $option ) && ! in_array( $option, $options, true ) ) {
						$options[] = $option;
					}
					if ( count( $options ) >= self::MAX_OPTIONS ) {
						break;
					}
				}

				// A list to choose from with nothing in it is a box that
				// cannot be answered, so it becomes a plain question instead
				// of a dead one.
				if ( ! $options ) {
					$type = 'text';
				}
			}

			$out[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => $options,
			);

			if ( count( $out ) >= self::MAX_FIELDS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Read this page's answers out of a submission.
	 *
	 * Validated here rather than trusted from the markup: `required` in the
	 * form is a convenience for the person filling it in, and anything
	 * posting to the endpoint directly never saw it.
	 *
	 * @param int                 $post_id The landing page.
	 * @param array<string,mixed> $posted  The raw submitted values, keyed by field key.
	 * @return array<int,array{label:string,value:string}>|WP_Error
	 */
	public static function collect( int $post_id, array $posted ) {
		$answers = array();

		foreach ( self::fields( $post_id ) as $field ) {
			$given = $posted[ $field['key'] ] ?? '';
			$value = self::value_of( $field, $given );

			if ( '' === $value ) {
				if ( $field['required'] ) {
					return new WP_Error(
						'kivun_field_required',
						sprintf(
							/* translators: %s: the question's label. */
							__( 'יש למלא: %s', 'kivun' ),
							$field['label']
						)
					);
				}
				continue;
			}

			$answers[] = array(
				'label' => $field['label'],
				'value' => $value,
			);
		}

		return $answers;
	}

	/**
	 * One answer, as text, whatever shape it arrived in.
	 *
	 * @param array $field The question it answers.
	 * @param mixed $given The submitted value.
	 * @return string '' when nothing was answered.
	 */
	private static function value_of( array $field, $given ): string {
		if ( 'consent' === $field['type'] ) {
			return empty( $given ) ? '' : __( 'כן', 'kivun' );
		}

		// A multiple choice arrives as a list; every other type as one value.
		if ( is_array( $given ) ) {
			$picked = array();
			foreach ( $given as $one ) {
				$one = sanitize_text_field( (string) $one );
				if ( '' !== trim( $one ) && self::allowed( $field, $one ) ) {
					$picked[] = $one;
				}
			}
			return implode( ', ', $picked );
		}

		$value = 'textarea' === $field['type']
			? sanitize_textarea_field( (string) $given )
			: sanitize_text_field( (string) $given );

		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// An answer the question never offered is not an answer. Without this
		// the stored value would be whatever was typed into the request, and
		// a report grouping by it would grow a row nobody can explain.
		return self::allowed( $field, $value ) ? $value : '';
	}

	/**
	 * Whether a value is one this question offered.
	 *
	 * @param array  $field The question.
	 * @param string $value The submitted answer.
	 * @return bool
	 */
	private static function allowed( array $field, string $value ): bool {
		if ( ! in_array( $field['type'], self::choice_types(), true ) ) {
			return true;
		}
		return in_array( $value, $field['options'], true );
	}

	/**
	 * The answers as the lines kept in the lead's own message.
	 *
	 * The same shape Kivun_Form_Details writes for an Elementor submission,
	 * so the leads table, the notification and the existing export column all
	 * read these without being changed.
	 *
	 * @param array<int,array{label:string,value:string}> $answers The answers.
	 * @param string                                      $message Anything the visitor wrote freely.
	 * @return string
	 */
	public static function lines( array $answers, string $message = '' ): string {
		$lines = array();
		if ( '' !== trim( $message ) ) {
			$lines[] = $message;
		}
		foreach ( $answers as $answer ) {
			$lines[] = $answer['label'] . ': ' . $answer['value'];
		}
		return implode( "\n", $lines );
	}
}
