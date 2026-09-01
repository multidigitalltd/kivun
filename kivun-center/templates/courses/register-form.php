<?php
/**
 * Template: course registration form.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

$price      = (int) get_post_meta( $course_id, '_kivun_price', true );
$product_id = (int) get_post_meta( $course_id, '_kivun_wc_product_id', true );
$is_paid    = $price > 0 && $product_id;
?>
<div class="kivun-register" id="kivun-register-<?php echo esc_attr( $course_id ); ?>">
	<h3 class="kivun-register__title">
		<?php
		echo $is_paid
			/* translators: %s: formatted course price in shekels. */
			? sprintf( esc_html__( 'הרשמה לקורס — %s ₪', 'kivun' ), number_format( $price ) )
			: esc_html__( 'הרשמה לקורס', 'kivun' );
		?>
	</h3>

	<form class="kivun-register-form" novalidate>
		<?php wp_nonce_field( 'kivun_nonce', '_nonce', false ); ?>
		<input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>">
		<input type="hidden" name="is_paid"   value="<?php echo $is_paid ? '1' : '0'; ?>">

		<div class="kivun-form-row">
			<label for="reg-name-<?php echo esc_attr( $course_id ); ?>"><?php esc_html_e( 'שם מלא *', 'kivun' ); ?></label>
			<input id="reg-name-<?php echo esc_attr( $course_id ); ?>" type="text" name="name" required autocomplete="name">
		</div>

		<div class="kivun-form-row">
			<label for="reg-email-<?php echo esc_attr( $course_id ); ?>"><?php esc_html_e( 'אימייל *', 'kivun' ); ?></label>
			<input id="reg-email-<?php echo esc_attr( $course_id ); ?>" type="email" name="email" required autocomplete="email">
		</div>

		<div class="kivun-form-row">
			<label for="reg-phone-<?php echo esc_attr( $course_id ); ?>"><?php esc_html_e( 'טלפון', 'kivun' ); ?></label>
			<input id="reg-phone-<?php echo esc_attr( $course_id ); ?>" type="tel" name="phone" autocomplete="tel">
		</div>

		<div class="kivun-form-row">
			<label for="reg-msg-<?php echo esc_attr( $course_id ); ?>"><?php esc_html_e( 'הערות', 'kivun' ); ?></label>
			<textarea id="reg-msg-<?php echo esc_attr( $course_id ); ?>" name="message" rows="3"></textarea>
		</div>

		<p class="kivun-error" style="display:none;color:var(--kivun-error)"></p>

		<button type="submit" class="kivun-btn kivun-btn--primary">
			<?php
			echo $is_paid
				? esc_html__( 'הרשמה ותשלום', 'kivun' )
				: esc_html__( 'שלח הרשמה', 'kivun' );
			?>
		</button>
	</form>
</div>
