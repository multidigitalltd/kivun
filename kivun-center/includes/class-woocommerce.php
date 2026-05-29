<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration for paid course registrations.
 *
 * Flow:
 *  1. Visitor clicks "הרשמה לקורס" on a paid course → JS sends to WC cart.
 *  2. On order completion → registration saved + confirmation email sent.
 */
class Kivun_WooCommerce {

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_completed',      [ __CLASS__, 'on_order_complete' ] );
		add_action( 'woocommerce_checkout_order_created',      [ __CLASS__, 'store_course_in_order' ] );

		// AJAX: add course product to cart and return checkout URL
		add_action( 'wp_ajax_kivun_course_checkout',        [ __CLASS__, 'ajax_checkout' ] );
		add_action( 'wp_ajax_nopriv_kivun_course_checkout', [ __CLASS__, 'ajax_checkout' ] );
	}

	/**
	 * Visitor hits "Register + Pay" → add WC product to cart, store course data in session.
	 */
	public static function ajax_checkout(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$name      = sanitize_text_field( $_POST['name']    ?? '' );
		$email     = sanitize_email( $_POST['email']         ?? '' );
		$phone     = sanitize_text_field( $_POST['phone']   ?? '' );
		$message   = sanitize_textarea_field( $_POST['message'] ?? '' );

		if ( ! $course_id || ! $name || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם ואימייל תקין.', 'kivun' ) ] );
		}

		$product_id = (int) get_post_meta( $course_id, '_kivun_wc_product_id', true );
		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'מוצר WooCommerce לא מוגדר לקורס זה.', 'kivun' ) ] );
		}

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product_id );

		WC()->session->set( 'kivun_pending_registration', [
			'course_id' => $course_id,
			'name'      => $name,
			'email'     => $email,
			'phone'     => $phone,
			'message'   => $message,
		] );

		wp_send_json_success( [ 'checkout_url' => wc_get_checkout_url() ] );
	}

	/**
	 * Attach session registration data to the order at checkout.
	 */
	public static function store_course_in_order( \WC_Order $order ): void {
		$reg = WC()->session ? WC()->session->get( 'kivun_pending_registration' ) : null;
		if ( ! $reg ) {
			return;
		}
		$order->update_meta_data( '_kivun_registration', $reg );
		$order->save();
		WC()->session->__unset( 'kivun_pending_registration' );
	}

	/**
	 * Payment confirmed → save registration and send emails.
	 */
	public static function on_order_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$reg   = $order->get_meta( '_kivun_registration' );

		if ( ! $reg || empty( $reg['course_id'] ) ) {
			return;
		}

		// Prevent double-processing
		if ( $order->get_meta( '_kivun_registration_saved' ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'kivun_registrations',
			[
				'course_id'  => $reg['course_id'],
				'name'       => $reg['name'],
				'email'      => $reg['email'],
				'phone'      => $reg['phone'],
				'message'    => $reg['message'] ?? '',
				'status'     => 'confirmed',
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		Kivun_Mailer::send_course_registration( $reg['course_id'], $reg );

		$order->update_meta_data( '_kivun_registration_saved', '1' );
		$order->add_order_note( sprintf( 'הרשמה לקורס #%d נשמרה.', $reg['course_id'] ) );
		$order->save();
	}
}
