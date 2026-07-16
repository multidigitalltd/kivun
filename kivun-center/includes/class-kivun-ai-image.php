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
	 * Selectable image styles: key => Hebrew label (shown in the form dropdown).
	 *
	 * @return array<string,string>
	 */
	public static function styles(): array {
		return array(
			'photo'        => __( 'צילום ריאליסטי', 'kivun' ),
			'illustration' => __( 'איור', 'kivun' ),
			'minimal'      => __( 'מינימלי ונקי', 'kivun' ),
			'3d'           => __( 'תלת-ממד', 'kivun' ),
			'abstract'     => __( 'רקע מופשט', 'kivun' ),
		);
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

		$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc   = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$style  = isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : 'photo';
		$custom = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';

		// A title is only required when there is no free-text prompt to work from.
		if ( '' === $title && '' === trim( $custom ) ) {
			wp_send_json_error( array( 'message' => __( 'מלאו כותרת או תיאור חופשי לתמונה.', 'kivun' ) ) );
		}

		$result = self::generate( $title, wp_strip_all_tags( $desc ), $type, $style, wp_strip_all_tags( $custom ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Generate an image and store it as an attachment.
	 *
	 * @param string $title  Content title.
	 * @param string $desc   Short description (plain text).
	 * @param string $type   Content type key (landing/course/session).
	 * @param string $style  Style key (see styles()).
	 * @param string $custom Optional free-text prompt; overrides the derived subject.
	 * @return array{id:int,url:string}|\WP_Error
	 */
	public static function generate( string $title, string $desc, string $type, string $style = 'photo', string $custom = '' ) {
		$key = trim( (string) Kivun_Admin_Settings::get( 'openai_api_key', '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'kivun_ai_no_key', __( 'לא הוגדר מפתח API. הזינו אותו בהגדרות → Kivun Center.', 'kivun' ) );
		}

		$model = (string) Kivun_Admin_Settings::get( 'ai_image_model', 'gpt-image-1' );
		$model = in_array( $model, array( 'gpt-image-1', 'dall-e-3' ), true ) ? $model : 'gpt-image-1';

		$body = array(
			'model'   => $model,
			'prompt'  => self::build_prompt( $title, $desc, $type, $style, $custom ),
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
	 * Crop the raw image to a fixed 16:9 landscape, compress it to at most
	 * 300 KB, and save it as a media attachment.
	 *
	 * @param string $bytes The image bytes.
	 * @param string $title Title used for the attachment.
	 * @return array{id:int,url:string}|\WP_Error
	 */
	private static function store_attachment( string $bytes, string $title ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( 'kivun-ai.png' );
		if ( ! $tmp ) {
			return new \WP_Error( 'kivun_ai_tmp', __( 'שגיאה בעיבוד התמונה.', 'kivun' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing decoded image bytes to a temp file for the image editor.
		file_put_contents( $tmp, $bytes );

		$editor = wp_get_image_editor( $tmp );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $tmp );
			return $editor;
		}

		// Center-crop to exactly 16:9, then scale down to 1280×720.
		$size  = $editor->get_size();
		$width = (int) ( $size['width'] ?? 0 );
		$hgt   = (int) ( $size['height'] ?? 0 );
		$ratio = 16 / 9;
		if ( $width > 0 && $hgt > 0 ) {
			if ( $width / $hgt > $ratio ) {
				$crop_w = (int) round( $hgt * $ratio );
				$editor->crop( (int) round( ( $width - $crop_w ) / 2 ), 0, $crop_w, $hgt );
			} else {
				$crop_h = (int) round( $width / $ratio );
				$editor->crop( 0, (int) round( ( $hgt - $crop_h ) / 2 ), $width, $crop_h );
			}
		}
		$editor->resize( 1280, 720, true );

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'kivun_ai_uploaddir', (string) $uploads['error'] );
		}
		$filename = wp_unique_filename( $uploads['path'], 'kivun-ai-' . wp_generate_uuid4() . '.jpg' );
		$dest     = trailingslashit( $uploads['path'] ) . $filename;

		// Save as JPEG, lowering quality until the file is at most 300 KB.
		$max_bytes = 300 * 1024;
		$saved     = null;
		foreach ( array( 85, 75, 65, 55, 45 ) as $quality ) {
			$editor->set_quality( $quality );
			$saved = $editor->save( $dest, 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				wp_delete_file( $tmp );
				return $saved;
			}
			if ( ! empty( $saved['path'] ) && file_exists( $saved['path'] ) && filesize( $saved['path'] ) <= $max_bytes ) {
				break;
			}
		}
		wp_delete_file( $tmp );

		if ( ! is_array( $saved ) || empty( $saved['path'] ) ) {
			return new \WP_Error( 'kivun_ai_save', __( 'שמירת התמונה נכשלה.', 'kivun' ) );
		}
		$file = (string) $saved['path'];

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return new \WP_Error( 'kivun_ai_attach', __( 'שמירת התמונה נכשלה.', 'kivun' ) );
		}

		$meta = wp_generate_attachment_metadata( (int) $attachment_id, $file );
		wp_update_attachment_metadata( (int) $attachment_id, $meta );

		$url = (string) wp_get_attachment_image_url( (int) $attachment_id, 'large' );
		if ( '' === $url ) {
			$url = (string) wp_get_attachment_url( (int) $attachment_id );
		}

		return array(
			'id'  => (int) $attachment_id,
			'url' => $url,
		);
	}

	/**
	 * Build the generation prompt from the style, an optional free-text subject,
	 * and — when no free text is given — the page content (title + description).
	 *
	 * @param string $title  Content title.
	 * @param string $desc   Short description.
	 * @param string $type   Content type key.
	 * @param string $style  Style key.
	 * @param string $custom Optional free-text subject.
	 * @return string
	 */
	private static function build_prompt( string $title, string $desc, string $type, string $style, string $custom ): string {
		if ( '' !== trim( $custom ) ) {
			$subject = trim( $custom );
		} else {
			$labels     = array(
				'landing' => 'landing page',
				'course'  => 'course',
				'session' => 'workshop',
			);
			$type_label = $labels[ $type ] ?? 'page';
			$subject    = sprintf(
				'a %1$s titled "%2$s"%3$s',
				$type_label,
				$title,
				'' !== $desc ? ', about ' . $desc : ''
			);
		}

		$prompt = sprintf(
			'%1$s. A clean, modern cover image for %2$s. Bright, welcoming and high quality, suitable as a website hero / featured image. Do not include any text, letters or words in the image.',
			self::style_fragment( $style ),
			$subject
		);

		/**
		 * Filter the AI image prompt.
		 *
		 * @param string $prompt The generated prompt.
		 * @param string $title  Content title.
		 * @param string $desc   Short description.
		 * @param string $type   Content type key.
		 * @param string $style  Style key.
		 * @param string $custom Free-text subject.
		 */
		return (string) apply_filters( 'kivun_ai_image_prompt', $prompt, $title, $desc, $type, $style, $custom );
	}

	/**
	 * Map a style key to its English prompt fragment.
	 *
	 * @param string $style The style key.
	 * @return string
	 */
	private static function style_fragment( string $style ): string {
		$map = array(
			'photo'        => 'Realistic professional photography',
			'illustration' => 'Modern flat vector illustration',
			'minimal'      => 'Minimal clean design with lots of negative space and simple shapes',
			'3d'           => 'Polished 3D render with soft lighting',
			'abstract'     => 'Abstract geometric background with soft gradients',
		);
		return $map[ $style ] ?? $map['photo'];
	}

	/**
	 * Resolve the API "size" — always the widest landscape the model supports,
	 * so there is enough material to crop to a fixed 16:9.
	 *
	 * @param string $model The model name.
	 * @return string
	 */
	private static function size_for( string $model ): string {
		return 'dall-e-3' === $model ? '1792x1024' : '1536x1024';
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
