<?php
/**
 * Which image model is asked, and what happens when it is gone.
 *
 * OpenAI shut gpt-image-1 down on 23 October 2026. Every cover asked for
 * since came back "The model 'gpt-image-1' does not exist", because the
 * plugin offered nothing else and fell back to it on any unknown setting.
 *
 * @package Kivun
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

// A queue of canned responses for the image endpoint, and the bodies sent.
if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Record the request and hand back the next canned response.
	 *
	 * @param string $url  The endpoint.
	 * @param array  $args Request arguments.
	 * @return array
	 */
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['kivun_test_http_sent'][] = json_decode( (string) ( $args['body'] ?? '' ), true );
		return array_shift( $GLOBALS['kivun_test_http_queue'] );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * The canned status code.
	 *
	 * @param array $response The canned response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * The canned body.
	 *
	 * @param array $response The canned response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}
}

/**
 * The image model and its fallback.
 */
final class AiImageModelTest extends TestCase {

	/**
	 * A key, and nothing queued.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once KIVUN_DIR . 'includes/class-kivun-ai-image.php';
		$GLOBALS['kivun_test_http_sent']  = array();
		$GLOBALS['kivun_test_http_queue'] = array();
	}

	/**
	 * Set the stored model.
	 *
	 * @param string $model The model the settings hold.
	 * @return void
	 */
	private function configured( string $model ): void {
		Kivun_Test_State::$options['kivun_settings'] = array(
			'openai_api_key' => 'sk-test',
			'ai_image_model' => $model,
		);
	}

	/**
	 * A site still set to gpt-image-1 is moved to the default.
	 *
	 * @return void
	 */
	public function test_a_retired_model_is_replaced(): void {
		$this->configured( 'gpt-image-1' );
		$this->assertSame( 'gpt-image-2', Kivun_AI_Image::model() );

		$this->configured( '' );
		$this->assertSame( 'gpt-image-2', Kivun_AI_Image::model() );

		$this->configured( 'dall-e-3' );
		$this->assertSame( 'dall-e-3', Kivun_AI_Image::model(), 'a model still offered is kept' );

		$this->assertNotContains( 'gpt-image-1', Kivun_AI_Image::MODELS );
	}

	/**
	 * The chosen model first, then the fallbacks, each once.
	 *
	 * @return void
	 */
	public function test_the_fallbacks_follow_the_choice(): void {
		$this->configured( 'dall-e-3' );
		$this->assertSame( array( 'dall-e-3', 'gpt-image-2', 'gpt-image-1.5' ), Kivun_AI_Image::models_to_try() );

		$this->configured( 'gpt-image-2' );
		$this->assertSame( array( 'gpt-image-2', 'gpt-image-1.5' ), Kivun_AI_Image::models_to_try() );
	}

	/**
	 * "Does not exist" is told apart from every other failure.
	 *
	 * @return void
	 */
	public function test_a_missing_model_is_recognised(): void {
		$gone = array(
			'error' => array(
				'message' => "The model 'gpt-image-1' does not exist.",
				'type'    => 'invalid_request_error',
				'code'    => null,
			),
		);
		$this->assertTrue( Kivun_AI_Image::model_missing( 400, $gone ) );
		$this->assertTrue( Kivun_AI_Image::model_missing( 404, array( 'error' => array( 'code' => 'model_not_found' ) ) ) );

		$this->assertFalse( Kivun_AI_Image::model_missing( 401, array( 'error' => array( 'code' => 'invalid_api_key' ) ) ) );
		$this->assertFalse( Kivun_AI_Image::model_missing( 400, array( 'error' => array( 'message' => 'Your prompt was rejected.' ) ) ) );
	}

	/**
	 * A missing model falls through to the next one; any other failure stops.
	 *
	 * @return void
	 */
	public function test_a_missing_model_falls_through_to_the_next(): void {
		$this->configured( 'gpt-image-1' );
		$GLOBALS['kivun_test_http_queue'] = array(
			array(
				'code' => 404,
				'body' => wp_json_encode( array( 'error' => array( 'message' => "The model 'gpt-image-2' does not exist." ) ) ),
			),
			array(
				'code' => 401,
				'body' => wp_json_encode( array( 'error' => array( 'code' => 'invalid_api_key' ) ) ),
			),
		);

		$result = Kivun_AI_Image::generate( 'סדנה', 'תיאור', 'session' );

		$this->assertSame(
			array( 'gpt-image-2', 'gpt-image-1.5' ),
			array_column( $GLOBALS['kivun_test_http_sent'], 'model' ),
			'the missing model was not followed by the next one, or the retired one was asked for'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'מפתח ה-API', $result->get_error_message(), 'a wrong key should be said as such, not as a missing model' );
	}
}
