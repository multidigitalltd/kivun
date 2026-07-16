<?php
/**
 * AI featured-image generation (OpenAI Images API).
 *
 * Generates a suitable cover image from the content's title/description and
 * saves it to the media library, ready to be used as the featured image. The
 * OpenAI API key, model, orientation and quality are configured under
 * Kivun Center → Settings and never exposed to the front end.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles server-side AI image generation for the content creator.
 */
class Kivun_AI_Image {

	/**
	 * OpenAI image-generation endpoint.
	 */
	const ENDPOINT = 'https://api.openai.com/v1/images/generations';

	/**
	 * Register the AJAX handler (logged-in users only).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_kivun_generate_ai_image', array( __CLASS__, 'ajax_generate' ) );
	}

	/**
	 * Whether AI image generation is available (an API key is configured).
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== trim( (string) Kivun_Admin_Settings::get( 'openai_api_key', '' ) );
	}

	/**
	 * AJAX: generate an image from the submitted content and attach it.
	 *
	 * @return void
	 */
	public static function ajax_generate(): void {
		check_ajax_referer( 'kivun_ai_image', 'nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין לך הרשאה ליצירת תמונות.', 'kivun' ) ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'יש להזין כותרת לפני יצירת תמונה.', 'kivun' ) ) );
		}

		$result = self::generate( $title, wp_strip_all_tags( $desc ), $type );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Generate an image and store it as an attachment.
	 *
	 * @param string $title Content title.
	 * @param string $desc  Short description (plain text).
	 * @param string $type  Content type key (landing/course/session).
	 * @return array{id:int,url:string}|\WP_Error
	 */
	public static function generate( string $title, string $desc, string $type ) {
		$key = trim( (string) Kivun_Admin_Settings::get( 'openai_api_key', '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'kivun_ai_no_key', __( 'לא הוגדר מפתח API. הזינו אותו בהגדרות → Kivun Center.', 'kivun' ) );
		}

		$model = (string) Kivun_Admin_Settings::get( 'ai_image_model', 'gpt-image-1' );
		$model = in_array( $model, array( 'gpt-image-1', 'dall-e-3' ), true ) ? $model : 'gpt-image-1';

		$body = array(
			'model'   => $model,
			'prompt'  => self::build_prompt( $title, $desc, $type ),
			'n'       => 1,
			'size'    => self::size_for( $model ),
			'quality' => self::quality_for( $model ),
		);
		if ( 'dall-e-3' === $model ) {
			$body['response_format'] = 'b64_json';
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 60,
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
			$message = is_array( $data ) && isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: __( 'שגיאה בשירות ה-AI. בדקו את המפתח והמכסה.', 'kivun' );
			return new \WP_Error( 'kivun_ai_api', $message );
		}

		$b64 = is_array( $data ) && isset( $data['data'][0]['b64_json'] ) ? (string) $data['data'][0]['b64_json'] : '';
		if ( '' === $b64 ) {
			return new \WP_Error( 'kivun_ai_empty', __( 'לא התקבלה תמונה מהשירות.', 'kivun' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the API's base64 image payload.
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'kivun_ai_decode', __( 'שגיאה בעיבוד התמונה שנוצרה.', 'kivun' ) );
		}

		return self::store_attachment( $bytes, $title );
	}

	/**
	 * Save raw PNG bytes as a media attachment.
	 *
	 * @param string $bytes The image bytes.
	 * @param string $title Title used for the attachment.
	 * @return array{id:int,url:string}|\WP_Error
	 */
	private static function store_attachment( string $bytes, string $title ) {
		$filename = sanitize_file_name( 'kivun-ai-' . wp_generate_uuid4() . '.png' );
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'kivun_ai_upload', (string) $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return new \WP_Error( 'kivun_ai_attach', __( 'שמירת התמונה נכשלה.', 'kivun' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( (int) $attachment_id, $meta );

		$url = (string) wp_get_attachment_image_url( (int) $attachment_id, 'medium' );
		if ( '' === $url ) {
			$url = (string) wp_get_attachment_url( (int) $attachment_id );
		}

		return array(
			'id'  => (int) $attachment_id,
			'url' => $url,
		);
	}

	/**
	 * Build the generation prompt from the content.
	 *
	 * @param string $title Content title.
	 * @param string $desc  Short description.
	 * @param string $type  Content type key.
	 * @return string
	 */
	private static function build_prompt( string $title, string $desc, string $type ): string {
		$labels     = array(
			'landing' => 'landing page',
			'course'  => 'course',
			'session' => 'workshop',
		);
		$type_label = $labels[ $type ] ?? 'page';

		$prompt = sprintf(
			'A professional, modern, clean cover image for a %1$s titled "%2$s".%3$s Bright, welcoming and high quality, suitable as a website hero / featured image. Do not include any text, letters or words in the image.',
			$type_label,
			$title,
			'' !== $desc ? ' Topic: ' . $desc . '.' : ''
		);

		/**
		 * Filter the AI image prompt.
		 *
		 * @param string $prompt The generated prompt.
		 * @param string $title  Content title.
		 * @param string $desc   Short description.
		 * @param string $type   Content type key.
		 */
		return (string) apply_filters( 'kivun_ai_image_prompt', $prompt, $title, $desc, $type );
	}

	/**
	 * Resolve the API "size" string for the configured orientation and model.
	 *
	 * @param string $model The model name.
	 * @return string
	 */
	private static function size_for( string $model ): string {
		$orientation = (string) Kivun_Admin_Settings::get( 'ai_image_orientation', 'landscape' );

		if ( 'dall-e-3' === $model ) {
			$map = array(
				'landscape' => '1792x1024',
				'portrait'  => '1024x1792',
				'square'    => '1024x1024',
			);
		} else {
			$map = array(
				'landscape' => '1536x1024',
				'portrait'  => '1024x1536',
				'square'    => '1024x1024',
			);
		}

		return $map[ $orientation ] ?? $map['landscape'];
	}

	/**
	 * Resolve the API "quality" value for the configured level and model.
	 *
	 * @param string $model The model name.
	 * @return string
	 */
	private static function quality_for( string $model ): string {
		$level = (string) Kivun_Admin_Settings::get( 'ai_image_quality', 'medium' );

		if ( 'dall-e-3' === $model ) {
			return 'high' === $level ? 'hd' : 'standard';
		}

		return in_array( $level, array( 'low', 'medium', 'high' ), true ) ? $level : 'medium';
	}
}
