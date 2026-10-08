<?php
/**
 * Extra form answers must survive the real lead, free-course and paid-course paths.
 *
 * @package Kivun
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/elementor-action-base.php';
require_once KIVUN_DIR . 'includes/functions.php';
foreach ( array( 'form-details', 'lead-capture', 'workshops', 'courses', 'woocommerce' ) as $class ) {
	require_once KIVUN_DIR . 'includes/class-kivun-' . $class . '.php';
}
require_once KIVUN_DIR . 'elementor/class-kivun-lead-action.php';
require_once KIVUN_DIR . 'elementor/class-kivun-course-registration-action.php';

/** Records the writes performed by the actual registration handlers. */
final class Kivun_Form_Test_Database {
	public string $prefix = 'wp_';
	public array $rows = array();

	public function prepare( string $sql, ...$args ): string {
		return $sql;
	}

	public function get_var( string $sql ) {
		return null;
	}

	public function insert( string $table, array $row, array $formats ): int {
		$this->rows[] = $row;
		return 1;
	}
}

/** A record with the same getters the production actions use. */
final class Kivun_Form_Test_Record {
	private array $fields;
	private array $settings;

	public function __construct( array $fields, array $settings = array() ) {
		$this->fields = $fields;
		$this->settings = $settings;
	}

	public function get( string $key ): array {
		return 'fields' === $key ? $this->fields : $this->settings;
	}

	public function get_form_settings( string $key ) {
		return $this->settings[ $key ] ?? '';
	}
}

/** Records Elementor errors and redirects without making an HTTP request. */
final class Kivun_Form_Test_Handler {
	public array $errors = array();
	public array $response = array();

	public function add_error_message( string $message ): void {
		$this->errors[] = $message;
	}

	public function add_response_data( string $key, $value ): void {
		$this->response[ $key ] = $value;
	}
}

/** The only WooCommerce interfaces touched by the paid-registration pipeline. */
class WooCommerce {}

final class Kivun_Form_Test_Session {
	public array $data = array();
	public function set( string $key, $value ): void { $this->data[ $key ] = $value; }
	public function get( string $key ) { return $this->data[ $key ] ?? null; }
	public function __unset( string $key ): void { unset( $this->data[ $key ] ); }
}

final class Kivun_Form_Test_Cart {
	public function empty_cart(): void {}
	public function add_to_cart( int $id ): void {}
}

class WC_Order {
	public array $meta = array();
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function get_meta( string $key ) { return $this->meta[ $key ] ?? null; }
	public function save(): void {}
	public function add_order_note( string $note ): void {}
}

function WC() {
	return $GLOBALS['kivun_form_test_wc'];
}

function wc_get_product( int $id ) {
	return 100 === $id ? (object) array( 'id' => $id ) : false;
}

function wc_get_checkout_url(): string {
	return 'https://example.test/checkout/';
}

function wc_get_order( int $id ): WC_Order {
	return $GLOBALS['kivun_form_test_order'];
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	function wp_get_referer(): string {
		return (string) ( Kivun_Test_State::$options['referer'] ?? '' );
	}
}

final class FormSubmissionDetailsTest extends TestCase {
	private $previous_database;
	private array $previous_cookies;

	protected function setUp(): void {
		Kivun_Test_State::reset();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->previous_cookies = $_COOKIE;
		$_COOKIE = array();
		$GLOBALS['wpdb'] = new Kivun_Form_Test_Database();
		$GLOBALS['kivun_form_test_wc'] = (object) array(
			'cart' => new Kivun_Form_Test_Cart(),
			'session' => new Kivun_Form_Test_Session(),
		);
		$GLOBALS['kivun_form_test_order'] = new WC_Order();
		Kivun_Test_State::$options['admin_email'] = 'admin@example.test';
		Kivun_Test_State::$options['referer'] = 'https://example.test/landing/';
		Kivun_Test_State::$posts = array(
			42 => array( 'post_type' => 'kivun_course', 'post_title' => 'קורס', 'permalink' => 'https://example.test/course/' ),
			43 => array( 'post_type' => 'kivun_workshop', 'post_title' => 'דף נחיתה', 'permalink' => 'https://example.test/landing/' ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		$_COOKIE = $this->previous_cookies;
		unset( $GLOBALS['kivun_form_test_wc'], $GLOBALS['kivun_form_test_order'] );
	}

	private function field( string $type, string $title, $value ): array {
		return compact( 'type', 'title', 'value' );
	}

	private function answers(): array {
		return array(
			'person' => $this->field( 'text', 'שם מלא', 'שרה' ),
			'phone' => $this->field( 'tel', 'טלפון', '0501234567' ),
			'email' => $this->field( 'email', 'אימייל', 'sara@example.test' ),
			'city' => $this->field( 'text', 'עיר', 'ירושלים' ),
			'gender' => $this->field( 'select', 'מגדר', 'אישה' ),
			'consent' => $this->field( 'acceptance', 'אישור דיוור', array( 'כן' ) ),
			'comment' => $this->field( 'textarea', 'הערות', "נא להתקשר בערב\nאחרי שש" ),
			'course_type' => $this->field( 'select', 'מסלול', 'עיצוב' ),
			'preferred_days' => $this->field( 'checkbox', 'ימים מתאימים', array( 'ראשון', 'שלישי' ) ),
			'levels' => $this->field( 'select', 'רמות', array( 'בסיסי', 'מתקדם' ) ),
			'extra_comment' => $this->field( 'textarea', 'בקשה נוספת', "שורה ראשונה\nשורה שנייה" ),
			'zero' => $this->field( 'number', 'ניסיון קודם', '0' ),
			'post_id' => $this->field( 'hidden', 'דף יעד', '43' ),
			'internal_token' => $this->field( 'hidden', 'נתון פנימי', 'hidden-secret' ),
			'login' => $this->field( 'password', 'סיסמה', 'password-secret' ),
			'captcha' => $this->field( 'recaptcha_v3', 'אימות', 'captcha-secret' ),
			'nonce' => $this->field( 'text', 'אימות טכני', 'nonce-secret' ),
		);
	}

	private function expected_message(): string {
		return "נא להתקשר בערב\nאחרי שש\nמסלול: עיצוב\nימים מתאימים: ראשון, שלישי\nרמות: בסיסי, מתקדם\nבקשה נוספת: שורה ראשונה\nשורה שנייה\nניסיון קודם: 0";
	}

	private function expected_course_message(): string {
		return "נא להתקשר בערב\nאחרי שש\nמגדר: אישה\nאישור דיוור: כן\nמסלול: עיצוב\nימים מתאימים: ראשון, שלישי\nרמות: בסיסי, מתקדם\nבקשה נוספת: שורה ראשונה\nשורה שנייה\nניסיון קודם: 0";
	}

	public function test_lead_action_saves_mapped_comments_and_every_extra_choice_once(): void {
		$settings = array(
			'kivun_lead_source' => 'manual',
			'kivun_lead_post_id' => 43,
			'kivun_lead_field_name' => 'person',
			'kivun_lead_field_message' => 'comment',
		);
		$handler = new Kivun_Form_Test_Handler();
		(new Kivun_Lead_Action())->run( new Kivun_Form_Test_Record( $this->answers(), $settings ), $handler );

		$this->assertSame( array(), $handler->errors );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows );
		$row = $GLOBALS['wpdb']->rows[0];
		$this->assertSame( $this->expected_message(), $row['message'] );
		$this->assertSame( 'שרה', $row['name'] );
		$this->assertSame( 'ירושלים', $row['city'] );
		$this->assertSame( 'אישה', $row['gender'] );
		$this->assertSame( 1, $row['marketing_consent'] );
		$this->assertArrayNotHasKey( 'notes', $row, 'Submitted comments must never overwrite staff notes.' );
		$this->assertStringContainsString( 'ימים מתאימים: ראשון, שלישי', Kivun_Test_State::$mail[0]['message'] );
	}

	public function test_generic_capture_uses_the_same_safe_answers_and_skips_kivun_actions(): void {
		Kivun_Lead_Capture::capture( new Kivun_Form_Test_Record( $this->answers() ), null );
		$this->assertSame( $this->expected_message(), $GLOBALS['wpdb']->rows[0]['message'] );

		foreach ( array( 'kivun_lead', 'kivun_course_registration' ) as $action ) {
			Kivun_Lead_Capture::capture( new Kivun_Form_Test_Record( $this->answers(), array( 'submit_actions' => array( $action ) ) ), null );
		}
		$this->assertCount( 1, $GLOBALS['wpdb']->rows, 'Do not insert a second row for forms saved by their Kivun action.' );
	}

	public function test_free_course_saves_unmapped_answers_and_mails_them(): void {
		$handler = $this->register_course();
		$this->assertSame( array(), $handler->errors );
		$message = $GLOBALS['wpdb']->rows[0]['message'];
		$this->assertSame( $this->expected_course_message(), $message );
		$this->assertStringContainsString( 'מגדר: אישה', $message );
		$this->assertStringContainsString( 'אישור דיוור: כן', $message );
		$this->assertSame( 1, substr_count( $message, 'נא להתקשר בערב' ) );
		$this->assertStringContainsString( 'רמות: בסיסי, מתקדם', Kivun_Test_State::$mail[0]['message'] );
	}

	public function test_paid_course_keeps_all_answers_from_session_through_completed_order(): void {
		Kivun_Test_State::$meta[42]['_kivun_wc_product_id'] = 100;
		$handler = $this->register_course();
		$this->assertSame( array(), $handler->errors );
		$this->assertSame( 'https://example.test/checkout/', $handler->response['redirect_url'] );
		$this->assertCount( 0, $GLOBALS['wpdb']->rows );
		$pending = WC()->session->get( 'kivun_pending_registration' );
		$this->assertSame( 'ירושלים', $pending['city'] );
		$this->assertSame( $this->expected_course_message(), $pending['message'] );

		Kivun_WooCommerce::store_course_in_order( $GLOBALS['kivun_form_test_order'] );
		$this->assertNull( WC()->session->get( 'kivun_pending_registration' ) );
		Kivun_WooCommerce::on_order_complete( 500 );
		$this->assertSame( $pending['message'], $GLOBALS['wpdb']->rows[0]['message'] );
		$this->assertSame( 'ירושלים', $GLOBALS['wpdb']->rows[0]['city'] );
		$this->assertStringContainsString( 'ימים מתאימים: ראשון, שלישי', Kivun_Test_State::$mail[0]['message'] );

		Kivun_WooCommerce::on_order_complete( 500 );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows, 'Order completion must remain idempotent.' );
	}

	private function register_course(): Kivun_Form_Test_Handler {
		$settings = array(
			'kivun_course_source' => 'manual',
			'kivun_course_id' => 42,
			'kivun_field_name' => 'person',
			'kivun_field_message' => 'comment',
		);
		$handler = new Kivun_Form_Test_Handler();
		(new Kivun_Course_Registration_Action())->run( new Kivun_Form_Test_Record( $this->answers(), $settings ), $handler );
		return $handler;
	}

	public function test_unmapped_comment_is_kept_when_its_field_id_differs_from_the_mapping(): void {
		$settings = array( 'kivun_lead_source' => 'board', 'kivun_lead_field_name' => 'person' );
		$handler = new Kivun_Form_Test_Handler();
		(new Kivun_Lead_Action())->run( new Kivun_Form_Test_Record( $this->answers(), $settings ), $handler );
		$this->assertSame( array(), $handler->errors );
		$this->assertStringContainsString( "הערות: נא להתקשר בערב\nאחרי שש", $GLOBALS['wpdb']->rows[0]['message'] );
	}

	public function test_explicit_hidden_contact_and_message_mappings_still_work(): void {
		$answers = $this->answers();
		foreach ( array( 'person', 'email', 'city', 'comment' ) as $id ) {
			$answers[ $id ]['type'] = 'hidden';
		}
		$settings = array(
			'kivun_lead_source' => 'manual',
			'kivun_lead_post_id' => 43,
			'kivun_lead_field_name' => 'person',
			'kivun_lead_field_message' => 'comment',
		);
		$handler = new Kivun_Form_Test_Handler();
		(new Kivun_Lead_Action())->run( new Kivun_Form_Test_Record( $answers, $settings ), $handler );
		$this->assertSame( array(), $handler->errors );
		$row = $GLOBALS['wpdb']->rows[0];
		$this->assertSame( 'שרה', $row['name'] );
		$this->assertSame( 'sara@example.test', $row['email'] );
		$this->assertSame( 'ירושלים', $row['city'] );
		$this->assertSame( $this->expected_message(), $row['message'] );
		$this->assertStringNotContainsString( 'hidden-secret', $row['message'] );
	}

	public function test_labels_and_all_array_values_are_sanitized_and_duplicate_labels_survive(): void {
		$fields = array(
			'first' => $this->field( 'checkbox', '<b>בחירה</b>', array( '<b>ראשון</b>', '<script>secret()</script>שלישי' ) ),
			'second' => $this->field( 'select', 'בחירה', array( 'רביעי', array( 'חמישי' ) ) ),
			'missing_label' => $this->field( 'number', '', 0 ),
			'unknown_value' => $this->field( 'text', 'לא תקין', new stdClass() ),
		);
		$message = Kivun_Form_Details::message( Kivun_Form_Details::fields( $fields ), array() );
		$this->assertSame( "בחירה: ראשון, שלישי\nבחירה: רביעי, חמישי\nmissing_label: 0", $message );
	}
}
