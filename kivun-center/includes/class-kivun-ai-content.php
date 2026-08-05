<?php
/**
 * AI text-content generation (OpenAI Chat Completions API).
 *
 * Reuses the same OpenAI API key configured for image generation to draft the
 * marketing copy for a course / workshop / session / event from a short topic:
 * title, short & long descriptions, target audience, schedule and cost. The key
 * is never exposed to the browser.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles server-side AI text generation for the content creator.
 */
class Kivun_AI_Content {

	/**
	 * OpenAI chat-completions endpoint.
	 */
	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Register the AJAX handler (logged-in users only).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_generate_ai_content', array( __CLASS__, 'ajax_generate' ) );
	}

	/**
	 * Selectable writing tones: key => Hebrew label (shown in the form dropdown).
	 *
	 * @return array<string,string>
	 */
	public static function tones(): array {
		return array(
			'marketing' => __( 'שיווקי-חם', 'kivun' ),
			'formal'    => __( 'רשמי ומקצועי', 'kivun' ),
			'friendly'  => __( 'ידידותי ואישי', 'kivun' ),
			'concise'   => __( 'קצר ותכליתי', 'kivun' ),
		);
	}

	/**
	 * Map a tone key to its Hebrew instruction fragment.
	 *
	 * @param string $tone The tone key.
	 * @return string
	 */
	private static function tone_fragment( string $tone ): string {
		$map = array(
			'marketing' => 'טון שיווקי, חם ומזמין',
			'formal'    => 'טון רשמי, מקצועי ומכובד',
			'friendly'  => 'טון ידידותי, אישי וקליל',
			'concise'   => 'טון קצר, תכליתי וברור, ללא מליצות מיותרות',
		);
		return $map[ $tone ] ?? $map['marketing'];
	}

	/**
	 * AJAX: draft content fields from a short topic.
	 *
	 * @return void
	 */
	public static function ajax_generate(): void {
		check_ajax_referer( 'kivun_ai_content', 'nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה ליצירת תוכן.', 'kivun' ) ) );
		}

		$topic = isset( $_POST['topic'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topic'] ) ) : '';
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$tone  = isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : 'marketing';

		if ( '' === trim( $topic ) ) {
			wp_send_json_error( array( 'message' => __( 'מלאו נושא ליצירת התוכן.', 'kivun' ) ) );
		}

		$result = self::generate( $topic, $type, $tone );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Call the API and return the drafted, sanitised content fields.
	 *
	 * @param string $topic The subject to write about.
	 * @param string $type  Content type key (landing/course/session/event).
	 * @param string $tone  Writing tone key (see tones()).
	 * @return array<string,string>|\WP_Error
	 */
	public static function generate( string $topic, string $type = '', string $tone = 'marketing' ) {
		$key = trim( (string) Kivun_Admin_Settings::get( 'openai_api_key', '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'kivun_ai_no_key', __( 'לא הוגדר מפתח API. הזינו אותו בהגדרות → Kivun Center.', 'kivun' ) );
		}

		$model = (string) Kivun_Admin_Settings::get( 'ai_text_model', 'gpt-4o-mini' );
		$model = '' !== trim( $model ) ? trim( $model ) : 'gpt-4o-mini';

		$labels     = array(
			'landing' => 'דף נחיתה',
			'course'  => 'קורס',
			'session' => 'סדנה',
			'event'   => 'אירוע',
		);
		$type_label = $labels[ $type ] ?? 'עמוד תוכן';

		$system = 'אתה קופירייטר תוכן למרכז קהילתי בישראל (מרכז כיוון). כתוב עברית תקנית ב' . self::tone_fragment( $tone ) . '. '
			. 'החזר אך ורק אובייקט JSON תקין, ללא טקסט נוסף.';

		$user = sprintf(
			'צור תוכן שיווקי עבור %1$s בנושא: "%2$s". החזר JSON עם המפתחות הבאים בלבד: '
			. '"title" (כותרת קצרה וקולעת), '
			. '"short" (תקציר של משפט–שניים, מותר HTML בסיסי), '
			. '"long" (תיאור מלא של 2–4 פסקאות בתגיות <p>), '
			. '"audience" (קהל היעד, משפט קצר), '
			. '"duration" (משך/מבנה, למשל מספר מפגשים ושעות), '
			. '"cost" (הצעת מחיר או "לפרטים"), '
			. '"date" (הצעת מועד או ריק). כל הערכים בעברית.',
			$type_label,
			$topic
		);

		$body = array(
			'model'           => $model,
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'response_format' => array( 'type' => 'json_object' ),
			'temperature'     => 0.8,
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new \WP_Error( 'kivun_ai_api', self::friendly_error( $code, $data ) );
		}

		$content = is_array( $data ) && isset( $data['choices'][0]['message']['content'] )
			? (string) $data['choices'][0]['message']['content']
			: '';
		$fields  = json_decode( $content, true );
		if ( ! is_array( $fields ) ) {
			return new \WP_Error( 'kivun_ai_parse', __( 'לא התקבל תוכן תקין מהשירות. נסו שוב.', 'kivun' ) );
		}

		$plain = static function ( $value ) {
			return sanitize_text_field( wp_strip_all_tags( (string) ( $value ?? '' ) ) );
		};

		return array(
			'title'    => $plain( $fields['title'] ?? '' ),
			'short'    => wp_kses_post( (string) ( $fields['short'] ?? '' ) ),
			'long'     => wp_kses_post( (string) ( $fields['long'] ?? '' ) ),
			'audience' => $plain( $fields['audience'] ?? '' ),
			'duration' => $plain( $fields['duration'] ?? '' ),
			'cost'     => $plain( $fields['cost'] ?? '' ),
			'date'     => $plain( $fields['date'] ?? '' ),
		);
	}

	/**
	 * Turn an OpenAI API error into a clear Hebrew message.
	 *
	 * @param int   $code HTTP status code.
	 * @param mixed $data Decoded response body.
	 * @return string
	 */
	private static function friendly_error( int $code, $data ): string {
		$api_msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
		$type    = '';
		if ( is_array( $data ) && isset( $data['error']['code'] ) ) {
			$type = (string) $data['error']['code'];
		} elseif ( is_array( $data ) && isset( $data['error']['type'] ) ) {
			$type = (string) $data['error']['type'];
		}

		if ( 401 === $code || 'invalid_api_key' === $type ) {
			return __( 'מפתח ה-API שגוי או חסר. בדקו את המפתח בהגדרות → Kivun Center.', 'kivun' );
		}
		if ( 'insufficient_quota' === $type || 429 === $code || ( '' !== $api_msg && false !== stripos( $api_msg, 'quota' ) ) ) {
			return __( 'נגמרו הקרדיטים/המכסה בחשבון ה-OpenAI. יש לטעון קרדיטים ולנסות שוב.', 'kivun' );
		}
		return '' !== $api_msg ? $api_msg : __( 'שגיאה בשירות ה-AI. נסו שוב מאוחר יותר.', 'kivun' );
	}
}
