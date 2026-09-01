<?php
/**
 * WooCommerce integration for paid course registrations.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration for paid course registrations.
 *
 * Flow:
 *  1. Visitor clicks "הרשמה לקורס" on a paid course → JS sends to WC cart.
 *  2. On order completion → registration saved + confirmation email sent.
 */
class Kivun_WooCommerce {

	/**
	 * Registers WooCommerce hooks when WooCommerce is active.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_complete' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'store_course_in_order' ) );

		// AJAX: add course product to cart and return checkout URL.
		add_action( 'wp_ajax_kivun_course_checkout', array( __CLASS__, 'ajax_checkout' ) );
		add_action( 'wp_ajax_nopriv_kivun_course_checkout', array( __CLASS__, 'ajax_checkout' ) );
	}

	/**
	 * Visitor hits "Register + Pay" → add WC product to cart, store course data in session.
	 *
	 * @return void
	 */
	public static function ajax_checkout(): void {
		check_ajax_referer( 'kivun_nonce', 'nonce' );

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$data      = array(
			'name'    => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'   => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'   => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'message' => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
		);

		$checkout_url = self::add_course_to_cart( $course_id, $data );
		if ( is_wp_error( $checkout_url ) ) {
			wp_send_json_error( array( 'message' => $checkout_url->get_error_message() ) );
		}

		wp_send_json_success( array( 'checkout_url' => $checkout_url ) );
	}

	/**
	 * Whether a course is paid (has a valid linked WooCommerce product).
	 *
	 * @param int $course_id The course post ID.
	 * @return bool True when WooCommerce is active and a valid product is linked.
	 */
	public static function is_paid_course( int $course_id ): bool {
		if ( ! $course_id || ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		$product_id = (int) get_post_meta( $course_id, '_kivun_wc_product_id', true );
		return $product_id > 0 && (bool) wc_get_product( $product_id );
	}

	/**
	 * Empty the cart, add the course's linked product, stash the pending
	 * registration in the WC session, and return the checkout URL.
	 *
	 * Shared by the built-in AJAX flow and the Elementor Forms action.
	 *
	 * @param int   $course_id The course post ID.
	 * @param array $data      Associative data: name, email, phone, message.
	 * @return string|\WP_Error Checkout URL on success, WP_Error on failure.
	 */
	public static function add_course_to_cart( int $course_id, array $data ) {
		$name    = sanitize_text_field( $data['name'] ?? '' );
		$email   = sanitize_email( $data['email'] ?? '' );
		$phone   = sanitize_text_field( $data['phone'] ?? '' );
		$message = sanitize_textarea_field( $data['message'] ?? '' );

		if ( ! $course_id || ! $name || ! is_email( $email ) ) {
			return new \WP_Error( 'kivun_invalid', __( 'נא למלא שם ואימייל תקין.', 'kivun' ) );
		}

		$product_id = (int) get_post_meta( $course_id, '_kivun_wc_product_id', true );
		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			return new \WP_Error( 'kivun_no_product', __( 'מוצר WooCommerce לא מוגדר לקורס זה.', 'kivun' ) );
		}

		// The WC cart/session may not be loaded yet on a non-standard request
		// (e.g. an Elementor Forms submission) — load it on demand.
		if ( ( ! WC()->cart || ! WC()->session ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart ) {
			return new \WP_Error( 'kivun_no_cart', __( 'עגלת הקנייה אינה זמינה כרגע. נסו שוב.', 'kivun' ) );
		}

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product_id );

		WC()->session->set(
			'kivun_pending_registration',
			array(
				'course_id' => $course_id,
				'name'      => $name,
				'email'     => $email,
				'phone'     => $phone,
				'message'   => $message,
			)
		);

		return wc_get_checkout_url();
	}

	/**
	 * Attach session registration data to the order at checkout.
	 *
	 * @param \WC_Order $order The order being created at checkout.
	 * @return void
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
	 *
	 * @param int $order_id The completed WooCommerce order ID.
	 * @return void
	 */
	public static function on_order_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$reg   = $order->get_meta( '_kivun_registration' );

		if ( ! $reg || empty( $reg['course_id'] ) ) {
			return;
		}

		// Prevent double-processing.
		if ( $order->get_meta( '_kivun_registration_saved' ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'kivun_registrations',
			array(
				'course_id'  => $reg['course_id'],
				'name'       => $reg['name'],
				'email'      => $reg['email'],
				'phone'      => $reg['phone'],
				'message'    => $reg['message'] ?? '',
				'status'     => 'confirmed',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		Kivun_Mailer::send_course_registration( $reg['course_id'], $reg );

		$order->update_meta_data( '_kivun_registration_saved', '1' );
		$order->add_order_note( sprintf( 'הרשמה לקורס #%d נשמרה.', $reg['course_id'] ) );
		$order->save();
	}
}
